<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\PromotionalOffer;
use App\Repository\MerchantGoogleReviewModuleRepository;
use App\Repository\MerchantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class MerchantMapController extends AbstractController
{
    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly MerchantGoogleReviewModuleRepository $googleReviewModuleRepository,
        private readonly EntityManagerInterface $entityManager,
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
                'has_active_content' => $hasActiveOffer || $hasActiveLoyaltyProgram,
                'loyalty_programs' => $loyaltyPrograms,
                'active_promotional_offers' => $activeOffers,
                'google_review' => $googleReview,
            ];
        }

        return new JsonResponse(['merchants' => $merchants]);
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
