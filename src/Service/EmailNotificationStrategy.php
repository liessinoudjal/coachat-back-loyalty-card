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
            NotificationType::CUSTOMER_SIGNUP => $this->buildCustomerSignupEmailData($merchant, $customer, $context),
            NotificationType::CARD_CREATED => $this->buildCardCreatedEmailData($merchant, $customer, $context),
            NotificationType::CARD_COMPLETED => $this->buildCardCompletedEmailData($merchant, $customer, $context),
            NotificationType::POINTS_ADDED => $this->buildPointsAddedEmailData($merchant, $customer, $context),
            NotificationType::REWARD_CLAIMED => $this->buildRewardClaimedEmailData($merchant, $customer, $context),
            default => throw new \InvalidArgumentException(sprintf('Unsupported email notification type "%s".', $type->value)),
        };
    }

    /**
     * @param array{dashboard_url?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildCustomerSignupEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $subject = sprintf('Bienvenue chez %s sur Coachat', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Bienvenue chez %s. Votre inscription est maintenant terminee.', $merchant->getCompanyName() ?? 'ce commerce'),
            'Votre espace client est pret pour suivre vos cartes, vos points et vos recompenses.',
            $dashboardUrl !== '' ? sprintf('Acceder a mon dashboard: %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_signup_welcome.html.twig', [
            'email_title' => 'Bienvenue sur Coachat',
            'email_eyebrow' => 'Bienvenue client',
            'email_accent' => 'BIENVENUE',
            'summary' => sprintf('Votre inscription chez %s est confirmee. Vous pouvez maintenant acceder a votre espace client.', $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $customer->getName() ?? 'Client',
            'primary_label' => 'Compte',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Merchant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, program_name?: string, target_value?: int|null, unit_label?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildCardCreatedEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $programName = (string) ($context['program_name'] ?? 'Carte fidelite');
        $targetValue = $context['target_value'] ?? null;
        $unitLabel = (string) ($context['unit_label'] ?? 'points');
        $subject = sprintf('Votre carte fidelite est prete chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Votre carte "%s" est maintenant active chez %s.', $programName, $merchant->getCompanyName() ?? 'ce commerce'),
            'Vous pouvez des maintenant la faire scanner lors de votre prochaine visite chez ce merchant partenaire.',
            $targetValue !== null ? sprintf('Objectif: %s %s', $targetValue, $unitLabel) : null,
            $dashboardUrl !== '' ? sprintf('Voir mon dashboard: %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_card_created.html.twig', [
            'email_title' => 'Carte activée',
            'email_eyebrow' => 'Nouvelle carte',
            'email_accent' => 'CARTE PRETE',
            'summary' => sprintf('Votre carte "%s" est prete. Vous pouvez maintenant la faire scanner chez %s.', $programName, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $programName,
            'primary_label' => 'Programme',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Merchant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'program_name' => $programName,
            'target_value' => $targetValue,
            'unit_label' => $unitLabel,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, reward_description?: string, program_name?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildCardCompletedEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $rewardDescription = (string) ($context['reward_description'] ?? 'Votre recompense');
        $programName = (string) ($context['program_name'] ?? 'Carte fidelite');
        $subject = sprintf('Votre recompense est prete chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Votre carte "%s" est completee chez %s.', $programName, $merchant->getCompanyName() ?? 'ce commerce'),
            sprintf('Bonne nouvelle, votre recompense "%s" est prete a etre recuperee.', $rewardDescription),
            $dashboardUrl !== '' ? sprintf('Voir mon dashboard: %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_card_completed.html.twig', [
            'email_title' => 'Recompense prete',
            'email_eyebrow' => 'Carte completee',
            'email_accent' => 'RECOMPENSE PRETE',
            'summary' => sprintf('Votre carte "%s" est completee. Votre recompense "%s" est maintenant prete a etre recuperee.', $programName, $rewardDescription),
            'primary_value' => $rewardDescription,
            'primary_label' => 'Recompense',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Merchant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'reward_description' => $rewardDescription,
            'program_name' => $programName,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
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
            sprintf('Bravo pour votre recompense "%s", recuperee chez %s.', $rewardDescription, $merchant->getCompanyName() ?? 'ce commerce'),
            'Nous esperons bientot vous revoir chez notre commercant partenaire.',
            $dashboardUrl !== '' ? sprintf('Creez une nouvelle carte depuis votre dashboard: %s', $dashboardUrl) : null,
            'Vous pouvez aussi vous rendre directement chez le commercant partenaire pour relancer votre parcours.',
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