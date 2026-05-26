<?php

namespace App\Repository;

use App\Entity\CampaignQrCode;
use App\Entity\CampaignQrScanEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampaignQrScanEvent>
 */
class CampaignQrScanEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampaignQrScanEvent::class);
    }

    /**
     * Returns the scan count grouped by day for the last N days.
     *
     * @return array<array{day: string, count: int}>
     */
    public function countByDaySince(CampaignQrCode $campaign, \DateTimeImmutable $from): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->executeQuery(
            'SELECT DATE(occurred_at) AS day, COUNT(*) AS cnt
             FROM campaign_qr_scan_event
             WHERE campaign_qr_code_id = :campaign
               AND occurred_at >= :from
             GROUP BY DATE(occurred_at)
             ORDER BY day ASC',
            [
                'campaign' => $campaign->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
            ],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'day' => (string) $row['day'],
            'count' => (int) $row['cnt'],
        ], $rows);
    }

    /**
     * Returns the scan count grouped by month (YYYY-MM) for the last 12 calendar months,
     * including months with zero scans (gaps filled).
     *
     * @return array<array{month: string, count: int}>
     */
    public function countByLast12Months(CampaignQrCode $campaign): array
    {
        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        $from = $now->modify('-11 months');

        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->executeQuery(
            "SELECT DATE_FORMAT(occurred_at, '%Y-%m') AS month, COUNT(*) AS cnt
             FROM campaign_qr_scan_event
             WHERE campaign_qr_code_id = :campaign
               AND occurred_at >= :from
             GROUP BY DATE_FORMAT(occurred_at, '%Y-%m')
             ORDER BY month ASC",
            [
                'campaign' => $campaign->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
            ],
        )->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['month']] = (int) $row['cnt'];
        }

        $result = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $from->modify(sprintf('+%d months', $i))->format('Y-m');
            $result[] = [
                'month' => $month,
                'count' => $counts[$month] ?? 0,
            ];
        }

        return $result;
    }
}
