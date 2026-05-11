<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\ContestReward;
use App\Entity\ContestWinner;
use App\Repository\ContestParticipationRepository;
use App\Repository\ContestRewardRepository;
use App\Repository\ContestWinnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ContestDrawService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestParticipationRepository $participationRepository,
        private readonly ContestRewardRepository $rewardRepository,
        private readonly ContestWinnerRepository $winnerRepository,
    ) {
    }

    /**
     * Execute draw for a contest, selecting winners for each reward
     *
     * @return ContestWinner[]
     *
     * @throws \InvalidArgumentException if not enough participations
     */
    public function executeDraw(Contest $contest): array
    {
        // Get rewards ordered by rank
        $rewards = $this->rewardRepository->findByContestOrderedByRank($contest);

        if (empty($rewards)) {
            throw new \InvalidArgumentException('Contest has no rewards to distribute.');
        }

        // Get eligible participations (not yet marked as winning)
        $eligibleParticipations = $this->participationRepository->findEligibleForDraw($contest);

        if (empty($eligibleParticipations)) {
            throw new \InvalidArgumentException('No eligible participants for draw.');
        }

        $winners = [];

        // For each reward, select a random winner
        foreach ($rewards as $reward) {
            if (empty($eligibleParticipations)) {
                break; // No more participants to select
            }

            // Select random index
            $randomIndex = \random_int(0, \count($eligibleParticipations) - 1);
            $selectedParticipation = $eligibleParticipations[$randomIndex];

            // Mark as winning entry
            $selectedParticipation->setIsWinningEntry(true);

            // Create winner record with unique QR token
            $winner = new ContestWinner();
            $winner->setContest($contest);
            $winner->setCustomer($selectedParticipation->getCustomer());
            $winner->setReward($reward);
            $winner->setQrCodeToken($this->generateQrToken());

            $this->entityManager->persist($winner);
            $winners[] = $winner;

            // Remove from eligible pool (one win per draw session per customer)
            unset($eligibleParticipations[$randomIndex]);
            $eligibleParticipations = \array_values($eligibleParticipations); // Reindex array
        }

        $this->entityManager->flush();

        return $winners;
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

        $this->entityManager->flush();
    }
}
