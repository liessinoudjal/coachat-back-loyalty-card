<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\Transaction;
use App\Repository\ContestRepository;
use App\Repository\ContestParticipationRepository;
use Doctrine\ORM\EntityManagerInterface;

class ContestParticipationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestRepository $contestRepository,
        private readonly ContestParticipationRepository $participationRepository,
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
            ->andWhere('c.endAt > :now')
            ->setParameter('merchant', $merchant)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        // For each active contest, create participation if not already participating
        foreach ($activeContests as $contest) {
            $existingParticipation = $this->participationRepository->findByContestAndCustomer($contest, $customer);
            if ($existingParticipation === null) {
                $participation = new ContestParticipation();
                $participation->setContest($contest);
                $participation->setCustomer($customer);
                $participation->setTransaction($transaction);
                $participation->setIsWinningEntry(false);
                $this->entityManager->persist($participation);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Get participation count for a customer in a contest
     */
    public function getCustomerParticipationCount(Customer $customer, Contest $contest): int
    {
        return $this->participationRepository
            ->createQueryBuilder('cp')
            ->select('COUNT(cp)')
            ->where('cp.customer = :customer')
            ->andWhere('cp.contest = :contest')
            ->setParameter('customer', $customer)
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Check if customer is participating in a contest
     */
    public function isParticipating(Customer $customer, Contest $contest): bool
    {
        return $this->participationRepository->findByContestAndCustomer($contest, $customer) !== null;
    }
}
