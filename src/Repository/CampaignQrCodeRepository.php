<?php

namespace App\Repository;

use App\Entity\CampaignQrCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampaignQrCode>
 */
class CampaignQrCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampaignQrCode::class);
    }

    public function findOneBySlug(string $slug): ?CampaignQrCode
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
