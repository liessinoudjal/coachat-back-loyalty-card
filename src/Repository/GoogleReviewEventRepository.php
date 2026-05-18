<?php

namespace App\Repository;

use App\Entity\GoogleReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\GoogleReviewEventType;

/**
 * @extends ServiceEntityRepository<GoogleReviewEvent>
 */
class GoogleReviewEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleReviewEvent::class);
    }

    public function findLatestOutboundClickedForCustomerAndMerchant(Customer $customer, Merchant $merchant): ?GoogleReviewEvent
    {
        return $this->findOneBy([
            'customer' => $customer,
            'merchant' => $merchant,
            'eventType' => GoogleReviewEventType::OUTBOUND_CLICKED,
        ], [
            'createdAt' => 'DESC',
        ]);
    }
}