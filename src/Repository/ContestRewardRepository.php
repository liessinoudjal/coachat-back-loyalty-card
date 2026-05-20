<?php

namespace App\Repository;

use App\Entity\Contest;
use App\Entity\ContestReward;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContestReward>
 */
class ContestRewardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContestReward::class);
    }

    /**
     * @return ContestReward[]
     */
    public function findByContestOrderedByRank(Contest $contest): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.contest = :contest')
            ->setParameter('contest', $contest->getId(), 'uuid')
            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
