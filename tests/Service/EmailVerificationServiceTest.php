<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class EmailVerificationServiceTest extends TestCase
{
    public function testIssueAndSendVerificationSetsTokenAndSendsEmail(): void
    {
        $user = new User();
        $user->setEmail('newcomer@example.com');
        $user->setName('Eléna Dupré');

        $renderedHtml = '<html>verify</html>';

        $twig = $this->createMock(Environment::class);
        $twig
            ->expects(self::once())
            ->method('render')
            ->with(
                'emails/email_verification.html.twig',
                self::callback(static function (array $context): bool {
                    return ($context['email_title'] ?? null) === 'Confirmez votre adresse email'
                        && ($context['audience'] ?? null) === 'customer'
                        && str_contains((string) ($context['verify_url'] ?? ''), '/verify-email?token=')
                        && ($context['name'] ?? null) === 'Eléna Dupré';
                }),
            )
            ->willReturn($renderedHtml);

        $capturedEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email) use (&$capturedEmail, $renderedHtml): bool {
                $capturedEmail = $email;
                return $email->getSubject() === 'Confirmez votre adresse email'
                    && str_contains((string) $email->getHtmlBody(), $renderedHtml)
                    && str_contains((string) $email->getTextBody(), 'confirmer votre adresse email')
                    && str_contains((string) $email->getTextBody(), 'À très bientôt');
            }));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::atLeastOnce())->method('flush');

        $service = new EmailVerificationService(
            $mailer,
            $twig,
            $em,
            new NullLogger(),
            'no-reply@coachat.test',
            'Coachat',
            'https://front.example.com',
        );

        $sent = $service->issueAndSendVerification($user, 'customer');

        self::assertTrue($sent);
        self::assertNotNull($user->getEmailVerificationToken());
        self::assertSame(64, strlen($user->getEmailVerificationToken()));
        self::assertNotNull($user->getEmailVerificationTokenSentAt());
        self::assertSame('newcomer@example.com', $user->getEmailVerificationSentTo());
        self::assertFalse($user->isEmailVerified());

        // The verification link must embed the generated token.
        self::assertInstanceOf(Email::class, $capturedEmail);
        self::assertStringContainsString(
            'token=' . $user->getEmailVerificationToken(),
            (string) $capturedEmail->getTextBody(),
        );
    }

    public function testResendVerificationIsThrottledWhenCooldownNotElapsed(): void
    {
        $user = new User();
        $user->setEmail('throttled@example.com');
        $user->setEmailVerificationToken(str_repeat('a', 64));
        $user->setEmailVerificationTokenSentAt(new \DateTimeImmutable('-5 seconds'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $em = $this->createMock(EntityManagerInterface::class);

        $service = new EmailVerificationService(
            $mailer,
            $twig,
            $em,
            new NullLogger(),
            'no-reply@coachat.test',
            'Coachat',
            'https://front.example.com',
        );

        $result = $service->resendVerification($user);

        self::assertFalse($result['sent']);
        self::assertFalse($result['already_verified']);
        self::assertNotNull($result['retry_in']);
        self::assertLessThanOrEqual(EmailVerificationService::RESEND_COOLDOWN_SECONDS, $result['retry_in']);
    }

    public function testResendVerificationShortCircuitsWhenAlreadyVerified(): void
    {
        $user = new User();
        $user->setEmail('verified@example.com');
        $user->setEmailVerified(true);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $service = new EmailVerificationService(
            $mailer,
            $this->createMock(Environment::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            'no-reply@coachat.test',
            'Coachat',
            'https://front.example.com',
        );

        $result = $service->resendVerification($user);

        self::assertTrue($result['already_verified']);
        self::assertFalse($result['sent']);
    }

    public function testConsumeTokenMarksUserAsVerifiedAndClearsToken(): void
    {
        $token = str_repeat('b', 64);
        $user = new User();
        $user->setEmail('to-verify@example.com');
        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenSentAt(new \DateTimeImmutable('-1 hour'));
        $user->setEmailVerified(false);

        $service = $this->buildServiceForUser($user, $token);
        $result = $service->consumeToken($token);

        self::assertSame('verified', $result);
        self::assertTrue($user->isEmailVerified());
        self::assertNotNull($user->getEmailVerifiedAt());
        self::assertNull($user->getEmailVerificationToken());
    }

    public function testConsumeTokenReturnsInvalidWhenTokenUnknown(): void
    {
        $service = $this->buildServiceForUser(null, 'unknown');
        self::assertSame('invalid_token', $service->consumeToken('unknown'));
    }

    public function testConsumeTokenReturnsExpiredWhenOlderThanTtl(): void
    {
        $token = str_repeat('c', 64);
        $user = new User();
        $user->setEmail('expired@example.com');
        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenSentAt(new \DateTimeImmutable('-2 days'));
        $user->setEmailVerified(false);

        $service = $this->buildServiceForUser($user, $token);
        $result = $service->consumeToken($token);

        self::assertSame('expired_token', $result);
        self::assertFalse($user->isEmailVerified());
    }

    public function testConsumeTokenReturnsAlreadyVerifiedWhenUserAlreadyVerified(): void
    {
        $token = str_repeat('d', 64);
        $user = new User();
        $user->setEmail('done@example.com');
        $user->setEmailVerificationToken($token);
        $user->setEmailVerified(true);

        $service = $this->buildServiceForUser($user, $token);
        $result = $service->consumeToken($token);

        self::assertSame('already_verified', $result);
        self::assertNull($user->getEmailVerificationToken());
    }

    private function buildServiceForUser(?User $userToReturn, string $token): EmailVerificationService
    {
        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository
            ->method('findOneBy')
            ->willReturnCallback(
                static fn (array $criteria) => ($criteria['emailVerificationToken'] ?? null) === $token ? $userToReturn : null
            );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($repository);
        $em->method('flush')->willReturnCallback(static function (): void {});

        return new EmailVerificationService(
            $this->createMock(MailerInterface::class),
            $this->createMock(Environment::class),
            $em,
            new NullLogger(),
            'no-reply@coachat.test',
            'Coachat',
            'https://front.example.com',
        );
    }
}
