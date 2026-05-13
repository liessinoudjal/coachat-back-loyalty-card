<?php

namespace App\Controller;

use App\Entity\Merchant;
use App\Entity\MerchantAssetDownloadEvent;
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

class MerchantController extends AbstractController
{
    private const MAX_LOGO_SIZE_BYTES = 5242880;

    private $entityManager;
    private $planRepository;
    private CustomerRepository $customerRepository;
    private LegalTermsVersionProvider $legalTermsVersionProvider;
    private SignupAlertMailer $signupAlertMailer;

    public function __construct(EntityManagerInterface $entityManager, PlanRepository $planRepository, CustomerRepository $customerRepository, LegalTermsVersionProvider $legalTermsVersionProvider, SignupAlertMailer $signupAlertMailer)
    {
        $this->entityManager = $entityManager;
        $this->planRepository = $planRepository;
        $this->customerRepository = $customerRepository;
        $this->legalTermsVersionProvider = $legalTermsVersionProvider;
        $this->signupAlertMailer = $signupAlertMailer;
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
            'logo_url' => $merchant->getLogoUrl(),
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

        return new JsonResponse([
            'plan' => $this->formatPlan($plan),
            'usage' => [
                'customers' => $customerCount,
                'programs' => $programCount,
            ],
            'limits_reached' => [
                'customers' => $plan !== null && $plan->getMaxCustomers() >= 0 && $customerCount >= $plan->getMaxCustomers(),
                'programs' => $plan !== null && $plan->getMaxPrograms() >= 0 && $programCount >= $plan->getMaxPrograms(),
            ],
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

        $merchant = new Merchant();
        $merchant->setCompanyName(trim($data['company_name']));
        $merchant->setEmail($data['email'] ?? $user->getEmail());
        $merchant->setPhone($data['phone'] ?? null);
        $merchant->setAddress($data['address'] ?? null);
        $merchant->setPostalCode(trim($data['postal_code']));
        $merchant->setCity(trim($data['city']));
        $merchant->setLogoUrl(null);
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
}
