<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PromotionalOfferController;
use App\Repository\PromotionalOfferRepository;
use App\Service\PromotionalOfferNotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PromotionalOfferControllerTest extends TestCase
{
    public function testDailyDispatchReturnsUnauthorizedWhenTokenMissing(): void
    {
        $controller = $this->createController(
            cronToken: 'token-123',
            cronBasicUser: '',
            cronBasicPassword: '',
            cronHeaderName: '',
            cronHeaderValue: '',
        );

        $response = $controller->dailyDispatch(new Request());
        $payload = $this->decodeResponse($response->getContent() ?: '');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $payload['error']);
    }

    public function testDailyDispatchReturnsUnauthorizedWhenBasicAuthInvalid(): void
    {
        $controller = $this->createController(
            cronToken: 'token-123',
            cronBasicUser: 'cron-user',
            cronBasicPassword: 'cron-pass',
            cronHeaderName: '',
            cronHeaderValue: '',
        );

        $request = new Request();
        $request->headers->set('X-Cron-Token', 'token-123');

        $response = $controller->dailyDispatch($request);
        $payload = $this->decodeResponse($response->getContent() ?: '');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized_basic_auth', $payload['error']);
    }

    public function testDailyDispatchReturnsUnauthorizedWhenCustomHeaderInvalid(): void
    {
        $controller = $this->createController(
            cronToken: 'token-123',
            cronBasicUser: 'cron-user',
            cronBasicPassword: 'cron-pass',
            cronHeaderName: 'X-Dispatch-Secret',
            cronHeaderValue: 'header-secret',
        );

        $request = new Request([], [], [], [], [], [
            'PHP_AUTH_USER' => 'cron-user',
            'PHP_AUTH_PW' => 'cron-pass',
        ]);
        $request->headers->set('X-Cron-Token', 'token-123');

        $response = $controller->dailyDispatch($request);
        $payload = $this->decodeResponse($response->getContent() ?: '');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized_custom_header', $payload['error']);
    }

    public function testDailyDispatchReturnsSuccessWhenAllSecurityLayersValid(): void
    {
        $dispatcher = $this->createMock(PromotionalOfferNotificationDispatcher::class);
        $dispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn([
                'start_notifications_sent_for_offers' => 2,
                'ending_soon_notifications_sent_for_offers' => 1,
            ]);

        $controller = $this->createController(
            cronToken: 'token-123',
            cronBasicUser: 'cron-user',
            cronBasicPassword: 'cron-pass',
            cronHeaderName: 'X-Dispatch-Secret',
            cronHeaderValue: 'header-secret',
            dispatcher: $dispatcher,
        );

        $request = new Request([], [], [], [], [], [
            'PHP_AUTH_USER' => 'cron-user',
            'PHP_AUTH_PW' => 'cron-pass',
        ]);
        $request->headers->set('X-Cron-Token', 'token-123');
        $request->headers->set('X-Dispatch-Secret', 'header-secret');

        $response = $controller->dailyDispatch($request);
        $payload = $this->decodeResponse($response->getContent() ?: '');

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('date', $payload);
        self::assertSame(2, $payload['start_notifications_sent_for_offers']);
        self::assertSame(1, $payload['ending_soon_notifications_sent_for_offers']);
    }

    private function createController(
        string $cronToken,
        string $cronBasicUser,
        string $cronBasicPassword,
        string $cronHeaderName,
        string $cronHeaderValue,
        ?PromotionalOfferNotificationDispatcher $dispatcher = null,
    ): PromotionalOfferController {
        /** @var EntityManagerInterface&MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        /** @var PromotionalOfferRepository&MockObject $offerRepository */
        $offerRepository = $this->createMock(PromotionalOfferRepository::class);

        if (!$dispatcher instanceof PromotionalOfferNotificationDispatcher) {
            /** @var PromotionalOfferNotificationDispatcher&MockObject $dispatcher */
            $dispatcher = $this->createMock(PromotionalOfferNotificationDispatcher::class);
            $dispatcher->expects(self::never())->method('dispatch');
        }

        return new PromotionalOfferController(
            $entityManager,
            $offerRepository,
            $dispatcher,
            $cronToken,
            $cronBasicUser,
            $cronBasicPassword,
            $cronHeaderName,
            $cronHeaderValue,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $content): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
