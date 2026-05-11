<?php

namespace App\Repository;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContestParticipation>
 */
class ContestParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContestParticipation::class);
    }

    /**
     * Find all non-winning participations for a contest (eligible for draw)
     */
    public function findEligibleForDraw(Contest $contest): array
    {
        return $this->createQueryBuilder('cp')
            ->where('cp.contest = :contest')
            ->andWhere('cp.isWinningEntry = false')
            ->setParameter('contest', $contest)
            ->orderBy('cp.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find participation by contest and customer
     */
    public function findByContestAndCustomer(Contest $contest, Customer $customer): ?ContestParticipation
    {
        return $this->createQueryBuilder('cp')
            ->where('cp.contest = :contest')
            ->andWhere('cp.customer = :customer')
            ->setParameter('contest', $contest)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Count unique participants in a contest
     */
    public function countUniqueParticipants(Contest $contest): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COUNT(DISTINCT cp.customer)')
            ->where('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count total participations in a contest
     */
    public function countParticipations(Contest $contest): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COUNT(cp)')
            ->where('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return int[]
     */
    public function findParticipantCustomerIds(Contest $contest): array
    {
        $rows = $this->createQueryBuilder('cp')
            ->select('DISTINCT IDENTITY(cp.customer) AS customer_id')
            ->where('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static fn (array $row): int => (int) ($row['customer_id'] ?? 0),
            array_filter($rows, static fn (array $row): bool => isset($row['customer_id'])),
        ));
    }
}
