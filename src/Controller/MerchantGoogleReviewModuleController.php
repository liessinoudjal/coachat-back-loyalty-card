<?php

namespace App\Controller;

use App\Entity\MerchantGoogleReviewModule;
use App\Exception\GoogleReviewException;
use App\Service\GoogleReviewModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class MerchantGoogleReviewModuleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleReviewModuleManager $moduleManager,
    ) {
    }

    #[Route('/api/merchants/me/google-review-module', name: 'merchant_google_review_module_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->getUser()?->getMerchant();
        if ($merchant === null) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $module = $this->moduleManager->getOrCreateForMerchant($merchant);
        $this->entityManager->flush();

        return new JsonResponse($this->formatModule($module));
    }

    #[Route('/api/merchants/me/google-review-module', name: 'merchant_google_review_module_upsert', methods: ['PUT'])]
    public function upsert(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->getUser()?->getMerchant();
        if ($merchant === null) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'google_review_payload_invalid'], 400);
        }

        $module = $this->moduleManager->getOrCreateForMerchant($merchant);

        try {
            $this->moduleManager->applyPayload($module, $payload);
            $this->entityManager->flush();
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }

        return new JsonResponse($this->formatModule($module));
    }

    private function formatModule(MerchantGoogleReviewModule $module): array
    {
        $merchant = $module->getMerchant();

        return [
            'id' => $module->getId()?->toRfc4122(),
            'merchant_id' => $merchant?->getId()?->toRfc4122(),
            'merchant_name' => $merchant?->getCompanyName(),
            'is_enabled' => $module->isEnabled(),
            'display_name' => $module->getDisplayName(),
            'google_review_url' => $module->getGoogleReviewUrl(),
            'show_in_customer_dashboard' => $module->isShowInCustomerDashboard(),
            'show_qr_code' => $module->isShowQrCode(),
            'reward_options' => $module->getRewardOptions(),
            'merchant_logo_url' => $merchant?->getLogoUrl(),
            'is_configuration_complete' => $this->moduleManager->isComplete($module),
            'created_at' => $module->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $module->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }
}