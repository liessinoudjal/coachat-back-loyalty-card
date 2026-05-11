<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\LoyaltyProgramType;
use App\Enum\NotificationType;
use App\Repository\MerchantGoogleReviewModuleRepository;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function send(Merchant $merchant, Customer $customer, NotificationType $type, array $context = []): void
    {
        $merchantId = $merchant->getId()?->toRfc4122();
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail();
        $customerId = $customer->getId() !== null ? (string) $customer->getId() : null;
        $recipient = $customer->getEmail() ?? $customer->getUser()?->getEmail();

        if ($recipient === null || trim($recipient) === '') {
            $this->logger->warning('Customer email notification skipped: missing recipient email.', [
                'notification_type' => $type->value,
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
                'customer_id' => $customerId,
            ]);

            return;
        }

        $this->logger->info('Customer email notification send started.', [
            'notification_type' => $type->value,
            'merchant_id' => $merchantId,
            'merchant_email' => $merchantEmail,
            'customer_id' => $customerId,
            'customer_email' => $recipient,
            'recipient_email' => $recipient,
        ]);

        try {
            $emailData = $this->buildEmailData($merchant, $customer, $type, $context);
        } catch (\Throwable $exception) {
            $this->logger->error('Customer email notification payload build failed.', [
                'notification_type' => $type->value,
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
                'customer_id' => $customerId,
                'customer_email' => $recipient,
                'recipient_email' => $recipient,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $email = (new Email())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($recipient)
            ->subject($emailData['subject'])
            ->text($emailData['text'])
            ->html($emailData['html']);

        try {
            $this->mailer->send($email);

            $this->logger->info('Customer email notification sent.', [
                'notification_type' => $type->value,
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
                'customer_id' => $customerId,
                'customer_email' => $recipient,
                'recipient_email' => $recipient,
                'subject' => $emailData['subject'],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Customer email notification failed.', [
                'notification_type' => $type->value,
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
                'customer_id' => $customerId,
                'customer_email' => $recipient,
                'recipient_email' => $recipient,
                'subject' => $emailData['subject'],
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    private function buildEmailData(Merchant $merchant, Customer $customer, NotificationType $type, array $context): array
    {
        return match ($type) {
            NotificationType::CUSTOMER_SIGNUP => $this->buildCustomerSignupEmailData($merchant, $customer, $context),
            NotificationType::EQUIPIER_ASSIGNED => $this->buildEquipierAssignedEmailData($merchant, $customer, $context),
            NotificationType::EQUIPIER_REMOVED => $this->buildEquipierRemovedEmailData($merchant, $customer, $context),
            NotificationType::CARD_CREATED => $this->buildCardCreatedEmailData($merchant, $customer, $context),
            NotificationType::CARD_COMPLETED => $this->buildCardCompletedEmailData($merchant, $customer, $context),
            NotificationType::POINTS_ADDED => $this->buildPointsAddedEmailData($merchant, $customer, $context),
            NotificationType::REWARD_CLAIMED => $this->buildRewardClaimedEmailData($merchant, $customer, $context),
            NotificationType::PROMOTIONAL_OFFER_STARTS => $this->buildPromotionalOfferStartsEmailData($merchant, $customer, $context),
            NotificationType::PROMOTIONAL_OFFER_ENDING_SOON => $this->buildPromotionalOfferEndingSoonEmailData($merchant, $customer, $context),
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_BEFORE => $this->buildPromotionalOfferFlashDayBeforeEmailData($merchant, $customer, $context),
            NotificationType::PROMOTIONAL_OFFER_FLASH_DAY_OF => $this->buildPromotionalOfferFlashDayOfEmailData($merchant, $customer, $context),
            NotificationType::CONTEST_DAY_BEFORE_START => $this->buildContestDayBeforeStartEmailData($merchant, $customer, $context),
            NotificationType::CONTEST_STARTS => $this->buildContestStartsEmailData($merchant, $customer, $context),
            NotificationType::CONTEST_ENDING_SOON => $this->buildContestEndingSoonEmailData($merchant, $customer, $context),
            default => throw new \InvalidArgumentException(sprintf('Unsupported email notification type "%s".', $type->value)),
        };
    }

    /**
     * @param array{dashboard_url?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildEquipierAssignedEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $subject = sprintf('Votre espace équipier est actif chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Un espace équipier vous a été attribué chez %s.', $merchant->getCompanyName() ?? 'ce commerce'),
            'Depuis votre dashboard client, ouvrez le menu puis cliquez sur "Changer d\'espace" pour accéder à votre espace équipier.',
            'Vous pouvez basculer à tout moment entre votre profil client et votre profil équipier depuis ce même menu.',
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/equipier_assigned.html.twig', [
            'email_title' => 'Espace équipier activé',
            'email_eyebrow' => 'Nouveau rôle',
            'email_accent' => 'ÉQUIPIER',
            'summary' => sprintf('Votre accès équipier est maintenant actif chez %s.', $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $customer->getName() ?? 'Utilisateur',
            'primary_label' => 'Compte',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'show_google_review_invite' => false,
            'google_review_url' => null,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildEquipierRemovedEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $subject = sprintf('Votre accès équipier a été désactivé chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Votre accès à l\'espace équipier chez %s a été désactivé.', $merchant->getCompanyName() ?? 'ce commerce'),
            'Votre espace client reste disponible normalement.',
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
        ]);

        $html = $this->twig->render('emails/equipier_removed.html.twig', [
            'email_title' => 'Espace équipier désactivé',
            'email_eyebrow' => 'Mise à jour du compte',
            'email_accent' => 'ÉQUIPIER',
            'summary' => sprintf('Votre accès équipier chez %s a été retiré.', $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $customer->getName() ?? 'Utilisateur',
            'primary_label' => 'Compte',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'show_google_review_invite' => false,
            'google_review_url' => null,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
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
     * @param array{dashboard_url?: string, offer_title?: string, offer_description?: string, offer_starts_on?: string, offer_ends_on?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildPromotionalOfferStartsEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $offerTitle = (string) ($context['offer_title'] ?? 'Bon plan');
        $offerDescription = (string) ($context['offer_description'] ?? 'Un nouveau bon plan est disponible.');
        $startsOn = (string) ($context['offer_starts_on'] ?? '');
        $endsOn = (string) ($context['offer_ends_on'] ?? '');
        $subject = sprintf('Nouveau bon plan disponible chez %s', $merchant->getCompanyName() ?? 'Coachat');
        $googleReviewInviteContext = $this->buildGoogleReviewInviteContext($merchant, $context);

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Offre exceptionnelle chez %s : "%s".', $merchant->getCompanyName() ?? 'ce commerce', $offerTitle),
            sprintf('Détail : %s', $offerDescription),
            $startsOn !== '' && $endsOn !== '' ? sprintf('Valable du %s au %s.', $startsOn, $endsOn) : null,
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez désactiver à tout moment les bons plans de ce commerçant depuis votre profil client.',
        ]);

        $html = $this->twig->render('emails/promotional_offer_starts.html.twig', [
            'email_title' => 'Bon plan disponible',
            'email_eyebrow' => 'Offre promotionnelle',
            'email_accent' => 'BON PLAN',
            'summary' => sprintf('Offre exceptionnelle chez %s : "%s".', $merchant->getCompanyName() ?? 'ce commerce', $offerTitle),
            'primary_value' => $offerTitle,
            'primary_label' => 'Offre',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'offer_title' => $offerTitle,
            'offer_description' => $offerDescription,
            'offer_starts_on' => $startsOn,
            'offer_ends_on' => $endsOn,
        ] + $googleReviewInviteContext);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, offer_title?: string, offer_description?: string, offer_starts_on?: string, offer_ends_on?: string, days_left?: int} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildPromotionalOfferEndingSoonEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $offerTitle = (string) ($context['offer_title'] ?? 'Bon plan');
        $offerDescription = (string) ($context['offer_description'] ?? 'Profitez encore de ce bon plan.');
        $startsOn = (string) ($context['offer_starts_on'] ?? '');
        $endsOn = (string) ($context['offer_ends_on'] ?? '');
        $daysLeft = max(1, (int) ($context['days_left'] ?? 2));
        $subject = sprintf('Plus que %d jours pour profiter du bon plan chez %s', $daysLeft, $merchant->getCompanyName() ?? 'Coachat');
        $googleReviewInviteContext = $this->buildGoogleReviewInviteContext($merchant, $context);

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Offre exceptionnelle chez %s : "%s" se termine dans %d jours.', $merchant->getCompanyName() ?? 'ce commerce', $offerTitle, $daysLeft),
            sprintf('Détail : %s', $offerDescription),
            $startsOn !== '' && $endsOn !== '' ? sprintf('Valable du %s au %s.', $startsOn, $endsOn) : null,
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez désactiver à tout moment les bons plans de ce commerçant depuis votre profil client.',
        ]);

        $html = $this->twig->render('emails/promotional_offer_ending_soon.html.twig', [
            'email_title' => sprintf('Plus que %d jours', $daysLeft),
            'email_eyebrow' => 'Offre promotionnelle',
            'email_accent' => 'DERNIERS JOURS',
            'summary' => sprintf('"%s" se termine dans %d jours chez %s.', $offerTitle, $daysLeft, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $offerTitle,
            'primary_label' => 'Offre',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'offer_title' => $offerTitle,
            'offer_description' => $offerDescription,
            'offer_starts_on' => $startsOn,
            'offer_ends_on' => $endsOn,
            'days_left' => $daysLeft,
        ] + $googleReviewInviteContext);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, offer_title?: string, offer_description?: string, offer_date?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildPromotionalOfferFlashDayBeforeEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $offerTitle = (string) ($context['offer_title'] ?? 'Offre flash');
        $offerDescription = (string) ($context['offer_description'] ?? 'Une offre exceptionnelle vous attend demain.');
        $offerDate = (string) ($context['offer_date'] ?? '');
        $subject = sprintf('Demain chez %s : offre flash à ne pas manquer !', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Offre flash demain chez %s : "%s".', $merchant->getCompanyName() ?? 'ce commerce', $offerTitle),
            sprintf('Détail : %s', $offerDescription),
            $offerDate !== '' ? sprintf('Uniquement le %s — une seule journée !', $offerDate) : null,
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez désactiver à tout moment les bons plans de ce commerçant depuis votre profil client.',
        ]);

        $html = $this->twig->render('emails/promotional_offer_flash_day_before.html.twig', [
            'email_title' => 'Offre flash demain',
            'email_eyebrow' => 'Offre exceptionnelle',
            'email_accent' => 'DEMAIN SEULEMENT',
            'summary' => sprintf('Offre flash demain chez %s : "%s". Une seule journée, ne la manquez pas !', $merchant->getCompanyName() ?? 'ce commerce', $offerTitle),
            'primary_value' => $offerTitle,
            'primary_label' => 'Offre flash',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'offer_title' => $offerTitle,
            'offer_description' => $offerDescription,
            'offer_date' => $offerDate,
            'show_google_review_invite' => false,
            'google_review_url' => null,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, offer_title?: string, offer_description?: string, offer_date?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildPromotionalOfferFlashDayOfEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $offerTitle = (string) ($context['offer_title'] ?? 'Offre flash');
        $offerDescription = (string) ($context['offer_description'] ?? "Une offre exceptionnelle est disponible aujourd'hui.");
        $offerDate = (string) ($context['offer_date'] ?? '');
        $subject = sprintf("Aujourd'hui seulement chez %s : offre flash !", $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf("Offre flash aujourd'hui chez %s : \"%s\".", $merchant->getCompanyName() ?? 'ce commerce', $offerTitle),
            sprintf('Détail : %s', $offerDescription),
            $offerDate !== '' ? sprintf('Uniquement le %s — ne la manquez pas !', $offerDate) : null,
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez désactiver à tout moment les bons plans de ce commerçant depuis votre profil client.',
        ]);

        $html = $this->twig->render('emails/promotional_offer_flash_day_of.html.twig', [
            'email_title' => "Offre flash aujourd'hui",
            'email_eyebrow' => 'Offre exceptionnelle',
            'email_accent' => "AUJOURD'HUI SEULEMENT",
            'summary' => sprintf("Offre flash aujourd'hui chez %s : \"%s\". Dernière chance !", $merchant->getCompanyName() ?? 'ce commerce', $offerTitle),
            'primary_value' => $offerTitle,
            'primary_label' => 'Offre flash',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'offer_title' => $offerTitle,
            'offer_description' => $offerDescription,
            'offer_date' => $offerDate,
            'show_google_review_invite' => false,
            'google_review_url' => null,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, contest_title?: string, contest_description?: string, contest_start_at?: string, contest_end_at?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildContestDayBeforeStartEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $contestTitle = (string) ($context['contest_title'] ?? 'Jeu concours');
        $contestDescription = (string) ($context['contest_description'] ?? 'Un nouveau jeu concours arrive.');
        $startAt = (string) ($context['contest_start_at'] ?? '');
        $endAt = (string) ($context['contest_end_at'] ?? '');
        $subject = sprintf('Demain, nouveau jeu concours chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Demain, le jeu concours "%s" commence chez %s.', $contestTitle, $merchant->getCompanyName() ?? 'ce commerce'),
            sprintf('Détail : %s', $contestDescription),
            $startAt !== '' && $endAt !== '' ? sprintf('Période : du %s au %s.', $startAt, $endAt) : null,
            'Comment s\'inscrire : présentez votre carte de fidélité en magasin lors de vos achats.',
            'Plus vous faites scanner votre carte, plus vous augmentez vos chances de remporter un prix.',
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez désactiver à tout moment les notifications concours depuis votre profil client.',
        ]);

        $html = $this->twig->render('emails/contest_day_before_start.html.twig', [
            'email_title' => 'Jeu concours demain',
            'email_eyebrow' => 'Jeu concours',
            'email_accent' => 'J-1',
            'summary' => sprintf('Le jeu concours "%s" démarre demain chez %s.', $contestTitle, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $contestTitle,
            'primary_label' => 'Concours',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'contest_title' => $contestTitle,
            'contest_description' => $contestDescription,
            'contest_start_at' => $startAt,
            'contest_end_at' => $endAt,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, contest_title?: string, contest_description?: string, contest_start_at?: string, contest_end_at?: string} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildContestStartsEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $contestTitle = (string) ($context['contest_title'] ?? 'Jeu concours');
        $contestDescription = (string) ($context['contest_description'] ?? 'Le jeu concours est ouvert.');
        $startAt = (string) ($context['contest_start_at'] ?? '');
        $endAt = (string) ($context['contest_end_at'] ?? '');
        $subject = sprintf('Le jeu concours commence aujourd\'hui chez %s', $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Le jeu concours "%s" est maintenant ouvert chez %s.', $contestTitle, $merchant->getCompanyName() ?? 'ce commerce'),
            sprintf('Détail : %s', $contestDescription),
            $startAt !== '' && $endAt !== '' ? sprintf('Période : du %s au %s.', $startAt, $endAt) : null,
            'Comment s\'inscrire : présentez votre carte de fidélité en magasin lors de vos achats.',
            'Plus vous faites scanner votre carte, plus vous augmentez vos chances de remporter un prix.',
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez désactiver à tout moment les notifications concours depuis votre profil client.',
        ]);

        $html = $this->twig->render('emails/contest_starts.html.twig', [
            'email_title' => 'Jeu concours ouvert',
            'email_eyebrow' => 'Jeu concours',
            'email_accent' => 'J-0',
            'summary' => sprintf('Le jeu concours "%s" démarre aujourd\'hui chez %s.', $contestTitle, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $contestTitle,
            'primary_label' => 'Concours',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'contest_title' => $contestTitle,
            'contest_description' => $contestDescription,
            'contest_start_at' => $startAt,
            'contest_end_at' => $endAt,
        ]);

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    /**
     * @param array{dashboard_url?: string, contest_title?: string, contest_description?: string, contest_start_at?: string, contest_end_at?: string, days_left?: int} $context
     *
     * @return array{subject: string, text: string, html: string}
     */
    private function buildContestEndingSoonEmailData(Merchant $merchant, Customer $customer, array $context): array
    {
        $dashboardUrl = (string) ($context['dashboard_url'] ?? '');
        $contestTitle = (string) ($context['contest_title'] ?? 'Jeu concours');
        $contestDescription = (string) ($context['contest_description'] ?? 'Le jeu concours se termine bientôt.');
        $startAt = (string) ($context['contest_start_at'] ?? '');
        $endAt = (string) ($context['contest_end_at'] ?? '');
        $daysLeft = max(1, (int) ($context['days_left'] ?? 2));
        $subject = sprintf('Plus que %d jours pour participer au jeu concours chez %s', $daysLeft, $merchant->getCompanyName() ?? 'Coachat');

        $text = implode("\n", [
            sprintf('Bonjour %s,', $customer->getName() ?? 'client'),
            '',
            sprintf('Le jeu concours "%s" se termine dans %d jours chez %s.', $contestTitle, $daysLeft, $merchant->getCompanyName() ?? 'ce commerce'),
            sprintf('Détail : %s', $contestDescription),
            $startAt !== '' && $endAt !== '' ? sprintf('Période : du %s au %s.', $startAt, $endAt) : null,
            'Comment s\'inscrire : présentez votre carte de fidélité en magasin lors de vos achats.',
            'Plus vous faites scanner votre carte, plus vous augmentez vos chances de remporter un prix.',
            $dashboardUrl !== '' ? sprintf('Accéder au dashboard : %s', $dashboardUrl) : null,
            'Vous pouvez désactiver à tout moment les notifications concours depuis votre profil client.',
        ]);

        $html = $this->twig->render('emails/contest_ending_soon.html.twig', [
            'email_title' => sprintf('Plus que %d jours', $daysLeft),
            'email_eyebrow' => 'Jeu concours',
            'email_accent' => 'DERNIERE LIGNE DROITE',
            'summary' => sprintf('Le jeu concours "%s" se termine dans %d jours chez %s.', $contestTitle, $daysLeft, $merchant->getCompanyName() ?? 'ce commerce'),
            'primary_value' => $contestTitle,
            'primary_label' => 'Concours',
            'secondary_value' => $merchant->getCompanyName() ?? 'Coachat',
            'secondary_label' => 'Commerçant',
            'customer' => $customer,
            'merchant' => $merchant,
            'dashboard_url' => $dashboardUrl,
            'contest_title' => $contestTitle,
            'contest_description' => $contestDescription,
            'contest_start_at' => $startAt,
            'contest_end_at' => $endAt,
            'days_left' => $daysLeft,
        ]);

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
        $merchantId = $merchant->getId()?->toRfc4122();
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail();
        $contextUrl = isset($context['google_review_url']) ? (string) $context['google_review_url'] : null;
        $contextDisplayName = isset($context['google_review_display_name']) ? (string) $context['google_review_display_name'] : 'Avis Google';
        $contextMerchantName = isset($context['google_review_merchant_name']) ? (string) $context['google_review_merchant_name'] : ($merchant->getCompanyName() ?? 'ce commerce');
        $contextShowFlag = $context['show_google_review_invite'] ?? null;

        if ($contextUrl !== null && trim($contextUrl) !== '') {
            $contextUrl = trim($contextUrl);
            $hasValidContextUrl = $this->googleReviewUrlValidator->isAllowedGoogleReviewUrl($contextUrl);
            $showInvite = is_bool($contextShowFlag) ? $contextShowFlag && $hasValidContextUrl : $hasValidContextUrl;

            $this->logger->info('Resolved Google review invite context from notification payload.', [
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
                'source' => 'context',
                'context_show_flag' => $contextShowFlag,
                'url_present' => true,
                'url_valid' => $hasValidContextUrl,
                'show_google_review_invite' => $showInvite,
                'google_review_url' => $showInvite ? $contextUrl : null,
            ]);

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

        $this->logger->info('Resolved Google review invite context from merchant module.', [
            'merchant_id' => $merchantId,
            'merchant_email' => $merchantEmail,
            'source' => 'merchant_module',
            'module_found' => $module !== null,
            'module_enabled' => $module?->isEnabled(),
            'url_present' => $url !== null && trim((string) $url) !== '',
            'url_valid' => $hasValidUrl,
            'show_google_review_invite' => $showInvite,
            'google_review_url' => $showInvite ? $url : null,
        ]);

        return [
            'show_google_review_invite' => $showInvite,
            'google_review_url' => $showInvite ? $url : null,
            'google_review_display_name' => $module?->getDisplayName() ?? 'Avis Google',
            'google_review_merchant_name' => $merchant->getCompanyName() ?? 'ce commerce',
        ];
    }
}