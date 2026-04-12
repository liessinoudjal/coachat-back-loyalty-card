<?php

namespace App\Controller;

use App\Entity\Merchant;
use App\Repository\PlanRepository;
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

    public function __construct(EntityManagerInterface $entityManager, PlanRepository $planRepository)
    {
        $this->entityManager = $entityManager;
        $this->planRepository = $planRepository;
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

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        return new JsonResponse($this->formatMerchant($merchant));
    }

    private function formatMerchant(Merchant $merchant): array
    {
        return [
            'id' => $merchant->getId(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'phone' => $merchant->getPhone(),
            'address' => $merchant->getAddress(),
            'logo_url' => $merchant->getLogoUrl(),
            'stripe_customer_id' => $merchant->getStripeCustomerId(),
            'trial_ends_at' => $merchant->getTrialEndsAt()?->format('Y-m-d\TH:i:s\Z'),
            'subscription_status' => $merchant->getSubscriptionStatus(),
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

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $plan = $merchant->getPlan();
        $customerCount = $merchant->getLoyaltyCards()->count();
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

        $merchant = new Merchant();
        $merchant->setCompanyName(trim($data['company_name']));
        $merchant->setEmail($data['email'] ?? $user->getEmail());
        $merchant->setPhone($data['phone'] ?? null);
        $merchant->setAddress($data['address'] ?? null);
        $merchant->setLogoUrl(null);
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
        if (isset($data['email'])) {
            $merchant->setEmail($data['email']);
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
