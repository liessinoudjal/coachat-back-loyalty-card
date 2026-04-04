<?php

namespace App\Repository;

use App\Entity\Plan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plan>
 */
class PlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function findBySlug(string $slug): ?Plan
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
