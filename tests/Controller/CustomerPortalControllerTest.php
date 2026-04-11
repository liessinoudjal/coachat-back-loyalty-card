<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\CustomerPortalSession;
use App\Exception\PortalTokenException;
use App\Service\CustomerPortalService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CustomerPortalControllerTest extends WebTestCase
{
    public function testBootstrapIsPublicWithoutAuthorization(): void
    {
        $client = static::createClient();

        $portalService = $this->buildPortalServicePartialMock();
        $session = $this->buildSession('+15 minutes');

        $portalService
            ->expects($this->once())
            ->method('bootstrap')
            ->with('wallet-token-abc', $this->anything(), $this->anything())
            ->willReturn(['pt_live_' . str_repeat('a', 64), $session]);

        static::getContainer()->set(CustomerPortalService::class, $portalService);

        $client->request(
            'POST',
            '/api/public/customer-portal/bootstrap',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['wallet_token' => 'wallet-token-abc'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Bearer', $payload['token_type']);
        self::assertArrayHasKey('portal_token', $payload);

        $setCookie = $client->getResponse()->headers->get('set-cookie');
        self::assertNotNull($setCookie);
        self::assertStringContainsString(CustomerPortalService::PORTAL_COOKIE_NAME . '=', $setCookie);
    }

    public function testBootstrapStillWorksWithMerchantAuthorizationPresent(): void
    {
        $client = static::createClient();

        $portalService = $this->buildPortalServicePartialMock();
        $session = $this->buildSession('+15 minutes');

        $portalService
            ->expects($this->once())
            ->method('bootstrap')
            ->with('wallet-token-xyz', $this->anything(), $this->anything())
            ->willReturn(['pt_live_' . str_repeat('b', 64), $session]);

        static::getContainer()->set(CustomerPortalService::class, $portalService);

        $client->request(
            'POST',
            '/api/public/customer-portal/bootstrap',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer merchant.jwt.token',
            ],
            content: json_encode(['wallet_token' => 'wallet-token-xyz'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Bearer', $payload['token_type']);
        self::assertArrayHasKey('portal_token', $payload);
    }

    public function testRefreshWorksWithMerchantAuthorizationWhenPortalCookieIsPresent(): void
    {
        $client = static::createClient();

        $portalService = $this->buildPortalServicePartialMock();
        $currentSession = $this->buildSession('+15 minutes');
        $newSession = $this->buildSession('+15 minutes');

        $portalService
            ->expects($this->once())
            ->method('validateToken')
            ->with('pt_live_' . str_repeat('c', 64))
            ->willReturn($currentSession);

        $portalService
            ->expects($this->once())
            ->method('refresh')
            ->with($currentSession)
            ->willReturn(['pt_live_' . str_repeat('d', 64), $newSession]);

        static::getContainer()->set(CustomerPortalService::class, $portalService);

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(
            CustomerPortalService::PORTAL_COOKIE_NAME,
            'pt_live_' . str_repeat('c', 64),
        ));

        $client->request(
            'POST',
            '/api/public/customer-portal/refresh',
            server: ['HTTP_AUTHORIZATION' => 'Bearer merchant.jwt.token'],
        );

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Bearer', $payload['token_type']);
        self::assertStringStartsWith('pt_live_', $payload['portal_token']);
    }

    public function testRefreshReturns401WhenPortalSessionIsExpired(): void
    {
        $client = static::createClient();

        $portalService = $this->buildPortalServicePartialMock();

        $portalService
            ->expects($this->once())
            ->method('validateToken')
            ->with('pt_live_' . str_repeat('e', 64))
            ->willThrowException(new PortalTokenException(
                'PORTAL_TOKEN_EXPIRED',
                401,
                'La session client a expiré. Repasser par le lien de carte.',
            ));

        $portalService
            ->expects($this->never())
            ->method('refresh');

        static::getContainer()->set(CustomerPortalService::class, $portalService);

        $client->request(
            'POST',
            '/api/public/customer-portal/refresh',
            server: ['HTTP_AUTHORIZATION' => 'Bearer pt_live_' . str_repeat('e', 64)],
        );

        self::assertResponseStatusCodeSame(401);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PORTAL_TOKEN_EXPIRED', $payload['code']);
    }

    private function buildPortalServicePartialMock(): CustomerPortalService
    {
        return $this->getMockBuilder(CustomerPortalService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['bootstrap', 'validateToken', 'refresh', 'revoke'])
            ->getMock();
    }

    private function buildSession(string $expires): CustomerPortalSession
    {
        $customer = (new Customer())
            ->setName('Portal Customer')
            ->setEmail('portal@example.com');

        return (new CustomerPortalSession())
            ->setCustomer($customer)
            ->setTokenHash(hash('sha256', random_bytes(8)))
            ->setExpiresAt(new \DateTimeImmutable($expires));
    }
}
