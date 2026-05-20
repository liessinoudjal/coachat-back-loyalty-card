<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestRewardCard;
use App\Entity\ContestWinner;
use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\LoyaltyCard;
use App\Entity\Merchant;
use App\Entity\NotificationLog;
use App\Entity\PromotionalOffer;
use App\Entity\Reward;
use App\Entity\Transaction;
use App\Enum\LoyaltyProgramType;
use App\Enum\NotificationChannel;
use App\Enum\NotificationLogStatus;
use App\Enum\NotificationType;
use App\Service\ContestParticipationService;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NotificationService
{
    public function __construct(
        private readonly EmailNotificationStrategy $emailStrategy,
        private readonly PushNotificationStrategy $pushStrategy,
        private readonly CustomerMerchantNotificationPreferenceRepository $preferenceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $appFrontBaseUrl,
    ) {
    }

    public function notifyPointsAdded(Transaction $transaction): void
    {
        $card = $transaction->getLoyaltyCard();
        $customer = $card?->getCustomer();
        $merchant = $transaction->getMerchant();

        if (!$customer instanceof Customer || !$merchant instanceof Merchant) {
            return;
        }

        $program = $card->getLoyaltyProgram();
        $unitLabel = $program?->getType() === LoyaltyProgramType::STAMP ? 'tampons' : 'points';

        $this->send(
            $merchant,
            $customer,
            NotificationType::POINTS_ADDED,
            [
                'transaction_id' => $transaction->getId(),
                'value_added' => max(0, (int) ($transaction->getPointsEarned() ?? 0)),
                'unit_label' => $unitLabel,
                'current_value' => $card->getCurrentValue(),
                'target_value' => $card->getTargetValue(),
                'reward_ready' => $card->isCompleted(),
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
            ],
        );
    }

    public function notifyRewardClaimed(Reward $reward): void
    {
        $customer = $reward->getCustomer();
        $merchant = $reward->getMerchant();

        if (!$customer instanceof Customer || !$merchant instanceof Merchant) {
            return;
        }

        $this->send(
            $merchant,
            $customer,
            NotificationType::REWARD_CLAIMED,
            [
                'reward_id' => (string) $reward->getId(),
                'reward_description' => $reward->getRewardDescription() ?? 'Votre récompense',
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
            ],
        );
    }

    public function notifyCustomerSignup(Customer $customer, Merchant $merchant, ?string $verifyUrl = null): void
    {
        $context = [
            'dashboard_url' => $this->buildCustomerDashboardUrl(),
        ];
        if ($verifyUrl !== null && $verifyUrl !== '') {
            $context['verify_url'] = $verifyUrl;
        }

        $this->send(
            $merchant,
            $customer,
            NotificationType::CUSTOMER_SIGNUP,
            $context,
        );
    }

    public function notifyEquipierAssigned(Customer $customer, Merchant $merchant): void
    {
        $this->send(
            $merchant,
            $customer,
            NotificationType::EQUIPIER_ASSIGNED,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
            ],
        );
    }

    public function notifyEquipierRemoved(Customer $customer, Merchant $merchant): void
    {
        $this->send(
            $merchant,
            $customer,
            NotificationType::EQUIPIER_REMOVED,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
            ],
        );
    }

    public function notifyCardCreated(LoyaltyCard $card): void
    {
        $customer = $card->getCustomer();
        $merchant = $card->getMerchant();

        if (!$customer instanceof Customer || !$merchant instanceof Merchant) {
            return;
        }

        $program = $card->getLoyaltyProgram();
        $unitLabel = $program?->getType() === LoyaltyProgramType::STAMP ? 'tampons' : 'points';

        $this->send(
            $merchant,
            $customer,
            NotificationType::CARD_CREATED,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'program_name' => $program?->getName() ?? 'Carte fidélité',
                'target_value' => $card->getTargetValue(),
                'unit_label' => $unitLabel,
            ],
        );
    }

    public function notifyCardCompleted(Reward $reward): void
    {
        $customer = $reward->getCustomer();
        $merchant = $reward->getMerchant();

        if (!$customer instanceof Customer || !$merchant instanceof Merchant) {
            return;
        }

        $card = $reward->getLoyaltyCard();
        $program = $card?->getLoyaltyProgram();

        $this->send(
            $merchant,
            $customer,
            NotificationType::CARD_COMPLETED,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'reward_description' => $reward->getRewardDescription() ?? 'Votre récompense',
                'program_name' => $program?->getName() ?? 'Carte fidélité',
            ],
        );
    }

    public function notifyContestCardValueAdded(ContestRewardCard $card, int $valueAdded): void
    {
        $customer = $card->getCustomer();
        $merchant = $card->getMerchant();

        if (!$customer instanceof Customer || !$merchant instanceof Merchant) {
            return;
        }

        $unitLabel = $card->getType()->value === 'CARD_STAMP' ? 'tampons' : 'points';

        $this->send(
            $merchant,
            $customer,
            NotificationType::POINTS_ADDED,
            [
                'transaction_id' => null,
                'value_added' => max(0, $valueAdded),
                'unit_label' => $unitLabel,
                'current_value' => $card->getCurrentValue(),
                'target_value' => $card->getTargetValue(),
                'reward_ready' => $card->isCompleted(),
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
            ],
        );
    }

    public function notifyPromotionalOfferStarts(Customer $customer, Merchant $merchant, PromotionalOffer $offer): bool
    {
        return $this->send(
            $merchant,
            $customer,
            NotificationType::PROMOTIONAL_OFFER_STARTS,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'offer_title' => $offer->getTitle(),
                'offer_description' => $offer->getDescription(),
                'offer_starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
                'offer_ends_on' => $offer->getEndsOn()?->format('Y-m-d'),
            ],
        );
    }

    public function notifyPromotionalOfferEndingSoon(Customer $customer, Merchant $merchant, PromotionalOffer $offer): bool
    {
        return $this->send(
            $merchant,
            $customer,
            NotificationType::PROMOTIONAL_OFFER_ENDING_SOON,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'offer_title' => $offer->getTitle(),
                'offer_description' => $offer->getDescription(),
                'offer_starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
                'offer_ends_on' => $offer->getEndsOn()?->format('Y-m-d'),
                'days_left' => 2,
            ],
        );
    }

    public function notifyPromotionalOfferFlashDayBefore(Customer $customer, Merchant $merchant, PromotionalOffer $offer): bool
    {
        return $this->send(
            $merchant,
            $customer,
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_BEFORE,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'offer_title' => $offer->getTitle(),
                'offer_description' => $offer->getDescription(),
                'offer_date' => $offer->getStartsOn()?->format('Y-m-d'),
            ],
        );
    }

    public function notifyPromotionalOfferFlashDayOf(Customer $customer, Merchant $merchant, PromotionalOffer $offer): bool
    {
        return $this->send(
            $merchant,
            $customer,
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_OF,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'offer_title' => $offer->getTitle(),
                'offer_description' => $offer->getDescription(),
                'offer_date' => $offer->getStartsOn()?->format('Y-m-d'),
            ],
        );
    }

    public function notifyContestDayBeforeStart(Customer $customer, Merchant $merchant, Contest $contest): bool
    {
        $contestRewards = array_map(
            static fn ($reward) => [
                'rank' => $reward->getRank(),
                'title' => $reward->getTitle(),
            ],
            $contest->getRewards()->toArray(),
        );

        return $this->send(
            $merchant,
            $customer,
            NotificationType::CONTEST_DAY_BEFORE_START,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'contest_title' => $contest->getTitle(),
                'contest_description' => $contest->getDescription(),
                'contest_start_at' => $contest->getStartAt()?->format(DATE_ATOM),
                'contest_end_at' => $contest->getEndAt()?->format(DATE_ATOM),
                'contest_draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
                'participation_limit' => ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER,
                'contest_rewards' => $contestRewards,
            ],
        );
    }

    public function notifyContestStarts(Customer $customer, Merchant $merchant, Contest $contest): bool
    {
        $contestRewards = array_map(
            static fn ($reward) => [
                'rank' => $reward->getRank(),
                'title' => $reward->getTitle(),
            ],
            $contest->getRewards()->toArray(),
        );

        return $this->send(
            $merchant,
            $customer,
            NotificationType::CONTEST_STARTS,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'contest_title' => $contest->getTitle(),
                'contest_description' => $contest->getDescription(),
                'contest_start_at' => $contest->getStartAt()?->format(DATE_ATOM),
                'contest_end_at' => $contest->getEndAt()?->format(DATE_ATOM),
                'contest_draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
                'participation_limit' => ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER,
                'contest_rewards' => $contestRewards,
            ],
        );
    }

    public function notifyContestEndingSoon(Customer $customer, Merchant $merchant, Contest $contest): bool
    {
        $contestRewards = array_map(
            static fn ($reward) => [
                'rank' => $reward->getRank(),
                'title' => $reward->getTitle(),
            ],
            $contest->getRewards()->toArray(),
        );

        return $this->send(
            $merchant,
            $customer,
            NotificationType::CONTEST_ENDING_SOON,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'contest_title' => $contest->getTitle(),
                'contest_description' => $contest->getDescription(),
                'contest_start_at' => $contest->getStartAt()?->format(DATE_ATOM),
                'contest_end_at' => $contest->getEndAt()?->format(DATE_ATOM),
                'contest_draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
                'days_left' => 2,
                'participation_limit' => ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER,
                'contest_rewards' => $contestRewards,
            ],
        );
    }

    public function notifyContestDrawDay(Customer $customer, Merchant $merchant, Contest $contest, int $participationCount): bool
    {
        $contestRewards = array_map(
            static fn ($reward) => [
                'rank' => $reward->getRank(),
                'title' => $reward->getTitle(),
            ],
            $contest->getRewards()->toArray(),
        );

        return $this->send(
            $merchant,
            $customer,
            NotificationType::CONTEST_DRAW_DAY,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'contest_title' => $contest->getTitle(),
                'contest_description' => $contest->getDescription(),
                'contest_start_at' => $contest->getStartAt()?->format(DATE_ATOM),
                'contest_end_at' => $contest->getEndAt()?->format(DATE_ATOM),
                'contest_draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
                'participation_limit' => ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER,
                'participation_count' => max(0, $participationCount),
                'contest_rewards' => $contestRewards,
            ],
        );
    }

    public function notifyContestParticipationUpdated(Customer $customer, Merchant $merchant, Contest $contest, int $participationCount, int $participationLimit): bool
    {
        $contestRewards = array_map(
            static fn ($reward) => [
                'rank' => $reward->getRank(),
                'title' => $reward->getTitle(),
            ],
            $contest->getRewards()->toArray(),
        );

        return $this->send(
            $merchant,
            $customer,
            NotificationType::CONTEST_PARTICIPATION_UPDATED,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'contest_title' => $contest->getTitle(),
                'contest_description' => $contest->getDescription(),
                'contest_start_at' => $contest->getStartAt()?->format(DATE_ATOM),
                'contest_end_at' => $contest->getEndAt()?->format(DATE_ATOM),
                'contest_draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
                'contest_rewards' => $contestRewards,
                'participation_count' => $participationCount,
                'participation_limit' => $participationLimit,
                'limit_reached' => $participationCount >= $participationLimit,
            ],
        );
    }

    public function notifyContestWinner(ContestWinner $winner): bool
    {
        $customer = $winner->getCustomer();
        $contest = $winner->getContest();
        $reward = $winner->getReward();
        $merchant = $contest?->getMerchant();

        if (!$customer instanceof Customer || !$contest instanceof Contest || !$merchant instanceof Merchant || $reward === null) {
            return false;
        }

        $rewardType = $reward->getType();
        $isCardReward = in_array($rewardType, [
            \App\Enum\ContestRewardType::CARD_STAMP,
            \App\Enum\ContestRewardType::CARD_POINT,
        ], true);

        return $this->send(
            $merchant,
            $customer,
            NotificationType::CONTEST_WINNER,
            [
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
                'contest_title' => $contest->getTitle(),
                'contest_description' => $contest->getDescription(),
                'contest_draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
                'reward_title' => $reward->getTitle(),
                'reward_rank' => $reward->getRank(),
                'reward_type' => $rewardType->value,
                'reward_description' => $reward->getRewardDescription(),
                'reward_target_value' => $reward->getTargetValue(),
                'qr_code_token' => $winner->getQrCodeToken(),
                'is_card_reward' => $isCardReward,
            ],
        );
    }

    public function send(Merchant $merchant, Customer $customer, NotificationType $type, array $context = []): bool
    {
        if (!$this->isMandatoryNotificationType($type)) {
            $preference = $this->preferenceRepository->findOneByCustomerAndMerchant($customer, $merchant);
            if ($preference instanceof CustomerMerchantNotificationPreference && !$this->isAllowedByPreference($preference, $type)) {
                return true;
            }
        }

        $recipientEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail();
        if ($recipientEmail === null || trim($recipientEmail) === '') {
            return true;
        }

        $channel = $this->resolveChannel($merchant);
        $log = $this->createPendingLog($merchant, $recipientEmail, $type, $this->buildSubject($type, $merchant));

        try {
            try {
                match ($channel) {
                    NotificationChannel::EMAIL => $this->emailStrategy->send($merchant, $customer, $type, $context),
                    NotificationChannel::PUSH => $this->pushStrategy->send($merchant, $customer, $type, $context),
                };
            } catch (\Throwable $pushException) {
                // Push is not yet implemented (or transiently failing): fall back to email
                // so critical notifications (winner, card completed, ...) still reach the customer.
                if ($channel !== NotificationChannel::PUSH) {
                    throw $pushException;
                }

                $this->logger->warning('Push notification failed, falling back to email.', [
                    'merchant_id' => $merchant->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                    'type' => $type->value,
                    'exception' => $pushException->getMessage(),
                ]);

                $this->emailStrategy->send($merchant, $customer, $type, $context);
                $channel = NotificationChannel::EMAIL;
            }

            $log
                ->setStatus(NotificationLogStatus::SENT)
                ->setSentAt(new \DateTimeImmutable())
                ->setErrorMessage(null);
            $this->entityManager->flush();

            return true;
        } catch (\Throwable $exception) {
            $log
                ->setStatus(NotificationLogStatus::FAILED)
                ->setErrorMessage($exception->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Customer notification failed.', [
                'merchant_id' => $merchant->getId()?->toRfc4122(),
                'customer_id' => $customer->getId(),
                'type' => $type->value,
                'channel' => $channel->value,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveChannel(Merchant $merchant): NotificationChannel
    {
        // Free account = accès aux fonctionnalités premium dont les notifications push.
        $pushEnabled = $merchant->isFreeAccount() || (bool) $merchant->getPlan()?->isHasPushNotifications();

        return $pushEnabled ? NotificationChannel::PUSH : NotificationChannel::EMAIL;
    }

    private function buildCustomerDashboardUrl(): string
    {
        return rtrim($this->appFrontBaseUrl, '/');
    }

    private function createPendingLog(Merchant $merchant, string $recipientEmail, NotificationType $type, ?string $subject): NotificationLog
    {
        $log = (new NotificationLog())
            ->setMerchant($merchant)
            ->setRecipientEmail($recipientEmail)
            ->setType($type)
            ->setSubject($subject)
            ->setStatus(NotificationLogStatus::PENDING);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private function buildSubject(NotificationType $type, Merchant $merchant): ?string
    {
        return match ($type) {
            NotificationType::CUSTOMER_SIGNUP => sprintf('Bienvenue chez %s sur Coachat', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::EQUIPIER_ASSIGNED => sprintf('Votre espace équipier est actif chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::EQUIPIER_REMOVED => sprintf('Votre accès équipier a été désactivé chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CARD_CREATED => sprintf('Votre carte fidélité est prêt chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CARD_COMPLETED => sprintf('Votre récompense est prêt chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::POINTS_ADDED => sprintf('Votre carte a été mise à jour chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::REWARD_CLAIMED => sprintf('Récompense récupérée chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::PROMOTIONAL_OFFER_STARTS => sprintf('Nouveau bon plan disponible chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::PROMOTIONAL_OFFER_ENDING_SOON => sprintf('Plus que 2 jours pour profiter du bon plan chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_BEFORE => sprintf('Demain chez %s : offre flash à ne pas manquer !', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_OF => sprintf("Aujourd'hui seulement chez %s : offre flash !", $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CONTEST_DAY_BEFORE_START => sprintf('Demain, nouveau jeu concours chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CONTEST_STARTS => sprintf('Le jeu concours commence aujourd\'hui chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CONTEST_ENDING_SOON => sprintf('Plus que 2 jours pour participer au jeu concours chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CONTEST_DRAW_DAY => sprintf('Tirage aujourd\'hui chez %s : bonne chance !', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CONTEST_PARTICIPATION_UPDATED => sprintf('Votre participation au jeu concours chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::CONTEST_WINNER => sprintf('🎉 Bravo, vous avez gagné au jeu concours chez %s !', $merchant->getCompanyName() ?? 'Coachat'),
            default => null,
        };
    }

    private function isMandatoryNotificationType(NotificationType $type): bool
    {
        return in_array($type, [
            NotificationType::EQUIPIER_ASSIGNED,
            NotificationType::EQUIPIER_REMOVED,
        ], true);
    }

    private function isAllowedByPreference(CustomerMerchantNotificationPreference $preference, NotificationType $type): bool
    {
        if (in_array($type, [
            NotificationType::PROMOTIONAL_OFFER_STARTS,
            NotificationType::PROMOTIONAL_OFFER_ENDING_SOON,
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_BEFORE,
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_OF,
        ], true)) {
            return $preference->isPromotionalOffersEnabled();
        }

        if (in_array($type, [
            NotificationType::CONTEST_DAY_BEFORE_START,
            NotificationType::CONTEST_STARTS,
            NotificationType::CONTEST_ENDING_SOON,
            NotificationType::CONTEST_DRAW_DAY,
            NotificationType::CONTEST_PARTICIPATION_UPDATED,
            NotificationType::CONTEST_WINNER,
        ], true)) {
            return $preference->isContestNotificationsEnabled();
        }

        return $preference->isEnabled();
    }
}