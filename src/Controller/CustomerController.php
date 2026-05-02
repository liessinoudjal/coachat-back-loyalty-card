<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\LoyaltyCard;
use App\Entity\Merchant;
use App\Entity\Reward;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CustomerController extends AbstractController
{
    private $entityManager;
    private $notificationService;

    public function __construct(EntityManagerInterface $entityManager, NotificationService $notificationService)
    {
        $this->entityManager = $entityManager;
        $this->notificationService = $notificationService;
    }

    #[Route('/api/customers', name: 'create_customer', methods: ['POST'])]
    public function create(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return new JsonResponse([
            'error' => 'manual_customer_creation_disabled',
            'message' => 'Customer signup is available only via Google auth with merchant_ref QR flow.',
        ], 403);
    }

    #[Route('/api/customers/me/bootstrap', name: 'get_customer_bootstrap', methods: ['GET'])]
    public function meBootstrap(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found for user'], 404);
        }

        $merchantList = $this->getCustomerMerchants($customer);
        $preferenceMap = $this->getNotificationPreferenceMap($customer, $merchantList);
        $merchants = [];
        foreach ($merchantList as $merchant) {
            $merchantId = $merchant->getId();
            if ($merchantId === null) {
                continue;
            }

            $merchants[$merchantId->toRfc4122()] = [
                'id' => $merchantId->toRfc4122(),
                'company_name' => $merchant->getCompanyName(),
                'logo_url' => $merchant->getLogoUrl(),
                'subscription_status' => $merchant->getSubscriptionStatus(),
                'notifications' => $this->formatNotificationPreference(
                    $merchant,
                    $preferenceMap[$merchantId->toRfc4122()] ?? null,
                ),
            ];
        }

        return new JsonResponse([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
            ],
            'customer' => [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'email' => $customer->getEmail(),
                'phone' => $customer->getPhone(),
                'is_equipier' => $customer->getStaffMerchant() !== null,
                'equipier_merchant_id' => $customer->getStaffMerchant()?->getId()?->toRfc4122(),
                'equipier_assigned_at' => $customer->getStaffAssignedAt()?->format(DATE_ATOM),
            ],
            'merchants' => array_values($merchants),
        ]);
    }

    #[Route('/api/customers/me/notification-preferences', name: 'get_customer_notification_preferences', methods: ['GET'])]
    public function meNotificationPreferences(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found for user'], 404);
        }

        $merchants = $this->getCustomerMerchants($customer);
        $preferenceMap = $this->getNotificationPreferenceMap($customer, $merchants);

        $payload = array_map(function (Merchant $merchant) use ($preferenceMap): array {
            $merchantId = $merchant->getId()?->toRfc4122();

            return [
                'merchant' => [
                    'id' => $merchantId,
                    'company_name' => $merchant->getCompanyName(),
                    'logo_url' => $merchant->getLogoUrl(),
                    'subscription_status' => $merchant->getSubscriptionStatus(),
                ],
                'notifications' => $this->formatNotificationPreference(
                    $merchant,
                    $merchantId !== null ? ($preferenceMap[$merchantId] ?? null) : null,
                ),
            ];
        }, $merchants);

        return new JsonResponse(array_values($payload));
    }

    #[Route('/api/customers/me/notification-preferences/{merchantId}', name: 'update_customer_notification_preference', methods: ['PATCH'])]
    public function updateNotificationPreference(string $merchantId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found for user'], 404);
        }

        $merchant = $this->entityManager->getRepository(Merchant::class)->find($merchantId);
        if (!$merchant instanceof Merchant || !$this->customerHasMerchant($customer, $merchant)) {
            return new JsonResponse(['error' => 'Merchant not found for customer'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('enabled', $data) || !is_bool($data['enabled'])) {
            return new JsonResponse(['error' => 'enabled must be a boolean'], 400);
        }

        $preferenceRepository = $this->entityManager->getRepository(CustomerMerchantNotificationPreference::class);
        $preference = $preferenceRepository->findOneBy([
            'customer' => $customer,
            'merchant' => $merchant,
        ]);

        if (!$preference instanceof CustomerMerchantNotificationPreference) {
            $preference = new CustomerMerchantNotificationPreference();
            $preference->setCustomer($customer);
            $preference->setMerchant($merchant);
            $this->entityManager->persist($preference);
        }

        $preference->setEnabled($data['enabled']);
        $this->entityManager->flush();

        return new JsonResponse([
            'merchant' => [
                'id' => $merchant->getId()?->toRfc4122(),
                'company_name' => $merchant->getCompanyName(),
                'logo_url' => $merchant->getLogoUrl(),
                'subscription_status' => $merchant->getSubscriptionStatus(),
            ],
            'notifications' => $this->formatNotificationPreference($merchant, $preference),
        ]);
    }

    #[Route('/api/customers/me/available-programs', name: 'get_customer_available_programs', methods: ['GET'])]
    public function meAvailablePrograms(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found for user'], 404);
        }

        $merchants = $this->getCustomerMerchants($customer);
        $merchantMap = [];
        foreach ($merchants as $merchant) {
            $merchantId = $merchant->getId()?->toRfc4122();
            if ($merchantId === null) {
                continue;
            }

            $merchantMap[$merchantId] = [
                'merchant' => [
                    'id' => $merchantId,
                    'company_name' => $merchant->getCompanyName(),
                    'logo_url' => $merchant->getLogoUrl(),
                ],
                'programs' => [],
            ];
        }

        if ($merchantMap === []) {
            return new JsonResponse([]);
        }

        $activeCards = $this->entityManager->getRepository(LoyaltyCard::class)
            ->createQueryBuilder('card')
            ->addSelect('program')
            ->leftJoin('card.loyaltyProgram', 'program')
            ->andWhere('card.customer = :customer')
            ->andWhere('card.isCompleted = :isCompleted')
            ->setParameter('customer', $customer)
            ->setParameter('isCompleted', false)
            ->getQuery()
            ->getResult();

        $activeProgramIds = [];
        foreach ($activeCards as $activeCard) {
            $programId = $activeCard->getLoyaltyProgram()?->getId();
            if ($programId !== null) {
                $activeProgramIds[$programId] = true;
            }
        }

        foreach ($merchants as $merchant) {
            $merchantId = $merchant->getId()?->toRfc4122();
            if ($merchantId === null || !isset($merchantMap[$merchantId])) {
                continue;
            }

            $activePrograms = $merchant->getLoyaltyPrograms()
                ->filter(static fn ($program) => $program->isActive())
                ->toArray();

            usort($activePrograms, static fn ($leftProgram, $rightProgram) => strcmp((string) $leftProgram->getName(), (string) $rightProgram->getName()));

            foreach ($activePrograms as $program) {
                $merchantMap[$merchantId]['programs'][] = $this->formatAvailableProgram(
                    $program,
                    isset($activeProgramIds[$program->getId() ?? 0]),
                );
            }
        }

        return new JsonResponse(array_values($merchantMap));
    }

    #[Route('/api/customers/me/cards', name: 'create_customer_card_self_enrollment', methods: ['POST'])]
    public function createOwnCard(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found for user'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('loyalty_program_id', $data) || $data['loyalty_program_id'] === null || $data['loyalty_program_id'] === '') {
            return new JsonResponse(['error' => 'loyalty_program_id_required'], 400);
        }

        $program = $this->entityManager->getRepository(\App\Entity\LoyaltyProgram::class)->find($data['loyalty_program_id']);
        if (!$program instanceof \App\Entity\LoyaltyProgram || !$program->isActive()) {
            return new JsonResponse(['error' => 'program_not_found'], 404);
        }

        $merchant = $program->getMerchant();
        if (!$merchant instanceof Merchant || !$this->customerHasMerchant($customer, $merchant)) {
            return new JsonResponse(['error' => 'not_customer_of_merchant'], 403);
        }

        $existingActiveCard = $this->entityManager->getRepository(LoyaltyCard::class)->findOneBy([
            'customer' => $customer,
            'loyaltyProgram' => $program,
            'isCompleted' => false,
        ]);

        if ($existingActiveCard instanceof LoyaltyCard) {
            return new JsonResponse(['error' => 'active_card_already_exists'], 409);
        }

        $card = new LoyaltyCard();
        $card->setCustomer($customer);
        $card->setMerchant($merchant);
        $card->setLoyaltyProgram($program);
        $card->setCurrentValue(0);
        $card->setTargetValue($program->getType()->value === 'POINTS' ? $program->getPointsTarget() : $program->getStampTarget());
        $card->setIsCompleted(false);

        $this->entityManager->persist($card);
        $this->entityManager->flush();
        $this->notificationService->notifyCardCreated($card);

        return new JsonResponse($this->formatCustomerCard($card), 201);
    }

    #[Route('/api/customers/me/cards', name: 'get_customer_cards', methods: ['GET'])]
    public function meCards(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found for user'], 404);
        }

        $cards = [];
        foreach ($customer->getLoyaltyCards() as $card) {
            if (!$card->isVisible()) {
                continue;
            }

            $cards[] = $this->formatCustomerCard($card);
        }

        return new JsonResponse($cards);
    }

    #[Route('/api/customers/me/rewards', name: 'get_customer_rewards', methods: ['GET'])]
    public function meRewards(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found for user'], 404);
        }

        $rewards = $this->entityManager->getRepository(Reward::class)
            ->createQueryBuilder('r')
            ->leftJoin('r.loyaltyCard', 'lc')->addSelect('lc')
            ->leftJoin('r.merchant', 'm')->addSelect('m')
            ->leftJoin('r.loyaltyProgram', 'lp')->addSelect('lp')
            ->andWhere('r.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('r.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $payload = array_map(static function (Reward $reward): array {
            return [
                'id' => (string) $reward->getId(),
                'loyalty_card_id' => $reward->getLoyaltyCard()?->getId(),
                'merchant_id' => $reward->getMerchant()?->getId()?->toRfc4122(),
                'wallet_token' => $reward->getLoyaltyCard()?->getWalletToken(),
                'reward_description' => $reward->getRewardDescription(),
                'status' => $reward->getStatus()->value,
                'generated_at' => $reward->getGeneratedAt()?->format(DATE_ATOM),
                'claimed_at' => $reward->getClaimedAt()?->format(DATE_ATOM),
                'claim_qr_token' => $reward->getClaimQrToken(),
                'merchant' => $reward->getMerchant() ? [
                    'id' => $reward->getMerchant()?->getId()?->toRfc4122(),
                    'company_name' => $reward->getMerchant()?->getCompanyName(),
                    'logo_url' => $reward->getMerchant()?->getLogoUrl(),
                ] : null,
                'loyalty_program' => $reward->getLoyaltyProgram() ? [
                    'id' => $reward->getLoyaltyProgram()?->getId(),
                    'name' => $reward->getLoyaltyProgram()?->getName(),
                    'type' => $reward->getLoyaltyProgram()?->getType()->value,
                ] : null,
            ];
        }, $rewards);

        return new JsonResponse($payload);
    }

    #[Route('/api/customers', name: 'get_customers', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Force merchant from JWT, ignore query parameter for security
        $customers = $this->entityManager->getRepository(Customer::class)->findByMerchant($merchant);

        return new JsonResponse(array_map(fn(Customer $c) => $this->formatMerchantCustomer($c), $customers));
    }

    #[Route('/api/customers/{id}', name: 'get_customer', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Secure lookup: customer must belong to current merchant
        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($id, $merchant);
        if (!$customer) {
            // Return 404 for both "not found" and "not authorized" to prevent info leakage
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        return new JsonResponse([
            ...$this->formatMerchantCustomer($customer),
        ]);
    }

    #[Route('/api/customers/{id}', name: 'update_customer', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Secure lookup: customer must belong to current merchant
        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($id, $merchant);
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['name'])) {
            $customer->setName($data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $customer->setPhone($data['phone']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            ...$this->formatMerchantCustomer($customer),
        ]);
    }

    #[Route('/api/customers/{id}', name: 'delete_customer', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Secure lookup: customer must belong to current merchant
        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($id, $merchant);
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        // Keep loyalty cards and detach customer link before deleting customer.
        $this->entityManager->createQueryBuilder()
            ->update(LoyaltyCard::class, 'lc')
            ->set('lc.customer', ':nullCustomer')
            ->where('lc.customer = :customer')
            ->setParameter('nullCustomer', null)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->execute();

        $this->entityManager->remove($customer);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * @return Merchant[]
     */
    private function getCustomerMerchants(Customer $customer): array
    {
        $merchants = [];

        foreach ($customer->getMerchants() as $merchant) {
            $merchantId = $merchant->getId()?->toRfc4122();
            if ($merchantId === null) {
                continue;
            }

            $merchants[$merchantId] = $merchant;
        }

        $directMerchant = $customer->getMerchant();
        if ($directMerchant instanceof Merchant && $directMerchant->getId() !== null) {
            $merchants[$directMerchant->getId()->toRfc4122()] = $directMerchant;
        }

        foreach ($customer->getLoyaltyCards() as $card) {
            $cardMerchant = $card->getMerchant();
            $cardMerchantId = $cardMerchant?->getId()?->toRfc4122();
            if ($cardMerchantId === null) {
                continue;
            }

            $merchants[$cardMerchantId] = $cardMerchant;
        }

        return array_values($merchants);
    }

    /**
     * @param Merchant[] $merchants
     *
     * @return array<string, CustomerMerchantNotificationPreference>
     */
    private function getNotificationPreferenceMap(Customer $customer, array $merchants): array
    {
        if ($merchants === []) {
            return [];
        }

        $preferences = $this->entityManager
            ->getRepository(CustomerMerchantNotificationPreference::class)
            ->findByCustomerAndMerchants($customer, $merchants);

        $map = [];
        foreach ($preferences as $preference) {
            $merchantId = $preference->getMerchant()?->getId()?->toRfc4122();
            if ($merchantId !== null) {
                $map[$merchantId] = $preference;
            }
        }

        return $map;
    }

    private function formatNotificationPreference(Merchant $merchant, ?CustomerMerchantNotificationPreference $preference): array
    {
        $pushAvailable = $merchant->getPlan()?->isHasPushNotifications() ?? false;

        return [
            'enabled' => $preference?->isEnabled() ?? true,
            'available_channels' => [
                'email' => true,
                'push' => $pushAvailable,
            ],
            'updated_at' => $preference?->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function formatAvailableProgram(\App\Entity\LoyaltyProgram $program, bool $alreadyHasActiveCard): array
    {
        return [
            'id' => $program->getId(),
            'name' => $program->getName(),
            'type' => $program->getType()->value,
            'stamp_target' => $program->getStampTarget(),
            'points_per_euro' => $program->getPointsPerEuro(),
            'points_target' => $program->getPointsTarget(),
            'reward_description' => $program->getRewardDescription(),
            'is_active' => $program->isActive(),
            'already_has_active_card' => $alreadyHasActiveCard,
        ];
    }

    private function formatCustomerCard(LoyaltyCard $card): array
    {
        $merchant = $card->getMerchant();
        $merchantId = $merchant?->getId()?->toRfc4122();
        $walletToken = $card->getWalletToken();

        return [
            'id' => $card->getId(),
            'wallet_token' => $walletToken,
            'current_value' => $card->getCurrentValue(),
            'target_value' => $card->getTargetValue(),
            'is_completed' => $card->isCompleted(),
            'wallet_apple_url' => $walletToken ? ('/public/wallet/apple/' . $walletToken) : null,
            'wallet_google_url' => $walletToken ? ('/public/wallet/google/' . $walletToken) : null,
            'merchant' => $merchant ? [
                'id' => $merchantId,
                'company_name' => $merchant->getCompanyName(),
                'logo_url' => $merchant->getLogoUrl(),
            ] : null,
            'loyalty_program' => $card->getLoyaltyProgram() ? [
                'id' => $card->getLoyaltyProgram()->getId(),
                'name' => $card->getLoyaltyProgram()->getName(),
                'type' => $card->getLoyaltyProgram()->getType()->value,
            ] : null,
        ];
    }

    private function customerHasMerchant(Customer $customer, Merchant $merchant): bool
    {
        $directMerchant = $customer->getMerchant();
        if ($directMerchant instanceof Merchant && $directMerchant === $merchant) {
            return true;
        }

        foreach ($customer->getLoyaltyCards() as $card) {
            if ($card->getMerchant() === $merchant) {
                return true;
            }
        }

        return $customer->getMerchants()->contains($merchant);
    }

    private function formatMerchantCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
            'is_equipier' => $customer->getStaffMerchant() !== null,
            'equipier_merchant_id' => $customer->getStaffMerchant()?->getId()?->toRfc4122(),
            'equipier_assigned_at' => $customer->getStaffAssignedAt()?->format(DATE_ATOM),
            'roles' => $customer->getUser()?->getRoles() ?? ['ROLE_CUSTOMER'],
        ];
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