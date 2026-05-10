<?php

namespace App\Repository;

use App\Entity\Merchant;
use App\Entity\PromotionalOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromotionalOffer>
 */
class PromotionalOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromotionalOffer::class);
    }

    /**
     * @return PromotionalOffer[]
     */
    public function findByMerchantOrdered(Merchant $merchant): array
    {
        $merchantId = $merchant->getId();
        if ($merchantId === null) {
            return [];
        }

        return $this->createQueryBuilder('offer')
            ->andWhere('IDENTITY(offer.merchant) = :merchantId')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->orderBy('offer.startsOn', 'DESC')
            ->addOrderBy('offer.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PromotionalOffer[]
     */
    public function findStartingOnDate(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('offer')
            ->andWhere('offer.startsOn = :date')
            ->andWhere('offer.startNotificationSentAt IS NULL')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PromotionalOffer[]
     */
    public function findEndingOnDate(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('offer')
            ->andWhere('offer.endsOn = :date')
            ->andWhere('offer.endingSoonNotificationSentAt IS NULL')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find single-day ("flash") offers starting on $tomorrow whose day-before notification has not been sent yet.
     *
     * @return PromotionalOffer[]
     */
    public function findFlashOffersForDayBefore(\DateTimeImmutable $tomorrow): array
    {
        return $this->createQueryBuilder('offer')
            ->andWhere('offer.startsOn = :tomorrow')
            ->andWhere('offer.endsOn = :tomorrow')
            ->andWhere('offer.dayBeforeNotificationSentAt IS NULL')
            ->setParameter('tomorrow', $tomorrow, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param \App\Entity\Merchant[] $merchants
     * @return PromotionalOffer[]
     */
    public function findActiveByMerchants(array $merchants, \DateTimeImmutable $today): array
    {
        if (empty($merchants)) {
            return [];
        }

        $qb = $this->createQueryBuilder('offer');

        $merchantConditions = [];
        $paramIndex = 0;
        foreach ($merchants as $merchant) {
            $merchantId = $merchant->getId();
            if ($merchantId === null) {
                continue;
            }
            $paramName = 'mid' . $paramIndex++;
            $merchantConditions[] = 'IDENTITY(offer.merchant) = :' . $paramName;
            $qb->setParameter($paramName, $merchantId, 'uuid');
        }

        if (empty($merchantConditions)) {
            return [];
        }

        return $qb
            ->andWhere('(' . implode(' OR ', $merchantConditions) . ')')
            ->andWhere('offer.startsOn <= :today')
            ->andWhere('offer.endsOn >= :today')
            ->setParameter('today', $today, 'date_immutable')
            ->orderBy('offer.endsOn', 'ASC')
            ->addOrderBy('offer.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}