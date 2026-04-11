<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LoyaltyCard;
use App\Entity\Reward;
use App\Enum\LoyaltyProgramType;
use App\Enum\RewardStatus;
use App\Exception\PortalTokenException;
use App\Service\CustomerPortalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/public/customer-portal', name: 'customer_portal_')]
class CustomerPortalController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerPortalService $portalService,
        #[Autowire(service: 'limiter.portal_bootstrap')]
        private readonly RateLimiterFactory $bootstrapLimiter,
        #[Autowire(service: 'limiter.portal_lookup')]
        private readonly RateLimiterFactory $lookupLimiter,
    ) {}

    /**
     * POST /api/public/customer-portal/bootstrap
     * Emit a short-lived portal_token from a valid wallet_token.
     */
    #[Route('/bootstrap', name: 'bootstrap', methods: ['POST'])]
    public function bootstrap(Request $request): JsonResponse
    {
        // Rate limit by IP: 10 req/min
        $limiter = $this->bootstrapLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            return $this->portalError('RATE_LIMITED', 'Trop de requêtes. Réessayez dans ' . $limit->getRetryAfter()->getTimestamp() . ' secondes.', 429);
        }

        $body = json_decode($request->getContent(), true);
        $walletToken = trim((string) ($body['wallet_token'] ?? ''));

        if ($walletToken === '') {
            return $this->portalError('CLAIM_WALLET_TOKEN_INVALID', 'Le champ wallet_token est requis.', 400);
        }

        try {
            [$rawToken, $session] = $this->portalService->bootstrap(
                $walletToken,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
        } catch (PortalTokenException $e) {
            return $this->portalError($e->getPortalCode(), $e->getMessage(), $e->getHttpStatus());
        }

        $customer = $session->getCustomer();

        $response = new JsonResponse([
            'portal_token' => $rawToken,
            'token_type'   => 'Bearer',
            'expires_at'   => $session->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'customer'     => [
                'id'   => $customer->getId(),
                'name' => $customer->getName(),
            ],
        ]);

        return $this->withPortalTokenCookie($response, $rawToken, $session->getExpiresAt(), $request);
    }

    /**
     * GET /api/public/customer-portal/overview
     * Return consolidated cards + rewards for the authenticated customer session.
     */
    #[Route('/overview', name: 'overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        try {
            $session = $this->resolvePortalSession($request);
        } catch (PortalTokenException $e) {
            return $this->portalError($e->getPortalCode(), $e->getMessage(), $e->getHttpStatus());
        }

        // Rate limit by token hash: 60 req/min
        $limiter = $this->lookupLimiter->create($session->getTokenHash());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            return $this->portalError('RATE_LIMITED', 'Trop de requêtes.', 429);
        }

        $customer = $session->getCustomer();

        // Parse query params
        $include = array_filter(
            array_map('trim', explode(',', $request->query->get('include', 'cards,rewards'))),
        );
        $statusFilter = $request->query->get('status');
        $page         = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(100, max(1, (int) $request->query->get('itemsPerPage', 20)));

        $cards   = [];
        $rewards = [];

        if (in_array('cards', $include, true)) {
            $cards = $this->buildCardsPayload($customer->getId(), $page, $itemsPerPage);
        }

        if (in_array('rewards', $include, true)) {
            $rewards = $this->buildRewardsPayload($customer->getId(), $statusFilter, $page, $itemsPerPage);
        }

        return new JsonResponse([
            'customer' => [
                'id'   => $customer->getId(),
                'name' => $customer->getName(),
            ],
            'cards'   => $cards,
            'rewards' => $rewards,
            'meta'    => [
                'cards_count'   => count($cards),
                'rewards_count' => count($rewards),
            ],
        ]);
    }

    /**
     * POST /api/public/customer-portal/refresh
     * Token rotation: revoke current portal session and issue a new one.
     */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        try {
            $session = $this->resolvePortalSession($request);
        } catch (PortalTokenException $e) {
            return $this->portalError($e->getPortalCode(), $e->getMessage(), $e->getHttpStatus());
        }

        try {
            [$rawToken, $newSession] = $this->portalService->refresh($session);
        } catch (PortalTokenException $e) {
            return $this->portalError($e->getPortalCode(), $e->getMessage(), $e->getHttpStatus());
        }

        $response = new JsonResponse([
            'portal_token' => $rawToken,
            'token_type'   => 'Bearer',
            'expires_at'   => $newSession->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);

        return $this->withPortalTokenCookie($response, $rawToken, $newSession->getExpiresAt(), $request);
    }

    /**
     * POST /api/public/customer-portal/revoke
     * Public logout — invalidate current portal session.
     */
    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        try {
            $session = $this->resolvePortalSession($request);
        } catch (PortalTokenException $e) {
            return $this->portalError($e->getPortalCode(), $e->getMessage(), $e->getHttpStatus());
        }

        $this->portalService->revoke($session);

        $response = new Response(null, Response::HTTP_NO_CONTENT);

        return $this->clearPortalTokenCookie($response, $request);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract and validate portal token from Authorization: Bearer header.
     *
     * @throws PortalTokenException
     */
    private function resolvePortalSession(Request $request): \App\Entity\CustomerPortalSession
    {
        $rawToken = $this->portalService->resolveRawTokenFromRequest($request);

        return $this->portalService->validateToken($rawToken);
    }

    private function withPortalTokenCookie(JsonResponse $response, string $rawToken, \DateTimeImmutable $expiresAt, Request $request): JsonResponse
    {
        $isSecure = $request->isSecure();
        $sameSite = $isSecure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX;

        $response->headers->setCookie(Cookie::create(
            CustomerPortalService::PORTAL_COOKIE_NAME,
            $rawToken,
            $expiresAt,
            '/api/public/customer-portal',
            null,
            $isSecure,
            true,
            false,
            $sameSite,
        ));

        return $response;
    }

    private function clearPortalTokenCookie(Response $response, Request $request): Response
    {
        $isSecure = $request->isSecure();
        $sameSite = $isSecure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX;

        $response->headers->setCookie(Cookie::create(
            CustomerPortalService::PORTAL_COOKIE_NAME,
            '',
            new \DateTimeImmutable('-1 hour'),
            '/api/public/customer-portal',
            null,
            $isSecure,
            true,
            false,
            $sameSite,
        ));

        return $response;
    }

    /** Build paginated card list for a customer. */
    private function buildCardsPayload(int $customerId, int $page, int $itemsPerPage): array
    {
        $qb = $this->em->getRepository(LoyaltyCard::class)
            ->createQueryBuilder('lc')
            ->leftJoin('lc.loyaltyProgram', 'lp')->addSelect('lp')
            ->leftJoin('lc.merchant', 'm')->addSelect('m')
            ->where('lc.customer = :cid')
            ->setParameter('cid', $customerId)
            ->orderBy('lc.id', 'DESC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        /** @var LoyaltyCard[] $cards */
        $cards = $qb->getQuery()->getResult();

        return array_map(function (LoyaltyCard $card): array {
            $lp = $card->getLoyaltyProgram();
            $merchant = $card->getMerchant();

            $targetValue = $card->getTargetValue()
                ?? ($lp !== null
                    ? ($lp->getType() === LoyaltyProgramType::POINTS ? $lp->getPointsTarget() : $lp->getStampTarget())
                    : null);

            return [
                'id'            => $card->getId(),
                'wallet_token'  => $card->getWalletToken(),
                'current_value' => $card->getCurrentValue(),
                'target_value'  => $targetValue,
                'is_completed'  => $card->isCompleted(),
                'type'          => $lp?->getType()?->value,
                'loyalty_program' => $lp !== null ? [
                    'id'                 => $lp->getId(),
                    'name'               => $lp->getName(),
                    'type'               => $lp->getType()->value,
                    'reward_description' => $lp->getRewardDescription(),
                ] : null,
                'merchant' => $merchant !== null ? [
                    'id'           => (string) $merchant->getId(),
                    'company_name' => $merchant->getCompanyName(),
                ] : null,
            ];
        }, $cards);
    }

    /** Build paginated reward list for a customer with optional status filter. */
    private function buildRewardsPayload(int $customerId, ?string $statusFilter, int $page, int $itemsPerPage): array
    {
        $qb = $this->em->getRepository(Reward::class)
            ->createQueryBuilder('r')
            ->leftJoin('r.loyaltyCard', 'lc')->addSelect('lc')
            ->leftJoin('r.loyaltyProgram', 'lp')->addSelect('lp')
            ->leftJoin('r.merchant', 'm')->addSelect('m')
            ->where('r.customer = :cid')
            ->setParameter('cid', $customerId)
            ->orderBy('r.generatedAt', 'DESC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        if ($statusFilter !== null) {
            $statusEnum = RewardStatus::tryFrom(strtoupper($statusFilter));
            if ($statusEnum !== null) {
                $qb->andWhere('r.status = :status')->setParameter('status', $statusEnum->value);
            }
        }

        /** @var Reward[] $rewards */
        $rewards = $qb->getQuery()->getResult();

        return array_map(function (Reward $reward): array {
            $lp       = $reward->getLoyaltyProgram();
            $merchant = $reward->getMerchant();
            $card     = $reward->getLoyaltyCard();

            return [
                'id'               => (string) $reward->getId(),
                'loyalty_card_id'  => $card?->getId(),
                'wallet_token'     => $card?->getWalletToken(),
                'reward_description' => $reward->getRewardDescription(),
                'status'           => $reward->getStatus()->value,
                'generated_at'     => $reward->getGeneratedAt()->format(\DateTimeInterface::ATOM),
                'claimed_at'       => $reward->getClaimedAt()?->format(\DateTimeInterface::ATOM),
                // claim_qr_token IS exposed to the customer (their own reward)
                'claim_qr_token'   => $reward->getClaimQrToken(),
                'loyalty_program'  => $lp !== null ? [
                    'id'                 => $lp->getId(),
                    'name'               => $lp->getName(),
                    'type'               => $lp->getType()->value,
                    'reward_description' => $lp->getRewardDescription(),
                ] : null,
                'merchant' => $merchant !== null ? [
                    'id'           => (string) $merchant->getId(),
                    'company_name' => $merchant->getCompanyName(),
                ] : null,
            ];
        }, $rewards);
    }

    /** Standardised portal error response. */
    private function portalError(string $code, string $message, int $status, mixed $details = null): JsonResponse
    {
        return new JsonResponse([
            'code'    => $code,
            'message' => $message,
            'details' => $details,
        ], $status);
    }
}
