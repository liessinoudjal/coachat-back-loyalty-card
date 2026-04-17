<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\Reward;
use App\Entity\User;
use App\Enum\LoyaltyProgramType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerRewardsControllerTest extends WebTestCase
{
    public function testCustomerCanListOwnRewards(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-owner', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant A');
        $program = $this->createLoyaltyProgram($merchant, 'Program A');

        $customerUser = $this->createUser('customer-owner', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer A', 'customer-a@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);

        $card = $this->createLoyaltyCard($merchant, $program, $customer, 10, 10, true);
        $this->createReward($card, 'Free coffee');

        $token = $this->createJwtFor($customerUser);
        $client->request('GET', '/api/customers/me/rewards', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload);
        self::assertSame($card->getId(), $payload[0]['loyalty_card_id']);
        self::assertSame('Free coffee', $payload[0]['reward_description']);
        self::assertSame('PENDING', $payload[0]['status']);
        self::assertNotNull($payload[0]['merchant']);
        self::assertNotNull($payload[0]['loyalty_program']);
        self::assertArrayHasKey('claim_qr_token', $payload[0]);
    }

    public function testCustomerCannotSeeOtherCustomerRewards(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-tenant', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant B');
        $program = $this->createLoyaltyProgram($merchant, 'Program B');

        $customerUserA = $this->createUser('customer-a', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customerA = $this->createCustomer('Customer A', 'tenant-a@example.com', null, $merchant, $customerUserA);
        $customerA->addMerchant($merchant);
        $cardA = $this->createLoyaltyCard($merchant, $program, $customerA, 10, 10, true);
        $rewardA = $this->createReward($cardA, 'Reward A');

        $customerUserB = $this->createUser('customer-b', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customerB = $this->createCustomer('Customer B', 'tenant-b@example.com', null, $merchant, $customerUserB);
        $customerB->addMerchant($merchant);
        $cardB = $this->createLoyaltyCard($merchant, $program, $customerB, 10, 10, true);
        $this->createReward($cardB, 'Reward B');

        $tokenA = $this->createJwtFor($customerUserA);
        $client->request('GET', '/api/customers/me/rewards', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload);
        self::assertSame((string) $rewardA->getId(), $payload[0]['id']);
        self::assertSame('Reward A', $payload[0]['reward_description']);
    }

    public function testMerchantTokenCannotAccessCustomerRewardsEndpoint(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-only', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUser, 'Merchant Only');

        $token = $this->createJwtFor($merchantUser);
        $client->request('GET', '/api/customers/me/rewards', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Customer not found for user', $payload['error']);
    }

    public function testPatchLoyaltyCardCompletionCreatesRewardOnce(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-patch', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant Patch');
        $program = $this->createLoyaltyProgram($merchant, 'Program Patch');

        $customerUser = $this->createUser('customer-patch', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Patch', 'customer-patch@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);

        $card = $this->createLoyaltyCard($merchant, $program, $customer, 9, 10, false);

        $token = $this->createJwtFor($merchantUser);

        $client->request(
            'PATCH',
            '/api/loyalty_cards/' . $card->getId(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['is_completed' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $em = $this->getEntityManager();
        $rewardRepo = $em->getRepository(Reward::class);
        self::assertSame(1, $rewardRepo->count(['loyaltyCard' => $card]));

        $client->request(
            'PATCH',
            '/api/loyalty_cards/' . $card->getId(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['is_completed' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $rewardRepo->count(['loyaltyCard' => $card]));
    }

    public function testMerchantCanFetchSingleLoyaltyCard(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-card-item', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant Item');
        $program = $this->createLoyaltyProgram($merchant, 'Program Item');

        $customerUser = $this->createUser('customer-card-item', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Item', 'customer-item@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);

        $card = $this->createLoyaltyCard($merchant, $program, $customer, 12, 20, false);

        $token = $this->createJwtFor($merchantUser);
        $client->request('GET', '/api/loyalty_cards/' . $card->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($card->getId(), $payload['id']);
        self::assertSame(12, $payload['current_value']);
        self::assertSame(20, $payload['target_value']);
        self::assertSame('Customer Item', $payload['customer']['name']);
        self::assertSame('Program Item', $payload['loyalty_program']['name']);
    }

    public function testTransactionCompletionFlowStillCreatesReward(): void
    {
        $client = static::createClient();

        if (!$this->hasTransactionAmountAddedColumn()) {
            self::markTestSkipped('Skipping: test database schema is missing transaction.amount_added column.');
        }

        $merchantUser = $this->createUser('merchant-transaction', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant Tx');
        $program = $this->createLoyaltyProgram($merchant, 'Program Tx');

        $customerUser = $this->createUser('customer-transaction', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Tx', 'customer-tx@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);

        $card = $this->createLoyaltyCard($merchant, $program, $customer, 9, 10, false);

        $token = $this->createJwtFor($merchantUser);

        $client->request(
            'POST',
            '/api/transactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'loyalty_card_id' => $card->getId(),
                'points_earned' => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);

        $em = $this->getEntityManager();
        $rewardRepo = $em->getRepository(Reward::class);
        self::assertSame(1, $rewardRepo->count(['loyaltyCard' => $card]));
    }

    private function hasTransactionAmountAddedColumn(): bool
    {
        $em = $this->getEntityManager();
        $connection = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['transaction'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('transaction');

        return array_key_exists('amount_added', $columns);
    }

    private function createUser(string $suffix, array $roles): User
    {
        $user = new User();
        $user->setEmail(sprintf('customer-reward-%s-%s@example.com', $suffix, bin2hex(random_bytes(4))));
        $user->setName('User ' . $suffix);
        $user->setRoles($roles);
        $user->setPassword('test-password');

        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createMerchant(User $user, string $companyName): Merchant
    {
        $merchant = new Merchant();
        $merchant->setCompanyName($companyName);
        $merchant->setEmail($user->getEmail());
        $merchant->setSubscriptionStatus('trial');
        $merchant->setTrialEndsAt((new \DateTime())->modify('+30 days'));
        $merchant->setUser($user);

        $em = $this->getEntityManager();
        $em->persist($merchant);
        $em->flush();

        return $merchant;
    }

    private function createLoyaltyProgram(Merchant $merchant, string $name): LoyaltyProgram
    {
        $program = new LoyaltyProgram();
        $program->setName($name);
        $program->setType(LoyaltyProgramType::POINTS);
        $program->setPointsPerEuro(10);
        $program->setPointsTarget(10);
        $program->setMerchant($merchant);

        $em = $this->getEntityManager();
        $em->persist($program);
        $em->flush();

        return $program;
    }

    private function createCustomer(string $name, string $email, ?string $phone, Merchant $merchant, User $user): Customer
    {
        $customer = new Customer();
        $customer->setName($name);
        $customer->setEmail($email);
        $customer->setPhone($phone);
        $customer->setMerchant($merchant);
        $customer->setUser($user);

        $em = $this->getEntityManager();
        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    private function createLoyaltyCard(Merchant $merchant, LoyaltyProgram $program, Customer $customer, int $currentValue, int $targetValue, bool $completed): LoyaltyCard
    {
        $card = new LoyaltyCard();
        $card->setCurrentValue($currentValue);
        $card->setTargetValue($targetValue);
        $card->setIsCompleted($completed);
        $card->setMerchant($merchant);
        $card->setLoyaltyProgram($program);
        $card->setCustomer($customer);

        $em = $this->getEntityManager();
        $em->persist($card);
        $em->flush();

        return $card;
    }

    private function createReward(LoyaltyCard $card, string $description): Reward
    {
        $reward = new Reward();
        $reward->setLoyaltyCard($card);
        $reward->setMerchant($card->getMerchant());
        $reward->setCustomer($card->getCustomer());
        $reward->setLoyaltyProgram($card->getLoyaltyProgram());
        $reward->setRewardDescription($description);
        $reward->setClaimQrToken(bin2hex(random_bytes(16)));

        $em = $this->getEntityManager();
        $em->persist($reward);
        $em->flush();

        return $reward;
    }

    private function createJwtFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwtManager->create($user);
    }

    private function getEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
