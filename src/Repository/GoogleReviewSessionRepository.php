<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\GoogleReviewSession;
use App\Entity\Merchant;
use App\Enum\GoogleReviewSessionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoogleReviewSession>
 */
class GoogleReviewSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleReviewSession::class);
    }

    public function findLatestForCustomerAndMerchant(Customer $customer, Merchant $merchant): ?GoogleReviewSession
    {
        return $this->findOneBy([
            'customer' => $customer,
            'merchant' => $merchant,
        ], [
            'createdAt' => 'DESC',
        ]);
    }

    public function findLatestActiveForCustomerAndMerchant(Customer $customer, Merchant $merchant): ?GoogleReviewSession
    {
        return $this->createQueryBuilder('session')
            ->leftJoin('session.reward', 'reward')->addSelect('reward')
            ->andWhere('session.customer = :customer')
            ->andWhere('session.merchant = :merchant')
            ->andWhere('session.status IN (:statuses)')
            ->setParameter('customer', $customer)
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->setParameter('statuses', [
                GoogleReviewSessionStatus::READY_TO_LAUNCH,
                GoogleReviewSessionStatus::OUTBOUND_OPENED,
                GoogleReviewSessionStatus::RETURNED_TO_APP,
                GoogleReviewSessionStatus::REWARD_READY,
            ])
            ->orderBy('session.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}