<?php

namespace App\Controller;

use App\Repository\PlanRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class PlanController extends AbstractController
{
    public function __construct(private readonly PlanRepository $planRepository)
    {
    }

    #[Route('/api/plans', name: 'list_plans', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $plans = $this->planRepository->findAllActive();

        return new JsonResponse(array_map(fn ($plan) => $this->formatPlan($plan), $plans));
    }

    #[Route('/api/plans/{id}', name: 'get_plan', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $plan = $this->planRepository->find($id);
        if (!$plan || !$plan->isActive()) {
            return new JsonResponse(['error' => 'Plan not found'], 404);
        }

        return new JsonResponse($this->formatPlan($plan));
    }

    private function formatPlan(\App\Entity\Plan $plan): array
    {
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
}
