<?php

namespace App\Repository;

use App\Entity\GoogleReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoogleReviewEvent>
 */
class GoogleReviewEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleReviewEvent::class);
    }
}