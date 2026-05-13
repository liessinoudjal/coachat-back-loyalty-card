<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AuthController;
use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\Merchant;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\CustomerRepository;
use App\Service\NotificationService;
use App\Service\CustomerMerchantLinker;
use App\Service\RefreshTokenService;
use App\Service\SignupAlertMailer;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class AuthControllerTest extends TestCase
{
    public function testMerchantLoginWithExistingMerchantReturnsTokens(): void
    {
        $user = $this->createUser('merchant@example.com', ['ROLE_USER'], 'google-merchant');
        $merchant = new Merchant();
        $merchant->setCompanyName('Merchant Login');
        $merchant->setUser($user);
        $user->setMerchant($merchant);

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => $criteria['googleId'] ?? null ? $user : null),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleMerchantLoginCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('jwt-token', $payload['token']);
        self::assertSame('refresh-token', $payload['refresh_token']);
        self::assertContains('ROLE_MERCHANT', $user->getRoles());
    }

    public function testMerchantLoginWithoutMerchantReturnsForbidden(): void
    {
        $user = $this->createUser('no-merchant@example.com', ['ROLE_USER'], 'google-no-merchant');

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => $criteria['googleId'] ?? null ? $user : null),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleMerchantLoginCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('merchant_not_found_for_login', $payload['error']);
    }

    public function testSuperAdminLoginWithoutMerchantReturnsTokensAndBindsGoogleIdByEmail(): void
    {
        $user = $this->createUser('user@example.com', ['ROLE_USER', 'ROLE_SUPER_ADMIN'], '');

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => match (true) {
                isset($criteria['googleId']) => null,
                ($criteria['email'] ?? null) === 'user@example.com' => $user,
                default => null,
            }),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleMerchantLoginCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('jwt-token', $payload['token']);
        self::assertContains('ROLE_SUPER_ADMIN', $user->getRoles());
        self::assertNotContains('ROLE_MERCHANT', $user->getRoles());
        self::assertSame('google-id', $user->getGoogleId());
    }

    public function testSuperAdminLoginWithoutMerchantReturnsTokensWhenMatchedByGoogleId(): void
    {
        $user = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_SUPER_ADMIN'], 'google-id');

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => match (true) {
                ($criteria['googleId'] ?? null) === 'google-id' => $user,
                default => null,
            }),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleMerchantLoginCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('jwt-token', $payload['token']);
        self::assertContains('ROLE_SUPER_ADMIN', $user->getRoles());
        self::assertNotContains('ROLE_MERCHANT', $user->getRoles());
    }

    public function testMerchantRegisterWithNewAccountReturnsTokens(): void
    {
        $capturedUser = null;
        $entityManager = $this->createEntityManager(
            userRepository: $this->createUserRepository(fn () => null),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
            onPersist: static function (object $entity) use (&$capturedUser): void {
                if ($entity instanceof User) {
                    $capturedUser = $entity;
                }
            },
        );

        $controller = $this->createControllerWithEntityManager($entityManager);

        $response = $controller->googleMerchantRegisterCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('jwt-token', $payload['token']);
        self::assertNotNull($capturedUser);
        self::assertContains('ROLE_MERCHANT', $capturedUser->getRoles());
    }

    public function testMerchantRegisterWithExistingMerchantReturnsConflict(): void
    {
        $user = $this->createUser('merchant-existing@example.com', ['ROLE_USER', 'ROLE_MERCHANT'], 'google-existing-merchant');
        $merchant = new Merchant();
        $merchant->setCompanyName('Existing Merchant');
        $merchant->setUser($user);
        $user->setMerchant($merchant);

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => $criteria['googleId'] ?? null ? $user : null),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleMerchantRegisterCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('account_already_merchant', $payload['error']);
    }

    public function testCustomerLoginDirectWithExistingCustomerReturnsTokens(): void
    {
        $user = $this->createUser('customer-login@example.com', ['ROLE_USER', 'ROLE_CUSTOMER'], 'google-customer-login');
        $customer = new Customer();
        $customer->setName('Customer Login');
        $customer->setEmail('customer-login@example.com');
        $customer->setUser($user);

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => $criteria['googleId'] ?? null ? $user : null),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleCustomerLoginCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('jwt-token', $payload['token']);
        self::assertSame('Customer Login', $payload['customer']['name']);
    }

    public function testCustomerLoginDirectWithMerchantLinkedAccountReturnsTokens(): void
    {
        $user = $this->createUser('merchant-admin@example.com', ['ROLE_USER', 'ROLE_CUSTOMER', 'ROLE_MERCHANT'], 'google-merchant-admin');
        $merchant = new Merchant();
        $merchant->setCompanyName('Merchant Admin');
        $customer = new Customer();
        $customer->setName('Merchant Admin Staff');
        $customer->setEmail('merchant-admin@example.com');
        $customer->setUser($user);
        $customer->setStaffMerchant($merchant);
        $user->setCustomer($customer);

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => $criteria['googleId'] ?? null ? $user : null),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleCustomerLoginCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('jwt-token', $payload['token']);
        self::assertContains('ROLE_CUSTOMER', $user->getRoles());
        self::assertContains('ROLE_MERCHANT', $user->getRoles());
    }

    public function testCustomerLoginDirectWithoutCustomerReturnsForbidden(): void
    {
        $user = $this->createUser('no-customer@example.com', ['ROLE_USER'], 'google-no-customer');

        $controller = $this->createController(
            userRepository: $this->createUserRepository(fn (array $criteria) => $criteria['googleId'] ?? null ? $user : null),
            customerRepository: $this->createCustomerRepository(fn () => null),
            merchantRepository: $this->createMerchantRepository(),
        );

        $response = $controller->googleCustomerLoginCallback($this->createCallbackRequest());
        $payload = $this->decodeResponse($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('customer_not_found', $payload['error']);
    }

    public function testCustomerQrSignupStillLinksCustomerToMerchant(): void
    {
        $merchant = new Merchant();
        $merchant->setCompanyName('QR Merchant');
        $merchantRef = $merchant->getId()?->toRfc4122();
        self::assertNotNull($merchantRef);

        $capturedCustomer = null;
        $capturedPreference = null;
        $entityManager = $this->createEntityManager(
            userRepository: $this->createUserRepository(fn () => null),
            customerRepository: $this->createCustomerRepository(fn () => null),
            merchantRepository: $this->createMerchantRepository(fn (mixed $id) => $merchant),
            notificationPreferenceRepository: $this->createNotificationPreferenceRepository(fn () => null),
            onPersist: static function (object $entity) use (&$capturedCustomer, &$capturedPreference): void {
                if ($entity instanceof Customer) {
                    $capturedCustomer = $entity;
                }
                if ($entity instanceof CustomerMerchantNotificationPreference) {
                    $capturedPreference = $entity;
                }
            },
        );

        $controller = $this->createControllerWithEntityManager($entityManager);

        $response = $controller->googleCustomerCallback($this->createCallbackRequest(['merchant_ref' => $merchantRef]));
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($merchantRef, $payload['customer']['merchant_ref']);
        self::assertArrayHasKey('created_at', $payload['customer']);
        self::assertNotNull($capturedCustomer);
        self::assertNotNull($capturedCustomer->getCreatedAt());
        self::assertSame($capturedCustomer->getCreatedAt()?->format(DATE_ATOM), $payload['customer']['created_at']);
        self::assertSame($merchant, $capturedCustomer->getMerchant());
        self::assertTrue($capturedCustomer->getMerchants()->contains($merchant));
        self::assertNotNull($capturedPreference);
        self::assertTrue($capturedPreference->isEnabled());
        self::assertSame($merchant, $capturedPreference->getMerchant());
        self::assertSame($capturedCustomer, $capturedPreference->getCustomer());
    }

    public function testCustomerQrSignupDispatchesSignupNotifications(): void
    {
        $merchant = new Merchant();
        $merchant->setCompanyName('QR Merchant');
        $merchantRef = $merchant->getId()?->toRfc4122();
        self::assertNotNull($merchantRef);

        $entityManager = $this->createEntityManager(
            userRepository: $this->createUserRepository(fn () => null),
            customerRepository: $this->createCustomerRepository(fn () => null),
            merchantRepository: $this->createMerchantRepository(fn (mixed $id) => $merchant),
            notificationPreferenceRepository: $this->createNotificationPreferenceRepository(fn () => null),
        );

        $signupAlertMailer = $this->createMock(SignupAlertMailer::class);
        $signupAlertMailer
            ->expects(self::once())
            ->method('notifyCustomerSignup')
            ->with(
                self::isInstanceOf(Customer::class),
                self::identicalTo($merchant),
            );

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects(self::once())
            ->method('notifyCustomerSignup')
            ->with(
                self::isInstanceOf(Customer::class),
                self::identicalTo($merchant),
            );

        $controller = $this->createControllerWithEntityManager(
            $entityManager,
            $signupAlertMailer,
            $notificationService,
        );

        $response = $controller->googleCustomerCallback($this->createCallbackRequest(['merchant_ref' => $merchantRef]));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCustomerQrSignupDoesNotDispatchNotificationsWhenAlreadyAttachedToMerchant(): void
    {
        $merchant = new Merchant();
        $merchant->setCompanyName('QR Merchant');
        $merchantRef = $merchant->getId()?->toRfc4122();
        self::assertNotNull($merchantRef);

        $existingCustomer = (new Customer())
            ->setName('Existing Customer')
            ->setEmail('user@example.com')
            ->setMerchant($merchant);
        $existingCustomer->addMerchant($merchant);
        $this->forceEntityId($existingCustomer, 42);

        $entityManager = $this->createEntityManager(
            userRepository: $this->createUserRepository(fn () => null),
            customerRepository: $this->createCustomerRepository(fn (array $criteria) => ($criteria['email'] ?? null) === 'user@example.com' ? $existingCustomer : null),
            merchantRepository: $this->createMerchantRepository(fn (mixed $id) => $merchant),
            notificationPreferenceRepository: $this->createNotificationPreferenceRepository(fn () => null),
        );

        $signupAlertMailer = $this->createMock(SignupAlertMailer::class);
        $signupAlertMailer
            ->expects(self::never())
            ->method('notifyCustomerSignup');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects(self::never())
            ->method('notifyCustomerSignup');

        $controller = $this->createControllerWithEntityManager(
            $entityManager,
            $signupAlertMailer,
            $notificationService,
        );

        $response = $controller->googleCustomerCallback($this->createCallbackRequest(['merchant_ref' => $merchantRef]));
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($merchantRef, $payload['customer']['merchant_ref']);
    }

    public function testCustomerQrAuthReturnsAuthorizationPayloadWithMerchantRef(): void
    {
        $merchant = new Merchant();
        $merchant->setCompanyName('QR Merchant');
        $merchantRef = $merchant->getId()?->toRfc4122();
        self::assertNotNull($merchantRef);

        $controller = $this->createController(
            userRepository: $this->createUserRepository(),
            customerRepository: $this->createCustomerRepository(),
            merchantRepository: $this->createMerchantRepository(fn (mixed $id) => $merchant),
        );

        $response = $controller->googleCustomerAuth(Request::create(
            '/api/auth/customer/google',
            'GET',
            [
                'merchant_ref' => $merchantRef,
                'redirect_uri' => 'https://front.example.com/auth/customer/callback',
            ],
        ));
        $payload = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($merchantRef, $payload['merchant_ref']);
        self::assertSame('https://accounts.google.com/o/oauth2/auth', $payload['redirectUrl']);
        self::assertArrayHasKey('state', $payload);
        self::assertNotSame('', $payload['state']);
    }

    public function testCustomerQrAuthReturnsLimitErrorWhenMerchantAtCapacity(): void
    {
        $merchant = new Merchant();
        $merchant->setCompanyName('Limited Merchant');
        $merchant->setPlan($this->buildPlanWithCustomerLimit(1));
        $merchantRef = $merchant->getId()?->toRfc4122();
        self::assertNotNull($merchantRef);

        $customerRepositoryService = $this->createMock(CustomerRepository::class);
        $customerRepositoryService
            ->expects(self::once())
            ->method('countByMerchant')
            ->with(self::identicalTo($merchant))
            ->willReturn(1);

        $signupAlertMailer = $this->createMock(SignupAlertMailer::class);
        $signupAlertMailer
            ->expects(self::once())
            ->method('notifyMerchantSignupRefusedDueToCustomerLimit')
            ->with(
                self::identicalTo($merchant),
                null,
                1,
                1,
                'parcours inscription Google',
            );

        $controller = $this->createControllerWithEntityManager(
            $this->createEntityManager(
                userRepository: $this->createUserRepository(),
                customerRepository: $this->createCustomerRepository(),
                merchantRepository: $this->createMerchantRepository(fn (mixed $id) => $merchant),
            ),
            $signupAlertMailer,
            null,
            $customerRepositoryService,
        );

        $response = $controller->googleCustomerAuth(Request::create(
            '/api/auth/customer/google',
            'GET',
            [
                'merchant_ref' => $merchantRef,
                'redirect_uri' => 'https://front.example.com/auth/customer/callback',
            ],
        ));
        $payload = $this->decodeResponse($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('customer_limit_reached', $payload['error']);
        self::assertSame(1, $payload['current_customers']);
        self::assertSame(1, $payload['max_customers']);
    }

    private function createController(
        EntityRepository $userRepository,
        EntityRepository $customerRepository,
        EntityRepository $merchantRepository,
        ?EntityRepository $notificationPreferenceRepository = null,
    ): AuthController {
        return $this->createControllerWithEntityManager(
            $this->createEntityManager($userRepository, $customerRepository, $merchantRepository, $notificationPreferenceRepository),
        );
    }

    private function createControllerWithEntityManager(
        EntityManagerInterface $entityManager,
        ?SignupAlertMailer $signupAlertMailer = null,
        ?NotificationService $notificationService = null,
        ?CustomerRepository $customerRepositoryService = null,
    ): AuthController
    {
        $provider = $this->createMock(AbstractProvider::class);
        $provider->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'google-access-token']));
        $provider->method('getAuthorizationUrl')->willReturn('https://accounts.google.com/o/oauth2/auth');

        $googleUser = $this->createMock(GoogleUser::class);
        $googleUser->method('getId')->willReturn('google-id');
        $googleUser->method('getEmail')->willReturn('user@example.com');
        $googleUser->method('getName')->willReturn('Google User');

        $oauthClient = $this->createMock(OAuth2Client::class);
        $oauthClient->method('getOAuth2Provider')->willReturn($provider);
        $oauthClient->method('fetchUserFromToken')->willReturn($googleUser);

        $clientRegistry = $this->createMock(ClientRegistry::class);
        $clientRegistry->method('getClient')->with('google')->willReturn($oauthClient);

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager->method('create')->willReturn('jwt-token');

        $refreshToken = new RefreshToken();
        $refreshToken->setToken('refresh-token');
        $refreshTokenService = $this->createMock(RefreshTokenService::class);
        $refreshTokenService->method('createRefreshToken')->willReturn($refreshToken);

        $signupAlertMailer ??= $this->createMock(SignupAlertMailer::class);
        $notificationService ??= $this->createMock(NotificationService::class);
        if ($customerRepositoryService === null) {
            $customerRepositoryService = $this->createMock(CustomerRepository::class);
            $customerRepositoryService->method('countByMerchant')->willReturn(0);
        }
        $notificationPreferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $notificationPreferenceRepository
            ->method('findOneByCustomerAndMerchant')
            ->willReturn(null);

        $customerMerchantLinker = new CustomerMerchantLinker(
            $entityManager,
            $customerRepositoryService,
            $notificationPreferenceRepository,
        );

        return new AuthController(
            $entityManager,
            $jwtManager,
            $clientRegistry,
            $refreshTokenService,
            new NullLogger(),
            $signupAlertMailer,
            $notificationService,
            $customerMerchantLinker,
        );
    }

    private function createEntityManager(
        EntityRepository $userRepository,
        EntityRepository $customerRepository,
        EntityRepository $merchantRepository,
        ?EntityRepository $notificationPreferenceRepository = null,
        ?callable $onPersist = null,
    ): EntityManagerInterface {
        $notificationPreferenceRepository ??= $this->createNotificationPreferenceRepository();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => match ($className) {
                User::class => $userRepository,
                Customer::class => $customerRepository,
                Merchant::class => $merchantRepository,
                CustomerMerchantNotificationPreference::class => $notificationPreferenceRepository,
                default => throw new \RuntimeException('Unexpected repository: ' . $className),
            }
        );
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use ($onPersist): void {
            if ($onPersist !== null) {
                $onPersist($entity);
            }
        });
        $entityManager->method('flush')->willReturnCallback(static function (): void {
        });

        return $entityManager;
    }

    private function createUserRepository(?callable $resolver = null): EntityRepository&MockObject
    {
        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => $resolver ? $resolver($criteria) : null
        );

        return $repository;
    }

    private function createCustomerRepository(?callable $resolver = null): EntityRepository&MockObject
    {
        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => $resolver ? $resolver($criteria) : null
        );

        return $repository;
    }

    private function createMerchantRepository(?callable $resolver = null): EntityRepository&MockObject
    {
        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository->method('find')->willReturnCallback(
            static fn (mixed $id) => $resolver ? $resolver($id) : null
        );

        return $repository;
    }

    private function buildPlanWithCustomerLimit(int $maxCustomers): \App\Entity\Plan
    {
        $plan = new \App\Entity\Plan();
        $plan->setSlug('free');
        $plan->setName('Free');
        $plan->setPriceMonthly(0);
        $plan->setMaxCustomers($maxCustomers);
        $plan->setMaxPrograms(1);
        $plan->setHasWalletIntegration(false);
        $plan->setHasPushNotifications(false);
        $plan->setHasAdvancedStats(false);
        $plan->setIsActive(true);
        $plan->setStripePriceId('price_free');

        return $plan;
    }

    private function createNotificationPreferenceRepository(?callable $resolver = null): EntityRepository&MockObject
    {
        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => $resolver ? $resolver($criteria) : null
        );

        return $repository;
    }

    private function createUser(string $email, array $roles, string $googleId): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Test User');
        $user->setRoles($roles);
        $user->setGoogleId($googleId);

        return $user;
    }

    private function forceEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function createCallbackRequest(array $extraPayload = []): Request
    {
        return Request::create(
            '/callback',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(array_merge([
                'code' => 'auth-code',
                'redirect_uri' => 'https://front.example.com/callback',
                'state' => 'state-token',
            ], $extraPayload), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
