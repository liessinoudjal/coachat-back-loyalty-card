<?php

namespace App\Repository;

use App\Entity\ContestRewardCard;
use App\Entity\Customer;
use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContestRewardCard>
 */
class ContestRewardCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContestRewardCard::class);
    }

    public function findByWalletToken(string $walletToken): ?ContestRewardCard
    {
        return $this->findOneBy(['walletToken' => $walletToken]);
    }

    /**
     * @return ContestRewardCard[]
     */
    public function findByCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ContestRewardCard[]
     */
    public function findByMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.merchant = :merchant')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
