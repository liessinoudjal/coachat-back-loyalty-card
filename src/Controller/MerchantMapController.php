<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\PromotionalOffer;
use App\Exception\CustomerMerchantLimitReachedException;
use App\Repository\CustomerRepository;
use App\Repository\MerchantGoogleReviewModuleRepository;
use App\Repository\MerchantRepository;
use App\Service\CustomerMerchantLinker;
use App\Service\NotificationService;
use App\Service\SignupAlertMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

final class MerchantMapController extends AbstractController
{
    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MerchantGoogleReviewModuleRepository $googleReviewModuleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerMerchantLinker $customerMerchantLinker,
        private readonly SignupAlertMailer $signupAlertMailer,
        private readonly NotificationService $notificationService,
    ) {
    }

    #[Route('/api/customer/merchants/map', name: 'customer_merchants_map', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'customer_not_found'], 404);
        }

        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');

        $lat = is_numeric($lat) ? (float) $lat : null;
        $lng = is_numeric($lng) ? (float) $lng : null;

        // Bounding box — defaults to metropolitan France if not provided
        $swLat = (float) $request->query->get('sw_lat', 41.3);
        $swLng = (float) $request->query->get('sw_lng', -5.1);
        $neLat = (float) $request->query->get('ne_lat', 51.1);
        $neLng = (float) $request->query->get('ne_lng', 9.6);

        $rows = $this->merchantRepository->findForMap($lat, $lng, $swLat, $swLng, $neLat, $neLng);

        if (empty($rows)) {
            return new JsonResponse(['merchants' => []]);
        }

        // Index merchant IDs the customer is already linked to
        $linkedMerchantIds = [];
        // ManyToMany (customer_merchants table)
        foreach ($customer->getMerchants() as $m) {
            $id = $m->getId()?->toRfc4122();
            if ($id !== null) {
                $linkedMerchantIds[$id] = true;
            }
        }
        // ManyToOne singular (legacy / primary merchant)
        $primaryId = $customer->getMerchant()?->getId()?->toRfc4122();
        if ($primaryId !== null) {
            $linkedMerchantIds[$primaryId] = true;
        }

        // Pre-load customer's loyalty cards indexed by merchant id
        $customerCardsByMerchant = $this->indexCustomerCardsByMerchant($customer);

        // Pre-load google review modules indexed by merchant id
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

                // Customer card for this program (most recent non-completed first, then completed)
                $customerCard = $customerCardsByMerchant[$merchantId][$program->getId()] ?? null;

                $loyaltyPrograms[] = [
                    'id' => $program->getId(),
                    'name' => $program->getName(),
                    'type' => $program->getType()->value,
                    'stamp_target' => $program->getStampTarget(),
                    'points_target' => $program->getPointsTarget(),
                    'reward_description' => $program->getRewardDescription(),
                    'customer_card' => $customerCard !== null ? [
                        'current_value' => $customerCard->getCurrentValue(),
                        'target_value' => $customerCard->getTargetValue(),
                        'is_completed' => $customerCard->isCompleted(),
                    ] : null,
                ];
            }

            // --- Active promotional offers ---
            $activeOffers = [];
            foreach ($merchant->getLoyaltyPrograms() as $program) {
                // Offers are on the merchant, not the program — iterate merchant offers
                // (handled below via the Merchant relation)
            }
            // Collect active offers directly from merchant (via lazy collection on PromotionalOffer)
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
                'is_customer_linked' => isset($linkedMerchantIds[$merchantId]),
                'loyalty_programs' => $loyaltyPrograms,
                'active_promotional_offers' => $activeOffers,
                'google_review' => $googleReview,
            ];
        }

        return new JsonResponse(['merchants' => $merchants]);
    }

    #[Route('/api/customer/merchants/search', name: 'customer_merchants_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'customer_not_found'], 404);
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

        $rows = $this->merchantRepository->searchForMap($query, $limit, $lat, $lng);

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
            ];
        }, $rows);

        return new JsonResponse(['merchants' => $merchants]);
    }

    #[Route('/api/customer/merchants/map-join', name: 'customer_map_join_merchant', methods: ['POST'])]
    public function joinFromMap(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'customer_not_found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $merchantId = is_array($data) ? ($data['merchant_id'] ?? null) : null;

        if (!is_string($merchantId) || trim($merchantId) === '') {
            return new JsonResponse(['error' => 'merchant_id_missing'], 400);
        }

        try {
            $uuid = Uuid::fromString(trim($merchantId));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $merchant = $this->entityManager->getRepository(Merchant::class)->find($uuid);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        try {
            $isNewLink = $this->customerMerchantLinker->link($customer, $merchant);
        } catch (CustomerMerchantLimitReachedException $exception) {
            $this->signupAlertMailer->notifyMerchantSignupRefusedDueToCustomerLimit(
                $merchant,
                $customer,
                $exception->getCurrentCustomers(),
                $exception->getMaxCustomers(),
                'carte interactive',
            );

            return new JsonResponse([
                'error' => $exception->getMessage(),
                'message' => sprintf(
                    'Ce commerce a déjà atteint son plafond de %d abonnés pour son plan actuel.',
                    $exception->getMaxCustomers(),
                ),
                'current_customers' => $exception->getCurrentCustomers(),
                'max_customers' => $exception->getMaxCustomers(),
            ], 409);
        }
        $this->entityManager->flush();

        if ($isNewLink) {
            // Alert admin with map source flag
            $this->signupAlertMailer->notifyCustomerSignupViaMap($customer, $merchant);
            // Alert the merchant by email
            $this->signupAlertMailer->notifyMerchantNewCustomerViaMap($customer, $merchant);
            // Send welcome email to customer
            $this->notificationService->notifyCustomerSignup($customer, $merchant);
        }

        return new JsonResponse([
            'success' => true,
            'already_linked' => !$isNewLink,
            'merchant' => [
                'id' => $merchant->getId()?->toRfc4122(),
                'company_name' => $merchant->getCompanyName(),
            ],
        ]);
    }

    /**
     * @param Customer $customer
     * @return array<string, array<int, LoyaltyCard>>  merchantId => [programId => LoyaltyCard]
     */
    private function indexCustomerCardsByMerchant(Customer $customer): array
    {
        $index = [];
        foreach ($customer->getLoyaltyCards() as $card) {
            $merchantId = $card->getMerchant()?->getId()?->toRfc4122();
            $programId = $card->getLoyaltyProgram()?->getId();
            if ($merchantId === null || $programId === null) {
                continue;
            }
            // Keep the most relevant card: prefer active over completed
            if (!isset($index[$merchantId][$programId]) || $card->isCompleted() === false) {
                $index[$merchantId][$programId] = $card;
            }
        }

        return $index;
    }

    /**
     * @param array<int, \App\Entity\Merchant> $merchants
     * @return array<string, MerchantGoogleReviewModule>
     */
    private function indexGoogleReviewModules(array $merchants): array
    {
        if (empty($merchants)) {
            return [];
        }

        $modules = $this->googleReviewModuleRepository->findBy(['merchant' => $merchants]);
        $index = [];
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
    private function collectActiveOffers(\App\Entity\Merchant $merchant, \DateTimeImmutable $today): array
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
