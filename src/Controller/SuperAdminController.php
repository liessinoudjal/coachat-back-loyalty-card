<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\PromotionalOffer;
use App\Entity\Reward;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\LoyaltyProgramRepository;
use App\Repository\MerchantAssetDownloadEventRepository;
use App\Repository\MerchantRepository;
use App\Repository\PlanRepository;
use App\Service\GoogleReviewModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class SuperAdminController extends AbstractController
{
    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly LoyaltyProgramRepository $loyaltyProgramRepository,
        private readonly GoogleReviewModuleManager $googleReviewModuleManager,
        private readonly EntityManagerInterface $em,
        private readonly MerchantAssetDownloadEventRepository $assetDownloadEventRepository,
        private readonly PlanRepository $planRepository,
    ) {
    }

    #[Route('/api/super-admin/me', name: 'super_admin_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'google_id' => $user->getGoogleId(),
            'roles' => $user->getRoles(),
            'is_super_admin' => in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true),
            'merchant' => $user->getMerchant() instanceof Merchant ? $this->formatMerchant($user->getMerchant()) : null,
        ]);
    }

    #[Route('/api/super-admin/merchants', name: 'super_admin_merchants_list', methods: ['GET'])]
    public function merchants(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchants = $this->merchantRepository->findBy([], ['companyName' => 'ASC']);

        return new JsonResponse([
            'items' => array_map(fn (Merchant $merchant): array => $this->formatMerchant($merchant), $merchants),
            'total' => count($merchants),
        ]);
    }

    #[Route('/api/super-admin/merchants', name: 'super_admin_merchants_create_unclaimed', methods: ['POST'])]
    public function createUnclaimedMerchant(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        $companyName = trim((string) ($data['company_name'] ?? ''));
        $rawEmail = trim((string) ($data['email'] ?? ''));
        $email = $rawEmail !== '' ? mb_strtolower($rawEmail) : null;
        $address = trim((string) ($data['address'] ?? ''));
        $postalCode = trim((string) ($data['postal_code'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $phone = isset($data['phone']) && $data['phone'] !== null ? trim((string) $data['phone']) : null;

        if ($companyName === '') {
            return new JsonResponse(['error' => 'company_name_required'], 422);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'email_invalid'], 422);
        }
        if ($address === '') {
            return new JsonResponse(['error' => 'address_required'], 422);
        }
        if ($postalCode === '') {
            return new JsonResponse(['error' => 'postal_code_required'], 422);
        }
        if ($city === '') {
            return new JsonResponse(['error' => 'city_required'], 422);
        }

        if ($email !== null) {
            $existingByEmail = $this->em->createQueryBuilder()
                ->select('m.id')
                ->from(Merchant::class, 'm')
                ->where('LOWER(m.email) = :email')
                ->setParameter('email', $email)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existingByEmail !== null) {
                return new JsonResponse([
                    'error' => 'merchant_email_already_exists',
                    'message' => 'Un commerce avec cet email existe déjà.',
                ], 409);
            }
        }

        $merchant = new Merchant();
        $merchant->setCompanyName($companyName);
        $merchant->setEmail($email);
        $merchant->setPhone($phone !== '' ? $phone : null);
        $merchant->setAddress($address);
        $merchant->setPostalCode($postalCode);
        $merchant->setCity($city);
        $merchant->setSubscriptionStatus((string) ($data['subscription_status'] ?? 'trial'));
        $merchant->setAcceptedTerms(false);
        $merchant->setAcceptedTermsVersion(null);
        $merchant->setAcceptedTermsAcceptedAt(null);

        $freePlan = $this->planRepository->findBySlug('free');
        if ($freePlan !== null) {
            $merchant->setPlan($freePlan);
        }

        $this->em->persist($merchant);
        $this->em->flush();

        return new JsonResponse($this->formatMerchant($merchant), 201);
    }

    #[Route('/api/super-admin/merchants/{merchantId}/claim-email', name: 'super_admin_merchants_set_claim_email', methods: ['PUT'])]
    public function setMerchantClaimEmail(string $merchantId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        $rawEmail = trim((string) ($data['email'] ?? ''));
        if ($rawEmail === '') {
            if ($merchant->getUser() !== null) {
                return new JsonResponse([
                    'error' => 'claimed_merchant_email_required',
                    'message' => 'Impossible de vider l\'email d\'un commerce déjà réclamé.',
                ], 409);
            }

            $merchant->setEmail(null);
            $this->em->persist($merchant);
            $this->em->flush();

            return new JsonResponse($this->formatMerchant($merchant));
        }

        $email = mb_strtolower($rawEmail);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'email_invalid'], 422);
        }

        $existingByEmail = $this->em->createQueryBuilder()
            ->select('m.id')
            ->from(Merchant::class, 'm')
            ->where('LOWER(m.email) = :email')
            ->andWhere('m.id != :merchantId')
            ->setParameter('email', $email)
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingByEmail !== null) {
            return new JsonResponse([
                'error' => 'merchant_email_already_exists',
                'message' => 'Un commerce avec cet email existe déjà.',
            ], 409);
        }

        $merchant->setEmail($email);
        $this->em->persist($merchant);
        $this->em->flush();

        return new JsonResponse($this->formatMerchant($merchant));
    }

    #[Route('/api/super-admin/merchants/{merchantId}/loyalty-programs', name: 'super_admin_merchant_loyalty_programs', methods: ['GET'])]
    public function merchantLoyaltyPrograms(string $merchantId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $programs = $this->loyaltyProgramRepository->findBy(['merchant' => $merchant], ['name' => 'ASC']);

        return new JsonResponse([
            'merchant' => $this->formatMerchantPresenter($merchant),
            'items' => array_map(fn (LoyaltyProgram $program): array => $this->formatLoyaltyProgram($program), $programs),
            'total' => count($programs),
        ]);
    }

    #[Route('/api/super-admin/merchants/{merchantId}/google-review-module', name: 'super_admin_merchant_google_review_module', methods: ['GET'])]
    public function merchantGoogleReviewModule(string $merchantId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $module = $this->googleReviewModuleManager->getForMerchant($merchant);
        if (!$module instanceof MerchantGoogleReviewModule) {
            return new JsonResponse(['error' => 'google_review_module_not_found'], 404);
        }

        return new JsonResponse($this->formatGoogleReviewModule($module));
    }

    #[Route('/api/super-admin/merchants/{merchantId}/kpis', name: 'super_admin_merchant_kpis', methods: ['GET'])]
    public function merchantKpis(string $merchantId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        // Build 12 monthly labels (oldest → newest)
        $now = new \DateTimeImmutable();
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = $now->modify("-{$i} months")->format('Y-m');
        }
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $months[0] . '-01')->setTime(0, 0, 0);

        // ── Helpers ───────────────────────────────────────────────────────────
        $bucket = static function (array $rows, string $dateKey, array $months): array {
            $b = array_fill_keys($months, 0);
            foreach ($rows as $row) {
                /** @var \DateTimeInterface $dt */
                $dt = $row[$dateKey];
                $key = $dt->format('Y-m');
                if (isset($b[$key])) {
                    $b[$key]++;
                }
            }
            return array_values($b);
        };

        $merchantId = $merchant->getId();

        // ── Customers (enrolled by this merchant via loyalty card) ─────────────
        $customers = $this->em->createQueryBuilder()
            ->select('DISTINCT c.id', 'c.createdAt')
            ->from(Customer::class, 'c')
            ->join('c.loyaltyCards', 'lc')
            ->join('lc.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('c.createdAt >= :from')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->getQuery()->getArrayResult();

        // ── Loyalty cards ─────────────────────────────────────────────────────
        $loyaltyCards = $this->em->createQueryBuilder()
            ->select('lc.id', 'lc.createdAt')
            ->from(LoyaltyCard::class, 'lc')
            ->join('lc.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('lc.createdAt >= :from')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->getQuery()->getArrayResult();

        // ── Transactions ──────────────────────────────────────────────────────
        $transactions = $this->em->createQueryBuilder()
            ->select('t.id', 't.createdAt')
            ->from(Transaction::class, 't')
            ->join('t.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('t.createdAt >= :from')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->getQuery()->getArrayResult();

        // ── Rewards generated ─────────────────────────────────────────────────
        $rewards = $this->em->createQueryBuilder()
            ->select('r.id', 'r.generatedAt')
            ->from(Reward::class, 'r')
            ->join('r.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('r.generatedAt >= :from')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->getQuery()->getArrayResult();

        // ── Promotional offers created ─────────────────────────────────────────
        $promoOffers = $this->em->createQueryBuilder()
            ->select('p.id', 'p.createdAt')
            ->from(PromotionalOffer::class, 'p')
            ->join('p.merchant', 'm')
            ->where('m.id = :merchantId')
            ->andWhere('p.createdAt >= :from')
            ->setParameter('merchantId', $merchantId, 'uuid')
            ->setParameter('from', $from)
            ->getQuery()->getArrayResult();

        // ── Asset download events ──────────────────────────────────────────────
        $assetEvents = $this->assetDownloadEventRepository->findByMerchantSince($merchant, $from);

        // Bucket asset events by type
        $printByMonth = array_fill_keys($months, 0);
        $downloadByMonth = array_fill_keys($months, 0);
        foreach ($assetEvents as $event) {
            /** @var \DateTimeInterface $dt */
            $dt = $event['occurredAt'];
            $key = $dt->format('Y-m');
            if ($event['eventType'] === 'print' && isset($printByMonth[$key])) {
                $printByMonth[$key]++;
            } elseif ($event['eventType'] === 'download' && isset($downloadByMonth[$key])) {
                $downloadByMonth[$key]++;
            }
        }

        // ── Totals ─────────────────────────────────────────────────────────────
        $totalCustomers = $merchant->getLoyaltyCards()
            ->map(fn ($lc) => $lc->getCustomer())
            ->filter(fn ($c) => $c !== null)
            ->count();

        $completedCards = $merchant->getLoyaltyCards()
            ->filter(fn (LoyaltyCard $lc) => $lc->isCompleted())
            ->count();

        $totalCards = $merchant->getLoyaltyCards()->count();

        $recentThreshold = $now->modify('-30 days');
        $activeCustomerIds = [];
        foreach ($merchant->getTransactions() as $t) {
            if ($t->getCreatedAt() >= $recentThreshold && $t->getLoyaltyCard()?->getCustomer() !== null) {
                $activeCustomerIds[$t->getLoyaltyCard()->getCustomer()->getId()] = true;
            }
        }

        // Labels formatted for display
        $labels = array_map(static function (string $ym): string {
            [$y, $m] = explode('-', $ym);
            $months_fr = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
            return $months_fr[(int) $m - 1] . ' ' . $y;
        }, $months);

        return new JsonResponse([
            'period_labels' => $labels,
            'customers_new' => $bucket($customers, 'createdAt', $months),
            'loyalty_cards_new' => $bucket($loyaltyCards, 'createdAt', $months),
            'transactions' => $bucket($transactions, 'createdAt', $months),
            'rewards_generated' => $bucket($rewards, 'generatedAt', $months),
            'promotional_offers_new' => $bucket($promoOffers, 'createdAt', $months),
            'asset_downloads_print' => array_values($printByMonth),
            'asset_downloads_download' => array_values($downloadByMonth),
            'totals' => [
                'customers_enrolled' => $merchant->getLoyaltyCards()
                    ->map(fn ($lc) => $lc->getCustomer()?->getId())
                    ->filter(fn ($id) => $id !== null)
                    ->toArray(),
                'customers' => count(array_unique(
                    array_filter(
                        $merchant->getLoyaltyCards()
                            ->map(fn ($lc) => $lc->getCustomer()?->getId())
                            ->toArray()
                    )
                )),
                'loyalty_cards' => $totalCards,
                'loyalty_cards_completed' => $completedCards,
                'completion_rate' => $totalCards > 0 ? round(($completedCards / $totalCards) * 100, 1) : 0,
                'transactions' => $merchant->getTransactions()->count(),
                'rewards' => $merchant->getRewards()->count(),
                'promotional_offers_total' => count($promoOffers) + $this->em->createQueryBuilder()
                    ->select('COUNT(p.id)')
                    ->from(PromotionalOffer::class, 'p')
                    ->join('p.merchant', 'm')
                    ->where('m.id = :merchantId')
                    ->andWhere('p.createdAt < :from')
                    ->setParameter('merchantId', $merchantId, 'uuid')
                    ->setParameter('from', $from)
                    ->getQuery()->getSingleScalarResult(),
                'active_customers_last_30d' => count($activeCustomerIds),
                'asset_downloads_print_total' => array_sum($printByMonth),
                'asset_downloads_download_total' => array_sum($downloadByMonth),
            ],
        ]);
    }

    #[Route('/api/super-admin/merchants/{merchantId}/free-account', name: 'super_admin_merchants_set_free_account', methods: ['PATCH'])]
    public function setMerchantFreeAccount(string $merchantId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('enabled', $data)) {
            return new JsonResponse(['error' => 'enabled_required'], 422);
        }

        $enabled = (bool) $data['enabled'];
        $currentUser = $this->getUser();

        $merchant->setIsFreeAccount($enabled);
        if ($enabled) {
            $merchant->setFreeAccountGrantedAt(new \DateTime());
            $merchant->setFreeAccountGrantedBy($currentUser instanceof User ? $currentUser : null);
        } else {
            $merchant->setFreeAccountGrantedAt(null);
            $merchant->setFreeAccountGrantedBy(null);
        }

        $this->em->persist($merchant);
        $this->em->flush();

        return new JsonResponse($this->formatMerchant($merchant));
    }

    #[Route('/api/super-admin/merchants/{merchantId}', name: 'super_admin_merchants_delete', methods: ['DELETE'])]
    public function deleteMerchant(string $merchantId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $merchantName = $merchant->getCompanyName();
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail() ?? 'n/a';

        // Détacher le user si le merchant est réclamé
        if ($merchant->getUser() instanceof User) {
            $user = $merchant->getUser();
            $merchant->setUser(null);
            // Garder le user mais détacher son merchant
            $this->em->persist($merchant);
            $this->em->persist($user);
        }

        // Supprimer le merchant
        $this->em->remove($merchant);
        $this->em->flush();

        return new JsonResponse([
            'message' => 'Merchant deleted successfully',
            'merchant' => [
                'id' => $merchantId,
                'company_name' => $merchantName,
                'email' => $merchantEmail,
            ],
        ], 200);
    }

    private function formatMerchant(Merchant $merchant): array
    {
        $user = $merchant->getUser();
        $plan = $merchant->getPlan();

        return [
            'id' => $merchant->getId()?->toRfc4122(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'phone' => $merchant->getPhone(),
            'address' => $merchant->getAddress(),
            'postal_code' => $merchant->getPostalCode(),
            'city' => $merchant->getCity(),
            'logo_url' => $merchant->getLogoUrl(),
            'stripe_customer_id' => $merchant->getStripeCustomerId(),
            'trial_ends_at' => $merchant->getTrialEndsAt()?->format('Y-m-d\TH:i:s\Z'),
            'current_period_start_at' => $merchant->getCurrentPeriodStartAt()?->format('Y-m-d\TH:i:s\Z'),
            'current_period_end_at' => $merchant->getCurrentPeriodEndAt()?->format('Y-m-d\TH:i:s\Z'),
            'accepted_terms' => $merchant->isAcceptedTerms(),
            'accepted_terms_version' => $merchant->getAcceptedTermsVersion(),
            'accepted_terms_accepted_at' => $merchant->getAcceptedTermsAcceptedAt() ? (clone $merchant->getAcceptedTermsAcceptedAt())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null,
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'active_loyalty_program_count' => $merchant->getActiveLoyaltyProgramCount(),
            'loyalty_program_count' => $merchant->getLoyaltyPrograms()->count(),
            'loyalty_card_count' => $merchant->getLoyaltyCards()->count(),
            'transaction_count' => $merchant->getTransactions()->count(),
            'reward_count' => $merchant->getRewards()->count(),
            'plan' => $plan ? [
                'id' => $plan->getId(),
                'slug' => $plan->getSlug(),
                'name' => $plan->getName(),
                'price_monthly' => $plan->getPriceMonthly(),
                'max_customers' => $plan->getMaxCustomers(),
                'max_programs' => $plan->getMaxPrograms(),
                'has_wallet_integration' => $plan->isHasWalletIntegration(),
                'has_push_notifications' => $plan->isHasPushNotifications(),
                'has_advanced_stats' => $plan->isHasAdvancedStats(),
            ] : null,
            'is_claimed' => $user !== null,
            'user' => $user ? [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
            ] : null,
            'is_free_account' => $merchant->isFreeAccount(),
            'free_account_granted_at' => $merchant->getFreeAccountGrantedAt()?->format('Y-m-d\TH:i:s\Z'),
            'free_account_granted_by' => $merchant->getFreeAccountGrantedBy() ? [
                'id' => $merchant->getFreeAccountGrantedBy()->getId(),
                'email' => $merchant->getFreeAccountGrantedBy()->getEmail(),
                'name' => $merchant->getFreeAccountGrantedBy()->getName(),
            ] : null,
        ];
    }

    private function formatMerchantPresenter(Merchant $merchant): array
    {
        return [
            'id' => $merchant->getId()?->toRfc4122(),
            'company_name' => $merchant->getCompanyName(),
            'logo_url' => $merchant->getLogoUrl(),
            'city' => $merchant->getCity(),
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'merchant_ref' => $merchant->getId()?->toRfc4122(),
            'customer_signup_qr_value' => $merchant->getId()?->toRfc4122(),
        ];
    }

    private function formatLoyaltyProgram(LoyaltyProgram $program): array
    {
        return [
            'id' => $program->getId(),
            'name' => $program->getName(),
            'description' => $program->getDescription(),
            'type' => $program->getType()->value,
            'points_per_euro' => $program->getPointsPerEuro(),
            'points_target' => $program->getPointsTarget(),
            'stamp_target' => $program->getStampTarget(),
            'reward_description' => $program->getRewardDescription(),
            'is_active' => $program->isActive(),
            'loyalty_card_count' => $program->getLoyaltyCards()->count(),
            'reward_count' => $program->getRewards()->count(),
        ];
    }

    private function formatGoogleReviewModule(MerchantGoogleReviewModule $module): array
    {
        $merchant = $module->getMerchant();

        return [
            'id' => $module->getId()?->toRfc4122(),
            'merchant_id' => $merchant?->getId()?->toRfc4122(),
            'merchant_name' => $merchant?->getCompanyName(),
            'merchant_logo_url' => $merchant?->getLogoUrl(),
            'is_enabled' => $module->isEnabled(),
            'display_name' => $module->getDisplayName(),
            'google_review_url' => $module->getGoogleReviewUrl(),
            'show_in_customer_dashboard' => $module->isShowInCustomerDashboard(),
            'show_qr_code' => $module->isShowQrCode(),
            'reward_options' => $module->getRewardOptions(),
            'is_configuration_complete' => $this->googleReviewModuleManager->isComplete($module),
            'created_at' => $module->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $module->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }
}
