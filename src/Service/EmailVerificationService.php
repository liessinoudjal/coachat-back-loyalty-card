<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Handles email verification: token generation, email sending,
 * verification, and resend throttling.
 */
class EmailVerificationService
{
    public const RESEND_COOLDOWN_SECONDS = 60;
    public const TOKEN_TTL_SECONDS = 86400; // 24 h

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly string $appFrontBaseUrl,
    ) {
    }

    /**
     * Generates a verification token for the user, persists it, and
     * sends the verification email. Returns true if the email was sent.
     */
    public function issueAndSendVerification(User $user, string $audience = 'customer'): bool
    {
        $token = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable();

        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenSentAt($now);
        $user->setEmailVerificationSentTo($user->getEmail());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->sendVerificationEmail($user, $token, $audience);
    }

    /**
     * Generates a verification token for the user, persists it, and returns
     * the verification URL so it can be embedded in another email (e.g. the
     * customer welcome email) instead of sending a dedicated email.
     */
    public function issueTokenAndBuildUrl(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable();

        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenSentAt($now);
        $user->setEmailVerificationSentTo($user->getEmail());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->buildVerifyUrl($token);
    }

    public function buildVerifyUrl(string $token): string
    {
        return sprintf(
            '%s/verify-email?token=%s',
            rtrim($this->appFrontBaseUrl, '/'),
            urlencode($token),
        );
    }

    /**
     * Re-issues a token only if the cooldown has elapsed.
     * Returns ['sent' => bool, 'retry_in' => int|null].
     */
    public function resendVerification(User $user, string $audience = 'customer'): array
    {
        if ($user->isEmailVerified()) {
            return ['sent' => false, 'retry_in' => null, 'already_verified' => true];
        }

        $sentAt = $user->getEmailVerificationTokenSentAt();
        if ($sentAt !== null) {
            $elapsed = (new \DateTimeImmutable())->getTimestamp() - $sentAt->getTimestamp();
            if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
                return [
                    'sent' => false,
                    'retry_in' => self::RESEND_COOLDOWN_SECONDS - $elapsed,
                    'already_verified' => false,
                ];
            }
        }

        $sent = $this->issueAndSendVerification($user, $audience);

        return ['sent' => $sent, 'retry_in' => null, 'already_verified' => false];
    }

    /**
     * Marks the user as verified if the token matches and is not expired.
     * Returns one of: 'verified', 'already_verified', 'invalid_token', 'expired_token'.
     */
    public function consumeToken(string $token): string
    {
        $repo = $this->entityManager->getRepository(User::class);
        $user = $repo->findOneBy(['emailVerificationToken' => $token]);
        if (!$user instanceof User) {
            // Maybe the user is already verified and the token was wiped.
            return 'invalid_token';
        }

        if ($user->isEmailVerified()) {
            // Clear any lingering token defensively.
            $user->setEmailVerificationToken(null);
            $this->entityManager->flush();

            return 'already_verified';
        }

        $sentAt = $user->getEmailVerificationTokenSentAt();
        if ($sentAt === null
            || (new \DateTimeImmutable())->getTimestamp() - $sentAt->getTimestamp() > self::TOKEN_TTL_SECONDS
        ) {
            return 'expired_token';
        }

        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationToken(null);
        $this->entityManager->flush();

        return 'verified';
    }

    private function sendVerificationEmail(User $user, string $token, string $audience): bool
    {
        $recipient = $user->getEmail();
        if ($recipient === null || $recipient === '') {
            return false;
        }

        $verifyUrl = $this->buildVerifyUrl($token);

        $name = $user->getName() ?: $recipient;

        try {
            $html = $this->twig->render('emails/email_verification.html.twig', [
                'email_title' => 'Confirmez votre adresse email',
                'email_eyebrow' => 'Activation du compte',
                'email_accent' => 'Confirmation',
                'summary' => 'Confirmez votre adresse email pour activer votre compte Coachat.',
                'primary_label' => 'Adresse à confirmer',
                'primary_value' => $recipient,
                'secondary_label' => 'Validité du lien',
                'secondary_value' => '24 heures',
                'name' => $name,
                'verify_url' => $verifyUrl,
                'audience' => $audience,
            ]);

            $text = implode("\n", [
                sprintf('Bonjour %s,', $name),
                '',
                'Merci de votre inscription sur Coachat.',
                'Pour activer votre compte, veuillez confirmer votre adresse email en cliquant sur le lien ci-dessous :',
                '',
                $verifyUrl,
                '',
                'Ce lien est valable 24 heures. Si vous n\'êtes pas à l\'origine de cette inscription, vous pouvez ignorer ce message.',
                '',
                'À très bientôt,',
                'L\'équipe Coachat',
            ]);

            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient)
                ->subject('Confirmez votre adresse email')
                ->text($text)
                ->html($html);

            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to send verification email', [
                'user_id' => $user->getId(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            $this->logger->error('Unexpected error while sending verification email', [
                'user_id' => $user->getId(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
