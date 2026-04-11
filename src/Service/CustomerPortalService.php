<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\CustomerPortalSession;
use App\Entity\LoyaltyCard;
use App\Exception\PortalTokenException;
use App\Repository\CustomerPortalSessionRepository;
use App\Repository\LoyaltyCardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

class CustomerPortalService
{
    public const PORTAL_COOKIE_NAME = 'customer_portal_token';

    /** Short-lived access token: 15 minutes. */
    private const TOKEN_TTL_SECONDS = 900;

    /** Maximum cumulative session chain via refresh: 24 hours. */
    private const REFRESH_WINDOW_SECONDS = 86400;

    /** Opaque token prefix — never decoded server-side, just visual hint. */
    private const TOKEN_PREFIX = 'pt_live_';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerPortalSessionRepository $sessionRepository,
        private readonly LoyaltyCardRepository $cardRepository,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {}

    /**
     * Bootstrap a portal session from a known wallet token.
     * Returns [rawToken, CustomerPortalSession].
     *
     * @throws PortalTokenException 404 if wallet_token unknown, 410 if card has no customer
     */
    public function bootstrap(string $walletToken, ?string $ip, ?string $userAgent): array
    {
        $card = $this->cardRepository->findOneBy(['walletToken' => $walletToken]);
        if (!$card instanceof LoyaltyCard) {
            throw new PortalTokenException(
                'CLAIM_WALLET_TOKEN_INVALID',
                404,
                'Wallet token inconnu.',
            );
        }

        $customer = $card->getCustomer();
        if (!$customer instanceof Customer) {
            throw new PortalTokenException(
                'CLAIM_WALLET_TOKEN_INVALID',
                410,
                'La carte ne possède pas de customer associé.',
            );
        }

        [$rawToken, $session] = $this->createSession(
            $customer,
            $walletToken,
            $ip,
            $userAgent,
            null,
        );

        $this->em->flush();

        return [$rawToken, $session];
    }

    /**
     * Validate a raw portal token extracted from Authorization: Bearer header.
     * Updates last_used_at on success.
     *
     * @throws PortalTokenException 401 invalid/revoked, 401 expired
     */
    public function validateToken(string $rawToken): CustomerPortalSession
    {
        if (!$this->isPortalToken($rawToken)) {
            throw new PortalTokenException(
                'PORTAL_TOKEN_INVALID',
                401,
                'Format du token portal invalide.',
            );
        }

        $hash = $this->hashToken($rawToken);

        $session = $this->sessionRepository->findByTokenHash($hash);

        if (!$session instanceof CustomerPortalSession) {
            throw new PortalTokenException(
                'PORTAL_TOKEN_INVALID',
                401,
                'Token portal absent ou invalide.',
            );
        }

        if ($session->isRevoked()) {
            throw new PortalTokenException(
                'PORTAL_TOKEN_REVOKED',
                401,
                'La session a été révoquée. Repasser par le lien de carte.',
            );
        }

        if ($session->isExpired()) {
            throw new PortalTokenException(
                'PORTAL_TOKEN_EXPIRED',
                401,
                'La session client a expiré. Repasser par le lien de carte.',
            );
        }

        $session->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $session;
    }

    /**
     * Resolve a portal token from request context.
     * Priority:
     * 1) Authorization: Bearer <portal_token>
     * 2) HttpOnly cookie customer_portal_token
     *
     * If Authorization is present but does not contain a portal token,
     * fallback to cookie is attempted to avoid collisions with merchant JWT interceptors.
     *
     * @throws PortalTokenException 401 if no valid portal token is found
     */
    public function resolveRawTokenFromRequest(Request $request): string
    {
        $authHeader = trim((string) $request->headers->get('Authorization', ''));
        $bearerToken = null;

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches) === 1) {
            $bearerToken = trim((string) ($matches[1] ?? ''));
            if ($bearerToken !== '' && $this->isPortalToken($bearerToken)) {
                return $bearerToken;
            }
        }

        $cookieToken = trim((string) $request->cookies->get(self::PORTAL_COOKIE_NAME, ''));
        if ($cookieToken !== '' && $this->isPortalToken($cookieToken)) {
            return $cookieToken;
        }

        if ($bearerToken !== null) {
            throw new PortalTokenException(
                'PORTAL_TOKEN_INVALID',
                401,
                'Token portal absent: le header Authorization courant ne contient pas un token portal valide.',
            );
        }

        throw new PortalTokenException(
            'PORTAL_TOKEN_INVALID',
            401,
            'Token portal absent: fournir Authorization Bearer (portal_token) ou cookie portal.',
        );
    }

    public function isPortalToken(string $token): bool
    {
        return preg_match('/^' . preg_quote(self::TOKEN_PREFIX, '/') . '[a-f0-9]{64}$/i', $token) === 1;
    }

    /**
     * Rotate portal token (old session revoked, new one created).
     * Refuses refresh that exceeds the 24h cumulative window.
     * Returns [rawToken, CustomerPortalSession].
     *
     * @throws PortalTokenException 403 if 24h window exceeded
     */
    public function refresh(CustomerPortalSession $session): array
    {
        $originalIssuedAt = $session->getOriginalIssuedAt() ?? $session->getIssuedAt();
        $windowDeadline = $originalIssuedAt->modify(sprintf('+%d seconds', self::REFRESH_WINDOW_SECONDS));

        if (new \DateTimeImmutable() > $windowDeadline) {
            throw new PortalTokenException(
                'PORTAL_TOKEN_EXPIRED',
                403,
                'Fenêtre de refresh de 24h dépassée. Repasser par le lien de carte.',
            );
        }

        // Revoke old session before creating the new one (token rotation)
        $session->setRevokedAt(new \DateTimeImmutable());

        [$rawToken, $newSession] = $this->createSession(
            $session->getCustomer(),
            $session->getIssuedFromWalletToken(),
            $session->getIp(),
            $session->getUserAgent(),
            $originalIssuedAt,
        );

        $this->em->flush();

        return [$rawToken, $newSession];
    }

    /**
     * Revoke an active portal session (public logout).
     */
    public function revoke(CustomerPortalSession $session): void
    {
        $session->setRevokedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    // -------------------------------------------------------------------------

    private function createSession(
        Customer $customer,
        ?string $walletToken,
        ?string $ip,
        ?string $userAgent,
        ?\DateTimeImmutable $originalIssuedAt,
    ): array {
        $rawToken = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $hash = $this->hashToken($rawToken);

        $session = new CustomerPortalSession();
        $session->setCustomer($customer);
        $session->setTokenHash($hash);
        $session->setIssuedFromWalletToken($walletToken);
        $session->setExpiresAt(new \DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS)));
        $session->setOriginalIssuedAt($originalIssuedAt);
        $session->setIp($ip !== null ? substr($ip, 0, 45) : null);
        $session->setUserAgent($userAgent !== null ? substr($userAgent, 0, 512) : null);

        $this->em->persist($session);

        return [$rawToken, $session];
    }

    /**
     * HMAC-SHA256 with APP_SECRET as pepper.
     * Constant-time comparison must be used at call site (hash_equals).
     */
    private function hashToken(string $rawToken): string
    {
        return hash_hmac('sha256', $rawToken, $this->appSecret);
    }
}
