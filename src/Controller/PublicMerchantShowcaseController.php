<?php

namespace App\Controller;

use App\Repository\MerchantRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class PublicMerchantShowcaseController extends AbstractController
{
    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/public/merchants/showcase', name: 'public_merchants_showcase', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 120);
        $limit = max(1, min($limit, 400));

        try {
            $merchants = $this->merchantRepository->listForPublicShowcase($limit);
            $loyaltyRows = [];
            $offerRows = [];
            
            if (!empty($merchants)) {
                $merchantIds = array_values(array_map(static fn(array $m): string => $m['id'], $merchants));
                $connection = $this->entityManager->getConnection();

                try {
                    $loyaltyRows = $connection->executeQuery(
                        '
                        SELECT
                            BIN_TO_UUID(lp.merchant_id) AS merchant_id,
                            lp.name,
                            lp.type,
                            lp.reward_description
                        FROM loyalty_program lp
                        WHERE lp.is_active = 1
                          AND BIN_TO_UUID(lp.merchant_id) IN (:merchant_ids)
                        ORDER BY lp.name ASC
                        ',
                        ['merchant_ids' => $merchantIds],
                        ['merchant_ids' => ArrayParameterType::STRING],
                    )->fetchAllAssociative();
                } catch (\Exception $e) {
                    $loyaltyRows = [];
                }

                try {
                    $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
                    $offerRows = $connection->executeQuery(
                        '
                        SELECT
                            BIN_TO_UUID(po.merchant_id) AS merchant_id,
                            po.title,
                            po.description,
                            DATE_FORMAT(po.ends_on, "%Y-%m-%d") AS ends_on
                        FROM promotional_offer po
                        WHERE po.starts_on <= :today
                          AND po.ends_on >= :today
                          AND BIN_TO_UUID(po.merchant_id) IN (:merchant_ids)
                        ORDER BY po.ends_on ASC, po.title ASC
                        ',
                        [
                            'today' => $today,
                            'merchant_ids' => $merchantIds,
                        ],
                        [
                            'merchant_ids' => ArrayParameterType::STRING,
                        ],
                    )->fetchAllAssociative();
                } catch (\Exception $e) {
                    $offerRows = [];
                }
            }
        } catch (\Exception $e) {
            // Fallback for development when database is not available
            $merchants = $this->getDevMockMerchants($limit);
            $loyaltyRows = [];
            $offerRows = [];
        }

        if (empty($merchants)) {
            $response = new JsonResponse([
                'merchants' => [],
                'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]);
            $response->setPublic();
            $response->setMaxAge(300);
            $response->setSharedMaxAge(900);

            return $response;
        }

        $loyaltyByMerchant = [];
        foreach ($loyaltyRows as $row) {
            $merchantId = (string) $row['merchant_id'];
            $loyaltyByMerchant[$merchantId][] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'reward_description' => $row['reward_description'] !== null ? (string) $row['reward_description'] : null,
            ];
        }

        $offersByMerchant = [];
        foreach ($offerRows as $row) {
            $merchantId = (string) $row['merchant_id'];
            $offersByMerchant[$merchantId][] = [
                'title' => (string) $row['title'],
                'description' => (string) $row['description'],
                'ends_on' => $row['ends_on'] !== null ? (string) $row['ends_on'] : null,
            ];
        }

        $payload = array_map(function (array $merchant) use ($loyaltyByMerchant, $offersByMerchant, $request): array {
            $merchantId = (string) $merchant['id'];
            $companyName = (string) $merchant['company_name'];
            $merchantSlug = $this->slugify($companyName);
            $partnerUrl = sprintf(
                '%s://%s/partenaires/%s-%s',
                $request->getScheme(),
                $request->getHttpHost(),
                $merchantId,
                $merchantSlug,
            );
            $loyaltyPrograms = $loyaltyByMerchant[$merchantId] ?? [];
            $activeOffers = $offersByMerchant[$merchantId] ?? [];

            return [
                'id' => $merchantId,
                'slug' => $merchantSlug,
                'partner_url' => $partnerUrl,
                'company_name' => $companyName,
                'logo_url' => $merchant['logo_url'],
                'address' => $merchant['address'],
                'postal_code' => $merchant['postal_code'],
                'city' => $merchant['city'],
                'has_loyalty_programs' => !empty($loyaltyPrograms) || (bool) $merchant['has_loyalty_programs'],
                'has_active_promotional_offers' => !empty($activeOffers) || (bool) $merchant['has_active_promotional_offers'],
                'loyalty_programs' => $loyaltyPrograms,
                'active_promotional_offers' => $activeOffers,
            ];
        }, $merchants);

        $response = new JsonResponse([
            'merchants' => $payload,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(900);

        return $response;
    }

    private function slugify(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'commerce';
    }

    private function getDevMockMerchants(int $limit): array
    {
        $mockData = [
            [
                'id' => '123e4567-e89b-12d3-a456-426614174000',
                'company_name' => 'Boulangerie du Centre',
                'logo_url' => '/icon-192.png',
                'address' => '123 Rue de la Paix',
                'postal_code' => '75001',
                'city' => 'Paris',
                'has_loyalty_programs' => true,
                'has_active_promotional_offers' => true,
            ],
            [
                'id' => '223e4567-e89b-12d3-a456-426614174001',
                'company_name' => 'Salon de Coiffure Marie',
                'logo_url' => '/icon-192.png',
                'address' => '45 Avenue des Champs',
                'postal_code' => '75008',
                'city' => 'Paris',
                'has_loyalty_programs' => true,
                'has_active_promotional_offers' => false,
            ],
            [
                'id' => '323e4567-e89b-12d3-a456-426614174002',
                'company_name' => 'Restaurant Le Gourmet',
                'logo_url' => '/icon-192.png',
                'address' => '78 Rue de Rivoli',
                'postal_code' => '75004',
                'city' => 'Paris',
                'has_loyalty_programs' => true,
                'has_active_promotional_offers' => true,
            ],
            [
                'id' => '423e4567-e89b-12d3-a456-426614174003',
                'company_name' => 'Pharmacie Santé Plus',
                'logo_url' => '/icon-192.png',
                'address' => '12 Place de l\'Église',
                'postal_code' => '92400',
                'city' => 'Courbevoie',
                'has_loyalty_programs' => true,
                'has_active_promotional_offers' => false,
            ],
            [
                'id' => '523e4567-e89b-12d3-a456-426614174004',
                'company_name' => 'Librairie Les Pages',
                'logo_url' => '/icon-192.png',
                'address' => '56 Boulevard Saint-Germain',
                'postal_code' => '75005',
                'city' => 'Paris',
                'has_loyalty_programs' => true,
                'has_active_promotional_offers' => true,
            ],
            [
                'id' => '623e4567-e89b-12d3-a456-426614174005',
                'company_name' => 'Gym Fit Énergie',
                'logo_url' => '/icon-192.png',
                'address' => '99 Rue Lafayette',
                'postal_code' => '75010',
                'city' => 'Paris',
                'has_loyalty_programs' => true,
                'has_active_promotional_offers' => false,
            ],
        ];

        return array_slice($mockData, 0, $limit);
    }
}
