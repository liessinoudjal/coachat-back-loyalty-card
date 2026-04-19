<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\GoogleReviewReward;
use App\Entity\GoogleReviewSession;
use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Enum\GoogleReviewEventType;
use App\Exception\GoogleReviewException;
use App\Repository\MerchantRepository;
use App\Service\GoogleReviewJourneyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CustomerGoogleReviewController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MerchantRepository $merchantRepository,
        private readonly GoogleReviewJourneyService $journeyService,
    ) {
    }

    #[Route('/api/customer/me/google-review-modules', name: 'customer_google_review_module_list', methods: ['GET'])]
    public function listModules(): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        $modules = $this->journeyService->getVisibleModulesForCustomer($customer);
        $payload = [];

        foreach ($modules as $module) {
            $reward = $this->journeyService->getCurrentRewardForCustomerAndMerchant($customer, $module->getMerchant());
            $session = $this->journeyService->getCurrentSessionForCustomerAndMerchant($customer, $module->getMerchant());
            $payload[] = $this->formatModuleSummary($module, $session, $reward);
        }

        return new JsonResponse($payload);
    }

    #[Route('/api/customer/me/google-review-modules/{merchantId}', name: 'customer_google_review_module_detail', methods: ['GET'])]
    public function detail(string $merchantId): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        try {
            $module = $this->journeyService->getVisibleModuleForCustomer($customer, $merchant);
            $session = $this->journeyService->getCurrentSessionForCustomerAndMerchant($customer, $merchant);
            $reward = $this->journeyService->getCurrentRewardForCustomerAndMerchant($customer, $merchant);

            $this->journeyService->logCustomEvent($module, $customer, $session, GoogleReviewEventType::DETAIL_VIEWED, 'detail_endpoint');
            $this->entityManager->flush();

            return new JsonResponse($this->formatModuleDetail($module, $session, $reward));
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    #[Route('/api/customer/me/google-review-modules/{merchantId}/reward-options', name: 'customer_google_review_module_reward_options', methods: ['GET'])]
    public function rewardOptions(string $merchantId): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        try {
            $module = $this->journeyService->getVisibleModuleForCustomer($customer, $merchant);

            return new JsonResponse([
                'merchant_id' => $merchant->getId()?->toRfc4122(),
                'merchant_name' => $merchant->getCompanyName(),
                'merchant_logo_url' => $merchant->getLogoUrl(),
                'display_name' => $module->getDisplayName(),
                'google_review_url' => $module->getGoogleReviewUrl(),
                'show_qr_code' => $module->isShowQrCode(),
                'reward_options' => $module->getActiveRewardOptions(),
            ]);
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    #[Route('/api/customer/me/google-review-modules/{merchantId}/launch', name: 'customer_google_review_launch', methods: ['POST'])]
    public function launch(string $merchantId): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        try {
            $session = $this->journeyService->launch($customer, $merchant);
            $this->entityManager->flush();

            return new JsonResponse([
                'session_id' => $session->getId()?->toRfc4122(),
                'status' => $session->getStatus()->value,
                'redirect_url' => $session->getModule()?->getGoogleReviewUrl(),
            ]);
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    #[Route('/api/customer/me/google-review-sessions/{sessionId}/return', name: 'customer_google_review_return', methods: ['POST'])]
    public function confirmReturn(string $sessionId): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        try {
            $session = $this->journeyService->getCustomerSession($sessionId, $customer);
            $session = $this->journeyService->confirmReturn($session);
            $this->entityManager->flush();

            return new JsonResponse($this->formatSession($session));
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    #[Route('/api/customer/me/google-review-sessions/{sessionId}', name: 'customer_google_review_session_show', methods: ['GET'])]
    public function showSession(string $sessionId): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        try {
            $session = $this->journeyService->getCustomerSession($sessionId, $customer);

            return new JsonResponse($this->formatSession($session));
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    #[Route('/api/customer/me/google-review-sessions/{sessionId}/spin', name: 'customer_google_review_spin', methods: ['POST'])]
    public function spin(string $sessionId): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        try {
            $session = $this->journeyService->getCustomerSession($sessionId, $customer);
            $reward = $this->journeyService->spin($session);
            $this->entityManager->flush();

            return new JsonResponse([
                'session_id' => $reward->getSession()?->getId()?->toRfc4122(),
                'status' => $reward->getSession()?->getStatus()->value,
                'google_review_url' => $reward->getSession()?->getModule()?->getGoogleReviewUrl(),
                'reward' => $this->formatRewardPayload($reward),
            ]);
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    #[Route('/api/customer/me/google-review-rewards/{rewardId}', name: 'customer_google_review_reward_show', methods: ['GET'])]
    public function showReward(string $rewardId): JsonResponse
    {
        $customer = $this->requireCustomer();
        if (!$customer instanceof Customer) {
            return $customer;
        }

        try {
            $reward = $this->journeyService->getCustomerReward($rewardId, $customer);
            $this->journeyService->logCustomEvent(
                $reward->getSession()->getModule(),
                $customer,
                $reward->getSession(),
                GoogleReviewEventType::QR_VIEWED,
                'reward_endpoint',
            );
            $this->entityManager->flush();

            return new JsonResponse($this->formatReward($reward));
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    private function requireCustomer(): Customer|JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CUSTOMER');

        $customer = $this->getUser()?->getCustomer();
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'customer_not_found'], 404);
        }

        return $customer;
    }

    private function formatModuleSummary(MerchantGoogleReviewModule $module, ?GoogleReviewSession $session, ?GoogleReviewReward $reward): array
    {
        $merchant = $module->getMerchant();

        return [
            'merchant_id' => $merchant?->getId()?->toRfc4122(),
            'merchant_name' => $merchant?->getCompanyName(),
            'display_name' => $module->getDisplayName(),
            'merchant_logo_url' => $merchant?->getLogoUrl(),
            'google_review_url' => $module->getGoogleReviewUrl(),
            'status' => $this->journeyService->deriveCustomerStatus($session, $reward),
            'detail_route' => '/customer/google-review/' . $merchant?->getId()?->toRfc4122(),
            'active_session_id' => $session?->getId()?->toRfc4122(),
            'active_reward_id' => $reward?->getId()?->toRfc4122(),
            'show_qr_code' => $module->isShowQrCode(),
        ];
    }

    private function formatModuleDetail(MerchantGoogleReviewModule $module, ?GoogleReviewSession $session, ?GoogleReviewReward $reward): array
    {
        $merchant = $module->getMerchant();

        return [
            'merchant_id' => $merchant?->getId()?->toRfc4122(),
            'merchant_name' => $merchant?->getCompanyName(),
            'display_name' => $module->getDisplayName(),
            'merchant_logo_url' => $merchant?->getLogoUrl(),
            'google_review_url' => $module->getGoogleReviewUrl(),
            'status' => $this->journeyService->deriveCustomerStatus($session, $reward),
            'active_session' => $session ? [
                'id' => $session->getId()?->toRfc4122(),
                'status' => $session->getStatus()->value,
            ] : null,
            'active_reward' => $reward ? $this->formatRewardPayload($reward) : null,
            'show_qr_code' => $module->isShowQrCode(),
        ];
    }

    private function formatSession(GoogleReviewSession $session): array
    {
        return [
            'id' => $session->getId()?->toRfc4122(),
            'status' => $session->getStatus()->value,
            'launch_count' => $session->getLaunchCount(),
            'launched_at' => $session->getLaunchedAt()?->format(DATE_ATOM),
            'returned_at' => $session->getReturnedAt()?->format(DATE_ATOM),
            'spun_at' => $session->getSpunAt()?->format(DATE_ATOM),
            'google_review_url' => $session->getModule()?->getGoogleReviewUrl(),
            'can_spin' => $session->getStatus()->value === 'RETURNED_TO_APP',
            'reward' => $session->getReward() ? $this->formatRewardPayload($session->getReward()) : null,
        ];
    }

    private function formatReward(GoogleReviewReward $reward): array
    {
        return [
            'id' => $reward->getId()?->toRfc4122(),
            'reward_label' => $reward->getRewardLabel(),
            'reward_description' => $reward->getRewardDescription(),
            'status' => $reward->getStatus()->value,
            'qr_token' => $reward->getQrToken(),
            'qr_payload' => $reward->getQrPayload(),
            'google_review_url' => $reward->getSession()?->getModule()?->getGoogleReviewUrl(),
            'redeemed_at' => $reward->getRedeemedAt()?->format(DATE_ATOM),
        ];
    }

    private function formatRewardPayload(GoogleReviewReward $reward): array
    {
        return [
            'id' => $reward->getId()?->toRfc4122(),
            'reward_label' => $reward->getRewardLabel(),
            'reward_description' => $reward->getRewardDescription(),
            'status' => $reward->getStatus()->value,
            'qr_token' => $reward->getQrToken(),
            'qr_payload' => $reward->getQrPayload(),
        ];
    }
}