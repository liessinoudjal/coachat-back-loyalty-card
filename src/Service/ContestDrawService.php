<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\ContestReward;
use App\Entity\ContestRewardCard;
use App\Entity\ContestWinner;
use App\Enum\ContestRewardType;
use App\Enum\ContestStatus;
use App\Repository\ContestParticipationRepository;
use App\Repository\ContestRewardRepository;
use App\Repository\ContestWinnerRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class ContestDrawService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestParticipationRepository $participationRepository,
        private readonly ContestRewardRepository $rewardRepository,
        private readonly ContestWinnerRepository $winnerRepository,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute draw for a contest, selecting winners for each reward.
     *
     * @return ContestWinner[]
     */
    public function executeDraw(Contest $contest): array
    {
        $winners = [];

        while (($winner = $this->drawNextReward($contest)) instanceof ContestWinner) {
            $winners[] = $winner;
        }

        return $winners;
    }

    public function drawNextReward(Contest $contest): ?ContestWinner
    {
        $reward = $this->findNextReward($contest);
        if (!$reward instanceof ContestReward) {
            $contest->setStatus(ContestStatus::FINISHED);
            $this->entityManager->flush();

            return null;
        }

        $eligibleParticipations = $this->participationRepository->findEligibleForDraw($contest);

        if (empty($eligibleParticipations)) {
            throw new \InvalidArgumentException('No eligible participants for draw.');
        }

        $randomIndex = \random_int(0, \count($eligibleParticipations) - 1);
        $selectedParticipation = $eligibleParticipations[$randomIndex];
        $selectedParticipation->setIsWinningEntry(true);

        $winner = new ContestWinner();
        $winner->setContest($contest);
        $winner->setCustomer($selectedParticipation->getCustomer());
        $winner->setReward($reward);
        $winner->setQrCodeToken($this->generateQrToken());

        $this->entityManager->persist($winner);

        if (in_array($reward->getType(), [ContestRewardType::CARD_STAMP, ContestRewardType::CARD_POINT], true)
            && ($reward->getTargetValue() ?? 0) > 0
            && $contest->getMerchant() !== null
            && $winner->getCustomer() !== null
        ) {
            $card = new ContestRewardCard();
            $card->setContestWinner($winner);
            $card->setCustomer($winner->getCustomer());
            $card->setMerchant($contest->getMerchant());
            $card->setType($reward->getType());
            $card->setTitle($reward->getTitle());
            $card->setRewardDescription($reward->getRewardDescription());
            $card->setTargetValue((int) $reward->getTargetValue());
            $card->setCurrentValue(0);
            $card->setIsCompleted(false);
            $this->entityManager->persist($card);
        }

        if ($this->findNextReward($contest, $reward) === null) {
            $contest->setStatus(ContestStatus::FINISHED);
        }

        $this->entityManager->flush();

        $this->sendWinnerNotification($winner);

        return $winner;
    }

    private function sendWinnerNotification(ContestWinner $winner): void
    {
        try {
            $this->notificationService->notifyContestWinner($winner);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to send contest winner notification.', [
                'winner_id' => $winner->getId(),
                'contest_id' => $winner->getContest()?->getId()?->toRfc4122(),
                'customer_id' => $winner->getCustomer()?->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return ContestReward[]
     */
    public function getRemainingRewards(Contest $contest): array
    {
        $rewards = $this->rewardRepository->findByContestOrderedByRank($contest);
        if (empty($rewards)) {
            return [];
        }

        $assignedRewardIds = array_map(
            static fn (ContestWinner $winner): ?int => $winner->getReward()?->getId(),
            $this->winnerRepository->findByContest($contest),
        );
        $assignedRewardIds = array_values(array_filter($assignedRewardIds, static fn (?int $id): bool => $id !== null));

        return array_values(array_filter(
            $rewards,
            static fn (ContestReward $reward): bool => !in_array($reward->getId(), $assignedRewardIds, true),
        ));
    }

    private function findNextReward(Contest $contest, ?ContestReward $currentReward = null): ?ContestReward
    {
        $rewards = $this->rewardRepository->findByContestOrderedByRank($contest);

        if (empty($rewards)) {
            throw new \InvalidArgumentException('Contest has no rewards to distribute.');
        }

        $assignedRewardIds = array_map(
            static fn (ContestWinner $winner): ?int => $winner->getReward()?->getId(),
            $this->winnerRepository->findByContest($contest),
        );
        $assignedRewardIds = array_values(array_filter($assignedRewardIds, static fn (?int $id): bool => $id !== null));

        foreach ($rewards as $reward) {
            if ($currentReward instanceof ContestReward && $reward->getId() === $currentReward->getId()) {
                $assignedRewardIds[] = $reward->getId();
                continue;
            }

            if (!in_array($reward->getId(), $assignedRewardIds, true)) {
                return $reward;
            }
        }

        return null;
    }

    /**
     * Generate a unique QR token for contest reward claiming
     */
    private function generateQrToken(): string
    {
        return 'contest_reward:' . Uuid::v4()->toBase58();
    }

    /**
     * Reset draw state (for testing or contest restart)
     */
    public function resetDraw(Contest $contest): void
    {
        // Mark all participations as non-winning
        $participations = $this->entityManager->getRepository(ContestParticipation::class)
            ->findBy(['contest' => $contest, 'isWinningEntry' => true]);

        foreach ($participations as $participation) {
            $participation->setIsWinningEntry(false);
        }

        // Remove all winners
        $winners = $this->entityManager->getRepository(ContestWinner::class)
            ->findBy(['contest' => $contest]);

        foreach ($winners as $winner) {
            $this->entityManager->remove($winner);
        }

        $contest->setStatus(ContestStatus::ACTIVE);

        $this->entityManager->flush();
    }
}
