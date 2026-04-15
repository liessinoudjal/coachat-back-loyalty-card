<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Merchant;
use App\Service\RefreshTokenService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use League\OAuth2\Client\Provider\GoogleUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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

    public function __construct(
        EntityManagerInterface $entityManager,
        JWTTokenManagerInterface $jwtManager,
        ClientRegistry $clientRegistry,
        RefreshTokenService $refreshTokenService
    ) {
        $this->entityManager = $entityManager;
        $this->jwtManager = $jwtManager;
        $this->clientRegistry = $clientRegistry;
        $this->refreshTokenService = $refreshTokenService;
    }

    #[Route('/api/auth/google', name: 'auth_google', methods: ['GET'])]
    public function googleAuth(Request $request): JsonResponse
    {
        $redirectUri = $request->query->get('redirect_uri');
        if (!$redirectUri) {
            return new JsonResponse(['error' => 'redirect_uri required'], 400);
        }

        /** @var OAuth2Client $client */
        $client = $this->clientRegistry->getClient('google');
        $state = bin2hex(random_bytes(16)); // Generate state for OAuth2 specification compliance
        $authUrl = $client->getOAuth2Provider()->getAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'scope' => ['openid', 'email', 'profile'],
            'state' => $state
        ]);

        return new JsonResponse([
            'redirectUrl' => $authUrl,
            'state' => $state
        ]);
    }

    #[Route('/api/auth/google/callback', name: 'auth_google_callback', methods: ['POST', 'OPTIONS'])]
    public function googleCallback(Request $request): JsonResponse
    {
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

        /** @var OAuth2Client $client */
        $client = $this->clientRegistry->getClient('google');
        $state = bin2hex(random_bytes(16));
        $authUrl = $client->getOAuth2Provider()->getAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'scope' => ['openid', 'email', 'profile'],
            'state' => $state,
        ]);

        return new JsonResponse([
            'redirectUrl' => $authUrl,
            'state' => $state,
            'merchant_ref' => trim($merchantRef),
        ]);
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
            /** @var OAuth2Client $client */
            $client = $this->clientRegistry->getClient('google');
            $accessToken = $client->getOAuth2Provider()->getAccessToken('authorization_code', [
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

            /** @var GoogleUser $googleUser */
            $googleUser = $client->fetchUserFromToken($accessToken);

            $user = $this->upsertGoogleUser($googleUser);
            $this->ensureUserRole($user, 'ROLE_CUSTOMER');

            $customerRepository = $this->entityManager->getRepository(Customer::class);
            $customer = $user->getCustomer();

            if ($customer === null && $user->getId() !== null) {
                $customer = $customerRepository->findOneBy(['user' => $user]);
            }

            if ($customer === null) {
                $customer = $customerRepository->findOneBy(['email' => $googleUser->getEmail()]);
                if ($customer instanceof Customer && $customer->getUser() === null) {
                    $customer->setUser($user);
                }
            }

            if ($customer === null) {
                $customer = new Customer();
                $customer->setName((string) ($googleUser->getName() ?? $googleUser->getEmail()));
                $customer->setEmail((string) $googleUser->getEmail());
                $customer->setUser($user);
            }

            $customer->addMerchant($merchant);
            if ($customer->getMerchant() === null) {
                $customer->setMerchant($merchant);
            }

            $this->entityManager->persist($user);
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            return new JsonResponse([
                'token' => $this->jwtManager->create($user),
                'refresh_token' => $this->refreshTokenService->createRefreshToken($user)->getToken(),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'name' => $user->getName(),
                ],
                'customer' => [
                    'id' => $customer->getId(),
                    'email' => $customer->getEmail(),
                    'name' => $customer->getName(),
                    'merchant_ref' => $merchant->getId()?->toRfc4122(),
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
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['googleId' => $googleUser->getId()]);

        if (!$user) {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $googleUser->getEmail()]);
        }

        if (!$user) {
            $user = new User();
            $user->setEmail((string) $googleUser->getEmail());
            $user->setRoles(['ROLE_USER']);
        }

        if (!$user->getGoogleId()) {
            $user->setGoogleId((string) $googleUser->getId());
        }

        if (!$user->getName()) {
            $user->setName((string) ($googleUser->getName() ?? $googleUser->getEmail()));
        }

        return $user;
    }

    private function ensureUserRole(User $user, string $role): void
    {
        $roles = $user->getRoles();
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
            $user->setRoles(array_values(array_unique($roles)));
        }
    }

    private function buildAuthSuccessResponse(User $user): JsonResponse
    {
        $jwt = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenService->createRefreshToken($user);

        return new JsonResponse([
            'token' => $jwt,
            'refresh_token' => $refreshToken->getToken(),
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
            ],
        ]);
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
            ]
        ]);
    }
}