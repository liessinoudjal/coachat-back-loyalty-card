<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\Reward;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CustomerController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
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

        $merchants = [];
        foreach ($customer->getMerchants() as $merchant) {
            $merchantId = $merchant->getId();
            if ($merchantId === null) {
                continue;
            }

            $merchants[$merchantId->toRfc4122()] = [
                'id' => $merchantId->toRfc4122(),
                'company_name' => $merchant->getCompanyName(),
                'logo_url' => $merchant->getLogoUrl(),
                'subscription_status' => $merchant->getSubscriptionStatus(),
            ];
        }

        $directMerchant = $customer->getMerchant();
        if ($directMerchant && $directMerchant->getId()) {
            $directId = $directMerchant->getId()->toRfc4122();
            if (!array_key_exists($directId, $merchants)) {
                $merchants[$directId] = [
                    'id' => $directId,
                    'company_name' => $directMerchant->getCompanyName(),
                    'logo_url' => $directMerchant->getLogoUrl(),
                    'subscription_status' => $directMerchant->getSubscriptionStatus(),
                ];
            }
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
            ],
            'merchants' => array_values($merchants),
        ]);
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

            $merchant = $card->getMerchant();
            $merchantId = $merchant?->getId()?->toRfc4122();
            $walletToken = $card->getWalletToken();

            $cards[] = [
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

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Force merchant from JWT, ignore query parameter for security
        $customers = $this->entityManager->getRepository(Customer::class)->findByMerchant($merchant);

        return new JsonResponse(array_map(fn(Customer $c) => [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'email' => $c->getEmail(),
            'phone' => $c->getPhone(),
        ], $customers));
    }

    #[Route('/api/customers/{id}', name: 'get_customer', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Secure lookup: customer must belong to current merchant
        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($id, $merchant);
        if (!$customer) {
            // Return 404 for both "not found" and "not authorized" to prevent info leakage
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        return new JsonResponse([
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
        ]);
    }

    #[Route('/api/customers/{id}', name: 'update_customer', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
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
        if (isset($data['email'])) {
            $customer->setEmail($data['email']);
        }
        if (array_key_exists('phone', $data)) {
            $customer->setPhone($data['phone']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
        ]);
    }

    #[Route('/api/customers/{id}', name: 'delete_customer', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
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
}