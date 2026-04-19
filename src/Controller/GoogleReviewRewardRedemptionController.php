<?php

namespace App\Controller;

use App\Entity\GoogleReviewReward;
use App\Entity\Merchant;
use App\Enum\GoogleReviewRewardStatus;
use App\Exception\GoogleReviewException;
use App\Repository\GoogleReviewRewardRepository;
use App\Service\GoogleReviewJourneyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class GoogleReviewRewardRedemptionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleReviewJourneyService $journeyService,
        private readonly GoogleReviewRewardRepository $rewardRepository,
    ) {
    }

    #[Route('/api/merchants/me/google-review-rewards', name: 'merchant_google_review_rewards_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->getUser()?->getMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(100, max(1, (int) $request->query->get('itemsPerPage', 15)));

        $status = null;
        $statusFilter = $request->query->get('status');
        if ($statusFilter !== null && $statusFilter !== '') {
            $status = GoogleReviewRewardStatus::tryFrom((string) $statusFilter);
            if (!$status instanceof GoogleReviewRewardStatus) {
                return new JsonResponse(['error' => 'google_review_reward_status_invalid'], 422);
            }
        }

        $search = $request->query->get('search');
        $search = is_string($search) ? trim($search) : null;

        $result = $this->rewardRepository->findPaginatedForMerchant($merchant, $status, $search, $page, $itemsPerPage);

        return new JsonResponse([
            'items' => array_map($this->formatRewardListItem(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
        ]);
    }

    #[Route('/api/merchants/me/google-review-rewards/{rewardId}/redeem', name: 'merchant_google_review_reward_redeem', methods: ['POST'])]
    public function manualRedeem(string $rewardId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        try {
            $reward = $this->journeyService->redeemRewardById($rewardId, $this->getUser());
            $this->entityManager->flush();

            return new JsonResponse([
                'id' => $reward->getId()?->toRfc4122(),
                'status' => $reward->getStatus()->value,
                'redeemed_at' => $reward->getRedeemedAt()?->format(DATE_ATOM),
            ]);
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    #[Route('/api/google-review-rewards/{qrToken}/redeem', name: 'google_review_reward_redeem', methods: ['POST'])]
    public function redeem(string $qrToken): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        try {
            $reward = $this->journeyService->redeemReward($qrToken, $this->getUser());
            $this->entityManager->flush();

            return new JsonResponse([
                'id' => $reward->getId()?->toRfc4122(),
                'status' => $reward->getStatus()->value,
                'reward_label' => $reward->getRewardLabel(),
                'redeemed_at' => $reward->getRedeemedAt()?->format(DATE_ATOM),
                'session_id' => $reward->getSession()?->getId()?->toRfc4122(),
            ]);
        } catch (GoogleReviewException $exception) {
            return new JsonResponse(['error' => $exception->getErrorCode()], $exception->getStatusCode());
        }
    }

    private function formatRewardListItem(GoogleReviewReward $reward): array
    {
        return [
            'id' => $reward->getId()?->toRfc4122(),
            'merchant_id' => $reward->getMerchant()?->getId()?->toRfc4122(),
            'customer_id' => $reward->getCustomer()?->getId(),
            'customer_name' => $reward->getCustomer()?->getName(),
            'customer_email' => $reward->getCustomer()?->getEmail(),
            'reward_label' => $reward->getRewardLabel(),
            'reward_description' => $reward->getRewardDescription(),
            'status' => $reward->getStatus()->value,
            'qr_token' => $reward->getQrToken(),
            'qr_payload' => $reward->getQrPayload(),
            'created_at' => $reward->getCreatedAt()?->format(DATE_ATOM),
            'redeemed_at' => $reward->getRedeemedAt()?->format(DATE_ATOM),
        ];
    }
}