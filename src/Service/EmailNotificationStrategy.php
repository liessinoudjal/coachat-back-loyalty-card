<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\LoyaltyProgramType;
use App\Enum\NotificationType;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class EmailNotificationStrategy implements NotificationStrategyInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function send(Merchant $merchant, Customer $customer, NotificationType $type, array $context = []): void
    {
        $recipient = $customer->getEmail() ?? $customer->getUser()?->getEmail();
        if ($recipient === null || trim($recipient) === '') {
            return;
        }

        $emailData = $this->buildEmailData($merchant, $customer, $type, $context);

        $email = (new Email())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($recipient)
            ->subject($emailData['subject'])
            ->text($emailData['text'])
            ->html($emailData['html']);

        $this->mailer->send($email);
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    private function buildEmailData(Merchant $merchant, Customer $customer, NotificationType $type, array $context): array
    {
        return match ($type) {
            NotificationType::POINTS_ADDED => $this->buildPointsAddedEmailData($merchant, $customer, $context),
            NotificationType::REWARD_CLAIMED => $this->buildRewardClaimedEmailData($merchant, $customer, $context),
            default => throw new \InvalidArgumentException(sprintf('Unsupported email notification type "%s".', $type->value)),
        };
    }

    /**
     * @param array{card?: mixed, transaction?: mixed, dashboard_url?: string, value_added?: int, unit_label?: string, current_value?: int, target_value?: int, reward_ready?: bool} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildPointsAddedEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $unitLabel = (string) ($context['unit_label'] ?? 'points');
        $valueAdded = (int) ($context['value_added'] ?? 0);
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $currentValue = $context['current_value'] ?? null;
        $targetValue = $context['target_value'] ?? null;
        $rewardReady = (bool) ($context['reward_ready'] ?? false);

        $subject = sprintf('Votre carte %s a ete mise a jour chez %s', $unitLabel, $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('%d %s ont ete ajoutes a votre carte chez %s.', $valueAdded, $unitLabel, $merchant->getCompanyName() ?? 'ce commerce'),
            $currentValue !== null && $targetValue !== null ? sprintf('Progression: %s / %s', $currentValue, $targetValue) : null,
            $rewardReady ? 'Votre carte est completee et une recompense vous attend.' : null,
            $dashboardUrl !== '' ? sprintf('Voir mon dashboard: %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_points_added.html.twig', [
            'email_title' => 'Carte mise a jour',
            'email_eyebrow' => 'Notification client',
            'email_accent' => strtoupper($unitLabel),
            'summary' => sprintf('%d %s ont ete ajoutes a votre carte chez %s.', $valueAdded, $unitLabel, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => sprintf('%d %s', $valueAdded, $unitLabel),
            'primary_label' => 'Ajoutes',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Merchant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'current_value' => $currentValue,
            'target_value' => $targetValue,
            'reward_ready' => $rewardReady,
            'unit_label' => $unitLabel,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{reward?: mixed, dashboard_url?: string, reward_description?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildRewardClaimedEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $rewardDescription = (string) ($context['reward_description'] ?? 'Votre recompense');

        $subject = sprintf('Recompense recuperee chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Votre recompense "%s" a bien ete recuperee chez %s.', $rewardDescription, $merchant->getCompanyName() ?? 'ce commerce'),
            $dashboardUrl !== '' ? sprintf('Creer une nouvelle carte depuis votre dashboard: %s', $dashboardUrl) : null,
            'Ou revenez simplement lors de votre prochaine visite en magasin.',
        ]);

        $html = $this->twig->render('emails/customer_reward_claimed.html.twig', [
            'email_title' => 'Recompense recuperee',
            'email_eyebrow' => 'Notification client',
            'email_accent' => 'RECOMPENSE',
            'summary' => sprintf('Votre recompense "%s" a bien ete recuperee chez %s.', $rewardDescription, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $rewardDescription,
            'primary_label' => 'Recompense',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Merchant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'reward_description' => $rewardDescription,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }
}