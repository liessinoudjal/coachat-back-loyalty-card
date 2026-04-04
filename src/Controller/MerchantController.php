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

        return new JsonResponse([
            'id' => $merchant->getId(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'stripe_customer_id' => $merchant->getStripeCustomerId(),
            'trial_ends_at' => $merchant->getTrialEndsAt()?->format('Y-m-d\TH:i:s\Z'),
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'active_loyalty_program_count' => $merchant->getActiveLoyaltyProgramCount(),
            'plan' => $this->formatPlan($merchant->getPlan()),
        ]);
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

        $merchant = new Merchant();
        $merchant->setCompanyName($data['company_name']);
        $merchant->setEmail($data['email'] ?? $user->getEmail());
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

        return new JsonResponse([
            'id' => $merchant->getId(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'trial_ends_at' => $merchant->getTrialEndsAt()?->format('Y-m-d\TH:i:s\Z'),
            'plan' => $this->formatPlan($merchant->getPlan()),
        ], 201);
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
        if (isset($data['company_name'])) {
            $merchant->setCompanyName($data['company_name']);
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

        return new JsonResponse([
            'id' => $merchant->getId(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'stripe_customer_id' => $merchant->getStripeCustomerId(),
            'trial_ends_at' => $merchant->getTrialEndsAt()?->format('Y-m-d\TH:i:s\Z'),
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'plan' => $this->formatPlan($merchant->getPlan()),
        ]);
    }
}
