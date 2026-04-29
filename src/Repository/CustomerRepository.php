<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }
    /**
     * Find all customers belonging to a specific merchant.
     *
     * A customer belongs to a merchant either:
     * - directly (created under this merchant), or
     * - historically via at least one loyalty card linked to this merchant's program.
     *
     * @return Customer[]
     */
    public function findByMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('c')
            ->select('DISTINCT c')
            ->leftJoin('c.merchant', 'directMerchant')
            ->leftJoin('c.merchants', 'linkedMerchant')
            ->leftJoin('c.loyaltyCards', 'lc')
            ->leftJoin('lc.merchant', 'cardMerchant')
            ->andWhere('directMerchant.id = :merchantId OR linkedMerchant.id = :merchantId OR cardMerchant.id = :merchantId')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Find a customer by ID if they belong to a specific merchant.
     * Returns null if the customer doesn't belong to the merchant.
     */
    public function findByIdAndMerchant(int $customerId, Merchant $merchant): ?Customer
    {
        return $this->createQueryBuilder('c')
            ->select('DISTINCT c')
            ->leftJoin('c.merchant', 'directMerchant')
            ->leftJoin('c.merchants', 'linkedMerchant')
            ->leftJoin('c.loyaltyCards', 'lc')
            ->leftJoin('lc.merchant', 'cardMerchant')
            ->andWhere('c.id = :customerId')
            ->andWhere('directMerchant.id = :merchantId OR linkedMerchant.id = :merchantId OR cardMerchant.id = :merchantId')
            ->setParameter('customerId', $customerId)
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /**
     * @return Customer[]
     */
    public function findStaffByMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.staffMerchant', 'm')
            ->andWhere('m.id = :merchantId')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
    //    /**
    //     * @return Customer[] Returns an array of Customer objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Customer
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //    ;
    //    }
}