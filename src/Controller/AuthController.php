<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Merchant;
use App\Repository\UserRepository;
use App\Service\CustomerMerchantLinker;
use App\Service\NotificationService;
use App\Service\RefreshTokenService;
use App\Service\SignupAlertMailer;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use League\OAuth2\Client\Provider\GoogleUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

class AuthController extends AbstractController
{
    private $entityManager;
    private $jwtManager;
    private $clientRegistry;
    private $refreshTokenService;
    private $logger;
    private $signupAlertMailer;
    private $notificationService;
    private $customerMerchantLinker;

    public function __construct(
        EntityManagerInterface $entityManager,
        JWTTokenManagerInterface $jwtManager,
        ClientRegistry $clientRegistry,
        RefreshTokenService $refreshTokenService,
        LoggerInterface $logger,
        SignupAlertMailer $signupAlertMailer,
        NotificationService $notificationService,
        CustomerMerchantLinker $customerMerchantLinker
    ) {
        $this->entityManager = $entityManager;
        $this->jwtManager = $jwtManager;
        $this->clientRegistry = $clientRegistry;
        $this->refreshTokenService = $refreshTokenService;
        $this->logger = $logger;
        $this->signupAlertMailer = $signupAlertMailer;
        $this->notificationService = $notificationService;
        $this->customerMerchantLinker = $customerMerchantLinker;
    }

    #[Route('/api/auth/google', name: 'auth_google', methods: ['GET'])]
    public function googleAuth(Request $request): JsonResponse
    {
        $this->logger->warning('Deprecated merchant Google auth endpoint used.', [
            'route' => 'auth_google',
        ]);

        $redirectUri = $request->query->get('redirect_uri');
        if (!$redirectUri) {
            return new JsonResponse(['error' => 'redirect_uri required'], 400);
        }

        return $this->buildGoogleAuthorizationResponse($redirectUri);
    }

    #[Route('/api/auth/google/callback', name: 'auth_google_callback', methods: ['POST', 'OPTIONS'])]
    public function googleCallback(Request $request): JsonResponse
    {
        $this->logger->warning('Deprecated merchant Google callback endpoint used.', [
            'route' => 'auth_google_callback',
        ]);

        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, 200);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;
        $state = $data['state'] ?? null;

        if (!$code || !$redirectUri) {
            return new JsonResponse(['error' => 'code and redirect_uri required'], 400);
        }

        // Note: State validation is handled client-side for API consistency
        // The state parameter is included for OAuth2 specification compliance

        try {
            /** @var OAuth2Client $client */
            $client = $this->clientRegistry->getClient('google');
            $accessToken = $client->getOAuth2Provider()->getAccessToken('authorization_code', [
                'code' => $code,
                'redirect_uri' => $redirectUri
            ]);

            /** @var GoogleUser $googleUser */
            $googleUser = $client->fetchUserFromToken($accessToken);

            $user = $this->upsertGoogleUser($googleUser);

            // Keep merchant and customer login flows isolated to avoid accidental role merge.
            if ($user->getCustomer() !== null && $user->getMerchant() === null) {
                return new JsonResponse([
                    'error' => 'account_already_customer',
                    'message' => 'This Google account is already linked to a customer profile. Use customer login flow.',
                ], 409);
            }

            $this->ensureUserRole($user, 'ROLE_MERCHANT');

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $this->buildAuthSuccessResponse($user);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }
    }

    #[Route('/api/auth/merchant/google/login', name: 'auth_merchant_google_login', methods: ['GET', 'OPTIONS'])]
    public function googleMerchantLoginAuth(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, 200);
        }

        $redirectUri = $request->query->get('redirect_uri');
        if (!$redirectUri) {
            return new JsonResponse(['error' => 'redirect_uri required'], 400);
        }

        return $this->buildGoogleAuthorizationResponse($redirectUri);
    }

    #[Route('/api/auth/merchant/google/register', name: 'auth_merchant_google_register', methods: ['GET', 'OPTIONS'])]
    public function googleMerchantRegisterAuth(Request $request): JsonResponse
    {
        $redirectUri = $request->query->get('redirect_uri');
        if (!$redirectUri) {
            return new JsonResponse(['error' => 'redirect_uri required'], 400);
        }

        return $this->buildGoogleAuthorizationResponse($redirectUri);
    }

    #[Route('/api/auth/merchant/google/login/callback', name: 'auth_merchant_google_login_callback', methods: ['POST', 'OPTIONS'])]
    public function googleMerchantLoginCallback(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, 200);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;

        if (!$code || !$redirectUri) {
            return new JsonResponse(['error' => 'code and redirect_uri required'], 400);
        }

        try {
            $googleUser = $this->fetchGoogleUserFromCode($code, $redirectUri);
            $superAdminUser = $this->findSuperAdminUserForMerchantLogin($googleUser);
            $user = $superAdminUser ?? $this->upsertGoogleUser($googleUser);
            $this->syncGoogleIdentity($user, $googleUser);
            $isSuperAdmin = $superAdminUser !== null || $this->isSuperAdminUser($user);
            $equipierMerchant = $this->resolveEquipierMerchant($user);

            if ($this->isCustomerOnlyUser($user) && !$isSuperAdmin && $equipierMerchant === null) {
                return new JsonResponse([
                    'error' => 'account_already_customer',
                    'message' => 'This Google account is already linked to a customer profile. Use customer login flow.',
                ], 409);
            }

            if ($user->getMerchant() === null && !$isSuperAdmin && $equipierMerchant === null) {
                return new JsonResponse([
                    'error' => 'merchant_not_found_for_login',
                    'message' => 'No merchant account is linked to this Google account. Please register first.',
                ], 403);
            }

            if (!$isSuperAdmin) {
                if ($user->getMerchant() !== null || $equipierMerchant !== null) {
                    $this->ensureUserRole($user, 'ROLE_MERCHANT');
                } else {
                    $this->removeUserRole($user, 'ROLE_MERCHANT');
                }
            }
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $this->buildAuthSuccessResponse($user);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }
    }

    #[Route('/api/auth/merchant/google/register/callback', name: 'auth_merchant_google_register_callback', methods: ['POST', 'OPTIONS'])]
    public function googleMerchantRegisterCallback(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, 200);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;

        if (!$code || !$redirectUri) {
            return new JsonResponse(['error' => 'code and redirect_uri required'], 400);
        }

        try {
            $googleUser = $this->fetchGoogleUserFromCode($code, $redirectUri);
            $user = $this->upsertGoogleUser($googleUser);

            if ($this->isCustomerOnlyUser($user)) {
                return new JsonResponse([
                    'error' => 'account_already_customer',
                    'message' => 'This Google account is already linked to a customer profile. Use customer signup or login flow.',
                ], 409);
            }

            if ($user->getMerchant() !== null) {
                return new JsonResponse([
                    'error' => 'account_already_merchant',
                    'message' => 'This Google account is already linked to a merchant profile. Use merchant login flow.',
                ], 409);
            }

            $this->ensureUserRole($user, 'ROLE_MERCHANT');
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $this->buildAuthSuccessResponse($user);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }
    }

    #[Route('/api/auth/customer/google', name: 'auth_customer_google', methods: ['GET'])]
    public function googleCustomerAuth(Request $request): JsonResponse
    {
        $redirectUri = $request->query->get('redirect_uri');
        if (!$redirectUri) {
            return new JsonResponse(['error' => 'redirect_uri required'], 400);
        }

        $merchantRef = $request->query->get('merchant_ref');
        if (!is_string($merchantRef) || trim($merchantRef) === '') {
            return new JsonResponse(['error' => 'merchant_ref_missing'], 422);
        }

        $merchant = $this->resolveMerchantByRef(trim($merchantRef));
        if ($merchant === null) {
            return new JsonResponse(['error' => 'merchant_ref_invalid'], 422);
        }
        if ($merchant->getSubscriptionStatus() === 'canceled') {
            return new JsonResponse(['error' => 'merchant_ref_inactive'], 422);
        }

        $response = $this->buildGoogleAuthorizationPayload($redirectUri);

        return new JsonResponse([
            'redirectUrl' => $response['redirectUrl'],
            'state' => $response['state'],
            'merchant_ref' => trim($merchantRef),
        ]);
    }

    #[Route('/api/auth/customer/google/login', name: 'auth_customer_google_login', methods: ['GET'])]
    public function googleCustomerLoginAuth(Request $request): JsonResponse
    {
        $redirectUri = $request->query->get('redirect_uri');
        if (!$redirectUri) {
            return new JsonResponse(['error' => 'redirect_uri required'], 400);
        }

        return $this->buildGoogleAuthorizationResponse($redirectUri);
    }

    #[Route('/api/auth/customer/google/callback', name: 'auth_customer_google_callback', methods: ['POST', 'OPTIONS'])]
    public function googleCustomerCallback(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, 200);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;
        $merchantRef = $data['merchant_ref'] ?? null;

        if (!$code || !$redirectUri) {
            return new JsonResponse(['error' => 'code and redirect_uri required'], 400);
        }
        if (!is_string($merchantRef) || trim($merchantRef) === '') {
            return new JsonResponse(['error' => 'merchant_ref_missing'], 422);
        }

        $merchant = $this->resolveMerchantByRef(trim($merchantRef));
        if ($merchant === null) {
            return new JsonResponse(['error' => 'merchant_ref_invalid'], 422);
        }
        if ($merchant->getSubscriptionStatus() === 'canceled') {
            return new JsonResponse(['error' => 'merchant_ref_inactive'], 422);
        }

        try {
            $googleUser = $this->fetchGoogleUserFromCode($code, $redirectUri);

            $user = $this->upsertGoogleUser($googleUser);

            // Keep merchant and customer login flows isolated to avoid accidental role merge.
            if ($user->getMerchant() !== null && $user->getCustomer() === null) {
                return new JsonResponse([
                    'error' => 'account_already_merchant',
                    'message' => 'This Google account is already linked to a merchant profile. Use merchant login flow.',
                ], 409);
            }

            $this->ensureUserRole($user, 'ROLE_CUSTOMER');

            $customerRepository = $this->entityManager->getRepository(Customer::class);
            $customer = $user->getCustomer();

            if ($customer === null && $user->getId() !== null) {
                $customer = $customerRepository->findOneBy(['user' => $user]);
            }

            if ($customer === null) {
                $customer = $customerRepository->findOneBy(['email' => $googleUser->getEmail()]);
            }

            if ($customer instanceof Customer && $this->canBindCustomerToUser($customer, $user)) {
                $customer->setUser($user);
            }

            if ($customer === null) {
                $customer = new Customer();
                $customer->setName((string) ($googleUser->getName() ?? $googleUser->getEmail()));
                $customer->setEmail((string) $googleUser->getEmail());
                $customer->setUser($user);
                $customer->setCreatedAt(new \DateTimeImmutable());
            }

            $shouldNotifyCustomerSignup = $customer->getId() === null;
            $isNewMerchantLink = $this->customerMerchantLinker->link($customer, $merchant);
            if ($isNewMerchantLink) {
                $shouldNotifyCustomerSignup = true;
            }

            $this->entityManager->persist($user);
            $this->entityManager->persist($customer);
            $this->entityManager->flush();
            if ($shouldNotifyCustomerSignup) {
                $this->signupAlertMailer->notifyCustomerSignup($customer, $merchant);
                $this->notificationService->notifyCustomerSignup($customer, $merchant);
            }

            return new JsonResponse([
                'token' => $this->jwtManager->create($user),
                'refresh_token' => $this->refreshTokenService->createRefreshToken($user)->getToken(),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'name' => $user->getName(),
                    'roles' => $user->getRoles(),
                ],
                'customer' => [
                    'id' => $customer->getId(),
                    'email' => $customer->getEmail(),
                    'name' => $customer->getName(),
                    'created_at' => $customer->getCreatedAt()?->format(DATE_ATOM),
                    'merchant_ref' => $merchant->getId()?->toRfc4122(),
                    'is_equipier' => $customer->getStaffMerchant() !== null,
                    'equipier_merchant_id' => $customer->getStaffMerchant()?->getId()?->toRfc4122(),
                ],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }
    }

    #[Route('/api/auth/customer/google/login/callback', name: 'auth_customer_google_login_callback', methods: ['POST', 'OPTIONS'])]
    public function googleCustomerLoginCallback(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, 200);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;

        if (!$code || !$redirectUri) {
            return new JsonResponse(['error' => 'code and redirect_uri required'], 400);
        }

        try {
            $googleUser = $this->fetchGoogleUserFromCode($code, $redirectUri);
            $user = $this->upsertGoogleUser($googleUser);

            $customer = $this->resolveCustomerForUser($user, $googleUser);
            if (!$this->hasCustomerAccess($user, $customer)) {
                return new JsonResponse([
                    'error' => 'customer_not_found',
                    'message' => 'No customer account is linked to this Google account yet. Please sign up first via the merchant QR code.',
                ], 403);
            }

            $this->ensureUserRole($user, 'ROLE_CUSTOMER');

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return new JsonResponse([
                'token' => $this->jwtManager->create($user),
                'refresh_token' => $this->refreshTokenService->createRefreshToken($user)->getToken(),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'name' => $user->getName(),
                    'roles' => $user->getRoles(),
                ],
                'customer' => [
                    'id' => $customer->getId(),
                    'email' => $customer->getEmail(),
                    'name' => $customer->getName(),
                    'created_at' => $customer->getCreatedAt()?->format(DATE_ATOM),
                    'is_equipier' => $customer->getStaffMerchant() !== null,
                    'equipier_merchant_id' => $customer->getStaffMerchant()?->getId()?->toRfc4122(),
                ],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }
    }

    #[Route('/api/profile', name: 'profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
        ]);
    }

    private function upsertGoogleUser(GoogleUser $googleUser): User
    {
        $user = $this->findUserByGoogleLogin($googleUser);

        if (!$user) {
            $user = new User();
            $user->setEmail($this->normalizeGoogleEmail((string) $googleUser->getEmail()));
            $user->setRoles(['ROLE_USER']);
        }

        $this->syncGoogleIdentity($user, $googleUser);

        return $user;
    }

    private function syncGoogleIdentity(User $user, GoogleUser $googleUser): void
    {
        if (!$user->getGoogleId()) {
            $user->setGoogleId(trim((string) $googleUser->getId()));
        }

        if (!$user->getEmail()) {
            $user->setEmail($this->normalizeGoogleEmail((string) $googleUser->getEmail()));
        }

        if (!$user->getName()) {
            $user->setName((string) ($googleUser->getName() ?? $googleUser->getEmail()));
        }
    }

    private function findUserByGoogleLogin(GoogleUser $googleUser): ?User
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $googleId = trim((string) $googleUser->getId());
        $email = $this->normalizeGoogleEmail((string) $googleUser->getEmail());

        if ($userRepository instanceof UserRepository) {
            return $userRepository->findOneByGoogleLogin($googleId, $email);
        }

        $user = $userRepository->findOneBy(['googleId' => $googleId]);
        if ($user instanceof User) {
            return $user;
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if ($user instanceof User) {
            return $user;
        }

        $lowercaseEmail = mb_strtolower($email);
        if ($lowercaseEmail !== $email) {
            $user = $userRepository->findOneBy(['email' => $lowercaseEmail]);
            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }

    private function findSuperAdminUserForMerchantLogin(GoogleUser $googleUser): ?User
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $googleId = trim((string) $googleUser->getId());
        $email = $this->normalizeGoogleEmail((string) $googleUser->getEmail());

        if ($userRepository instanceof UserRepository) {
            return $userRepository->findOneSuperAdminByGoogleLogin($googleId, $email);
        }

        $user = $this->findUserByGoogleLogin($googleUser);
        if ($user instanceof User && $this->isSuperAdminUser($user)) {
            return $user;
        }

        return null;
    }

    private function normalizeGoogleEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function ensureUserRole(User $user, string $role): void
    {
        $roles = $user->getRoles();
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
            $user->setRoles(array_values(array_unique($roles)));
        }
    }

    private function removeUserRole(User $user, string $role): void
    {
        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $currentRole): bool => $currentRole !== $role,
        ));

        $user->setRoles(array_values(array_unique($roles)));
    }

    private function buildAuthSuccessResponse(User $user): JsonResponse
    {
        $jwt = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenService->createRefreshToken($user);
        $equipierMerchant = $this->resolveEquipierMerchant($user);
        $merchant = $user->getMerchant() ?? $equipierMerchant;

        return new JsonResponse([
            'token' => $jwt,
            'refresh_token' => $refreshToken->getToken(),
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
            ],
            'merchant_context' => $merchant ? [
                'id' => $merchant->getId()?->toRfc4122(),
                'company_name' => $merchant->getCompanyName(),
                'owner_user_id' => $merchant->getUser()?->getId(),
                'is_owner' => $user->getMerchant() === $merchant,
                'is_equipier' => $equipierMerchant === $merchant,
            ] : null,
        ]);
    }

    private function buildGoogleAuthorizationResponse(string $redirectUri): JsonResponse
    {
        return new JsonResponse($this->buildGoogleAuthorizationPayload($redirectUri));
    }

    /**
     * @return array{redirectUrl: string, state: string}
     */
    private function buildGoogleAuthorizationPayload(string $redirectUri): array
    {
        /** @var OAuth2Client $client */
        $client = $this->clientRegistry->getClient('google');
        $state = bin2hex(random_bytes(16));
        $authUrl = $client->getOAuth2Provider()->getAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'scope' => ['openid', 'email', 'profile'],
            'state' => $state,
            'prompt' => 'select_account', # Force account selection on each login
        ]);

        return [
            'redirectUrl' => $authUrl,
            'state' => $state,
        ];
    }

    private function fetchGoogleUserFromCode(string $code, string $redirectUri): GoogleUser
    {
        /** @var OAuth2Client $client */
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $client->getOAuth2Provider()->getAccessToken('authorization_code', [
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        /** @var GoogleUser $googleUser */
        $googleUser = $client->fetchUserFromToken($accessToken);

        return $googleUser;
    }

    private function resolveCustomerForUser(User $user, GoogleUser $googleUser): ?Customer
    {
        $customerRepository = $this->entityManager->getRepository(Customer::class);
        $customer = $user->getCustomer();

        if ($customer === null && $user->getId() !== null) {
            $customer = $customerRepository->findOneBy(['user' => $user]);
        }

        if ($customer === null) {
            $customer = $customerRepository->findOneBy(['email' => $googleUser->getEmail()]);
        }

        if ($customer instanceof Customer && $this->canBindCustomerToUser($customer, $user)) {
            $customer->setUser($user);
        }

        return $customer;
    }

    private function hasCustomerAccess(User $user, ?Customer $customer): bool
    {
        if ($customer === null || !in_array('ROLE_CUSTOMER', $user->getRoles(), true)) {
            return false;
        }

        return $customer->getUser() === $user;
    }

    private function isCustomerOnlyUser(User $user): bool
    {
        return $user->getCustomer() !== null && $user->getMerchant() === null;
    }

    private function resolveEquipierMerchant(User $user): ?Merchant
    {
        $roles = $user->getRoles();
        if (!in_array('ROLE_EQUIPIER', $roles, true) && !in_array('ROLE_MERCHANT', $roles, true)) {
            return null;
        }

        return $user->getCustomer()?->getStaffMerchant();
    }

    private function isSuperAdminUser(User $user): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    private function canBindCustomerToUser(Customer $customer, User $user): bool
    {
        $linkedUser = $customer->getUser();

        if ($linkedUser === null || $linkedUser === $user) {
            return true;
        }

        $linkedGoogleId = $linkedUser->getGoogleId();
        $userGoogleId = $user->getGoogleId();
        if ($linkedGoogleId !== null && $userGoogleId !== null && $linkedGoogleId === $userGoogleId) {
            return true;
        }

        $linkedEmail = $linkedUser->getEmail();
        $userEmail = $user->getEmail();

        return $linkedEmail !== null
            && $userEmail !== null
            && strcasecmp($linkedEmail, $userEmail) === 0;
    }

    private function resolveMerchantByRef(string $merchantRef): ?Merchant
    {
        try {
            $merchantId = Uuid::fromString($merchantRef);
        } catch (\Throwable) {
            return null;
        }

        return $this->entityManager->getRepository(Merchant::class)->find($merchantId);
    }

    #[Route('/api/merchants/me', name: 'merchants_me', methods: ['GET'])]
    public function getMerchant(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if ($merchant === null) {
            $merchant = $this->resolveEquipierMerchant($user);
        }
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        return new JsonResponse([
            'id' => $merchant->getId(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'phone' => $merchant->getPhone(),
            'address' => $merchant->getAddress(),
            'postal_code' => $merchant->getPostalCode(),
            'city' => $merchant->getCity(),
            'logo_url' => $merchant->getLogoUrl(),
            'accepted_terms' => $merchant->isAcceptedTerms(),
            'accepted_terms_version' => $merchant->getAcceptedTermsVersion(),
            'accepted_terms_accepted_at' => $merchant->getAcceptedTermsAcceptedAt() ? (clone $merchant->getAcceptedTermsAcceptedAt())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'is_owner' => $merchant->getUser() === $user,
                'is_equipier' => in_array('ROLE_EQUIPIER', $user->getRoles(), true),
            ]
        ]);
    }

    #[Route('/api/auth/logout', name: 'logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $this->refreshTokenService->revokeUserRefreshTokens($user);

        return new JsonResponse(['message' => 'Logged out']);
    }

    #[Route('/api/auth/refresh', name: 'refresh_token', methods: ['POST'])]
    public function refreshToken(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? null;

        if (!$refreshTokenString) {
            return new JsonResponse(['error' => 'refresh_token required'], 400);
        }

        $refreshToken = $this->refreshTokenService->getValidRefreshToken($refreshTokenString);
        if (!$refreshToken) {
            return new JsonResponse(['error' => 'Invalid or expired refresh token'], 401);
        }

        $user = $refreshToken->getUser();
        $jwt = $this->jwtManager->create($user);
        $newRefreshToken = $this->refreshTokenService->createRefreshToken($user);

        // Revoke old refresh token
        $this->refreshTokenService->revokeRefreshToken($refreshToken);

        return new JsonResponse([
            'token' => $jwt,
            'refresh_token' => $newRefreshToken->getToken(),
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
            ]
        ]);
    }
}