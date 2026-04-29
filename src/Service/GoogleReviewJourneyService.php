<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\GoogleReviewEvent;
use App\Entity\GoogleReviewReward;
use App\Entity\GoogleReviewSession;
use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\User;
use App\Enum\GoogleReviewEventType;
use App\Enum\GoogleReviewRewardStatus;
use App\Enum\GoogleReviewSessionStatus;
use App\Exception\GoogleReviewException;
use App\Repository\GoogleReviewRewardRepository;
use App\Repository\GoogleReviewSessionRepository;
use App\Repository\MerchantGoogleReviewModuleRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class GoogleReviewJourneyService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MerchantGoogleReviewModuleRepository $moduleRepository,
        private readonly GoogleReviewSessionRepository $sessionRepository,
        private readonly GoogleReviewRewardRepository $rewardRepository,
        private readonly GoogleReviewModuleManager $moduleManager,
    ) {
    }

    /**
     * @return Merchant[]
     */
    public function getCustomerMerchants(Customer $customer): array
    {
        $merchants = [];

        foreach ($customer->getMerchants() as $merchant) {
            $merchantId = $merchant->getId()?->toRfc4122();
            if ($merchantId !== null) {
                $merchants[$merchantId] = $merchant;
            }
        }

        $directMerchant = $customer->getMerchant();
        if ($directMerchant instanceof Merchant && $directMerchant->getId() !== null) {
            $merchants[$directMerchant->getId()->toRfc4122()] = $directMerchant;
        }

        foreach ($customer->getLoyaltyCards() as $card) {
            $cardMerchant = $card->getMerchant();
            $merchantId = $cardMerchant?->getId()?->toRfc4122();
            if ($merchantId !== null) {
                $merchants[$merchantId] = $cardMerchant;
            }
        }

        return array_values($merchants);
    }

    public function customerHasMerchant(Customer $customer, Merchant $merchant): bool
    {
        if ($customer->getMerchant() === $merchant) {
            return true;
        }

        foreach ($customer->getLoyaltyCards() as $card) {
            if ($card->getMerchant() === $merchant) {
                return true;
            }
        }

        return $customer->getMerchants()->contains($merchant);
    }

    /**
     * @return MerchantGoogleReviewModule[]
     */
    public function getVisibleModulesForCustomer(Customer $customer): array
    {
        $modules = [];

        foreach ($this->getCustomerMerchants($customer) as $merchant) {
            $module = $this->moduleRepository->findOneByMerchant($merchant);
            if ($module instanceof MerchantGoogleReviewModule && $this->moduleManager->isVisibleToCustomer($module)) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    public function getVisibleModuleForCustomer(Customer $customer, Merchant $merchant): MerchantGoogleReviewModule
    {
        if (!$this->customerHasMerchant($customer, $merchant)) {
            throw new GoogleReviewException('merchant_not_found', 404);
        }

        $module = $this->moduleRepository->findOneByMerchant($merchant);
        if (!$module instanceof MerchantGoogleReviewModule || !$this->moduleManager->isVisibleToCustomer($module)) {
            throw new GoogleReviewException('google_review_module_not_found', 404);
        }

        return $module;
    }

    public function getCustomerSession(string $sessionId, Customer $customer): GoogleReviewSession
    {
        $session = $this->sessionRepository->find($sessionId);
        if (!$session instanceof GoogleReviewSession || $session->getCustomer() !== $customer) {
            throw new GoogleReviewException('google_review_session_not_found', 404);
        }

        return $session;
    }

    public function getCustomerReward(string $rewardId, Customer $customer): GoogleReviewReward
    {
        $reward = $this->rewardRepository->find($rewardId);
        if (!$reward instanceof GoogleReviewReward || $reward->getCustomer() !== $customer) {
            throw new GoogleReviewException('google_review_reward_not_found', 404);
        }

        return $reward;
    }

    public function getCurrentSessionForCustomerAndMerchant(Customer $customer, Merchant $merchant): ?GoogleReviewSession
    {
        return $this->sessionRepository->findLatestForCustomerAndMerchant($customer, $merchant);
    }

    public function getCurrentRewardForCustomerAndMerchant(Customer $customer, Merchant $merchant): ?GoogleReviewReward
    {
        return $this->rewardRepository->findActiveRewardForCustomerAndMerchant($customer, $merchant)
            ?? $this->rewardRepository->findLatestForCustomerAndMerchant($customer, $merchant);
    }

    public function launch(Customer $customer, Merchant $merchant): GoogleReviewSession
    {
        $module = $this->getVisibleModuleForCustomer($customer, $merchant);
        $session = $this->sessionRepository->findLatestForCustomerAndMerchant($customer, $merchant);

        if ($session instanceof GoogleReviewSession && !$this->isReusableSession($session)) {
            $session = null;
        }

        if (!$session instanceof GoogleReviewSession) {
            $session = new GoogleReviewSession();
            $session->setCustomer($customer);
            $session->setMerchant($merchant);
            $session->setModule($module);
            $this->entityManager->persist($session);
        }

        if ($session->getReward() instanceof GoogleReviewReward) {
            return $session;
        }

        $session->setModule($module);
        $session->incrementLaunchCount();
        $session->setLaunchedAt(new \DateTimeImmutable());
        $session->setStatus(GoogleReviewSessionStatus::OUTBOUND_OPENED);

        $this->logEvent($merchant, $customer, $module, GoogleReviewEventType::OUTBOUND_CLICKED, $session, 'launch_endpoint', [
            'launch_count' => $session->getLaunchCount(),
        ]);

        return $session;
    }

    public function confirmReturn(GoogleReviewSession $session): GoogleReviewSession
    {
        if ($session->getStatus() === GoogleReviewSessionStatus::OUTBOUND_OPENED) {
            $session->setReturnedAt(new \DateTimeImmutable());
            $session->setStatus(GoogleReviewSessionStatus::RETURNED_TO_APP);

            $this->logEvent(
                $session->getMerchant(),
                $session->getCustomer(),
                $session->getModule(),
                GoogleReviewEventType::RETURN_CONFIRMED,
                $session,
                'return_endpoint',
            );

            return $session;
        }

        if (in_array($session->getStatus(), [GoogleReviewSessionStatus::RETURNED_TO_APP, GoogleReviewSessionStatus::REWARD_READY], true)) {
            return $session;
        }

        throw new GoogleReviewException('google_review_session_invalid_state', 409);
    }

    public function spin(GoogleReviewSession $session): GoogleReviewReward
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($session): GoogleReviewReward {
            $lockedSession = $this->sessionRepository->find($session->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedSession instanceof GoogleReviewSession) {
                throw new GoogleReviewException('google_review_session_not_found', 404);
            }

            if ($lockedSession->getReward() instanceof GoogleReviewReward) {
                return $lockedSession->getReward();
            }

            if ($lockedSession->getStatus() !== GoogleReviewSessionStatus::RETURNED_TO_APP) {
                throw new GoogleReviewException('google_review_session_invalid_state', 409);
            }

            $activeReward = $this->rewardRepository->findActiveRewardForCustomerAndMerchant(
                $lockedSession->getCustomer(),
                $lockedSession->getMerchant(),
            );

            if ($activeReward instanceof GoogleReviewReward && $activeReward->getSession() !== $lockedSession) {
                throw new GoogleReviewException('google_review_reward_already_active', 409);
            }

            $module = $lockedSession->getModule();
            if (!$module instanceof MerchantGoogleReviewModule || !$this->moduleManager->isComplete($module)) {
                throw new GoogleReviewException('google_review_module_incomplete', 409);
            }

            $rewardOptions = $module->getActiveRewardOptions();
            if ($rewardOptions === []) {
                throw new GoogleReviewException('google_review_rewards_missing', 422);
            }

            $selectedOption = $rewardOptions[random_int(0, count($rewardOptions) - 1)];
            $reward = new GoogleReviewReward();
            $reward->setSession($lockedSession);
            $reward->setMerchant($lockedSession->getMerchant());
            $reward->setCustomer($lockedSession->getCustomer());
            $reward->setRewardLabel($selectedOption['label']);
            $reward->setRewardDescription($selectedOption['description']);
            $reward->setQrToken($this->generateUniqueQrToken());
            $reward->setQrPayload('coachat-google-review-reward://' . $reward->getQrToken());
            $reward->setStatus(GoogleReviewRewardStatus::ACTIVE);

            $lockedSession->setReward($reward);
            $lockedSession->setSpunAt(new \DateTimeImmutable());
            $lockedSession->setStatus(GoogleReviewSessionStatus::REWARD_READY);

            $entityManager->persist($reward);

            $this->logEvent($lockedSession->getMerchant(), $lockedSession->getCustomer(), $module, GoogleReviewEventType::WHEEL_SPUN, $lockedSession, 'spin_endpoint');
            $this->logEvent($lockedSession->getMerchant(), $lockedSession->getCustomer(), $module, GoogleReviewEventType::REWARD_REVEALED, $lockedSession, 'spin_endpoint', [
                'reward_label' => $reward->getRewardLabel(),
            ]);

            return $reward;
        });
    }

    public function redeemReward(string $rawQrToken, User $actor): GoogleReviewReward
    {
        $merchant = $this->resolveActorMerchant($actor);
        if (!$merchant instanceof Merchant) {
            throw new GoogleReviewException('merchant_not_found', 404);
        }

        $token = $this->extractQrToken($rawQrToken);
        $reward = $this->rewardRepository->findOneByQrToken($token);
        if (!$reward instanceof GoogleReviewReward || $reward->getMerchant()?->getId()?->toRfc4122() !== $merchant->getId()?->toRfc4122()) {
            throw new GoogleReviewException('google_review_reward_not_found', 404);
        }

        return $this->redeemResolvedReward($reward, $actor, 'merchant_redeem_endpoint');
    }

    public function redeemRewardById(string $rewardId, User $actor): GoogleReviewReward
    {
        $merchant = $this->resolveActorMerchant($actor);
        if (!$merchant instanceof Merchant) {
            throw new GoogleReviewException('merchant_not_found', 404);
        }

        $reward = $this->rewardRepository->find($rewardId);
        if (!$reward instanceof GoogleReviewReward || $reward->getMerchant()?->getId()?->toRfc4122() !== $merchant->getId()?->toRfc4122()) {
            throw new GoogleReviewException('google_review_reward_not_found', 404);
        }

        return $this->redeemResolvedReward($reward, $actor, 'merchant_manual_redeem_endpoint');
    }

    private function redeemResolvedReward(GoogleReviewReward $reward, User $actor, string $source): GoogleReviewReward
    {
        if ($reward->getStatus() === GoogleReviewRewardStatus::REDEEMED) {
            throw new GoogleReviewException('google_review_reward_already_redeemed', 409);
        }

        if ($reward->getStatus() !== GoogleReviewRewardStatus::ACTIVE) {
            throw new GoogleReviewException('google_review_reward_not_redeemable', 409);
        }

        if ($reward->getExpiresAt() instanceof \DateTimeImmutable && $reward->getExpiresAt() <= new \DateTimeImmutable()) {
            $reward->setStatus(GoogleReviewRewardStatus::EXPIRED);
            $reward->getSession()?->setStatus(GoogleReviewSessionStatus::EXPIRED);

            throw new GoogleReviewException('google_review_reward_not_redeemable', 409);
        }

        $reward->setStatus(GoogleReviewRewardStatus::REDEEMED);
        $reward->setRedeemedAt(new \DateTimeImmutable());
        $reward->setRedeemedBy($actor);

        $session = $reward->getSession();
        if ($session instanceof GoogleReviewSession) {
            $session->setStatus(GoogleReviewSessionStatus::REDEEMED);
        }

        $this->logEvent(
            $reward->getMerchant(),
            $reward->getCustomer(),
            $session?->getModule(),
            GoogleReviewEventType::REWARD_REDEEMED,
            $session,
            $source,
            ['reward_id' => $reward->getId()?->toRfc4122()],
        );

        return $reward;
    }

    public function logCustomEvent(
        MerchantGoogleReviewModule $module,
        ?Customer $customer,
        ?GoogleReviewSession $session,
        GoogleReviewEventType $eventType,
        string $source,
        ?array $metadata = null,
    ): GoogleReviewEvent {
        return $this->logEvent($module->getMerchant(), $customer, $module, $eventType, $session, $source, $metadata);
    }

    public function deriveCustomerStatus(?GoogleReviewSession $session, ?GoogleReviewReward $reward): string
    {
        if ($reward instanceof GoogleReviewReward) {
            return match ($reward->getStatus()) {
                GoogleReviewRewardStatus::ACTIVE => 'reward_ready',
                GoogleReviewRewardStatus::REDEEMED => 'reward_redeemed',
                GoogleReviewRewardStatus::EXPIRED, GoogleReviewRewardStatus::CANCELLED => 'expired',
            };
        }

        if (!$session instanceof GoogleReviewSession) {
            return 'ready_to_launch';
        }

        return match ($session->getStatus()) {
            GoogleReviewSessionStatus::RETURNED_TO_APP => 'ready_to_spin',
            GoogleReviewSessionStatus::REWARD_READY => 'reward_ready',
            GoogleReviewSessionStatus::REDEEMED => 'reward_redeemed',
            GoogleReviewSessionStatus::EXPIRED => 'expired',
            default => 'ready_to_launch',
        };
    }

    private function extractQrToken(string $rawToken): string
    {
        $trimmed = trim($rawToken);

        if (str_starts_with($trimmed, 'coachat-google-review-reward://')) {
            return substr($trimmed, strlen('coachat-google-review-reward://'));
        }

        return $trimmed;
    }

    private function resolveActorMerchant(User $actor): ?Merchant
    {
        $merchant = $actor->getMerchant();
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        if (!in_array('ROLE_EQUIPIER', $actor->getRoles(), true)) {
            return null;
        }

        return $actor->getCustomer()?->getStaffMerchant();
    }

    private function isReusableSession(GoogleReviewSession $session): bool
    {
        return in_array($session->getStatus(), [
            GoogleReviewSessionStatus::READY_TO_LAUNCH,
            GoogleReviewSessionStatus::OUTBOUND_OPENED,
            GoogleReviewSessionStatus::RETURNED_TO_APP,
            GoogleReviewSessionStatus::REWARD_READY,
        ], true);
    }

    private function generateUniqueQrToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
            $existing = $this->rewardRepository->findOneByQrToken($token);
        } while ($existing instanceof GoogleReviewReward);

        return $token;
    }

    private function logEvent(
        ?Merchant $merchant,
        ?Customer $customer,
        ?MerchantGoogleReviewModule $module,
        GoogleReviewEventType $eventType,
        ?GoogleReviewSession $session,
        string $source,
        ?array $metadata = null,
    ): GoogleReviewEvent {
        if (!$merchant instanceof Merchant || !$module instanceof MerchantGoogleReviewModule) {
            throw new GoogleReviewException('google_review_module_not_found', 404);
        }

        $event = new GoogleReviewEvent();
        $event->setMerchant($merchant);
        $event->setCustomer($customer);
        $event->setModule($module);
        $event->setSession($session);
        $event->setEventType($eventType);
        $event->setSource($source);
        $event->setMetadata($metadata);

        $this->entityManager->persist($event);

        return $event;
    }
}