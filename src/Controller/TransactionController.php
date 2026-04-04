<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Entity\Merchant;
use App\Entity\LoyaltyCard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TransactionController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
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
        if (!$merchant || $merchant->getUser() !== $user) {
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
                    'qr_code' => $transaction->getLoyaltyCard()->getQrCode(),
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

        $merchant = $user->getMerchant();
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

        // Update card points
        $card->setPoints($card->getPoints() + $data['points_earned'] - ($data['points_redeemed'] ?? 0));

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $transaction->getId(),
            'points_earned' => $transaction->getPointsEarned(),
            'points_redeemed' => $transaction->getPointsRedeemed(),
            'created_at' => $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
        ], 201);
    }
}