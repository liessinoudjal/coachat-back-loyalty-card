<?php

namespace App\Repository;

use App\Entity\Merchant;
use App\Entity\MerchantAssetDownloadEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MerchantAssetDownloadEvent>
 */
class MerchantAssetDownloadEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MerchantAssetDownloadEvent::class);
    }

    /**
     * @return array<array{occurredAt: \DateTimeImmutable, eventType: string}>
     */
    public function findByMerchantSince(Merchant $merchant, \DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.occurredAt', 'e.eventType')
            ->join('e.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('e.occurredAt >= :from')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->setParameter('from', $from)
            ->getQuery()
            ->getArrayResult();
    }
}
