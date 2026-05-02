<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Entity\Merchant;
use App\Entity\LoyaltyCard;
use App\Enum\LoyaltyProgramType;
use App\Service\AppleWalletPushService;
use App\Service\GoogleWalletSyncService;
use App\Service\NotificationService;
use App\Service\RewardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TransactionController extends AbstractController
{
    private $entityManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly AppleWalletPushService $appleWalletPushService,
        private readonly GoogleWalletSyncService $googleWalletSyncService,
        private readonly NotificationService $notificationService,
        private readonly RewardService $rewardService,
    ) {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/transactions', name: 'get_transactions', methods: ['GET'])]
    public function getAll(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchantId = $request->query->get('merchant');
        if (!$merchantId) {
            return new JsonResponse(['error' => 'merchant parameter required'], 400);
        }

        $merchant = $this->entityManager->getRepository(Merchant::class)->find($merchantId);
        $actorMerchant = $this->resolveActorMerchant();
        if (!$merchant || !$actorMerchant || $merchant !== $actorMerchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $transactions = $merchant->getTransactions();
        $data = [];
        foreach ($transactions as $transaction) {
            $data[] = [
                'id' => $transaction->getId(),
                'points_earned' => $transaction->getPointsEarned(),
                'points_redeemed' => $transaction->getPointsRedeemed(),
                'created_at' => $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
                'loyalty_card' => [
                    'id' => $transaction->getLoyaltyCard()->getId(),
                    'wallet_token' => $transaction->getLoyaltyCard()->getWalletToken(),
                ],
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/api/transactions', name: 'create_transaction', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['loyalty_card_id']) || !isset($data['points_earned'])) {
            return new JsonResponse(['error' => 'loyalty_card_id and points_earned required'], 400);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($data['loyalty_card_id']);
        if (!$card || $card->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        $transaction = new Transaction();
        $transaction->setPointsEarned($data['points_earned']);
        $transaction->setPointsRedeemed($data['points_redeemed'] ?? 0);
        $transaction->setMerchant($merchant);
        $transaction->setLoyaltyCard($card);

        $previouslyCompleted = $card->isCompleted();
        $newValue = max(0, (int) $card->getPoints() + (int) $data['points_earned'] - (int) ($data['points_redeemed'] ?? 0));
        $card->setPoints($newValue);

        $program = $card->getLoyaltyProgram();
        $targetValue = $card->getTargetValue();
        if ($targetValue === null && $program !== null) {
            $targetValue = $program->getType() === LoyaltyProgramType::POINTS
                ? $program->getPointsTarget()
                : $program->getStampTarget();
        }

        if ($targetValue !== null && $targetValue > 0 && $newValue >= $targetValue) {
            $card->setIsCompleted(true);
        }

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $justCompleted = !$previouslyCompleted && $card->isCompleted();

        if ($justCompleted) {
            $reward = $this->rewardService->createRewardFromCompletion($card, $transaction->getId());
            $this->entityManager->flush();
            $this->notificationService->notifyCardCompleted($reward);
        }

        if ((int) $transaction->getPointsEarned() > 0 && !$justCompleted) {
            $this->notificationService->notifyPointsAdded($transaction);
        }

        $this->appleWalletPushService->notifyUpdate($card->getWalletToken());
        $this->googleWalletSyncService->syncCard($card);

        return new JsonResponse([
            'id' => $transaction->getId(),
            'points_earned' => $transaction->getPointsEarned(),
            'points_redeemed' => $transaction->getPointsRedeemed(),
            'created_at' => $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
        ], 201);
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
}