<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerMerchantNotificationPreference>
 */
class CustomerMerchantNotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerMerchantNotificationPreference::class);
    }

    public function findOneByCustomerAndMerchant(Customer $customer, Merchant $merchant): ?CustomerMerchantNotificationPreference
    {
        return $this->findOneBy([
            'customer' => $customer,
            'merchant' => $merchant,
        ]);
    }

    /**
     * @param Merchant[] $merchants
     *
     * @return CustomerMerchantNotificationPreference[]
     */
    public function findByCustomerAndMerchants(Customer $customer, array $merchants): array
    {
        if ($merchants === []) {
            return [];
        }

        return $this->createQueryBuilder('preference')
            ->addSelect('merchant')
            ->leftJoin('preference.merchant', 'merchant')
            ->andWhere('preference.customer = :customer')
            ->andWhere('preference.merchant IN (:merchants)')
            ->setParameter('customer', $customer)
            ->setParameter('merchants', $merchants)
            ->getQuery()
            ->getResult();
    }
}