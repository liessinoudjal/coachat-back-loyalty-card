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
    public function findEligibleForDraw(Contest $contest, array $excludedCustomerIds = []): array
    {
        $queryBuilder = $this->createQueryBuilder('cp')
            ->where('cp.contest = :contest')
            ->andWhere('cp.isWinningEntry = false')
            ->setParameter('contest', $contest->getId(), 'uuid')
            ->orderBy('cp.createdAt', 'ASC');

        if ($excludedCustomerIds !== []) {
            $queryBuilder
                ->andWhere('IDENTITY(cp.customer) NOT IN (:excludedCustomerIds)')
                ->setParameter('excludedCustomerIds', array_values(array_unique(array_map('intval', $excludedCustomerIds))));
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Find one participation by contest and customer
     */
    public function findByContestAndCustomer(Contest $contest, Customer $customer): ?ContestParticipation
    {
        return $this->createQueryBuilder('cp')
            ->where('cp.contest = :contest')
            ->andWhere('cp.customer = :customer')
            ->setParameter('contest', $contest->getId(), 'uuid')
            ->setParameter('customer', $customer)
            ->orderBy('cp.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByContestAndCustomer(Contest $contest, Customer $customer): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COUNT(cp.id)')
            ->where('cp.contest = :contest')
            ->andWhere('cp.customer = :customer')
            ->setParameter('contest', $contest->getId(), 'uuid')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count unique participants in a contest
     */
    public function countUniqueParticipants(Contest $contest): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COUNT(DISTINCT cp.customer)')
            ->where('cp.contest = :contest')
            ->setParameter('contest', $contest->getId(), 'uuid')
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
            ->setParameter('contest', $contest->getId(), 'uuid')
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
            ->setParameter('contest', $contest->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static fn (array $row): int => (int) ($row['customer_id'] ?? 0),
            array_filter($rows, static fn (array $row): bool => isset($row['customer_id'])),
        ));
    }

    /**
     * @return array<int, array{customer_id:int, customer_name:?string, customer_email:?string, participation_count:int}>
     */
    public function getParticipantSummaries(Contest $contest): array
    {
        $rows = $this->createQueryBuilder('cp')
            ->select('IDENTITY(cp.customer) AS customer_id')
            ->addSelect('customer.name AS customer_name')
            ->addSelect('customer.email AS customer_email')
            ->addSelect('COUNT(cp.id) AS participation_count')
            ->innerJoin('cp.customer', 'customer')
            ->where('cp.contest = :contest')
            ->setParameter('contest', $contest->getId(), 'uuid')
            ->groupBy('customer.id')
            ->addGroupBy('customer.name')
            ->addGroupBy('customer.email')
            ->orderBy('participation_count', 'DESC')
            ->addOrderBy('customer.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'customer_id' => (int) ($row['customer_id'] ?? 0),
                'customer_name' => isset($row['customer_name']) ? (string) $row['customer_name'] : null,
                'customer_email' => isset($row['customer_email']) ? (string) $row['customer_email'] : null,
                'participation_count' => (int) ($row['participation_count'] ?? 0),
            ],
            $rows,
        );
    }
}
