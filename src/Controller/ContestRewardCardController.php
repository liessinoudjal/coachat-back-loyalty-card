<?php

namespace App\Controller;

use App\Entity\ContestRewardCard;
use App\Entity\Customer;
use App\Entity\Merchant;
use App\Repository\ContestRewardCardRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ContestRewardCardController extends AbstractController
{
    public const QR_PREFIX = 'lacarte-contest-card:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestRewardCardRepository $cardRepository,
        private readonly NotificationService $notificationService,
    ) {
    }

    #[Route('/api/contest-reward-cards/scan', name: 'contest_reward_card_scan', methods: ['POST'])]
    public function scan(Request $request): JsonResponse
    {
        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        $rawToken = trim((string) ($payload['qr_token'] ?? ''));
        if ($rawToken === '') {
            return new JsonResponse(['error' => 'qr_token required'], 400);
        }
        if (str_starts_with($rawToken, self::QR_PREFIX)) {
            $rawToken = substr($rawToken, strlen(self::QR_PREFIX));
        }

        $amount = (int) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            return new JsonResponse(['error' => 'amount must be a positive integer'], 400);
        }

        $card = $this->cardRepository->findByWalletToken($rawToken);
        if ($card === null) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        if ($card->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'This card does not belong to your business'], 403);
        }

        if ($card->isCompleted()) {
            return new JsonResponse(['error' => 'This card is already completed'], 409);
        }

        $target = $card->getTargetValue();
        $remaining = max(0, $target - $card->getCurrentValue());
        if ($remaining <= 0) {
            return new JsonResponse(['error' => 'No remaining value on this card'], 409);
        }

        $applied = min($amount, $remaining);
        $newValue = $card->getCurrentValue() + $applied;
        $card->setCurrentValue($newValue);

        if ($newValue >= $target) {
            $card->setIsCompleted(true);
            $card->setCompletedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();

        try {
            $this->notificationService->notifyContestCardValueAdded($card, $applied);
        } catch (\Throwable $exception) {
            // Notification failures must not break the scan flow.
        }

        return new JsonResponse([
            'id' => $card->getId(),
            'wallet_token' => $card->getWalletToken(),
            'type' => $card->getType()->value,
            'title' => $card->getTitle(),
            'reward_description' => $card->getRewardDescription(),
            'current_value' => $card->getCurrentValue(),
            'target_value' => $card->getTargetValue(),
            'is_completed' => $card->isCompleted(),
            'applied_value' => $applied,
            'requested_amount' => $amount,
            'overflow' => max(0, $amount - $applied),
        ]);
    }

    #[Route('/api/customer/contest-reward-cards', name: 'customer_contest_reward_cards_list', methods: ['GET'])]
    public function customerList(): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }
        $customer = method_exists($user, 'getCustomer') ? $user->getCustomer() : null;
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'customer_not_found'], 404);
        }

        $cards = $this->cardRepository->findByCustomer($customer);

        return new JsonResponse([
            'cards' => array_map(fn (ContestRewardCard $c) => $this->formatCard($c), $cards),
        ]);
    }

    private function formatCard(ContestRewardCard $card): array
    {
        $merchant = $card->getMerchant();
        $winner = $card->getContestWinner();
        $contest = $winner?->getContest();

        return [
            'id' => $card->getId(),
            'wallet_token' => $card->getWalletToken(),
            'qr_token' => self::QR_PREFIX . $card->getWalletToken(),
            'type' => $card->getType()->value,
            'title' => $card->getTitle(),
            'reward_description' => $card->getRewardDescription(),
            'current_value' => $card->getCurrentValue(),
            'target_value' => $card->getTargetValue(),
            'is_completed' => $card->isCompleted(),
            'completed_at' => $card->getCompletedAt()?->format(DATE_ATOM),
            'created_at' => $card->getCreatedAt()?->format(DATE_ATOM),
            'merchant' => $merchant !== null ? [
                'id' => $merchant->getId()?->toRfc4122(),
                'company_name' => $merchant->getCompanyName(),
                'logo_url' => $merchant->getLogoUrl(),
            ] : null,
            'contest' => $contest !== null ? [
                'id' => $contest->getId()?->toRfc4122(),
                'title' => $contest->getTitle(),
            ] : null,
        ];
    }

    private function resolveActorMerchant(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        $merchant = method_exists($user, 'getMerchant') ? $user->getMerchant() : null;
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_EQUIPIER', $roles, true) && !in_array('ROLE_MERCHANT', $roles, true)) {
            return null;
        }

        return method_exists($user, 'getCustomer') ? $user->getCustomer()?->getStaffMerchant() : null;
    }
}
