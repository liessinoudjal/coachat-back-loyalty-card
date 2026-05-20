<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\GoogleReviewReward;
use App\Entity\Merchant;
use App\Enum\GoogleReviewRewardStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoogleReviewReward>
 */
class GoogleReviewRewardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleReviewReward::class);
    }

    public function findOneByQrToken(string $qrToken): ?GoogleReviewReward
    {
        return $this->findOneBy(['qrToken' => $qrToken]);
    }

    public function findActiveRewardForCustomerAndMerchant(Customer $customer, Merchant $merchant): ?GoogleReviewReward
    {
        return $this->findOneBy([
            'customer' => $customer,
            'merchant' => $merchant,
            'status' => GoogleReviewRewardStatus::ACTIVE,
        ]);
    }

    public function findLatestForCustomerAndMerchant(Customer $customer, Merchant $merchant): ?GoogleReviewReward
    {
        return $this->createQueryBuilder('reward')
            ->andWhere('reward.customer = :customer')
            ->andWhere('reward.merchant = :merchant')
            ->setParameter('customer', $customer)
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->orderBy('reward.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{items: list<GoogleReviewReward>, total: int}
     */
    public function findPaginatedForMerchant(
        Merchant $merchant,
        ?GoogleReviewRewardStatus $status,
        ?string $search,
        int $page,
        int $itemsPerPage,
    ): array {
        $qb = $this->createQueryBuilder('reward')
            ->leftJoin('reward.customer', 'customer')->addSelect('customer')
            ->leftJoin('reward.merchant', 'merchant_scope')
            ->andWhere('merchant_scope.id = :merchantId')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->orderBy('reward.createdAt', 'DESC');

        if ($status instanceof GoogleReviewRewardStatus) {
            $qb
                ->andWhere('reward.status = :status')
                ->setParameter('status', $status);
        }

        if ($search !== null && $search !== '') {
            $qb
                ->andWhere('(LOWER(customer.name) LIKE :search OR LOWER(customer.email) LIKE :search)')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(DISTINCT reward.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $qb
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        return [
            'items' => array_values($qb->getQuery()->getResult()),
            'total' => $total,
        ];
    }
}