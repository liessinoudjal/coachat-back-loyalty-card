<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\Customer;
use App\Entity\Transaction;
use App\Repository\ContestRepository;
use App\Repository\ContestParticipationRepository;
use Doctrine\ORM\EntityManagerInterface;

class ContestParticipationService
{
    public const MAX_PARTICIPATIONS_PER_CUSTOMER = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestRepository $contestRepository,
        private readonly ContestParticipationRepository $participationRepository,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * Auto-enroll customer in all active contests for a merchant
     * Called after a transaction is created
     */
    public function autoEnrollInActiveContests(Transaction $transaction): void
    {
        $loyaltyCard = $transaction->getLoyaltyCard();
        $merchant = $transaction->getMerchant();

        // Get customer from loyalty card
        $customer = $loyaltyCard->getCustomer();
        if (!$customer instanceof Customer) {
            return;
        }

        // Get all active contests for this merchant
        $now = new \DateTimeImmutable();
        $activeContests = $this->entityManager->getRepository(Contest::class)
            ->createQueryBuilder('c')
            ->where('c.merchant = :merchant')
            ->andWhere('c.startAt <= :now')
            ->andWhere('c.endAt >= :now')
            ->setParameter('merchant', $merchant)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $notifications = [];

        // For each active contest, create participation while the customer has not reached the limit
        foreach ($activeContests as $contest) {
            $currentCount = $this->participationRepository->countByContestAndCustomer($contest, $customer);
            if ($currentCount >= self::MAX_PARTICIPATIONS_PER_CUSTOMER) {
                continue;
            }

            $participation = new ContestParticipation();
            $participation->setContest($contest);
            $participation->setCustomer($customer);
            $participation->setTransaction($transaction);
            $participation->setIsWinningEntry(false);
            $this->entityManager->persist($participation);

            $notifications[] = [
                'contest' => $contest,
                'count' => $currentCount + 1,
            ];
        }

        $this->entityManager->flush();

        foreach ($notifications as $notification) {
            $this->notificationService->notifyContestParticipationUpdated(
                $customer,
                $merchant,
                $notification['contest'],
                $notification['count'],
                self::MAX_PARTICIPATIONS_PER_CUSTOMER,
            );
        }
    }

    /**
     * Get participation count for a customer in a contest
     */
    public function getCustomerParticipationCount(Customer $customer, Contest $contest): int
    {
        return $this->participationRepository->countByContestAndCustomer($contest, $customer);
    }

    /**
     * Check if customer is participating in a contest
     */
    public function isParticipating(Customer $customer, Contest $contest): bool
    {
        return $this->getCustomerParticipationCount($customer, $contest) > 0;
    }
}
