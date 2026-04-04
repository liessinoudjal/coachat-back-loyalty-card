<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\Merchant;
use App\Entity\LoyaltyProgram;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class LoyaltyCardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $appBaseUrl,
    ) {}

    private function formatCard(LoyaltyCard $card): array
    {
        $walletToken = $card->getWalletToken();

        return [
            'id' => $card->getId(),
            'qr_code' => $card->getQrCode(),
            'current_value' => $card->getCurrentValue(),
            'target_value' => $card->getTargetValue(),
            'is_completed' => $card->isCompleted(),
            'wallet_token' => $walletToken,
            'wallet_apple_url' => $this->appBaseUrl . '/public/wallet/apple/' . $walletToken,
            'wallet_google_url' => $this->appBaseUrl . '/public/wallet/google/' . $walletToken,
            'loyalty_program' => [
                'id' => $card->getLoyaltyProgram()->getId(),
                'name' => $card->getLoyaltyProgram()->getName(),
                'type' => $card->getLoyaltyProgram()->getType()->value,
            ],
            'customer' => $card->getCustomer() ? [
                'id' => $card->getCustomer()->getId(),
                'name' => $card->getCustomer()->getName(),
                'email' => $card->getCustomer()->getEmail(),
            ] : null,
        ];
    }

    #[Route('/api/loyalty_cards', name: 'get_loyalty_cards', methods: ['GET'])]
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

        $cards = $merchant->getLoyaltyCards();
        $data = [];
        foreach ($cards as $card) {
            $data[] = $this->formatCard($card);
        }

        return new JsonResponse($data);
    }

    #[Route('/api/loyalty_cards/by-qr/{qrCode}', name: 'get_loyalty_card_by_qr', methods: ['GET'])]
    public function getByQr(string $qrCode): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->findOneBy(['qrCode' => $qrCode]);
        if (!$card || $card->getMerchant()->getUser() !== $user) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        return new JsonResponse($this->formatCard($card));
    }

    #[Route('/api/loyalty_cards', name: 'create_loyalty_card', methods: ['POST'])]
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
        if (!isset($data['loyalty_program_id'])) {
            return new JsonResponse(['error' => 'loyalty_program_id required'], 400);
        }

        $program = $this->entityManager->getRepository(LoyaltyProgram::class)->find($data['loyalty_program_id']);
        if (!$program || $program->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Program not found'], 404);
        }

        $qrCode = uniqid('card_', true);

        $card = new LoyaltyCard();
        $card->setQrCode($qrCode);
        $card->setCurrentValue(0);
        $card->setTargetValue($data['target_value'] ?? null);
        $card->setMerchant($merchant);
        $card->setLoyaltyProgram($program);

        if (!empty($data['customer_id'])) {
            $customer = $this->entityManager->getRepository(Customer::class)->find($data['customer_id']);
            if ($customer) {
                $card->setCustomer($customer);
            }
        }

        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return new JsonResponse($this->formatCard($card), 201);
    }

    #[Route('/api/loyalty_cards/{id}', name: 'update_loyalty_card', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($id);
        if (!$card || $card->getMerchant()->getUser() !== $user) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['current_value'])) {
            $card->setCurrentValue($data['current_value']);
        }
        if (array_key_exists('target_value', $data)) {
            $card->setTargetValue($data['target_value']);
        }
        if (isset($data['is_completed'])) {
            $card->setIsCompleted($data['is_completed']);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->formatCard($card));
    }
}