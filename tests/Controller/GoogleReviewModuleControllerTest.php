<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\User;
use App\Enum\GoogleReviewRewardStatus;
use App\Repository\GoogleReviewRewardRepository;
use App\Repository\GoogleReviewSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleReviewModuleControllerTest extends WebTestCase
{
    public function testAuthenticatedMerchantCanReadOwnGoogleReviewConfig(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-read', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUser, 'Merchant Read');

        $client->request('GET', '/api/merchants/me/google-review-module', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['is_enabled']);
        self::assertSame('Avis Google', $payload['display_name']);
        self::assertSame([], $payload['reward_options']);
        self::assertArrayHasKey('merchant_name', $payload);
        self::assertArrayHasKey('merchant_logo_url', $payload);
        self::assertArrayHasKey('is_configuration_complete', $payload);
        self::assertArrayNotHasKey('google_place_id', $payload);
        self::assertArrayNotHasKey('google_place_name', $payload);
    }

    public function testAuthenticatedMerchantCanCreateOrUpdateCompleteConfig(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-update', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant Update');

        $client->request(
            'PUT',
            '/api/merchants/me/google-review-module',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
            ],
            content: json_encode([
                'is_enabled' => true,
                'display_name' => 'Avis Google',
                'google_review_url' => 'https://g.page/r/demo/review',
                'show_in_customer_dashboard' => true,
                'show_qr_code' => true,
                'reward_options' => [
                    [
                        'label' => 'Cafe offert',
                        'description' => null,
                        'active' => true,
                        'order' => 1,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['is_enabled']);
        self::assertSame('https://g.page/r/demo/review', $payload['google_review_url']);
        self::assertSame('Merchant Update', $payload['merchant_name']);
        self::assertTrue($payload['is_configuration_complete']);
        self::assertCount(1, $payload['reward_options']);
        self::assertArrayNotHasKey('google_place_id', $payload);
        self::assertArrayNotHasKey('google_place_name', $payload);

        $module = $this->getEntityManager()->getRepository(MerchantGoogleReviewModule::class)->findOneBy(['merchant' => $merchant]);
        self::assertInstanceOf(MerchantGoogleReviewModule::class, $module);
        self::assertTrue($module->isEnabled());
    }

    public function testActivationIsRejectedWithoutGoogleReviewUrl(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-missing-url', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUser, 'Merchant Missing URL');

        $client->request(
            'PUT',
            '/api/merchants/me/google-review-module',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
            ],
            content: json_encode([
                'is_enabled' => true,
                'reward_options' => [
                    ['label' => 'Cafe offert', 'description' => null, 'active' => true, 'order' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_url_missing', $payload['error']);
    }

    public function testActivationIsRejectedWithoutActiveRewardOption(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-missing-reward', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUser, 'Merchant Missing Reward');

        $client->request(
            'PUT',
            '/api/merchants/me/google-review-module',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
            ],
            content: json_encode([
                'is_enabled' => true,
                'google_review_url' => 'https://g.page/r/demo/review',
                'reward_options' => [
                    ['label' => 'Cafe offert', 'description' => null, 'active' => false, 'order' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_rewards_missing', $payload['error']);
    }

    public function testActivationIsRejectedWithNonGoogleUrl(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-invalid-url', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUser, 'Merchant Invalid URL');

        $client->request(
            'PUT',
            '/api/merchants/me/google-review-module',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
            ],
            content: json_encode([
                'is_enabled' => true,
                'google_review_url' => 'https://example.com/review',
                'reward_options' => [
                    ['label' => 'Cafe offert', 'description' => null, 'active' => true, 'order' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_url_invalid', $payload['error']);
    }

    public function testLegacyGooglePlaceFieldsAreIgnored(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-legacy-payload', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUser, 'Merchant Legacy Payload');

        $client->request(
            'PUT',
            '/api/merchants/me/google-review-module',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
            ],
            content: json_encode([
                'is_enabled' => true,
                'display_name' => 'Avis Google',
                'google_review_url' => 'https://g.page/r/demo/review',
                'google_place_id' => 'legacy-place-id',
                'google_place_name' => 'Legacy Place Name',
                'show_in_customer_dashboard' => true,
                'show_qr_code' => true,
                'reward_options' => [
                    [
                        'label' => 'Cafe offert',
                        'description' => null,
                        'active' => true,
                        'order' => 1,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('google_place_id', $payload);
        self::assertArrayNotHasKey('google_place_name', $payload);
        self::assertTrue($payload['is_configuration_complete']);
    }

    public function testCustomerCannotCallMerchantGoogleReviewRoutes(): void
    {
        $client = static::createClient();

        $customerUser = $this->createUser('customer-no-merchant', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer No Merchant', 'customer-no-merchant@example.com', null, null, $customerUser);
        self::assertInstanceOf(Customer::class, $customer);

        $client->request('GET', '/api/merchants/me/google-review-module', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($customerUser),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCustomerGetsOnlyActiveAndCompleteModulesForLinkedMerchants(): void
    {
        $client = static::createClient();

        $merchantUserA = $this->createUser('merchant-linked', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchantA = $this->createMerchant($merchantUserA, 'Merchant Linked');
        $this->createGoogleReviewModule($merchantA, true, 'https://g.page/r/linked/review');

        $merchantUserB = $this->createUser('merchant-incomplete', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchantB = $this->createMerchant($merchantUserB, 'Merchant Incomplete');
        $this->createGoogleReviewModule($merchantB, true, null);

        $merchantUserC = $this->createUser('merchant-unlinked', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchantC = $this->createMerchant($merchantUserC, 'Merchant Unlinked');
        $this->createGoogleReviewModule($merchantC, true, 'https://g.page/r/unlinked/review');

        $customerUser = $this->createUser('customer-linked', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Linked', 'customer-linked@example.com', null, $merchantA, $customerUser);
        $customer->addMerchant($merchantA);
        $customer->addMerchant($merchantB);
        $this->getEntityManager()->flush();

        $client->request('GET', '/api/customer/me/google-review-modules', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($customerUser),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload);
        self::assertSame($merchantA->getId()?->toRfc4122(), $payload[0]['merchant_id']);
        self::assertTrue($payload[0]['is_active']);
        self::assertSame('ready_to_launch', $payload[0]['status']);
    }

    public function testCustomerCanGetActiveRewardOptionsForSpecificMerchant(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-reward-options', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant Reward Options');

        $module = new MerchantGoogleReviewModule();
        $module->setMerchant($merchant);
        $module->setIsEnabled(true);
        $module->setDisplayName('Avis Google');
        $module->setGoogleReviewUrl('https://g.page/r/reward-options/review');
        $module->setShowInCustomerDashboard(true);
        $module->setShowQrCode(true);
        $module->setRewardOptions([
            [
                'id' => 'reward-active-1',
                'label' => 'Cafe offert',
                'description' => null,
                'active' => true,
                'order' => 1,
            ],
            [
                'id' => 'reward-inactive',
                'label' => 'Reward inactive',
                'description' => null,
                'active' => false,
                'order' => 2,
            ],
            [
                'id' => 'reward-active-2',
                'label' => 'Cookie offert',
                'description' => 'Un cookie offert',
                'active' => true,
                'order' => 3,
            ],
        ]);

        $em = $this->getEntityManager();
        $em->persist($module);

        $customerUser = $this->createUser('customer-reward-options', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer Reward Options', 'customer-reward-options@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);
        $em->flush();

        $client->request('GET', '/api/customer/me/google-review-modules/' . $merchant->getId()?->toRfc4122() . '/reward-options', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($customerUser),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($merchant->getId()?->toRfc4122(), $payload['merchant_id']);
        self::assertSame('Merchant Reward Options', $payload['merchant_name']);
        self::assertSame('https://g.page/r/reward-options/review', $payload['google_review_url']);
        self::assertCount(2, $payload['reward_options']);
        self::assertSame('reward-active-1', $payload['reward_options'][0]['id']);
        self::assertSame('reward-active-2', $payload['reward_options'][1]['id']);
    }

    public function testCustomerCannotGetRewardOptionsForUnlinkedMerchant(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-unlinked-reward-options', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant Unlinked Reward Options');
        $this->createGoogleReviewModule($merchant, true, 'https://g.page/r/unlinked-reward-options/review');

        $customerUser = $this->createUser('customer-unlinked-reward-options', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $this->createCustomer('Customer Unlinked Reward Options', 'customer-unlinked-reward-options@example.com', null, null, $customerUser);

        $client->request('GET', '/api/customer/me/google-review-modules/' . $merchant->getId()?->toRfc4122() . '/reward-options', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($customerUser),
        ]);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('merchant_not_found', $payload['error']);
    }

    public function testLaunchCreatesOrReusesValidSession(): void
    {
        $client = static::createClient();

        [$customerUser, $merchant] = $this->createCustomerWithCompleteModule('launch-flow');
        $token = $this->createJwtFor($customerUser);

        $client->request('POST', '/api/customer/me/google-review-modules/' . $merchant->getId()?->toRfc4122() . '/launch', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(200);
        $firstPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/customer/me/google-review-modules/' . $merchant->getId()?->toRfc4122() . '/launch', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(200);
        $secondPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($firstPayload['session_id'], $secondPayload['session_id']);

        $sessionRepository = $this->getEntityManager()->getRepository(\App\Entity\GoogleReviewSession::class);
        self::assertSame(1, $sessionRepository->count(['merchant' => $merchant]));

        $session = $sessionRepository->find($firstPayload['session_id']);
        self::assertSame(2, $session->getLaunchCount());
    }

    public function testReturnOnlyWorksForOwningCustomer(): void
    {
        $client = static::createClient();

        [$ownerUser, $merchant] = $this->createCustomerWithCompleteModule('return-owner');
        [$otherUser] = $this->createCustomerWithCompleteModule('return-other');

        $ownerToken = $this->createJwtFor($ownerUser);
        $client->request('POST', '/api/customer/me/google-review-modules/' . $merchant->getId()?->toRfc4122() . '/launch', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $ownerToken,
        ]);
        $launchPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $launchPayload['session_id'] . '/return', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($otherUser),
        ]);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_session_not_found', $payload['error']);
    }

    public function testSpinGeneratesOnlyOneRewardPerSession(): void
    {
        $client = static::createClient();

        [$customerUser, $merchant] = $this->createCustomerWithCompleteModule('spin-once');
        $token = $this->createJwtFor($customerUser);
        $sessionId = $this->launchAndReturn($client, $token, $merchant->getId()?->toRfc4122());

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(200);
        $firstPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(200);
        $secondPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($firstPayload['reward']['id'], $secondPayload['reward']['id']);

        /** @var GoogleReviewRewardRepository $rewardRepository */
        $rewardRepository = $this->getEntityManager()->getRepository(\App\Entity\GoogleReviewReward::class);
        /** @var GoogleReviewSessionRepository $sessionRepository */
        $sessionRepository = $this->getEntityManager()->getRepository(\App\Entity\GoogleReviewSession::class);
        $session = $sessionRepository->find($sessionId);
        self::assertSame(1, $rewardRepository->count(['session' => $session]));
    }

    public function testSpinReturnsExistingRewardIfAlreadyGenerated(): void
    {
        $client = static::createClient();

        [$customerUser, $merchant] = $this->createCustomerWithCompleteModule('spin-existing');
        $token = $this->createJwtFor($customerUser);
        $sessionId = $this->launchAndReturn($client, $token, $merchant->getId()?->toRfc4122());

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $firstPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $secondPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($firstPayload['reward']['id'], $secondPayload['reward']['id']);
        self::assertSame($firstPayload['reward']['qr_token'], $secondPayload['reward']['qr_token']);
    }

    public function testRedeemedRewardCannotBeReused(): void
    {
        $client = static::createClient();

        [$customerUser, $merchant, $merchantUser] = $this->createCustomerWithCompleteModule('redeem-once', true);
        $token = $this->createJwtFor($customerUser);
        $sessionId = $this->launchAndReturn($client, $token, $merchant->getId()?->toRfc4122());

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $spinPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $qrToken = $spinPayload['reward']['qr_token'];

        $client->request('POST', '/api/google-review-rewards/' . $qrToken . '/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->request('POST', '/api/google-review-rewards/' . $qrToken . '/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
        ]);
        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_reward_already_redeemed', $payload['error']);

        $reward = $this->getEntityManager()->getRepository(\App\Entity\GoogleReviewReward::class)->find($spinPayload['reward']['id']);
        self::assertSame(GoogleReviewRewardStatus::REDEEMED, $reward->getStatus());
    }

    public function testMerchantCannotRedeemAnotherMerchantsReward(): void
    {
        $client = static::createClient();

        [$customerUser, $merchant, $merchantUser] = $this->createCustomerWithCompleteModule('redeem-owner', true);
        [$otherCustomerUser, $otherMerchant, $otherMerchantUser] = $this->createCustomerWithCompleteModule('redeem-other', true);
        self::assertNotSame($merchant->getId()?->toRfc4122(), $otherMerchant->getId()?->toRfc4122());
        self::assertNotSame($customerUser->getId(), $otherCustomerUser->getId());

        $token = $this->createJwtFor($customerUser);
        $sessionId = $this->launchAndReturn($client, $token, $merchant->getId()?->toRfc4122());

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $spinPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/google-review-rewards/' . $spinPayload['reward']['qr_token'] . '/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($otherMerchantUser),
        ]);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_reward_not_found', $payload['error']);

        $client->request('POST', '/api/google-review-rewards/' . $spinPayload['reward']['qr_token'] . '/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testMerchantCanListOwnGoogleReviewRewardsWithFiltersAndPagination(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('merchant-list-rewards', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant List Rewards');

        $module = new MerchantGoogleReviewModule();
        $module->setMerchant($merchant);
        $module->setIsEnabled(true);
        $module->setDisplayName('Avis Google');
        $module->setGoogleReviewUrl('https://g.page/r/merchant-list-rewards/review');
        $module->setShowInCustomerDashboard(true);
        $module->setShowQrCode(true);
        $module->setRewardOptions([
            [
                'id' => 'reward-list-1',
                'label' => 'Cafe offert',
                'description' => null,
                'active' => true,
                'order' => 1,
            ],
            [
                'id' => 'reward-list-2',
                'label' => 'Cookie offert',
                'description' => null,
                'active' => true,
                'order' => 2,
            ],
        ]);

        $em = $this->getEntityManager();
        $em->persist($module);

        $firstCustomerUser = $this->createUser('customer-list-first', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $firstCustomer = $this->createCustomer('Jean Searchable', 'jean-searchable@example.com', null, $merchant, $firstCustomerUser);
        $firstCustomer->addMerchant($merchant);

        $secondCustomerUser = $this->createUser('customer-list-second', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $secondCustomer = $this->createCustomer('Alice Filtered', 'alice-filtered@example.com', null, $merchant, $secondCustomerUser);
        $secondCustomer->addMerchant($merchant);
        $em->flush();

        $firstSessionId = $this->launchAndReturn($client, $this->createJwtFor($firstCustomerUser), $merchant->getId()?->toRfc4122());
        $client->request('POST', '/api/customer/me/google-review-sessions/' . $firstSessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($firstCustomerUser),
        ]);
        self::assertResponseStatusCodeSame(200);

        $secondSessionId = $this->launchAndReturn($client, $this->createJwtFor($secondCustomerUser), $merchant->getId()?->toRfc4122());
        $client->request('POST', '/api/customer/me/google-review-sessions/' . $secondSessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($secondCustomerUser),
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->request('GET', '/api/merchants/me/google-review-rewards?status=ACTIVE&search=alice&page=1&itemsPerPage=15', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['total']);
        self::assertSame(1, $payload['page']);
        self::assertSame(15, $payload['itemsPerPage']);
        self::assertCount(1, $payload['items']);
        self::assertSame('Alice Filtered', $payload['items'][0]['customer_name']);
        self::assertSame('alice-filtered@example.com', $payload['items'][0]['customer_email']);
        self::assertSame('ACTIVE', $payload['items'][0]['status']);
    }

    public function testMerchantCanManuallyRedeemRewardById(): void
    {
        $client = static::createClient();

        [$customerUser, $merchant, $merchantUser] = $this->createCustomerWithCompleteModule('manual-redeem', true);
        $customerToken = $this->createJwtFor($customerUser);
        $sessionId = $this->launchAndReturn($client, $customerToken, $merchant->getId()?->toRfc4122());

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $customerToken,
        ]);
        self::assertResponseStatusCodeSame(200);
        $spinPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/merchants/me/google-review-rewards/' . $spinPayload['reward']['id'] . '/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($spinPayload['reward']['id'], $payload['id']);
        self::assertSame('REDEEMED', $payload['status']);
        self::assertNotNull($payload['redeemed_at']);
    }

    public function testManualRedeemReturnsExplicitConflictWhenAlreadyRedeemed(): void
    {
        $client = static::createClient();

        [$customerUser, $merchant, $merchantUser] = $this->createCustomerWithCompleteModule('manual-redeem-twice', true);
        $customerToken = $this->createJwtFor($customerUser);
        $sessionId = $this->launchAndReturn($client, $customerToken, $merchant->getId()?->toRfc4122());

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $sessionId . '/spin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $customerToken,
        ]);
        self::assertResponseStatusCodeSame(200);
        $spinPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $merchantToken = $this->createJwtFor($merchantUser);
        $client->request('POST', '/api/merchants/me/google-review-rewards/' . $spinPayload['reward']['id'] . '/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $merchantToken,
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->request('POST', '/api/merchants/me/google-review-rewards/' . $spinPayload['reward']['id'] . '/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $merchantToken,
        ]);
        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_reward_already_redeemed', $payload['error']);
    }

    private function launchAndReturn($client, string $token, string $merchantId): string
    {
        $client->request('POST', '/api/customer/me/google-review-modules/' . $merchantId . '/launch', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $launchPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/customer/me/google-review-sessions/' . $launchPayload['session_id'] . '/return', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(200);

        return $launchPayload['session_id'];
    }

    /**
     * @return array{0: User, 1: Merchant, 2?: User}
     */
    private function createCustomerWithCompleteModule(string $suffix, bool $returnMerchantUser = false): array
    {
        $merchantUser = $this->createUser('merchant-' . $suffix, ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Merchant ' . $suffix);
        $this->createGoogleReviewModule($merchant, true, 'https://g.page/r/' . $suffix . '/review');

        $customerUser = $this->createUser('customer-' . $suffix, ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Customer ' . $suffix, 'customer-' . $suffix . '@example.com', null, $merchant, $customerUser);
        $customer->addMerchant($merchant);
        $this->getEntityManager()->flush();

        return $returnMerchantUser ? [$customerUser, $merchant, $merchantUser] : [$customerUser, $merchant];
    }

    private function createUser(string $suffix, array $roles): User
    {
        $user = new User();
        $user->setEmail(sprintf('google-review-%s-%s@example.com', $suffix, bin2hex(random_bytes(4))));
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

    private function createCustomer(string $name, string $email, ?string $phone, ?Merchant $merchant, User $user): Customer
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

    private function createGoogleReviewModule(Merchant $merchant, bool $enabled, ?string $url): MerchantGoogleReviewModule
    {
        $module = new MerchantGoogleReviewModule();
        $module->setMerchant($merchant);
        $module->setIsEnabled($enabled);
        $module->setDisplayName('Avis Google');
        $module->setGoogleReviewUrl($url);
        $module->setShowInCustomerDashboard(true);
        $module->setShowQrCode(true);
        $module->setRewardOptions([
            [
                'id' => 'reward-' . bin2hex(random_bytes(4)),
                'label' => 'Cafe offert',
                'description' => null,
                'active' => true,
                'order' => 1,
            ],
        ]);

        $em = $this->getEntityManager();
        $em->persist($module);
        $em->flush();

        return $module;
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