<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Merchant;
use App\Service\SignupAlertMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class SignupAlertMailerTest extends TestCase
{
    public function testMerchantLimitRefusalMailIncludesSubscriptionLink(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);

        $capturedContext = null;
        $twig
            ->expects(self::once())
            ->method('render')
            ->with(
                'emails/signup_alert_base.html.twig',
                self::callback(static function (array $context) use (&$capturedContext): bool {
                    $capturedContext = $context;
                    return ($context['cta_url'] ?? null) === 'https://front.example.com/subscription'
                        && ($context['cta_label'] ?? null) === 'Activer un plan supérieur';
                }),
            )
            ->willReturn('<html><body>mail</body></html>');

        $mailer
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                $text = (string) $email->getTextBody();
                $html = (string) $email->getHtmlBody();

                return str_contains($text, 'https://front.example.com/subscription')
                        && str_contains($text, 'Accédez à votre dashboard d\'abonnement pour activer un plan supérieur')
                        && str_contains($text, 'abonnés maximum')
                        && str_contains($text, 'Abonnés actuels')
                    && str_contains($html, 'mail');
            }));

        $merchant = new Merchant();
        $merchant->setCompanyName('Merchant limit');
        $merchant->setEmail('merchant@example.com');

        $mailerService = new SignupAlertMailer(
            $mailer,
            $twig,
            new NullLogger(),
            new NullLogger(),
            'alerts@example.com',
            'from@example.com',
            'Coachat',
            'https://front.example.com',
        );

        $mailerService->notifyMerchantSignupRefusedDueToCustomerLimit(
            $merchant,
            null,
            1,
            1,
            'parcours inscription Google',
        );

        self::assertIsArray($capturedContext);
        self::assertSame('https://front.example.com/subscription', $capturedContext['cta_url']);
        self::assertSame('Activer un plan supérieur', $capturedContext['cta_label']);
    }
}
