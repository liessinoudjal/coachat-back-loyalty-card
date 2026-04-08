<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomerPortalSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerPortalSession>
 */
class CustomerPortalSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerPortalSession::class);
    }

    /**
     * Find a session by its token hash, regardless of validity (for specific error messages).
     */
    public function findByTokenHash(string $hash): ?CustomerPortalSession
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }
}
