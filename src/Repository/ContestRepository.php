<?php

namespace App\Repository;

use App\Entity\Contest;
use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contest>
 */
class ContestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contest::class);
    }

    /**
     * @return Contest[]
     */
    public function findByMerchantOrdered(Merchant $merchant): array
    {
        return $this->createQueryBuilder('contest')
            ->distinct()
            ->addSelect('reward')
            ->leftJoin('contest.rewards', 'reward')
            ->andWhere('IDENTITY(contest.merchant) = :merchantId')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->orderBy('contest.startAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Contest[]
     */
    public function findStartingBetween(\DateTimeImmutable $fromInclusive, \DateTimeImmutable $toExclusive): array
    {
        return $this->createQueryBuilder('contest')
            ->andWhere('contest.startAt >= :fromInclusive')
            ->andWhere('contest.startAt < :toExclusive')
            ->andWhere('contest.startNotificationSentAt IS NULL')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Contest[]
     */
    public function findEndingBetween(\DateTimeImmutable $fromInclusive, \DateTimeImmutable $toExclusive): array
    {
        return $this->createQueryBuilder('contest')
            ->andWhere('contest.endAt >= :fromInclusive')
            ->andWhere('contest.endAt < :toExclusive')
            ->andWhere('contest.endingSoonNotificationSentAt IS NULL')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Contest[]
     */
    public function findDayBeforeStartBetween(\DateTimeImmutable $fromInclusive, \DateTimeImmutable $toExclusive): array
    {
        return $this->createQueryBuilder('contest')
            ->andWhere('contest.startAt >= :fromInclusive')
            ->andWhere('contest.startAt < :toExclusive')
            ->andWhere('contest.dayBeforeNotificationSentAt IS NULL')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Contest[]
     */
    public function findDrawDayBetween(\DateTimeImmutable $fromInclusive, \DateTimeImmutable $toExclusive): array
    {
        return $this->createQueryBuilder('contest')
            ->andWhere('contest.drawAt >= :fromInclusive')
            ->andWhere('contest.drawAt < :toExclusive')
            ->andWhere('contest.drawDayNotificationSentAt IS NULL')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->getQuery()
            ->getResult();
    }
}
