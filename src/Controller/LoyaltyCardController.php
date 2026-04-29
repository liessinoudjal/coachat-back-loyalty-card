<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\Merchant;
use App\Entity\LoyaltyProgram;
use App\Service\NotificationService;
use App\Service\RewardService;
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
        private readonly RewardService $rewardService,
        private readonly NotificationService $notificationService,
    ) {}

    private function formatCard(LoyaltyCard $card): array
    {
        $walletToken = $card->getWalletToken();

        return [
            'id' => $card->getId(),
            'wallet_token' => $walletToken,
            'current_value' => $card->getCurrentValue(),
            'target_value' => $card->getTargetValue(),
            'is_completed' => $card->isCompleted(),
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
            'merchant' => $card->getMerchant() ? [
                'id' => $card->getMerchant()->getId(),
                'company_name' => $card->getMerchant()->getCompanyName(),
                'logo_url' => $card->getMerchant()->getLogoUrl(),
                'phone' => $card->getMerchant()->getPhone(),
                'address' => $card->getMerchant()->getAddress(),
                'postal_code' => $card->getMerchant()->getPostalCode(),
                'city' => $card->getMerchant()->getCity(),
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
        $actorMerchant = $this->resolveActorMerchant();
        if (!$merchant || !$actorMerchant || $merchant !== $actorMerchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $cards = $merchant->getLoyaltyCards();
        $data = [];
        foreach ($cards as $card) {
            if (!$card->isVisible()) {
                continue;
            }
            $data[] = $this->formatCard($card);
        }

        return new JsonResponse($data);
    }

    #[Route('/api/loyalty_cards', name: 'create_loyalty_card', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!$this->isMerchantOwner($user)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $merchant = $this->resolveActorMerchant();
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

        $card = new LoyaltyCard();
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

    #[Route('/api/loyalty_cards/{id}', name: 'get_loyalty_card', methods: ['GET'])]
    public function getOne(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($id);
        $actorMerchant = $this->resolveActorMerchant();
        if (!$card || !$actorMerchant || $card->getMerchant() !== $actorMerchant) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        return new JsonResponse($this->formatCard($card));
    }

    #[Route('/api/loyalty_cards/{id}', name: 'update_loyalty_card', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!$this->isMerchantOwner($user)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($id);
        if (!$card || $card->getMerchant()->getUser() !== $user) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $wasCompleted = $card->isCompleted();

        if (isset($data['current_value'])) {
            $card->setCurrentValue($data['current_value']);
        }
        if (array_key_exists('target_value', $data)) {
            $card->setTargetValue($data['target_value']);
        }
        if (isset($data['is_completed'])) {
            $card->setIsCompleted($data['is_completed']);
        }

        if (!$wasCompleted && $card->isCompleted() && $card->getCustomer() !== null) {
            $reward = $this->rewardService->createRewardFromCompletion($card);
            $this->notificationService->notifyCardCompleted($reward);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->formatCard($card));
    }

    #[Route('/api/loyalty_cards/{id}/disable', name: 'disable_loyalty_card', methods: ['PATCH'])]
    public function disable(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!$this->isMerchantOwner($user)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($id);
        if (!$card || $card->getMerchant()->getUser() !== $user) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        $card->setVisible(false);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/api/loyalty_cards/{id}', name: 'delete_loyalty_card', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!$this->isMerchantOwner($user)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $card = $this->entityManager->getRepository(LoyaltyCard::class)->find($id);
        if (!$card || $card->getMerchant()->getUser() !== $user) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        // Keep historical transactions/rewards and hide the card from standard listings.
        $card->setVisible(false);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
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

        if (!in_array('ROLE_EQUIPIER', $user->getRoles(), true)) {
            return null;
        }

        return $user->getCustomer()?->getStaffMerchant();
    }

    private function isMerchantOwner(object $user): bool
    {
        return method_exists($user, 'getMerchant') && $user->getMerchant() instanceof Merchant;
    }
}