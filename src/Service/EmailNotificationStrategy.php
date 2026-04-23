<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\LoyaltyProgramType;
use App\Enum\NotificationType;
use App\Repository\MerchantGoogleReviewModuleRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class EmailNotificationStrategy implements NotificationStrategyInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly MerchantGoogleReviewModuleRepository $googleReviewModuleRepository,
        private readonly GoogleReviewUrlValidator $googleReviewUrlValidator,
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
        $googleReviewInviteContext = $this->buildGoogleReviewInviteContext($merchant, $context);

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Bienvenue chez %s. Votre inscription est maintenant terminée.', $merchant->getCompanyName() ?? 'ce commerce'),
            'Votre espace client est prêt pour suivre vos cartes, vos points et vos récompenses.',
            'Vous pouvez maintenant choisir une carte de fidélité du commerçant depuis votre espace client carte ou demander au commerçant de vous en attribuer une.',
            $dashboardUrl !== '' ? sprintf('Accéder à mon dashboard : %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_signup_welcome.html.twig', [
            'email_title' => 'Bienvenue sur Coachat',
            'email_eyebrow' => 'Bienvenue client',
            'email_accent' => 'BIENVENUE',
            'summary' => sprintf('Votre inscription chez %s est confirmée. Vous pouvez maintenant accéder à votre espace client.', $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $customer->getName() ?? 'Client',
            'primary_label' => 'Compte',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
        ] + $googleReviewInviteContext);

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
        $programName = (string) ($context['program_name'] ?? 'Carte fidélité');
        $targetValue = $context['target_value'] ?? null;
        $unitLabel = (string) ($context['unit_label'] ?? 'points');
        $subject = sprintf('Votre carte fidélité est prête chez %s', $merchant->getCompanyName() ?? 'Coachat');
        $googleReviewInviteContext = $this->buildGoogleReviewInviteContext($merchant, $context);

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Votre carte "%s" est maintenant active chez %s.', $programName, $merchant->getCompanyName() ?? 'ce commerce'),
            'Vous pouvez dès maintenant la faire scanner lors de votre prochaine visite chez ce commerçant partenaire.',
            $targetValue !== null ? sprintf('Objectif : %s %s', $targetValue, $unitLabel) : null,
            $dashboardUrl !== '' ? sprintf('Voir mon dashboard : %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_card_created.html.twig', [
            'email_title' => 'Carte activée',
            'email_eyebrow' => 'Nouvelle carte',
            'email_accent' => 'CARTE PRÊTE',
            'summary' => sprintf('Votre carte "%s" est prête. Vous pouvez maintenant la faire scanner chez %s.', $programName, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $programName,
            'primary_label' => 'Programme',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'program_name' => $programName,
            'target_value' => $targetValue,
            'unit_label' => $unitLabel,
        ] + $googleReviewInviteContext);

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
        $rewardDescription = (string) ($context['reward_description'] ?? 'Votre récompense');
        $programName = (string) ($context['program_name'] ?? 'Carte fidélité');
        $subject = sprintf('Votre récompense est prête chez %s', $merchant->getCompanyName() ?? 'Coachat');
        $googleReviewInviteContext = $this->buildGoogleReviewInviteContext($merchant, $context);

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Votre carte "%s" est complétée chez %s.', $programName, $merchant->getCompanyName() ?? 'ce commerce'),
            sprintf('Bonne nouvelle, votre récompense "%s" est prête à être récupérée.', $rewardDescription),
            $dashboardUrl !== '' ? sprintf('Voir mon dashboard : %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_card_completed.html.twig', [
            'email_title' => 'Récompense prête',
            'email_eyebrow' => 'Carte complétée',
            'email_accent' => 'RÉCOMPENSE PRÊTE',
            'summary' => sprintf('Votre carte "%s" est complétée. Votre récompense "%s" est maintenant prête à être récupérée.', $programName, $rewardDescription),
            'primary_value' => $rewardDescription,
            'primary_label' => 'Récompense',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'reward_description' => $rewardDescription,
            'program_name' => $programName,
        ] + $googleReviewInviteContext);

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
        $googleReviewInviteContext = $this->buildGoogleReviewInviteContext($merchant, $context);

        $subject = sprintf('Votre carte %s a été mise à jour chez %s', $unitLabel, $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('%d %s ont été ajoutés à votre carte chez %s.', $valueAdded, $unitLabel, $merchant->getCompanyName() ?? 'ce commerce'),
            $currentValue !== null && $targetValue !== null ? sprintf('Progression: %s / %s', $currentValue, $targetValue) : null,
            $rewardReady ? 'Votre carte est complétée et une récompense vous attend.' : null,
            $dashboardUrl !== '' ? sprintf('Voir mon dashboard : %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/customer_points_added.html.twig', [
            'email_title' => 'Carte mise à jour',
            'email_eyebrow' => 'Notification client',
            'email_accent' => strtoupper($unitLabel),
            'summary' => sprintf('%d %s ont été ajoutés à votre carte chez %s.', $valueAdded, $unitLabel, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => sprintf('%d %s', $valueAdded, $unitLabel),
            'primary_label' => 'Ajoutés',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'current_value' => $currentValue,
            'target_value' => $targetValue,
            'reward_ready' => $rewardReady,
            'unit_label' => $unitLabel,
        ] + $googleReviewInviteContext);

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
        $rewardDescription = (string) ($context['reward_description'] ?? 'Votre récompense');
        $googleReviewInviteContext = $this->buildGoogleReviewInviteContext($merchant, $context);

        $subject = sprintf('Récompense récupérée chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Bravo pour votre récompense "%s", récupérée chez %s.', $rewardDescription, $merchant->getCompanyName() ?? 'ce commerce'),
            'Nous espérons bientôt vous revoir chez notre commerçant partenaire.',
            $dashboardUrl !== '' ? sprintf('Créez une nouvelle carte depuis votre dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez aussi vous rendre directement chez le commerçant partenaire pour relancer votre parcours.',
        ]);

        $html = $this->twig->render('emails/customer_reward_claimed.html.twig', [
            'email_title' => 'Récompense récupérée',
            'email_eyebrow' => 'Notification client',
            'email_accent' => 'RÉCOMPENSE',
            'summary' => sprintf('Votre récompense "%s" a bien été récupérée chez %s.', $rewardDescription, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $rewardDescription,
            'primary_label' => 'Récompense',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'reward_description' => $rewardDescription,
        ] + $googleReviewInviteContext);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @return array{show_google_review_invite: bool, google_review_url: ?string, google_review_display_name: string, google_review_merchant_name: string}
     */
    private function buildGoogleReviewInviteContext(Merchant $merchant, array $context = []): array
    {
        $contextUrl = isset($context['google_review_url']) ? (string) $context['google_review_url'] : null;
        $contextDisplayName = isset($context['google_review_display_name']) ? (string) $context['google_review_display_name'] : 'Avis Google';
        $contextMerchantName = isset($context['google_review_merchant_name']) ? (string) $context['google_review_merchant_name'] : ($merchant->getCompanyName() ?? 'ce commerce');
        $contextShowFlag = $context['show_google_review_invite'] ?? null;

        if ($contextUrl !== null && trim($contextUrl) !== '') {
            $contextUrl = trim($contextUrl);
            $hasValidContextUrl = $this->googleReviewUrlValidator->isAllowedGoogleReviewUrl($contextUrl);
            $showInvite = is_bool($contextShowFlag) ? $contextShowFlag && $hasValidContextUrl : $hasValidContextUrl;

            return [
                'show_google_review_invite' => $showInvite,
                'google_review_url' => $showInvite ? $contextUrl : null,
                'google_review_display_name' => $contextDisplayName,
                'google_review_merchant_name' => $contextMerchantName,
            ];
        }

        $module = $this->googleReviewModuleRepository->findOneByMerchant($merchant);
        $url = $module?->getGoogleReviewUrl();
        $hasValidUrl = $this->googleReviewUrlValidator->isAllowedGoogleReviewUrl($url);
        $showInvite = $module?->isEnabled() === true && $hasValidUrl;

        return [
            'show_google_review_invite' => $showInvite,
            'google_review_url' => $showInvite ? $url : null,
            'google_review_display_name' => $module?->getDisplayName() ?? 'Avis Google',
            'google_review_merchant_name' => $merchant->getCompanyName() ?? 'ce commerce',
        ];
    }
}