<?php

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\CustomerPortalSession;
use App\Exception\PortalTokenException;
use App\Repository\CustomerPortalSessionRepository;
use App\Repository\LoyaltyCardRepository;
use App\Service\CustomerPortalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class CustomerPortalServiceTest extends TestCase
{
    public function testResolveRawTokenFromRequestUsesPortalBearerFirst(): void
    {
        $service = $this->buildService();

        $portalToken = 'pt_live_' . str_repeat('a', 64);
        $request = Request::create('/api/public/customer-portal/overview', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $portalToken);

        $resolved = $service->resolveRawTokenFromRequest($request);

        $this->assertSame($portalToken, $resolved);
    }

    public function testResolveRawTokenFromRequestFallsBackToCookieWhenBearerIsMerchantJwt(): void
    {
        $service = $this->buildService();

        $portalToken = 'pt_live_' . str_repeat('b', 64);
        $request = Request::create('/api/public/customer-portal/overview', 'GET');
        $request->headers->set('Authorization', 'Bearer merchant.jwt.token');
        $request->cookies->set(CustomerPortalService::PORTAL_COOKIE_NAME, $portalToken);

        $resolved = $service->resolveRawTokenFromRequest($request);

        $this->assertSame($portalToken, $resolved);
    }

    public function testResolveRawTokenFromRequestWithoutBearerOrCookieThrows(): void
    {
        $service = $this->buildService();
        $request = Request::create('/api/public/customer-portal/overview', 'GET');

        $this->expectException(PortalTokenException::class);
        $this->expectExceptionMessage('Token portal absent');

        $service->resolveRawTokenFromRequest($request);
    }

    public function testValidateTokenAcceptsValidSession(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $sessionRepository = $this->createMock(CustomerPortalSessionRepository::class);
        $cardRepository = $this->createMock(LoyaltyCardRepository::class);

        $portalToken = 'pt_live_' . str_repeat('c', 64);
        $hash = hash_hmac('sha256', $portalToken, 'test-secret');

        $session = (new CustomerPortalSession())
            ->setCustomer(new Customer())
            ->setTokenHash($hash)
            ->setExpiresAt(new \DateTimeImmutable('+10 minutes'));

        $sessionRepository
            ->expects($this->once())
            ->method('findByTokenHash')
            ->with($hash)
            ->willReturn($session);

        $entityManager
            ->expects($this->once())
            ->method('flush');

        $service = new CustomerPortalService($entityManager, $sessionRepository, $cardRepository, 'test-secret');

        $resolved = $service->validateToken($portalToken);

        $this->assertSame($session, $resolved);
        $this->assertNotNull($session->getLastUsedAt());
    }

    public function testValidateTokenRejectsExpiredSession(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $sessionRepository = $this->createMock(CustomerPortalSessionRepository::class);
        $cardRepository = $this->createMock(LoyaltyCardRepository::class);

        $portalToken = 'pt_live_' . str_repeat('d', 64);
        $hash = hash_hmac('sha256', $portalToken, 'test-secret');

        $expiredSession = (new CustomerPortalSession())
            ->setCustomer(new Customer())
            ->setTokenHash($hash)
            ->setExpiresAt(new \DateTimeImmutable('-1 minute'));

        $sessionRepository
            ->expects($this->once())
            ->method('findByTokenHash')
            ->with($hash)
            ->willReturn($expiredSession);

        $entityManager
            ->expects($this->never())
            ->method('flush');

        $service = new CustomerPortalService($entityManager, $sessionRepository, $cardRepository, 'test-secret');

        $this->expectException(PortalTokenException::class);
        $this->expectExceptionMessage('La session client a expiré');

        $service->validateToken($portalToken);
    }

    private function buildService(): CustomerPortalService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $sessionRepository = $this->createMock(CustomerPortalSessionRepository::class);
        $cardRepository = $this->createMock(LoyaltyCardRepository::class);

        return new CustomerPortalService($entityManager, $sessionRepository, $cardRepository, 'test-secret');
    }
}
