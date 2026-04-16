<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\User;
use App\Enum\LoyaltyProgramType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CustomerSecurityControllerTest extends WebTestCase
{
    public function testCustomerCanListAvailableProgramsByMerchant(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-available-programs');
        $merchant = $this->createMerchant($merchantUser, 'Coffee Shop');

        $programActive = $this->createLoyaltyProgram($merchant, 'Programme tampons cafe', LoyaltyProgramType::STAMP, true);
        $programActive->setStampTarget(10);
        $programActive->setRewardDescription('1 cafe offert');

        $programInactive = $this->createLoyaltyProgram($merchant, 'Programme inactif', LoyaltyProgramType::POINTS, false);

        $em = $this->getEntityManager();
        $em->flush();

        $customerUser = $this->createUser('customer-available-programs', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Available', 'customer-available@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);
        $this->createLoyaltyCard($merchant, $programActive, $customer);

        $token = $this->createJwtFor($customerUser);
        $client->request('GET', '/api/customers/me/available-programs', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload);
        self::assertSame('Coffee Shop', $payload[0]['merchant']['company_name']);
        self::assertCount(1, $payload[0]['programs']);
        self::assertSame($programActive->getId(), $payload[0]['programs'][0]['id']);
        self::assertSame('STAMP', $payload[0]['programs'][0]['type']);
        self::assertTrue($payload[0]['programs'][0]['already_has_active_card']);
        self::assertSame('1 cafe offert', $payload[0]['programs'][0]['reward_description']);
        self::assertNotSame($programInactive->getId(), $payload[0]['programs'][0]['id']);
    }

    public function testCustomerCanSelfEnrollIntoProgram(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-self-enroll');
        $merchant = $this->createMerchant($merchantUser, 'Merchant Self Enroll');
        $program = $this->createLoyaltyProgram($merchant, 'Program Self Enroll', LoyaltyProgramType::STAMP, true);
        $program->setStampTarget(8);
        $this->getEntityManager()->flush();

        $customerUser = $this->createUser('customer-self-enroll', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Self Enroll', 'customer-self-enroll@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);
        $this->getEntityManager()->flush();

        $token = $this->createJwtFor($customerUser);
        $client->request(
            'POST',
            '/api/customers/me/cards',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['loyalty_program_id' => $program->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($program->getId(), $payload['loyalty_program']['id']);
        self::assertSame(0, $payload['current_value']);
        self::assertSame(8, $payload['target_value']);
        self::assertFalse($payload['is_completed']);
    }

    public function testCustomerCannotSelfEnrollTwiceIntoActiveProgram(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-self-enroll-conflict');
        $merchant = $this->createMerchant($merchantUser, 'Merchant Conflict');
        $program = $this->createLoyaltyProgram($merchant, 'Program Conflict');

        $customerUser = $this->createUser('customer-self-enroll-conflict', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Conflict', 'customer-conflict@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);
        $this->createLoyaltyCard($merchant, $program, $customer);

        $token = $this->createJwtFor($customerUser);
        $client->request(
            'POST',
            '/api/customers/me/cards',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['loyalty_program_id' => $program->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('active_card_already_exists', $payload['error']);
    }

    public function testCustomerCannotSelfEnrollWithoutMerchantRelationship(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-self-enroll-forbidden');
        $merchant = $this->createMerchant($merchantUser, 'Merchant Forbidden');
        $program = $this->createLoyaltyProgram($merchant, 'Program Forbidden');

        $customerUser = $this->createUser('customer-self-enroll-forbidden', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $this->createCustomer('Customer Forbidden', 'customer-forbidden@example.com', null, null, $customerUser);

        $token = $this->createJwtFor($customerUser);
        $client->request(
            'POST',
            '/api/customers/me/cards',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['loyalty_program_id' => $program->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not_customer_of_merchant', $payload['error']);
    }

    public function testCustomerSelfEnrollRequiresProgramId(): void
    {
        $client = static::createClient();

        $customerUser = $this->createUser('customer-self-enroll-missing', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $this->createCustomer('Customer Missing Program', 'customer-missing@example.com', null, null, $customerUser);

        $token = $this->createJwtFor($customerUser);
        $client->request(
            'POST',
            '/api/customers/me/cards',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('loyalty_program_id_required', $payload['error']);
    }

    public function testMerchantACannotSeeMerchantBCustomers(): void
    {
        $client = static::createClient();

        // Setup: Create 2 merchants with their own customers
        $userA = $this->createUser('merchant-a');
        $merchantA = $this->createMerchant($userA, 'Shop A');

        $userB = $this->createUser('merchant-b');
        $merchantB = $this->createMerchant($userB, 'Shop B');

        // Create loyalty program and cards for Merchant A
        $programA = $this->createLoyaltyProgram($merchantA, 'Program A');
        $customerA1 = $this->createCustomer('Alice', 'alice@example.com');
        $this->createLoyaltyCard($merchantA, $programA, $customerA1);

        $customerA2 = $this->createCustomer('Bob', 'bob@example.com');
        $this->createLoyaltyCard($merchantA, $programA, $customerA2);

        // Create loyalty program and cards for Merchant B
        $programB = $this->createLoyaltyProgram($merchantB, 'Program B');
        $customerB1 = $this->createCustomer('Charlie', 'charlie@example.com');
        $this->createLoyaltyCard($merchantB, $programB, $customerB1);

        // Merchant A lists customers - should see only A1 and A2
        $tokenA = $this->createJwtFor($userA);
        $client->request('GET', '/api/customers', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $payload);
        self::assertSame('Alice', $payload[0]['name']);
        self::assertSame('Bob', $payload[1]['name']);

        // Merchant B lists customers - should see only B1
        $tokenB = $this->createJwtFor($userB);
        $client->request('GET', '/api/customers', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame('Charlie', $payload[0]['name']);
    }

    public function testMerchantCannotAccessOtherMerchantCustomerById(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-access-test-a');
        $merchantA = $this->createMerchant($userA, 'Shop A Access');

        $userB = $this->createUser('merchant-access-test-b');
        $merchantB = $this->createMerchant($userB, 'Shop B Access');

        $programA = $this->createLoyaltyProgram($merchantA, 'Program A2');
        $customerA = $this->createCustomer('Alice Access', 'alice-access@example.com');
        $this->createLoyaltyCard($merchantA, $programA, $customerA);

        $programB = $this->createLoyaltyProgram($merchantB, 'Program B2');
        $customerB = $this->createCustomer('Charlie Access', 'charlie-access@example.com');
        $this->createLoyaltyCard($merchantB, $programB, $customerB);

        $tokenB = $this->createJwtFor($userB);
        $client->request('GET', '/api/customers/' . $customerA->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
        ]);

        // Should return 404 to prevent info leakage
        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Customer not found', $payload['error']);
    }

    public function testMerchantCanAccessOwnCustomerById(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-own-access');
        $merchantA = $this->createMerchant($userA, 'Shop Own Access');

        $programA = $this->createLoyaltyProgram($merchantA, 'Program Own');
        $customerA = $this->createCustomer('Alice Own', 'alice-own@example.com', '06 12 34 56 78');
        $this->createLoyaltyCard($merchantA, $programA, $customerA);

        $tokenA = $this->createJwtFor($userA);
        $client->request('GET', '/api/customers/' . $customerA->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alice Own', $payload['name']);
        self::assertSame('alice-own@example.com', $payload['email']);
        self::assertSame('06 12 34 56 78', $payload['phone']);
    }

    public function testQueryParameterMerchantIsIgnoredForSecurity(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-query-param-a');
        $merchantA = $this->createMerchant($userA, 'Shop Query A');

        $userB = $this->createUser('merchant-query-param-b');
        $merchantB = $this->createMerchant($userB, 'Shop Query B');

        $programA = $this->createLoyaltyProgram($merchantA, 'Program Query A');
        $customerA = $this->createCustomer('Query Alice', 'query-alice@example.com');
        $this->createLoyaltyCard($merchantA, $programA, $customerA);

        $programB = $this->createLoyaltyProgram($merchantB, 'Program Query B');
        $customerB = $this->createCustomer('Query Charlie', 'query-charlie@example.com');
        $this->createLoyaltyCard($merchantB, $programB, $customerB);

        $tokenA = $this->createJwtFor($userA);
        // Attempt to access Merchant B's customers by passing merchant parameter
        $client->request('GET', '/api/customers?merchant=' . $merchantB->getId()->toRfc4122(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);

        // Should still see only Merchant A's customers (JWT is enforced)
        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame('Query Alice', $payload[0]['name']);
    }

    public function testCustomersWithoutCardsAreVisibleForOwnMerchant(): void
    {
        $client = static::createClient();

        $userC = $this->createUser('merchant-empty-list');
        $merchantC = $this->createMerchant($userC, 'Shop Empty');

        // Create a customer directly owned by this merchant, without cards.
        $this->createCustomer('Orphan Customer', 'orphan@example.com', null, $merchantC);

        $tokenC = $this->createJwtFor($userC);
        $client->request('GET', '/api/customers', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenC,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame('Orphan Customer', $payload[0]['name']);
    }

    public function testMerchantCanAccessOwnCustomerByIdWithoutCard(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-own-no-card');
        $merchantA = $this->createMerchant($userA, 'Shop Own No Card');

        $customerA = $this->createCustomer('No Card Customer', 'no-card@example.com', '06 10 20 30 40', $merchantA);

        $tokenA = $this->createJwtFor($userA);
        $client->request('GET', '/api/customers/' . $customerA->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('No Card Customer', $payload['name']);
        self::assertSame('no-card@example.com', $payload['email']);
        self::assertSame('06 10 20 30 40', $payload['phone']);
    }

    public function testMerchantCannotAccessOtherMerchantDirectCustomerByIdWithoutCard(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-no-card-owner-a');
        $merchantA = $this->createMerchant($userA, 'Shop No Card Owner A');

        $userB = $this->createUser('merchant-no-card-owner-b');
        $this->createMerchant($userB, 'Shop No Card Owner B');

        $customerA = $this->createCustomer('Direct Owned Customer', 'direct-owned@example.com', null, $merchantA);

        $tokenB = $this->createJwtFor($userB);
        $client->request('GET', '/api/customers/' . $customerA->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
        ]);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Customer not found', $payload['error']);
    }

    public function testCustomerWithMultipleCardsAppearsOnce(): void
    {
        $client = static::createClient();

        $userD = $this->createUser('merchant-duplicate-customer');
        $merchantD = $this->createMerchant($userD, 'Shop Duplicate');

        $programD1 = $this->createLoyaltyProgram($merchantD, 'Program D1');
        $programD2 = $this->createLoyaltyProgram($merchantD, 'Program D2');

        $customerD = $this->createCustomer('Alice Duplicate', 'alice-dup@example.com');
        $this->createLoyaltyCard($merchantD, $programD1, $customerD);
        $this->createLoyaltyCard($merchantD, $programD2, $customerD);

        $tokenD = $this->createJwtFor($userD);
        $client->request('GET', '/api/customers', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenD,
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // DISTINCT should ensure customer appears only once
        self::assertCount(1, $payload);
        self::assertSame('Alice Duplicate', $payload[0]['name']);
    }

    public function testUnauthorizedRequestReturns401(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/customers');

        self::assertResponseStatusCodeSame(401);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Unauthorized', $payload['error']);
    }

    public function testManualCustomerCreationIsDisabled(): void
    {
        $client = static::createClient();

        $user = $this->createUser('merchant-manual-create-disabled');
        $this->createMerchant($user, 'Shop Manual Disabled');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/customers',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'name' => 'Blocked Creation',
                'email' => 'blocked@example.com',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('manual_customer_creation_disabled', $payload['error']);
    }

    public function testMerchantCannotUpdateOtherMerchantCustomer(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-update-a');
        $merchantA = $this->createMerchant($userA, 'Shop Update A');

        $userB = $this->createUser('merchant-update-b');
        $merchantB = $this->createMerchant($userB, 'Shop Update B');

        $programA = $this->createLoyaltyProgram($merchantA, 'Program Update A');
        $customerA = $this->createCustomer('Alice Update', 'alice-update@example.com');
        $this->createLoyaltyCard($merchantA, $programA, $customerA);

        $programB = $this->createLoyaltyProgram($merchantB, 'Program Update B');
        $customerB = $this->createCustomer('Charlie Update', 'charlie-update@example.com');
        $this->createLoyaltyCard($merchantB, $programB, $customerB);

        $tokenB = $this->createJwtFor($userB);
        $client->request(
            'PUT',
            '/api/customers/' . $customerA->getId(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
            ],
            content: json_encode(['name' => 'Hacked Alice'], JSON_THROW_ON_ERROR),
        );

        // Should return 404 to prevent modification
        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Customer not found', $payload['error']);
    }

    public function testMerchantCanUpdateOwnCustomer(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-update-own');
        $merchantA = $this->createMerchant($userA, 'Shop Update Own');

        $programA = $this->createLoyaltyProgram($merchantA, 'Program Update Own');
        $customerA = $this->createCustomer('Alice Own Update', 'alice-own-update@example.com', '06 00 00 00 00');
        $this->createLoyaltyCard($merchantA, $programA, $customerA);

        $tokenA = $this->createJwtFor($userA);
        $client->request(
            'PUT',
            '/api/customers/' . $customerA->getId(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
            ],
            content: json_encode([
                'name' => 'Alice Updated',
                'phone' => '06 11 22 33 44',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alice Updated', $payload['name']);
        self::assertSame('06 11 22 33 44', $payload['phone']);
    }

    public function testMerchantCannotDeleteOtherMerchantCustomer(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-delete-a');
        $merchantA = $this->createMerchant($userA, 'Shop Delete A');

        $userB = $this->createUser('merchant-delete-b');
        $merchantB = $this->createMerchant($userB, 'Shop Delete B');

        $programA = $this->createLoyaltyProgram($merchantA, 'Program Delete A');
        $customerA = $this->createCustomer('Alice Delete', 'alice-delete@example.com');
        $this->createLoyaltyCard($merchantA, $programA, $customerA);

        $programB = $this->createLoyaltyProgram($merchantB, 'Program Delete B');
        $customerB = $this->createCustomer('Charlie Delete', 'charlie-delete@example.com');
        $this->createLoyaltyCard($merchantB, $programB, $customerB);

        $tokenB = $this->createJwtFor($userB);
        $client->request(
            'DELETE',
            '/api/customers/' . $customerA->getId(),
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
            ],
        );

        // Should return 404 to prevent deletion
        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Customer not found', $payload['error']);

        // Verify customer A still exists
        $em = $this->getEntityManager();
        $stillExists = $em->getRepository(Customer::class)->find($customerA->getId());
        self::assertNotNull($stillExists);
    }

    public function testMerchantCanDeleteOwnCustomer(): void
    {
        $client = static::createClient();

        $userA = $this->createUser('merchant-delete-own');
        $merchantA = $this->createMerchant($userA, 'Shop Delete Own');

        $programA = $this->createLoyaltyProgram($merchantA, 'Program Delete Own');
        $customerA = $this->createCustomer('Alice Own Delete', 'alice-own-delete@example.com');
        $this->createLoyaltyCard($merchantA, $programA, $customerA);

        $tokenA = $this->createJwtFor($userA);
        $customerId = $customerA->getId();

        $client->request(
            'DELETE',
            '/api/customers/' . $customerId,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
            ],
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(true, $payload['success']);

        // Verify customer is gone
        $em = $this->getEntityManager();
        $deleted = $em->getRepository(Customer::class)->find($customerId);
        self::assertNull($deleted);
    }

    private function createUser(string $suffix, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail(sprintf('customer-sec-test-%s-%s@example.com', $suffix, bin2hex(random_bytes(4))));
        $user->setName('Test User ' . $suffix);
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

    private function createLoyaltyProgram(Merchant $merchant, string $name, LoyaltyProgramType $type = LoyaltyProgramType::POINTS, bool $isActive = true): LoyaltyProgram
    {
        $program = new LoyaltyProgram();
        $program->setName($name);
        $program->setType($type);
        $program->setIsActive($isActive);
        if ($type === LoyaltyProgramType::POINTS) {
            $program->setPointsPerEuro(10);
            $program->setPointsTarget(100);
            $program->setStampTarget(null);
        } else {
            $program->setPointsPerEuro(null);
            $program->setPointsTarget(null);
            $program->setStampTarget(10);
        }
        $program->setMerchant($merchant);

        $em = $this->getEntityManager();
        $em->persist($program);
        $em->flush();

        return $program;
    }

    private function createCustomer(string $name, string $email, ?string $phone = null, ?Merchant $merchant = null, ?User $user = null): Customer
    {
        $customer = new Customer();
        $customer->setName($name);
        $customer->setEmail($email);
        if ($phone) {
            $customer->setPhone($phone);
        }
        if ($merchant) {
            $customer->setMerchant($merchant);
        }
        if ($user) {
            $customer->setUser($user);
        }

        $em = $this->getEntityManager();
        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    private function createLoyaltyCard(Merchant $merchant, LoyaltyProgram $program, Customer $customer): LoyaltyCard
    {
        $card = new LoyaltyCard();
        $card->setCurrentValue(0);
        $card->setTargetValue(100);
        $card->setMerchant($merchant);
        $card->setLoyaltyProgram($program);
        $card->setCustomer($customer);

        $em = $this->getEntityManager();
        $em->persist($card);
        $em->flush();

        return $card;
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
