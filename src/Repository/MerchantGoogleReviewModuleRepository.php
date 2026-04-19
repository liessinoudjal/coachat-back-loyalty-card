<?php

namespace App\Repository;

use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MerchantGoogleReviewModule>
 */
class MerchantGoogleReviewModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MerchantGoogleReviewModule::class);
    }

    public function findOneByMerchant(Merchant $merchant): ?MerchantGoogleReviewModule
    {
        return $this->findOneBy(['merchant' => $merchant]);
    }

    /**
     * @param Merchant[] $merchants
     *
     * @return MerchantGoogleReviewModule[]
     */
    public function findVisibleModulesForMerchants(array $merchants): array
    {
        if ($merchants === []) {
            return [];
        }

        return $this->createQueryBuilder('module')
            ->addSelect('merchant')
            ->innerJoin('module.merchant', 'merchant')
            ->andWhere('module.merchant IN (:merchants)')
            ->andWhere('module.isEnabled = :enabled')
            ->andWhere('module.showInCustomerDashboard = :showInDashboard')
            ->setParameter('merchants', $merchants)
            ->setParameter('enabled', true)
            ->setParameter('showInDashboard', true)
            ->orderBy('merchant.companyName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}