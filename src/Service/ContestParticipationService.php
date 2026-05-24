<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\Customer;
use App\Entity\Transaction;
use App\Enum\ContestStatus;
use App\Repository\ContestRepository;
use App\Repository\ContestParticipationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ContestParticipationService
{
    public const MAX_PARTICIPATIONS_PER_CUSTOMER = 10;

    private LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestRepository $contestRepository,
        private readonly ContestParticipationRepository $participationRepository,
        private readonly NotificationService $notificationService,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
        $customer = $loyaltyCard?->getCustomer();
        if (!$customer instanceof Customer) {
            $this->logger->warning('Contest auto-enrollment skipped: no customer linked to loyalty card', [
                'transaction_id' => $transaction->getId(),
                'card_id' => $loyaltyCard?->getId(),
                'merchant_id' => $merchant?->getId(),
                'program_type' => $loyaltyCard?->getLoyaltyProgram()?->getType()?->value,
            ]);
            return;
        }

        if (!$merchant) {
            $this->logger->warning('Contest auto-enrollment skipped: no merchant on transaction', [
                'transaction_id' => $transaction->getId(),
                'card_id' => $loyaltyCard?->getId(),
            ]);
            return;
        }

        // Get all active contests for this merchant
        $now = new \DateTimeImmutable();
        $activeContests = $this->entityManager->getRepository(Contest::class)
            ->createQueryBuilder('c')
            ->where('c.merchant = :merchant')
            ->andWhere('c.startAt <= :now')
            ->andWhere('c.endAt >= :now')
            ->andWhere('c.status != :draft')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->setParameter('now', $now)
            ->setParameter('draft', ContestStatus::DRAFT->value)
            ->getQuery()
            ->getResult();

        $this->logger->info('Contest auto-enrollment evaluating', [
            'transaction_id' => $transaction->getId(),
            'card_id' => $loyaltyCard?->getId(),
            'customer_id' => $customer->getId(),
            'merchant_id' => $merchant->getId(),
            'program_type' => $loyaltyCard?->getLoyaltyProgram()?->getType()?->value,
            'active_contests_found' => count($activeContests),
        ]);

        $notifications = [];

        // For each active contest, create participation while the customer has not reached the limit
        foreach ($activeContests as $contest) {
            // Defensive auto-transition: SCHEDULED -> ACTIVE if dates say so.
            if ($contest->getStatus() === ContestStatus::SCHEDULED) {
                $contest->setStatus(ContestStatus::ACTIVE);
            }

            $currentCount = $this->participationRepository->countByContestAndCustomer($contest, $customer);
            if ($currentCount >= self::MAX_PARTICIPATIONS_PER_CUSTOMER) {
                $this->logger->info('Contest auto-enrollment skipped: limit reached', [
                    'contest_id' => $contest->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                    'current_count' => $currentCount,
                ]);
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
