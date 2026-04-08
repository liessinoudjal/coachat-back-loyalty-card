<?php

namespace App\Service;

use App\Entity\LoyaltyCard;
use App\Entity\Reward;
use App\Entity\RewardStatusLog;
use App\Entity\User;
use App\Enum\RewardStatus;
use App\Repository\RewardRepository;
use Doctrine\ORM\EntityManagerInterface;

class RewardService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RewardRepository $rewardRepository,
    ) {}

    public function createRewardFromCompletion(LoyaltyCard $card, ?int $transactionId = null): Reward
    {
        if (!$card->isCompleted()) {
            throw new \InvalidArgumentException('Loyalty card is not completed');
        }

        if (!$card->getMerchant() || !$card->getCustomer()) {
            throw new \InvalidArgumentException('Loyalty card must have merchant and customer');
        }

        $existing = $this->rewardRepository->findOneBy(['loyaltyCard' => $card]);
        if ($existing instanceof Reward) {
            return $existing;
        }

        $reward = new Reward();
        $reward->setLoyaltyCard($card);
        $reward->setMerchant($card->getMerchant());
        $reward->setCustomer($card->getCustomer());
        $reward->setLoyaltyProgram($card->getLoyaltyProgram());
        $reward->setRewardDescription($card->getLoyaltyProgram()?->getRewardDescription());
        $reward->setClaimQrToken($this->generateUniqueClaimToken());

        if ($transactionId !== null) {
            $reward->setMetadata(['transaction_id' => $transactionId]);
        }

        $this->entityManager->persist($reward);
        $this->logStatusTransition($reward, null, RewardStatus::PENDING, null, 'reward_generated', [
            'transaction_id' => $transactionId,
        ]);

        return $reward;
    }

    public function claimByQrToken(string $rawToken, User $actor): Reward
    {
        $token = $this->extractToken($rawToken);
        $reward = $this->rewardRepository->findOneBy(['claimQrToken' => $token]);

        if (!$reward instanceof Reward) {
            throw new \RuntimeException('Reward not found');
        }

        if ($reward->getStatus() === RewardStatus::CLAIMED) {
            throw new \LogicException('Reward already claimed');
        }

        if ($reward->getStatus() !== RewardStatus::PENDING) {
            throw new \LogicException('Reward cannot be claimed from current status');
        }

        $fromStatus = $reward->getStatus();
        $reward->setStatus(RewardStatus::CLAIMED);
        $reward->setClaimedAt(new \DateTimeImmutable());
        $reward->setClaimedByMerchantUser($actor);

        $this->logStatusTransition($reward, $fromStatus, RewardStatus::CLAIMED, $actor, 'claim_by_qr', [
            'claim_qr_token_prefix' => substr($token, 0, 8),
        ]);

        return $reward;
    }

    public function transitionStatus(Reward $reward, RewardStatus $toStatus, ?User $actor = null, ?string $reason = null): Reward
    {
        $fromStatus = $reward->getStatus();

        if ($fromStatus === $toStatus) {
            return $reward;
        }

        if ($toStatus === RewardStatus::CLAIMED && $fromStatus !== RewardStatus::PENDING) {
            throw new \LogicException('Only pending rewards can be claimed');
        }

        $reward->setStatus($toStatus);

        if ($toStatus === RewardStatus::CLAIMED) {
            $reward->setClaimedAt(new \DateTimeImmutable());
            $reward->setClaimedByMerchantUser($actor);
        }

        if ($toStatus === RewardStatus::CANCELLED) {
            $reward->setCancelReason($reason);
        }

        $this->logStatusTransition($reward, $fromStatus, $toStatus, $actor, $reason);

        return $reward;
    }

    private function extractToken(string $rawToken): string
    {
        $trimmed = trim($rawToken);

        if (str_starts_with($trimmed, 'lacarte-reward:')) {
            return substr($trimmed, strlen('lacarte-reward:'));
        }

        return $trimmed;
    }

    private function generateUniqueClaimToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
            $existing = $this->rewardRepository->findOneBy(['claimQrToken' => $token]);
        } while ($existing instanceof Reward);

        return $token;
    }

    private function logStatusTransition(
        Reward $reward,
        ?RewardStatus $fromStatus,
        RewardStatus $toStatus,
        ?User $actor = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): void {
        $log = new RewardStatusLog();
        $log->setReward($reward);
        $log->setFromStatus($fromStatus);
        $log->setToStatus($toStatus);
        $log->setActorMerchantUser($actor);
        $log->setReason($reason);
        $log->setMetadata($metadata);

        $this->entityManager->persist($log);
    }
}
