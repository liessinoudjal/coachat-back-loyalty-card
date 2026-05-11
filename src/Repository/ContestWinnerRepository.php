<?php

namespace App\Repository;

use App\Entity\Contest;
use App\Entity\ContestWinner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContestWinner>
 */
class ContestWinnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContestWinner::class);
    }

    /**
     * Find winner by QR code token
     */
    public function findByQrToken(string $qrToken): ?ContestWinner
    {
        return $this->createQueryBuilder('cw')
            ->where('cw.qrCodeToken = :qrToken')
            ->setParameter('qrToken', $qrToken)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all winners for a contest
     */
    public function findByContest(Contest $contest): array
    {
        return $this->createQueryBuilder('cw')
            ->addSelect('reward')
            ->addSelect('customer')
            ->leftJoin('cw.reward', 'reward')
            ->leftJoin('cw.customer', 'customer')
            ->where('cw.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('reward.rank', 'ASC')
            ->addOrderBy('cw.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unclaimed winners for a contest (for customer notification)
     */
    public function findUnclaimedByContest(Contest $contest): array
    {
        return $this->createQueryBuilder('cw')
            ->where('cw.contest = :contest')
            ->andWhere('cw.isClaimed = false')
            ->setParameter('contest', $contest)
            ->orderBy('cw.reward.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total winners for a contest
     */
    public function countByContest(Contest $contest): int
    {
        return (int) $this->createQueryBuilder('cw')
            ->select('COUNT(cw)')
            ->where('cw.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return int[]
     */
    public function findWinnerCustomerIds(Contest $contest): array
    {
        $rows = $this->createQueryBuilder('cw')
            ->select('DISTINCT IDENTITY(cw.customer) AS customer_id')
            ->where('cw.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static fn (array $row): int => (int) ($row['customer_id'] ?? 0),
            array_filter($rows, static fn (array $row): bool => isset($row['customer_id'])),
        ));
    }
}
