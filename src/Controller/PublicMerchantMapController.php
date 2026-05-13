<?php

namespace App\Controller;

use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\PromotionalOffer;
use App\Repository\CustomerRepository;
use App\Repository\MerchantGoogleReviewModuleRepository;
use App\Repository\MerchantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Public API endpoints for merchant map (landing page)
 * No authentication required
 * Rate limited per IP with dedicated endpoint budgets
 */
final class PublicMerchantMapController extends AbstractController
{
    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MerchantGoogleReviewModuleRepository $googleReviewModuleRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.public_map_view_limiter')]
        private readonly RateLimiterFactory $publicMapViewLimiter,
        #[Autowire(service: 'limiter.public_map_search_limiter')]
        private readonly RateLimiterFactory $publicMapSearchLimiter,
        #[Autowire(service: 'limiter.public_map_detail_limiter')]
        private readonly RateLimiterFactory $publicMapDetailLimiter,
    ) {
    }

    /**
     * GET /api/public/merchants/map
     * Retrieve merchants for map display (public, no auth required)
     * Returns merchants with loyalty programs, offers, and subscription links
     */
    #[Route('/api/public/merchants/map', name: 'public_merchants_map', methods: ['GET'])]
    public function map(Request $request): JsonResponse
    {
        // Rate limit by IP
        $limiter = $this->publicMapViewLimiter->create((string) $request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(
                ['error' => 'rate_limit_exceeded', 'retry_after' => 60],
                429,
                ['Retry-After' => '60']
            );
        }

        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        $lat = is_numeric($lat) ? (float) $lat : null;
        $lng = is_numeric($lng) ? (float) $lng : null;

        // Bounding box — defaults to metropolitan France
        $swLat = (float) $request->query->get('sw_lat', 41.3);
        $swLng = (float) $request->query->get('sw_lng', -5.1);
        $neLat = (float) $request->query->get('ne_lat', 51.1);
        $neLng = (float) $request->query->get('ne_lng', 9.6);

        try {
            $rows = $this->merchantRepository->findForMap($lat, $lng, $swLat, $swLng, $neLat, $neLng);
        } catch (\Exception) {
            $rows = [];
        }

        if (empty($rows)) {
            $response = new JsonResponse(['merchants' => []]);
            $response->setPublic();
            $response->setMaxAge(300); // 5 min cache
            return $response;
        }

        $googleModulesByMerchant = $this->indexGoogleReviewModules(
            array_map(fn($row) => $row['merchant'], $rows),
        );

        $today = new \DateTimeImmutable('today');
        $merchants = [];

        foreach ($rows as $row) {
            $merchant = $row['merchant'];
            $merchantId = $merchant->getId()?->toRfc4122();

            if ($merchantId === null) {
                continue;
            }

            // --- Loyalty programs ---
            $loyaltyPrograms = [];
            $hasActiveLoyaltyProgram = false;
            foreach ($merchant->getLoyaltyPrograms() as $program) {
                if (!$program->isActive()) {
                    continue;
                }
                $hasActiveLoyaltyProgram = true;

                $loyaltyPrograms[] = [
                    'id' => $program->getId(),
                    'name' => $program->getName(),
                    'type' => $program->getType()->value,
                    'stamp_target' => $program->getStampTarget(),
                    'points_target' => $program->getPointsTarget(),
                    'reward_description' => $program->getRewardDescription(),
                ];
            }

            // --- Active promotional offers ---
            $activeOffers = $this->collectActiveOffers($merchant, $today);
            $hasActiveOffer = count($activeOffers) > 0;

            // --- Google Review module ---
            $googleModule = $googleModulesByMerchant[$merchantId] ?? null;
            $googleReview = null;
            if ($googleModule instanceof MerchantGoogleReviewModule && $googleModule->isEnabled()) {
                $googleReview = [
                    'is_enabled' => true,
                    'display_name' => $googleModule->getDisplayName(),
                    'url' => $googleModule->getGoogleReviewUrl(),
                ];
            }

            // Count subscribers for social proof
            $subscriberCount = $this->customerRepository->countByMerchant($merchant);
            $plan = $merchant->getPlan();
            $customerSignupAvailable = $plan === null || $plan->getMaxCustomers() < 0 || $subscriberCount < $plan->getMaxCustomers();

            $merchants[] = [
                'id' => $merchantId,
                'company_name' => $merchant->getCompanyName(),
                'address' => $merchant->getAddress(),
                'postal_code' => $merchant->getPostalCode(),
                'city' => $merchant->getCity(),
                'phone' => $merchant->getPhone(),
                'logo_url' => $merchant->getLogoUrl(),
                'latitude' => $merchant->getLatitude(),
                'longitude' => $merchant->getLongitude(),
                'distance_km' => $row['distance_km'],
                'subscriber_count' => $subscriberCount,
                'customer_signup_available' => $customerSignupAvailable,
                'has_active_content' => $hasActiveOffer || $hasActiveLoyaltyProgram,
                'loyalty_programs' => $loyaltyPrograms,
                'active_promotional_offers' => $activeOffers,
                'google_review' => $googleReview,
                // Public signup link for this merchant
                'subscribe_url' => sprintf('/?customer_signup=1&merchant_ref=%s&signup_type=landing_map', urlencode($merchantId)),
            ];
        }

        $response = new JsonResponse(['merchants' => $merchants]);
        $response->setPublic();
        $response->setMaxAge(300); // 5 min cache
        return $response;
    }

    /**
     * GET /api/public/merchants/search
     * Search merchants by name/address (public, no auth required)
     */
    #[Route('/api/public/merchants/search', name: 'public_merchants_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        // Rate limit by IP
        $limiter = $this->publicMapSearchLimiter->create((string) $request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(
                ['error' => 'rate_limit_exceeded', 'retry_after' => 60],
                429,
                ['Retry-After' => '60']
            );
        }

        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 3) {
            return new JsonResponse(['merchants' => []]);
        }

        $limit = (int) $request->query->get('limit', 5);
        $limit = max(1, min($limit, 10));

        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        $lat = is_numeric($lat) ? (float) $lat : null;
        $lng = is_numeric($lng) ? (float) $lng : null;

        try {
            $rows = $this->merchantRepository->searchForMap($query, $limit, $lat, $lng);
        } catch (\Exception) {
            $rows = [];
        }

        $merchantById = [];
        if (!empty($rows)) {
            $uuids = array_map(
                static fn(array $row) => Uuid::fromString((string) $row['id']),
                $rows,
            );
            /** @var array<int, Merchant> $entities */
            $entities = $this->entityManager->getRepository(Merchant::class)->findBy(['id' => $uuids]);
            foreach ($entities as $entity) {
                $id = $entity->getId()?->toRfc4122();
                if ($id !== null) {
                    $merchantById[$id] = $entity;
                }
            }
        }

        $today = new \DateTimeImmutable('today');

        $merchants = array_map(function (array $row) use ($merchantById, $today): array {
            $merchantEntity = $merchantById[$row['id']] ?? null;
            $hasLoyaltyPrograms = $merchantEntity instanceof Merchant
                ? $merchantEntity->getActiveLoyaltyProgramCount() > 0
                : false;
            $hasActiveOffers = $merchantEntity instanceof Merchant
                ? count($this->collectActiveOffers($merchantEntity, $today)) > 0
                : false;

            return [
                'id' => $row['id'],
                'company_name' => $row['company_name'],
                'address' => $row['address'],
                'postal_code' => $row['postal_code'],
                'city' => $row['city'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'distance_km' => $row['distance_km'],
                'has_loyalty_programs' => $hasLoyaltyPrograms,
                'has_active_promotional_offers' => $hasActiveOffers,
                'subscribe_url' => sprintf('/?customer_signup=1&merchant_ref=%s&signup_type=landing_map', urlencode($row['id'])),
            ];
        }, $rows);

        $response = new JsonResponse(['merchants' => $merchants]);
        $response->setPublic();
        $response->setMaxAge(45); // 45 sec cache for search results
        return $response;
    }

    /**
     * GET /api/public/merchants/{id}
     * Get single merchant details (public, no auth required)
     */
    #[Route('/api/public/merchants/{id}', name: 'public_merchant_detail', methods: ['GET'])]
    public function detail(string $id, Request $request): JsonResponse
    {
        // Rate limit by IP
        $limiter = $this->publicMapDetailLimiter->create((string) $request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(
                ['error' => 'rate_limit_exceeded', 'retry_after' => 60],
                429,
                ['Retry-After' => '60']
            );
        }

        try {
            $uuid = Uuid::fromString(trim($id));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $merchant = $this->entityManager->getRepository(Merchant::class)->find($uuid);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $merchantId = $merchant->getId()?->toRfc4122();
        if ($merchantId === null) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        // --- Loyalty programs ---
        $loyaltyPrograms = [];
        foreach ($merchant->getLoyaltyPrograms() as $program) {
            if (!$program->isActive()) {
                continue;
            }

            $loyaltyPrograms[] = [
                'id' => $program->getId(),
                'name' => $program->getName(),
                'type' => $program->getType()->value,
                'stamp_target' => $program->getStampTarget(),
                'points_target' => $program->getPointsTarget(),
                'reward_description' => $program->getRewardDescription(),
            ];
        }

        // --- Active promotional offers ---
        $today = new \DateTimeImmutable('today');
        $activeOffers = $this->collectActiveOffers($merchant, $today);

        // --- Google Review module ---
        $googleModule = $this->googleReviewModuleRepository->findOneBy(['merchant' => $merchant]);
        $googleReview = null;
        if ($googleModule instanceof MerchantGoogleReviewModule && $googleModule->isEnabled()) {
            $googleReview = [
                'is_enabled' => true,
                'display_name' => $googleModule->getDisplayName(),
                'url' => $googleModule->getGoogleReviewUrl(),
            ];
        }

        $merchantData = [
            'id' => $merchantId,
            'company_name' => $merchant->getCompanyName(),
            'address' => $merchant->getAddress(),
            'postal_code' => $merchant->getPostalCode(),
            'city' => $merchant->getCity(),
            'phone' => $merchant->getPhone(),
            'logo_url' => $merchant->getLogoUrl(),
            'latitude' => $merchant->getLatitude(),
            'longitude' => $merchant->getLongitude(),
            'loyalty_programs' => $loyaltyPrograms,
            'active_promotional_offers' => $activeOffers,
            'google_review' => $googleReview,
            'subscribe_url' => sprintf('/?customer_signup=1&merchant_ref=%s&signup_type=landing_map', urlencode($merchantId)),
        ];

        $response = new JsonResponse($merchantData);
        $response->setPublic();
        $response->setMaxAge(600); // 10 min cache for detail
        return $response;
    }

    /**
     * @param array<Merchant> $merchants
     * @return array<string, MerchantGoogleReviewModule|null>
     */
    private function indexGoogleReviewModules(array $merchants): array
    {
        if (empty($merchants)) {
            return [];
        }

        $index = [];
        $modules = $this->googleReviewModuleRepository->findBy(['merchant' => $merchants]);

        foreach ($modules as $module) {
            $merchantId = $module->getMerchant()?->getId()?->toRfc4122();
            if ($merchantId !== null) {
                $index[$merchantId] = $module;
            }
        }

        return $index;
    }

    /**
     * @return array<int, array{id: int, title: string, description: string, ends_on: string}>
     */
    private function collectActiveOffers(Merchant $merchant, \DateTimeImmutable $today): array
    {
        $offers = $this->entityManager->getRepository(PromotionalOffer::class)
            ->createQueryBuilder('o')
            ->where('IDENTITY(o.merchant) = :merchantId')
            ->andWhere('o.startsOn <= :today')
            ->andWhere('o.endsOn >= :today')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->setParameter('today', $today, 'date_immutable')
            ->orderBy('o.endsOn', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn(PromotionalOffer $o) => [
            'id' => $o->getId(),
            'title' => $o->getTitle(),
            'description' => $o->getDescription(),
            'ends_on' => $o->getEndsOn()?->format('Y-m-d'),
        ], $offers);
    }
}
