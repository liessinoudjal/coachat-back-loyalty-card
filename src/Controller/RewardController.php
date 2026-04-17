<?php

namespace App\Controller;

use App\Entity\LoyaltyCard;
use App\Entity\Merchant;
use App\Entity\Reward;
use App\Enum\RewardStatus;
use App\Service\NotificationService;
use App\Service\RewardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class RewardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly RewardService $rewardService,
    ) {}

    #[Route('/api/rewards', name: 'get_rewards', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(100, max(1, (int) $request->query->get('itemsPerPage', 20)));

        $qb = $this->entityManager->getRepository(Reward::class)
            ->createQueryBuilder('r')
            ->leftJoin('r.customer', 'c')->addSelect('c')
            ->leftJoin('r.loyaltyCard', 'lc')->addSelect('lc')
            ->leftJoin('r.loyaltyProgram', 'lp')->addSelect('lp')
            ->leftJoin('r.merchant', 'm')->addSelect('m')
            ->orderBy('r.generatedAt', 'DESC');

        $merchantFilter = $request->query->get('merchant');
        $customerEmail = $request->query->get('customer_email');
        $walletToken = $request->query->get('wallet_token');
        $statusFilter = $request->query->get('status');

        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        if ($merchantFilter && $merchantFilter !== (string) $merchant->getId()) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $qb->andWhere('r.merchant = :merchant')->setParameter('merchant', $merchant);

        if ($merchantFilter && $user) {
            $merchant = $this->entityManager->getRepository(Merchant::class)->find($merchantFilter);
            if (!$merchant instanceof Merchant || $merchant !== $user->getMerchant()) {
                return new JsonResponse(['error' => 'Merchant not found'], 404);
            }
            $qb->andWhere('r.merchant = :merchantFilter')->setParameter('merchantFilter', $merchant);
        }

        if ($customerEmail) {
            $qb->andWhere('c.email = :customerEmail')->setParameter('customerEmail', $customerEmail);
        }

        if ($walletToken) {
            $qb->andWhere('lc.walletToken = :walletToken')->setParameter('walletToken', $walletToken);
        }

        if ($statusFilter) {
            $status = RewardStatus::tryFrom((string) $statusFilter);
            if (!$status instanceof RewardStatus) {
                return new JsonResponse(['error' => 'Invalid status filter'], 400);
            }
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $rewards = $qb
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();

        $items = array_map(fn(Reward $reward) => $this->formatReward($reward, true), $rewards);

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'itemsPerPage' => $itemsPerPage,
                'total' => $total,
            ],
        ]);
    }

    #[Route('/api/rewards/from-completion', name: 'reward_from_completion', methods: ['POST'])]
    public function createFromCompletion(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $loyaltyCardId = $data['loyalty_card_id'] ?? null;
        if (!$loyaltyCardId) {
            return new JsonResponse(['error' => 'loyalty_card_id required'], 400);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($loyaltyCardId);
        if (!$card instanceof LoyaltyCard || $card->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        if (!$card->isCompleted()) {
            return new JsonResponse(['error' => 'Card is not completed'], 409);
        }

        $transactionId = isset($data['transaction_id']) ? (int) $data['transaction_id'] : null;

        $reward = $this->rewardService->createRewardFromCompletion($card, $transactionId);
        $this->entityManager->flush();

        return new JsonResponse($this->formatReward($reward));
    }

    #[Route('/api/rewards/by-card/{cardId}', name: 'reward_by_card', methods: ['GET'])]
    public function getByCardId(int $cardId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($cardId);
        if (!$card instanceof LoyaltyCard || $card->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        $reward = $this->entityManager->getRepository(Reward::class)->findOneBy(
            ['loyaltyCard' => $card, 'merchant' => $merchant],
            ['generatedAt' => 'DESC'],
        );

        if (!$reward instanceof Reward) {
            return new JsonResponse(['error' => 'Reward not found'], 404);
        }

        return new JsonResponse($this->formatReward($reward));
    }

    #[Route('/api/rewards/claim-by-qr', name: 'reward_claim_by_qr', methods: ['POST'])]
    public function claimByQr(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $rawToken = $data['qr_token'] ?? null;
        if (!is_string($rawToken) || trim($rawToken) === '') {
            return new JsonResponse(['error' => 'qr_token required'], 400);
        }

        try {
            $reward = $this->rewardService->claimByQrToken($rawToken, $user);
        } catch (\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => 'Reward not found'], 404);
        }

        if ($reward->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Reward not found'], 404);
        }

        $this->entityManager->flush();
        $this->notificationService->notifyRewardClaimed($reward);

        return new JsonResponse($this->formatReward($reward));
    }

    #[Route('/api/rewards/{id}', name: 'reward_patch', methods: ['PATCH'])]
    public function patch(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $reward = $this->entityManager->getRepository(Reward::class)->find($id);
        if (!$reward instanceof Reward || $reward->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Reward not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $statusRaw = isset($data['status']) ? (string) $data['status'] : '';
        $status = RewardStatus::tryFrom($statusRaw);

        if (!$status instanceof RewardStatus) {
            return new JsonResponse(['error' => 'status must be one of PENDING, CLAIMED, CANCELLED, EXPIRED'], 400);
        }

        if (!in_array($status, [RewardStatus::CANCELLED, RewardStatus::EXPIRED], true)) {
            return new JsonResponse(['error' => 'Only CANCELLED and EXPIRED are allowed in PATCH'], 400);
        }

        try {
            $this->rewardService->transitionStatus(
                $reward,
                $status,
                $user,
                isset($data['cancel_reason']) ? (string) $data['cancel_reason'] : null,
            );
        } catch (\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->formatReward($reward));
    }

    private function formatReward(Reward $reward, bool $includeSensitive = true): array
    {
        $payload = [
            'id' => (string) $reward->getId(),
            'loyalty_card_id' => $reward->getLoyaltyCard()?->getId(),
            'merchant_id' => (string) $reward->getMerchant()?->getId(),
            'customer_id' => $reward->getCustomer()?->getId(),
            'wallet_token' => $reward->getLoyaltyCard()?->getWalletToken(),
            'reward_description' => $reward->getRewardDescription(),
            'status' => $reward->getStatus()->value,
            'generated_at' => $reward->getGeneratedAt()?->format(DATE_ATOM),
            'claimed_at' => $reward->getClaimedAt()?->format(DATE_ATOM),
            'customer' => $reward->getCustomer() ? [
                'id' => $reward->getCustomer()->getId(),
                'name' => $reward->getCustomer()->getName(),
            ] : null,
            'merchant' => $reward->getMerchant() ? [
                'id' => (string) $reward->getMerchant()->getId(),
                'company_name' => $reward->getMerchant()->getCompanyName(),
            ] : null,
            'loyalty_program' => $reward->getLoyaltyProgram() ? [
                'id' => $reward->getLoyaltyProgram()->getId(),
                'name' => $reward->getLoyaltyProgram()->getName(),
                'type' => $reward->getLoyaltyProgram()->getType()->value,
                'reward_description' => $reward->getLoyaltyProgram()->getRewardDescription(),
            ] : null,
        ];

        if ($includeSensitive) {
            $payload['claim_qr_token'] = $reward->getClaimQrToken();
            if (isset($payload['customer']) && is_array($payload['customer'])) {
                $payload['customer']['email'] = $reward->getCustomer()?->getEmail();
            }
        }

        return $payload;
    }
}
