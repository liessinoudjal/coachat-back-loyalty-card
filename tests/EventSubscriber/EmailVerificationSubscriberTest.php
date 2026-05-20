<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\EmailVerificationSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class EmailVerificationSubscriberTest extends TestCase
{
    public function testBlocksDashboardWhenUserIsNotVerified(): void
    {
        $user = $this->buildUser(verified: false);
        $event = $this->buildEvent('/api/customers/me/bootstrap');

        $subscriber = new EmailVerificationSubscriber($this->buildSecurity($user));
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('email_verification_required', $payload['error']);
        self::assertSame('pending@example.com', $payload['email']);
    }

    public function testAllowsDashboardWhenUserIsVerified(): void
    {
        $user = $this->buildUser(verified: true);
        $event = $this->buildEvent('/api/customers/me/bootstrap');

        $subscriber = new EmailVerificationSubscriber($this->buildSecurity($user));
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAllowsOnboardingRoutesEvenWhenUnverified(): void
    {
        $user = $this->buildUser(verified: false);

        $subscriber = new EmailVerificationSubscriber($this->buildSecurity($user));

        foreach ([
            '/api/customers/me/terms',
            '/api/merchants',
            '/api/legal/terms/current-version',
            '/api/auth/email/verify',
            '/api/auth/email/resend-verification',
            '/api/auth/logout',
            '/api/profile',
        ] as $path) {
            $event = $this->buildEvent($path);
            $subscriber->onKernelRequest($event);
            self::assertNull(
                $event->getResponse(),
                sprintf('Path %s must not be blocked by the verification gate.', $path),
            );
        }
    }

    public function testIgnoresOptionsRequests(): void
    {
        $user = $this->buildUser(verified: false);
        $event = $this->buildEvent('/api/customers/me/bootstrap', method: 'OPTIONS');

        $subscriber = new EmailVerificationSubscriber($this->buildSecurity($user));
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresAnonymousRequests(): void
    {
        $event = $this->buildEvent('/api/customers/me/bootstrap');

        $subscriber = new EmailVerificationSubscriber($this->buildSecurity(null));
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    private function buildUser(bool $verified): User
    {
        $user = new User();
        $user->setEmail('pending@example.com');
        $user->setEmailVerified($verified);

        return $user;
    }

    private function buildSecurity(?User $user): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function buildEvent(string $path, string $method = 'GET'): RequestEvent
    {
        $request = Request::create($path, $method);
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
