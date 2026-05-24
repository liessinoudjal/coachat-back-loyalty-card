<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\Merchant;
use App\Entity\MerchantAssetDownloadEvent;
use App\Entity\MerchantEstablishmentType;
use App\Entity\Transaction;
use App\Repository\CustomerRepository;
use App\Repository\PlanRepository;
use App\Service\LegalTermsVersionProvider;
use App\Service\SignupAlertMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MerchantController extends AbstractController
{
    private const MAX_LOGO_SIZE_BYTES = 5242880;

    private $entityManager;
    private $planRepository;
    private CustomerRepository $customerRepository;
    private LegalTermsVersionProvider $legalTermsVersionProvider;
    private SignupAlertMailer $signupAlertMailer;
    private CacheInterface $cache;

    public function __construct(EntityManagerInterface $entityManager, PlanRepository $planRepository, CustomerRepository $customerRepository, LegalTermsVersionProvider $legalTermsVersionProvider, SignupAlertMailer $signupAlertMailer, CacheInterface $cache)
    {
        $this->entityManager = $entityManager;
        $this->planRepository = $planRepository;
        $this->customerRepository = $customerRepository;
        $this->legalTermsVersionProvider = $legalTermsVersionProvider;
        $this->signupAlertMailer = $signupAlertMailer;
        $this->cache = $cache;
    }

    #[Route('/api/merchants/me/dashboard-kpis', name: 'merchant_dashboard_kpis', methods: ['GET'])]
    public function dashboardKpis(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        $monthKeys = $this->buildMonthKeys($now, 12);
        $historyMonthKeys = array_slice($monthKeys, 0, 11);

        $historyStart = \DateTimeImmutable::createFromFormat('Y-m-d', $historyMonthKeys[0] . '-01')
            ->setTime(0, 0, 0);
        $currentMonthStart = \DateTimeImmutable::createFromFormat('Y-m-d', $monthKeys[11] . '-01')
            ->setTime(0, 0, 0);
        $nextMonthStart = $currentMonthStart->modify('+1 month');

        $merchantId = $merchant->getId()?->toRfc4122();
        $historyCacheKey = sprintf(
            'merchant_dashboard_kpis_history_v2_%s_%s_to_%s',
            $merchantId ?? 'unknown',
            $historyMonthKeys[0],
            $historyMonthKeys[10],
        );

        $historical = $this->cache->get($historyCacheKey, function (ItemInterface $item) use ($merchant, $historyStart, $currentMonthStart): array {
            // Historical months are immutable in business terms, so a long TTL is acceptable.
            $item->expiresAfter(86400 * 30);

            return [
                'customers_new' => $this->fetchMonthlyDistinctCustomers($merchant, $historyStart, $currentMonthStart),
                'loyalty_cards_new' => $this->fetchMonthlyCards($merchant, $historyStart, $currentMonthStart),
                'transactions_scanned' => $this->fetchMonthlyTransactions($merchant, $historyStart, $currentMonthStart),
            ];
        });

        $currentMonth = [
            'customers_new' => $this->countDistinctCustomersForPeriod($merchant, $currentMonthStart, $nextMonthStart),
            'loyalty_cards_new' => $this->countCardsForPeriod($merchant, $currentMonthStart, $nextMonthStart),
            'transactions_scanned' => $this->countTransactionsForPeriod($merchant, $currentMonthStart, $nextMonthStart),
        ];

        $customersByMonth = array_fill_keys($monthKeys, 0);
        foreach ($historyMonthKeys as $ym) {
            $customersByMonth[$ym] = (int) ($historical['customers_new'][$ym] ?? 0);
        }
        $customersByMonth[$monthKeys[11]] = $currentMonth['customers_new'];

        $cardsByMonth = array_fill_keys($monthKeys, 0);
        foreach ($historyMonthKeys as $ym) {
            $cardsByMonth[$ym] = (int) ($historical['loyalty_cards_new'][$ym] ?? 0);
        }
        $cardsByMonth[$monthKeys[11]] = $currentMonth['loyalty_cards_new'];

        $transactionsByMonth = array_fill_keys($monthKeys, 0);
        foreach ($historyMonthKeys as $ym) {
            $transactionsByMonth[$ym] = (int) ($historical['transactions_scanned'][$ym] ?? 0);
        }
        $transactionsByMonth[$monthKeys[11]] = $currentMonth['transactions_scanned'];

        $rolling30Start = (new \DateTimeImmutable())->modify('-30 days');
        $rolling30End = new \DateTimeImmutable();

        $totalCards = $this->countCardsTotal($merchant);
        $completedCards = $this->countCompletedCardsTotal($merchant);
        $completionRate = $totalCards > 0 ? round(($completedCards / $totalCards) * 100, 1) : 0.0;

        $totalCustomers = $this->countDistinctCustomersTotal($merchant);
        $activeCustomers30d = $this->countDistinctActiveCustomersForPeriod($merchant, $rolling30Start, $rolling30End);
        $inactiveCustomers30d = max($totalCustomers - $activeCustomers30d, 0);
        $activeRate30dPct = $totalCustomers > 0 ? round(($activeCustomers30d / $totalCustomers) * 100, 1) : 0.0;
        $transactionsScanned30d = $this->countTransactionsForPeriod($merchant, $rolling30Start, $rolling30End);
        $avgScansPerActiveCustomer30d = $activeCustomers30d > 0
            ? round($transactionsScanned30d / $activeCustomers30d, 2)
            : 0.0;

        $previousMonthScans = (int) ($transactionsByMonth[$monthKeys[10]] ?? 0);
        $currentMonthScans = (int) ($transactionsByMonth[$monthKeys[11]] ?? 0);
        $scansMoMChangePct = $previousMonthScans > 0
            ? round((($currentMonthScans - $previousMonthScans) / $previousMonthScans) * 100, 1)
            : null;

        return new JsonResponse([
            'period_keys' => $monthKeys,
            'period_labels' => array_map(static fn (string $ym): string => self::formatMonthLabel($ym), $monthKeys),
            'customers_new' => array_values($customersByMonth),
            'loyalty_cards_new' => array_values($cardsByMonth),
            'transactions_scanned' => array_values($transactionsByMonth),
            'health_kpis' => [
                'completion_rate_pct' => $completionRate,
                'total_customers' => $totalCustomers,
                'active_customers_30d' => $activeCustomers30d,
                'active_rate_30d_pct' => $activeRate30dPct,
                'inactive_customers_30d' => $inactiveCustomers30d,
                'transactions_scanned_30d' => $transactionsScanned30d,
                'avg_scans_per_active_customer_30d' => $avgScansPerActiveCustomer30d,
                'scans_month_over_month_change_pct' => $scansMoMChangePct,
            ],
            'cache' => [
                'historical_months_cached' => true,
                'current_month_is_fresh' => true,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function buildMonthKeys(\DateTimeImmutable $monthStart, int $count): array
    {
        $keys = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $keys[] = $monthStart->modify("-{$i} months")->format('Y-m');
        }

        return $keys;
    }

    private static function formatMonthLabel(string $yearMonth): string
    {
        [$y, $m] = explode('-', $yearMonth);
        $monthsFr = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

        return $monthsFr[(int) $m - 1] . ' ' . $y;
    }

    /**
     * @return array<string, int>
     */
    private function fetchMonthlyDistinctCustomers(Merchant $merchant, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('SUBSTRING(c.createdAt, 1, 7) AS ym', 'COUNT(DISTINCT c.id) AS total')
            ->from(Customer::class, 'c')
            ->leftJoin('c.merchant', 'directMerchant')
            ->leftJoin('c.merchants', 'linkedMerchant')
            ->leftJoin('c.loyaltyCards', 'lc')
            ->leftJoin('lc.merchant', 'cardMerchant')
            ->where('directMerchant.id = :merchantId OR linkedMerchant.id = :merchantId OR cardMerchant.id = :merchantId')
            ->andWhere('c.createdAt >= :from')
            ->andWhere('c.createdAt < :to')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('ym')
            ->getQuery()
            ->getArrayResult();

        return $this->rowsToMonthMap($rows);
    }

    /**
     * @return array<string, int>
     */
    private function fetchMonthlyCards(Merchant $merchant, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('SUBSTRING(lc.createdAt, 1, 7) AS ym', 'COUNT(lc.id) AS total')
            ->from(LoyaltyCard::class, 'lc')
            ->join('lc.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('lc.createdAt >= :from')
            ->andWhere('lc.createdAt < :to')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('ym')
            ->getQuery()
            ->getArrayResult();

        return $this->rowsToMonthMap($rows);
    }

    /**
     * @return array<string, int>
     */
    private function fetchMonthlyTransactions(Merchant $merchant, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('SUBSTRING(t.createdAt, 1, 7) AS ym', 'COUNT(t.id) AS total')
            ->from(Transaction::class, 't')
            ->join('t.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('t.createdAt >= :from')
            ->andWhere('t.createdAt < :to')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('ym')
            ->getQuery()
            ->getArrayResult();

        return $this->rowsToMonthMap($rows);
    }

    /**
     * @param array<int, array{ym?:mixed,y?:mixed,m?:mixed,total:mixed}> $rows
     *
     * @return array<string, int>
     */
    private function rowsToMonthMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if (preg_match('/^\d{4}-\d{2}$/', $ym) === 1) {
                $map[$ym] = (int) ($row['total'] ?? 0);

                continue;
            }

            $year = (int) ($row['y'] ?? 0);
            $month = (int) ($row['m'] ?? 0);
            if ($year <= 0 || $month <= 0) {
                continue;
            }

            $map[sprintf('%04d-%02d', $year, $month)] = (int) ($row['total'] ?? 0);
        }

        return $map;
    }

    private function countDistinctCustomersForPeriod(Merchant $merchant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from(Customer::class, 'c')
            ->leftJoin('c.merchant', 'directMerchant')
            ->leftJoin('c.merchants', 'linkedMerchant')
            ->leftJoin('c.loyaltyCards', 'lc')
            ->leftJoin('lc.merchant', 'cardMerchant')
            ->where('directMerchant.id = :merchantId OR linkedMerchant.id = :merchantId OR cardMerchant.id = :merchantId')
            ->andWhere('c.createdAt >= :from')
            ->andWhere('c.createdAt < :to')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countCardsForPeriod(Merchant $merchant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(lc.id)')
            ->from(LoyaltyCard::class, 'lc')
            ->join('lc.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('lc.createdAt >= :from')
            ->andWhere('lc.createdAt < :to')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countTransactionsForPeriod(Merchant $merchant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Transaction::class, 't')
            ->join('t.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('t.createdAt >= :from')
            ->andWhere('t.createdAt < :to')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countCardsTotal(Merchant $merchant): int
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(lc.id)')
            ->from(LoyaltyCard::class, 'lc')
            ->join('lc.merchant', 'm')
            ->where('m.id = :merchantId')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countCompletedCardsTotal(Merchant $merchant): int
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(lc.id)')
            ->from(LoyaltyCard::class, 'lc')
            ->join('lc.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('lc.isCompleted = :completed')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('completed', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countDistinctCustomersTotal(Merchant $merchant): int
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from(Customer::class, 'c')
            ->leftJoin('c.merchant', 'directMerchant')
            ->leftJoin('c.merchants', 'linkedMerchant')
            ->leftJoin('c.loyaltyCards', 'lc')
            ->leftJoin('lc.merchant', 'cardMerchant')
            ->where('directMerchant.id = :merchantId OR linkedMerchant.id = :merchantId OR cardMerchant.id = :merchantId')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countDistinctActiveCustomersForPeriod(Merchant $merchant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $merchantId = $merchant->getId();
        if (!$merchantId instanceof Uuid) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from(Transaction::class, 't')
            ->join('t.merchant', 'm')
            ->join('t.loyaltyCard', 'lc')
            ->join('lc.customer', 'c')
            ->where('m.id = :merchantId')
            ->andWhere('t.createdAt >= :from')
            ->andWhere('t.createdAt < :to')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function formatPlan(?\App\Entity\Plan $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'id' => $plan->getId(),
            'slug' => $plan->getSlug(),
            'name' => $plan->getName(),
            'price_monthly' => $plan->getPriceMonthly(),
            'max_customers' => $plan->getMaxCustomers(),
            'max_programs' => $plan->getMaxPrograms(),
            'has_wallet_integration' => $plan->isHasWalletIntegration(),
            'has_push_notifications' => $plan->isHasPushNotifications(),
            'has_advanced_stats' => $plan->isHasAdvancedStats(),
        ];
    }

    #[Route('/api/merchant/me', name: 'get_current_merchant', methods: ['GET'])]
    public function getCurrentMerchant(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        return new JsonResponse($this->formatMerchant($merchant));
    }

    private function formatMerchant(Merchant $merchant): array
    {
        $currentUser = $this->getUser();

        return [
            'id' => $merchant->getId(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'phone' => $merchant->getPhone(),
            'address' => $merchant->getAddress(),
            'postal_code' => $merchant->getPostalCode(),
            'city' => $merchant->getCity(),
            'establishment_type' => $merchant->getEstablishmentType()?->getCode(),
            'logo_url' => $merchant->getLogoUrl(),
            'instagram_url' => $merchant->getInstagramUrl(),
            'tiktok_url' => $merchant->getTiktokUrl(),
            'website_url' => $merchant->getWebsiteUrl(),
            'stripe_customer_id' => $merchant->getStripeCustomerId(),
            'trial_ends_at' => $merchant->getTrialEndsAt()?->format('Y-m-d\TH:i:s\Z'),
            'accepted_terms' => $merchant->isAcceptedTerms(),
            'accepted_terms_version' => $merchant->getAcceptedTermsVersion(),
            'accepted_terms_accepted_at' => $merchant->getAcceptedTermsAcceptedAt() ? (clone $merchant->getAcceptedTermsAcceptedAt())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null,
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'owner_user_id' => $merchant->getUser()?->getId(),
            'is_owner' => $currentUser !== null && $merchant->getUser() === $currentUser,
            'active_loyalty_program_count' => $merchant->getActiveLoyaltyProgramCount(),
            'plan' => $this->formatPlan($merchant->getPlan()),
            'is_free_account' => $merchant->isFreeAccount(),
            'free_account_granted_at' => $merchant->getFreeAccountGrantedAt()?->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    #[Route('/api/merchants/me/plan-usage', name: 'merchant_plan_usage', methods: ['GET'])]
    public function getPlanUsage(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $plan = $merchant->getPlan();
        $customerCount = $this->customerRepository->countByMerchant($merchant);
        $programCount = $merchant->getLoyaltyPrograms()->count();
        $isFreeAccount = $merchant->isFreeAccount();

        return new JsonResponse([
            'plan' => $this->formatPlan($plan),
            'usage' => [
                'customers' => $customerCount,
                'programs' => $programCount,
            ],
            'limits_reached' => [
                'customers' => !$isFreeAccount && $plan !== null && $plan->getMaxCustomers() >= 0 && $customerCount >= $plan->getMaxCustomers(),
                'programs' => !$isFreeAccount && $plan !== null && $plan->getMaxPrograms() >= 0 && $programCount >= $plan->getMaxPrograms(),
            ],
            'is_free_account' => $isFreeAccount,
        ]);
    }

    #[Route('/api/merchant/asset-download-events', name: 'merchant_track_asset_download', methods: ['POST'])]
    public function trackAssetDownload(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $eventType = $data['event_type'] ?? '';
        $assetType = $data['asset_type'] ?? '';

        if (!in_array($eventType, ['print', 'download'], true)) {
            return new JsonResponse(['error' => 'invalid_event_type'], 422);
        }

        if (empty($assetType)) {
            return new JsonResponse(['error' => 'missing_asset_type'], 422);
        }

        $event = new MerchantAssetDownloadEvent();
        $event->setMerchant($merchant);
        $event->setEventType($eventType);
        $event->setAssetType(substr($assetType, 0, 50));

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'recorded'], 201);
    }

    private function resolveActorMerchant(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        $merchant = $user->getMerchant();
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_EQUIPIER', $roles, true) && !in_array('ROLE_MERCHANT', $roles, true)) {
            return null;
        }

        return $user->getCustomer()?->getStaffMerchant();
    }

    #[Route('/api/merchant-establishment-types', name: 'merchant_establishment_types', methods: ['GET'])]
    public function establishmentTypes(): JsonResponse
    {
        $types = $this->entityManager->createQueryBuilder()
            ->select('t.code AS code', 't.label AS label')
            ->from(MerchantEstablishmentType::class, 't')
            ->orderBy('t.label', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return new JsonResponse($types);
    }

    #[Route('/api/merchants', name: 'create_merchant', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if ($user->getMerchant()) {
            return new JsonResponse(['error' => 'Merchant already exists'], 400);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['company_name'])) {
            return new JsonResponse(['error' => 'company_name required'], 400);
        }
        if (!is_string($data['company_name']) || trim($data['company_name']) === '') {
            return new JsonResponse(['error' => 'company_name must be a non-empty string'], 400);
        }
        if (array_key_exists('phone', $data) && $data['phone'] !== null && !is_string($data['phone'])) {
            return new JsonResponse(['error' => 'phone must be a string or null'], 400);
        }
        if (array_key_exists('address', $data) && $data['address'] !== null && !is_string($data['address'])) {
            return new JsonResponse(['error' => 'address must be a string or null'], 400);
        }
        $instagramUrl = $this->normalizeOptionalHttpUrl($data, 'instagram_url');
        if ($instagramUrl === false) {
            return new JsonResponse(['error' => 'instagram_url must be a valid http(s) URL or null'], 400);
        }
        $tiktokUrl = $this->normalizeOptionalHttpUrl($data, 'tiktok_url');
        if ($tiktokUrl === false) {
            return new JsonResponse(['error' => 'tiktok_url must be a valid http(s) URL or null'], 400);
        }
        $websiteUrl = $this->normalizeOptionalHttpUrl($data, 'website_url');
        if ($websiteUrl === false) {
            return new JsonResponse(['error' => 'website_url must be a valid http(s) URL or null'], 400);
        }
        $establishmentTypeCode = $this->normalizeEstablishmentType($data, 'establishment_type');
        if ($establishmentTypeCode === false) {
            return new JsonResponse(['error' => 'establishment_type must be a string code or null'], 400);
        }
        $establishmentType = $this->resolveEstablishmentType($establishmentTypeCode);
        if ($establishmentTypeCode !== null && $establishmentType === null) {
            return new JsonResponse(['error' => 'Unknown establishment_type code'], 400);
        }
        if (!array_key_exists('postal_code', $data)) {
            return new JsonResponse(['error' => 'postal_code required'], 400);
        }
        if (!is_string($data['postal_code']) || trim($data['postal_code']) === '') {
            return new JsonResponse(['error' => 'postal_code must be a non-empty string'], 400);
        }
        if (mb_strlen(trim($data['postal_code'])) > 10) {
            return new JsonResponse(['error' => 'postal_code must be at most 10 characters'], 400);
        }
        if (!array_key_exists('city', $data)) {
            return new JsonResponse(['error' => 'city required'], 400);
        }
        if (!is_string($data['city']) || trim($data['city']) === '') {
            return new JsonResponse(['error' => 'city must be a non-empty string'], 400);
        }
        if (mb_strlen(trim($data['city'])) > 100) {
            return new JsonResponse(['error' => 'city must be at most 100 characters'], 400);
        }
        if (!array_key_exists('accepted_terms', $data) || $data['accepted_terms'] !== true) {
            return new JsonResponse(['error' => 'accepted_terms must be true'], 422);
        }
        if (!array_key_exists('accepted_terms_version', $data) || !is_string($data['accepted_terms_version']) || trim($data['accepted_terms_version']) === '') {
            return new JsonResponse(['error' => 'accepted_terms_version required'], 422);
        }
        if (mb_strlen(trim($data['accepted_terms_version'])) > 32) {
            return new JsonResponse(['error' => 'accepted_terms_version must be at most 32 characters'], 422);
        }
        if (trim($data['accepted_terms_version']) !== $this->legalTermsVersionProvider->getCurrentVersion()) {
            return new JsonResponse(['error' => 'accepted_terms_version is outdated'], 422);
        }
        if (!array_key_exists('accepted_terms_accepted_at', $data) || !is_string($data['accepted_terms_accepted_at'])) {
            return new JsonResponse(['error' => 'accepted_terms_accepted_at required'], 422);
        }
        try {
            $acceptedAt = new \DateTime($data['accepted_terms_accepted_at']);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'accepted_terms_accepted_at must be a valid datetime'], 422);
        }

        $resolvedEmail = null;
        if (array_key_exists('email', $data) && $data['email'] !== null) {
            if (!is_string($data['email'])) {
                return new JsonResponse(['error' => 'email must be a string'], 400);
            }
            $resolvedEmail = trim(mb_strtolower($data['email']));
        }
        if ($resolvedEmail === null || $resolvedEmail === '') {
            $resolvedEmail = mb_strtolower((string) ($user->getEmail() ?? ''));
        }
        if ($resolvedEmail === '' || !filter_var($resolvedEmail, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'email must be a valid non-empty email'], 422);
        }

        $merchant = new Merchant();
        $merchant->setCompanyName(trim($data['company_name']));
        $merchant->setEmail($resolvedEmail);
        $merchant->setPhone($data['phone'] ?? null);
        $merchant->setAddress($data['address'] ?? null);
        $merchant->setPostalCode(trim($data['postal_code']));
        $merchant->setCity(trim($data['city']));
        $merchant->setEstablishmentType($establishmentType);
        $merchant->setLogoUrl(null);
        $merchant->setInstagramUrl($instagramUrl);
        $merchant->setTiktokUrl($tiktokUrl);
        $merchant->setWebsiteUrl($websiteUrl);
        $merchant->setAcceptedTerms(true);
        $merchant->setAcceptedTermsVersion(trim($data['accepted_terms_version']));
        $merchant->setAcceptedTermsAcceptedAt($acceptedAt);
        $merchant->setSubscriptionStatus($data['subscription_status'] ?? 'trial');

        if (isset($data['trial_ends_at'])) {
            $merchant->setTrialEndsAt(new \DateTime($data['trial_ends_at']));
        } else {
            $merchant->setTrialEndsAt((new \DateTime())->add(new \DateInterval('P30D')));
        }

        if (isset($data['plan_id'])) {
            $plan = $this->planRepository->find($data['plan_id']);
            if ($plan) {
                $merchant->setPlan($plan);
            }
        } elseif (isset($data['plan_slug'])) {
            $plan = $this->planRepository->findBySlug($data['plan_slug']);
            if ($plan) {
                $merchant->setPlan($plan);
            }
        } else {
            $freePlan = $this->planRepository->findBySlug('free');
            if ($freePlan) {
                $merchant->setPlan($freePlan);
            }
        }

        $merchant->setUser($user);

        $this->entityManager->persist($merchant);
        $this->entityManager->flush();
        $this->signupAlertMailer->notifyMerchantSignup($merchant);

        return new JsonResponse($this->formatMerchant($merchant), 201);
    }

    #[Route('/api/merchants/{id}', name: 'update_merchant', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->entityManager->getRepository(Merchant::class)->find($id);
        if (!$merchant || $merchant->getUser() !== $user) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (array_key_exists('company_name', $data)) {
            if (!is_string($data['company_name']) || trim($data['company_name']) === '') {
                return new JsonResponse(['error' => 'company_name must be a non-empty string'], 400);
            }

            $merchant->setCompanyName($data['company_name']);
        }
        if (array_key_exists('phone', $data)) {
            if ($data['phone'] !== null && !is_string($data['phone'])) {
                return new JsonResponse(['error' => 'phone must be a string or null'], 400);
            }

            $merchant->setPhone($data['phone']);
        }
        if (array_key_exists('address', $data)) {
            if ($data['address'] !== null && !is_string($data['address'])) {
                return new JsonResponse(['error' => 'address must be a string or null'], 400);
            }

            $merchant->setAddress($data['address']);
        }
        if (array_key_exists('postal_code', $data)) {
            if ($data['postal_code'] !== null && !is_string($data['postal_code'])) {
                return new JsonResponse(['error' => 'postal_code must be a string or null'], 400);
            }
            if (is_string($data['postal_code']) && mb_strlen(trim($data['postal_code'])) > 10) {
                return new JsonResponse(['error' => 'postal_code must be at most 10 characters'], 400);
            }

            $merchant->setPostalCode($data['postal_code'] !== null ? trim($data['postal_code']) : null);
        }
        if (array_key_exists('city', $data)) {
            if ($data['city'] !== null && !is_string($data['city'])) {
                return new JsonResponse(['error' => 'city must be a string or null'], 400);
            }
            if (is_string($data['city']) && mb_strlen(trim($data['city'])) > 100) {
                return new JsonResponse(['error' => 'city must be at most 100 characters'], 400);
            }

            $merchant->setCity($data['city'] !== null ? trim($data['city']) : null);
        }
        if (array_key_exists('establishment_type', $data)) {
            $establishmentTypeCode = $this->normalizeEstablishmentType($data, 'establishment_type');
            if ($establishmentTypeCode === false) {
                return new JsonResponse(['error' => 'establishment_type must be a string code or null'], 400);
            }

            $establishmentType = $this->resolveEstablishmentType($establishmentTypeCode);
            if ($establishmentTypeCode !== null && $establishmentType === null) {
                return new JsonResponse(['error' => 'Unknown establishment_type code'], 400);
            }

            $merchant->setEstablishmentType($establishmentType);
        }
        if (array_key_exists('instagram_url', $data)) {
            $instagramUrl = $this->normalizeOptionalHttpUrl($data, 'instagram_url');
            if ($instagramUrl === false) {
                return new JsonResponse(['error' => 'instagram_url must be a valid http(s) URL or null'], 400);
            }

            $merchant->setInstagramUrl($instagramUrl);
        }
        if (array_key_exists('tiktok_url', $data)) {
            $tiktokUrl = $this->normalizeOptionalHttpUrl($data, 'tiktok_url');
            if ($tiktokUrl === false) {
                return new JsonResponse(['error' => 'tiktok_url must be a valid http(s) URL or null'], 400);
            }

            $merchant->setTiktokUrl($tiktokUrl);
        }
        if (array_key_exists('website_url', $data)) {
            $websiteUrl = $this->normalizeOptionalHttpUrl($data, 'website_url');
            if ($websiteUrl === false) {
                return new JsonResponse(['error' => 'website_url must be a valid http(s) URL or null'], 400);
            }

            $merchant->setWebsiteUrl($websiteUrl);
        }
        if (array_key_exists('accepted_terms', $data)) {
            if ($data['accepted_terms'] !== true) {
                return new JsonResponse(['error' => 'accepted_terms cannot be set to false'], 422);
            }

            $merchant->setAcceptedTerms(true);
        }
        if (array_key_exists('accepted_terms_version', $data)) {
            if (!is_string($data['accepted_terms_version']) || trim($data['accepted_terms_version']) === '') {
                return new JsonResponse(['error' => 'accepted_terms_version must be a non-empty string'], 422);
            }
            if (mb_strlen(trim($data['accepted_terms_version'])) > 32) {
                return new JsonResponse(['error' => 'accepted_terms_version must be at most 32 characters'], 422);
            }
            if (!$merchant->isAcceptedTerms()) {
                return new JsonResponse(['error' => 'accepted_terms must be true to set acceptance metadata'], 422);
            }
            if (trim($data['accepted_terms_version']) !== $this->legalTermsVersionProvider->getCurrentVersion()) {
                return new JsonResponse(['error' => 'accepted_terms_version is outdated'], 422);
            }

            $merchant->setAcceptedTermsVersion(trim($data['accepted_terms_version']));
        }
        if (array_key_exists('accepted_terms_accepted_at', $data)) {
            if (!is_string($data['accepted_terms_accepted_at'])) {
                return new JsonResponse(['error' => 'accepted_terms_accepted_at must be a valid datetime'], 422);
            }
            if (!$merchant->isAcceptedTerms()) {
                return new JsonResponse(['error' => 'accepted_terms must be true to set acceptance metadata'], 422);
            }

            try {
                $merchant->setAcceptedTermsAcceptedAt(new \DateTime($data['accepted_terms_accepted_at']));
            } catch (\Exception) {
                return new JsonResponse(['error' => 'accepted_terms_accepted_at must be a valid datetime'], 422);
            }
        }
        if (isset($data['stripe_customer_id'])) {
            $merchant->setStripeCustomerId($data['stripe_customer_id']);
        }
        if (isset($data['trial_ends_at'])) {
            $merchant->setTrialEndsAt(new \DateTime($data['trial_ends_at']));
        }
        if (isset($data['subscription_status'])) {
            $merchant->setSubscriptionStatus($data['subscription_status']);
        }
        if (isset($data['plan_id'])) {
            $plan = $this->planRepository->find($data['plan_id']);
            if ($plan) {
                $merchant->setPlan($plan);
            }
        } elseif (isset($data['plan_slug'])) {
            $plan = $this->planRepository->findBySlug($data['plan_slug']);
            if ($plan) {
                $merchant->setPlan($plan);
            }
        }

        $this->entityManager->flush();

        return new JsonResponse($this->formatMerchant($merchant));
    }

    #[Route('/api/merchants/{id}/logo', name: 'upload_merchant_logo', methods: ['POST'])]
    public function uploadLogo(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->entityManager->getRepository(Merchant::class)->find($id);
        if (!$merchant || $merchant->getUser() !== $user) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $logo = $data['logo'] ?? null;

        if (!is_string($logo) || $logo === '') {
            return new JsonResponse(['error' => 'logo is required'], 400);
        }

        if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,([A-Za-z0-9+\/=\s]+)$/i', $logo, $matches)) {
            return new JsonResponse(['error' => 'Format invalide'], 400);
        }

        $rawBase64 = preg_replace('/\s+/', '', $matches[2]);
        $decodedLogo = base64_decode($rawBase64, true);

        if ($decodedLogo === false) {
            return new JsonResponse(['error' => 'Format invalide'], 400);
        }

        if (strlen($decodedLogo) > self::MAX_LOGO_SIZE_BYTES) {
            return new JsonResponse(['error' => 'Fichier trop volumineux (max 5MB)'], 400);
        }

        $merchant->setLogoUrl($logo);
        $this->entityManager->flush();

        return new JsonResponse($this->formatMerchant($merchant));
    }

    private function normalizeOptionalHttpUrl(array $data, string $key): string|false|null
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > 255) {
            return false;
        }

        $isValid = filter_var($trimmed, FILTER_VALIDATE_URL) !== false;
        $scheme = (string) parse_url($trimmed, PHP_URL_SCHEME);
        if (!$isValid || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        return $trimmed;
    }

    private function normalizeEstablishmentType(array $data, string $key): string|false|null
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        if (!preg_match('/^[a-z0-9_\\-]+$/', $normalized)) {
            return false;
        }

        return $normalized;
    }

    private function resolveEstablishmentType(?string $code): ?MerchantEstablishmentType
    {
        if ($code === null) {
            return null;
        }

        return $this->entityManager
            ->getRepository(MerchantEstablishmentType::class)
            ->findOneBy(['code' => $code]);
    }
}
