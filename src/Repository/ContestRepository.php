<?php

namespace App\Repository;

use App\Entity\Contest;
use App\Entity\Merchant;
use App\Enum\ContestStatus;
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
            ->andWhere('contest.status != :draft')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->setParameter('draft', ContestStatus::DRAFT->value)
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
            ->andWhere('contest.status != :draft')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->setParameter('draft', ContestStatus::DRAFT->value)
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
            ->andWhere('contest.status != :draft')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->setParameter('draft', ContestStatus::DRAFT->value)
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
            ->andWhere('contest.status != :draft')
            ->setParameter('fromInclusive', $fromInclusive, 'datetime_immutable')
            ->setParameter('toExclusive', $toExclusive, 'datetime_immutable')
            ->setParameter('draft', ContestStatus::DRAFT->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Visible contests for the public WMCP API: scheduled or active contests
     * that have not yet ended. Eagerly loads rewards.
     *
     * @param Merchant[] $merchants
     * @return Contest[]
     */
    public function findVisibleForMerchants(array $merchants, \DateTimeImmutable $now): array
    {
        if (empty($merchants)) {
            return [];
        }

        return $this->createQueryBuilder('contest')
            ->distinct()
            ->addSelect('reward')
            ->leftJoin('contest.rewards', 'reward')
            ->andWhere('contest.merchant IN (:merchants)')
            ->andWhere('contest.status IN (:statuses)')
            ->andWhere('contest.endAt >= :now')
            ->setParameter('merchants', $merchants)
            ->setParameter('statuses', [ContestStatus::SCHEDULED->value, ContestStatus::ACTIVE->value])
            ->setParameter('now', $now, 'datetime_immutable')
            ->orderBy('contest.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Visible contests across claimed merchants for the public WMCP listing endpoint.
     *
     * @return Contest[]
     */
    public function findVisibleForPublicListing(
        \DateTimeImmutable $now,
        ?Merchant $merchant = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('contest')
            ->distinct()
            ->addSelect('reward', 'merchant')
            ->leftJoin('contest.rewards', 'reward')
            ->innerJoin('contest.merchant', 'merchant')
            ->andWhere('merchant.user IS NOT NULL')
            ->andWhere('contest.status IN (:statuses)')
            ->andWhere('contest.endAt >= :now')
            ->setParameter('statuses', [ContestStatus::SCHEDULED->value, ContestStatus::ACTIVE->value])
            ->setParameter('now', $now, 'datetime_immutable')
            ->orderBy('contest.startAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($merchant !== null) {
            $qb->andWhere('IDENTITY(contest.merchant) = :merchantId')
                ->setParameter('merchantId', $merchant->getId(), 'uuid');
        }

        return $qb->getQuery()->getResult();
    }
}
