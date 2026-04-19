<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\GoogleReviewEventType;
use App\Exception\GoogleReviewException;
use App\Repository\MerchantRepository;
use App\Service\GoogleReviewJourneyService;
use App\Service\GoogleReviewModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class GoogleReviewEventController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MerchantRepository $merchantRepository,
        private readonly GoogleReviewModuleManager $moduleManager,
        private readonly GoogleReviewJourneyService $journeyService,
    ) {
    }

    #[Route('/api/google-review-events', name: 'google_review_event_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CUSTOMER');

        $customer = $this->getUser()?->getCustomer();
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'customer_not_found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'google_review_payload_invalid'], 400);
        }

        $merchant = $this->merchantRepository->find($payload['merchant_id'] ?? null);
        if (!$merchant instanceof Merchant || !$this->journeyService->customerHasMerchant($customer, $merchant)) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $module = $this->moduleManager->getForMerchant($merchant);
        if ($module === null) {
            return new JsonResponse(['error' => 'google_review_module_not_found'], 404);
        }

        $eventType = GoogleReviewEventType::tryFrom((string) ($payload['event_type'] ?? ''));
        if (!$eventType instanceof GoogleReviewEventType) {
            return new JsonResponse(['error' => 'google_review_event_invalid'], 422);
        }

        $session = null;
        if (isset($payload['session_id']) && is_string($payload['session_id']) && trim($payload['session_id']) !== '') {
            try {
                $session = $this->journeyService->getCustomerSession($payload['session_id'], $customer);
            } catch (GoogleReviewException $exception) {
                return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
            }
        }

        $source = isset($payload['source']) && is_string($payload['source']) && trim($payload['source']) !== ''
            ? trim($payload['source'])
            : 'customer_app';

        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : null;

        $event = $this->journeyService->logCustomEvent($module, $customer, $session, $eventType, $source, $metadata);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $event->getId()?->toRfc4122(),
            'event_type' => $event->getEventType()->value,
            'created_at' => $event->getCreatedAt()?->format(DATE_ATOM),
        ], 201);
    }
}