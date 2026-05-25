<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\PromotionalOffer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SignupAlertMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $customerLogger,
        private readonly LoggerInterface $merchantLogger,
        private readonly string $alertRecipient,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly string $appFrontBaseUrl,
    ) {
    }

    public function notifyMerchantSignup(Merchant $merchant): void
    {
        $merchantId = $merchant->getId()?->toRfc4122() ?? 'n/a';
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail() ?? 'n/a';
        $merchantName = $merchant->getCompanyName() ?? 'n/a';
        $contactName = $merchant->getUser()?->getName() ?? 'n/a';

        $this->sendMessage(
            subject: sprintf('Nouvelle inscription merchant: %s', $merchantName),
            textBody: implode("\n", [
                'Un merchant vient de s\'inscrire.',
                '',
                sprintf('Merchant ID: %s', $merchantId),
                sprintf('Société: %s', $merchantName),
                sprintf('Email: %s', $merchantEmail),
                sprintf('Nom contact: %s', $contactName),
                sprintf('Téléphone: %s', $merchant->getPhone() ?? 'n/a'),
                sprintf('Ville: %s', $merchant->getCity() ?? 'n/a'),
                sprintf('Code postal: %s', $merchant->getPostalCode() ?? 'n/a'),
            ]),
            htmlBody: $this->twig->render('emails/signup_alert_merchant.html.twig', [
                'email_title' => 'Nouveau merchant inscrit',
                'email_eyebrow' => 'Alerte inscription',
                'email_accent' => 'Merchant',
                'summary' => 'Un nouveau commerce vient de finaliser son inscription sur la plateforme.',
                'primary_value' => $merchantName,
                'primary_label' => 'Société',
                'secondary_value' => $merchantEmail,
                'secondary_label' => 'Email',
                'merchant' => [
                    'id' => $merchantId,
                    'company_name' => $merchantName,
                    'email' => $merchantEmail,
                    'contact_name' => $contactName,
                    'phone' => $merchant->getPhone() ?? 'n/a',
                    'city' => $merchant->getCity() ?? 'n/a',
                    'postal_code' => $merchant->getPostalCode() ?? 'n/a',
                ],
            ]),
            context: [
                'signup_type' => 'merchant',
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
            ],
            logger: $this->merchantLogger,
        );
    }

    public function notifyCustomerSignupViaMap(Customer $customer, Merchant $merchant): void
    {
        $merchantId = $merchant->getId()?->toRfc4122() ?? 'n/a';
        $merchantName = $merchant->getCompanyName() ?? 'n/a';
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail() ?? 'n/a';
        $customerEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail() ?? 'n/a';
        $customerName = $customer->getName() ?? $customer->getUser()?->getName() ?? 'n/a';
        $customerId = $customer->getId() !== null ? (string) $customer->getId() : 'n/a';

        $this->sendMessage(
            subject: sprintf('[MAP] Nouvelle inscription customer: %s', $customerEmail),
            textBody: implode("\n", [
                'Un customer vient de s\'inscrire via la carte interactive (map) de l\'application.',
                '',
                sprintf('Customer ID: %s', $customerId),
                sprintf('Email: %s', $customerEmail),
                sprintf('Nom: %s', $customerName),
                sprintf('Téléphone: %s', $customer->getPhone() ?? 'n/a'),
                sprintf('Merchant: %s', $merchantName),
                sprintf('Merchant ID: %s', $merchantId),
                sprintf('Source: Carte map (inscription automatique)'),
            ]),
            htmlBody: $this->twig->render('emails/signup_alert_customer.html.twig', [
                'email_title' => '[MAP] Nouveau customer inscrit',
                'email_eyebrow' => 'Alerte inscription',
                'email_accent' => 'Customer',
                'summary' => 'Un client s\'est inscrit via la carte interactive (map) de l\'application.',
                'source_label' => 'Carte map (inscription automatique)',
                'primary_value' => $customerEmail,
                'primary_label' => 'Email client',
                'secondary_value' => $merchantName,
                'secondary_label' => 'Merchant',
                'customer' => [
                    'id' => $customerId,
                    'email' => $customerEmail,
                    'name' => $customerName,
                    'phone' => $customer->getPhone() ?? 'n/a',
                ],
                'merchant' => [
                    'id' => $merchantId,
                    'company_name' => $merchantName,
                ],
            ]),
            context: [
                'signup_type' => 'customer_map',
                'customer_id' => $customerId,
                'customer_email' => $customerEmail,
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
            ],
            logger: $this->customerLogger,
        );
    }

    public function notifyMerchantNewCustomerViaMap(Customer $customer, Merchant $merchant): void
    {
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail();
        if ($merchantEmail === null || trim($merchantEmail) === '') {
            $this->merchantLogger->warning('Merchant map-join notification skipped: no email.', [
                'merchant_id' => $merchant->getId()?->toRfc4122(),
            ]);
            return;
        }

        $merchantName = $merchant->getCompanyName() ?? 'votre commerce';
        $customerEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail() ?? 'n/a';
        $customerName = $customer->getName() ?? $customer->getUser()?->getName() ?? 'n/a';

        $subject = sprintf('Nouveau client inscrit via la carte Lakarte : %s', $customerName);
        $textBody = implode("\n", [
            sprintf('Bonjour %s,', $merchantName),
            '',
            'Un nouveau client vient de rejoindre votre programme de fidélité depuis la carte interactive de l\'application Lakarte.',
            '',
            sprintf('Nom : %s', $customerName),
            sprintf('Email : %s', $customerEmail),
            '',
            'Vous pouvez retrouver ce client dans votre espace merchant.',
        ]);

        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address($this->fromEmail, $this->fromName))
            ->to($merchantEmail)
            ->subject($subject)
            ->text($textBody)
            ->html($this->twig->render('emails/merchant_new_customer_via_map.html.twig', [
                'merchant_name' => $merchantName,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
            ]));

        try {
            $this->mailer->send($email);
            $this->merchantLogger->info('Merchant map-join notification sent.', [
                'merchant_email' => $merchantEmail,
                'customer_email' => $customerEmail,
            ]);
        } catch (\Throwable $e) {
            $this->merchantLogger->error('Failed to send merchant map-join notification.', [
                'merchant_email' => $merchantEmail,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function notifyMerchantSignupRefusedDueToCustomerLimit(
        Merchant $merchant,
        ?Customer $customer,
        int $currentCustomers,
        int $maxCustomers,
        string $sourceLabel,
    ): void {
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail();
        if ($merchantEmail === null || trim($merchantEmail) === '') {
            $this->merchantLogger->warning('Merchant limit notification skipped: no email.', [
                'merchant_id' => $merchant->getId()?->toRfc4122(),
            ]);
            return;
        }

        $merchantName = $merchant->getCompanyName() ?? 'votre commerce';
        $merchantId = $merchant->getId()?->toRfc4122() ?? 'n/a';
        $customerEmail = $customer?->getEmail() ?? $customer?->getUser()?->getEmail() ?? 'n/a';
        $customerName = $customer?->getName() ?? $customer?->getUser()?->getName() ?? 'n/a';
        $subscriptionUrl = rtrim($this->appFrontBaseUrl, '/') . '/subscription';

        $subject = sprintf('Inscription customer refusée - plafond atteint chez %s', $merchantName);
        $textBody = array_filter([
            sprintf('Bonjour %s,', $merchantName),
            '',
                'Une demande d\'inscription client a été refusée car le plafond de votre plan est atteint.',
                sprintf('Plafond : %d abonnés maximum', $maxCustomers),
                sprintf('Abonnés actuels : %d', $currentCustomers),
                sprintf('Accédez à votre dashboard d\'abonnement pour activer un plan supérieur : %s', $subscriptionUrl),
            sprintf('Merchant ID: %s', $merchantId),
            sprintf('Source: %s', $sourceLabel),
            $customer !== null ? sprintf('Nom client: %s', $customerName) : null,
            $customer !== null ? sprintf('Email client: %s', $customerEmail) : null,
        ]);

        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address($this->fromEmail, $this->fromName))
            ->to($merchantEmail)
            ->subject($subject)
            ->text(implode("\n", $textBody))
            ->html($this->twig->render('emails/signup_alert_base.html.twig', [
                'email_title' => 'Inscription client refusée',
                'email_eyebrow' => 'Plafond atteint',
                'email_accent' => 'Alerte',
                'summary' => sprintf(
                    'Une demande d\'inscription client a été refusée chez %s car le plafond de votre plan est atteint.',
                    $merchantName,
                ),
                'primary_value' => sprintf('%d / %d abonnés', $currentCustomers, $maxCustomers),
                'primary_label' => 'Plafond atteint',
                'secondary_value' => $sourceLabel,
                'secondary_label' => 'Source',
                'dashboard_url' => $subscriptionUrl,
                'cta_label' => 'Activer un plan supérieur',
                    'cta_text' => 'Accédez à votre dashboard d\'abonnement pour activer un plan supérieur et rouvrir les inscriptions.',
                'cta_url' => $subscriptionUrl,
            ]));

        try {
            $this->mailer->send($email);
            $this->merchantLogger->info('Merchant limit notification sent.', [
                'merchant_email' => $merchantEmail,
                'merchant_id' => $merchantId,
                'current_customers' => $currentCustomers,
                'max_customers' => $maxCustomers,
                'source' => $sourceLabel,
            ]);
        } catch (\Throwable $e) {
            $this->merchantLogger->error('Failed to send merchant limit notification.', [
                'merchant_email' => $merchantEmail,
                'merchant_id' => $merchantId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function notifyCustomerSignup(Customer $customer, Merchant $merchant): void
    {
        $merchantId = $merchant->getId()?->toRfc4122() ?? 'n/a';
        $merchantName = $merchant->getCompanyName() ?? 'n/a';
        $merchantEmail = $merchant->getEmail() ?? $merchant->getUser()?->getEmail() ?? 'n/a';
        $customerEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail() ?? 'n/a';
        $customerName = $customer->getName() ?? $customer->getUser()?->getName() ?? 'n/a';
        $customerId = $customer->getId() !== null ? (string) $customer->getId() : 'n/a';

        $this->sendMessage(
            subject: sprintf('Nouvelle inscription customer: %s', $customerEmail),
            textBody: implode("\n", [
                'Un customer vient de s\'inscrire.',
                '',
                sprintf('Customer ID: %s', $customerId),
                sprintf('Email: %s', $customerEmail),
                sprintf('Nom: %s', $customerName),
                sprintf('Téléphone: %s', $customer->getPhone() ?? 'n/a'),
                sprintf('Merchant: %s', $merchantName),
                sprintf('Merchant ID: %s', $merchantId),
            ]),
            htmlBody: $this->twig->render('emails/signup_alert_customer.html.twig', [
                'email_title' => 'Nouveau customer inscrit',
                'email_eyebrow' => 'Alerte inscription',
                'email_accent' => 'Customer',
                'summary' => 'Un client vient de s\'inscrire depuis le parcours QR et a été rattaché à un merchant.',
                'primary_value' => $customerEmail,
                'primary_label' => 'Email client',
                'secondary_value' => $merchantName,
                'secondary_label' => 'Merchant',
                'customer' => [
                    'id' => $customerId,
                    'email' => $customerEmail,
                    'name' => $customerName,
                    'phone' => $customer->getPhone() ?? 'n/a',
                ],
                'merchant' => [
                    'id' => $merchantId,
                    'company_name' => $merchantName,
                ],
            ]),
            context: [
                'signup_type' => 'customer',
                'customer_id' => $customerId,
                'customer_email' => $customerEmail,
                'merchant_id' => $merchantId,
                'merchant_email' => $merchantEmail,
            ],
            logger: $this->customerLogger,
        );
    }

    public function notifyFlashOfferDispatched(PromotionalOffer $offer, string $triggerType): void
    {
        $offerId = (string) $offer->getId();
        $offerTitle = $offer->getTitle() ?? 'n/a';
        $offerDate = $offer->getStartsOn()?->format('Y-m-d') ?? 'n/a';
        $merchant = $offer->getMerchant();
        $merchantId = $merchant?->getId()?->toRfc4122() ?? 'n/a';
        $merchantName = $merchant?->getCompanyName() ?? 'n/a';

        $this->sendMessage(
            subject: sprintf('[Flash] Offre "%s" notifiée (%s) — %s', $offerTitle, $triggerType, $merchantName),
            textBody: implode("\n", [
                sprintf('Offre flash notifiée (%s).', $triggerType),
                '',
                sprintf('Offer ID: %s', $offerId),
                sprintf('Titre: %s', $offerTitle),
                sprintf('Date: %s', $offerDate),
                sprintf('Merchant ID: %s', $merchantId),
                sprintf('Merchant: %s', $merchantName),
                sprintf('Déclencheur: %s', $triggerType),
            ]),
            htmlBody: $this->twig->render('emails/signup_alert_base.html.twig', [
                'email_title' => sprintf('Offre flash notifiée (%s)', $triggerType),
                'email_eyebrow' => 'Alerte offre flash',
                'email_accent' => strtoupper($triggerType),
                'summary' => sprintf('L\'offre flash "%s" (%s) a été notifiée aux clients du commerce "%s".', $offerTitle, $triggerType, $merchantName),
                'primary_value' => $offerTitle,
                'primary_label' => 'Offre flash',
                'secondary_value' => $merchantName,
                'secondary_label' => 'Merchant',
            ]),
            context: [
                'event' => 'flash_offer_dispatched',
                'trigger_type' => $triggerType,
                'offer_id' => $offerId,
                'merchant_id' => $merchantId,
            ],
            logger: $this->merchantLogger,
        );
    }

    public function notifyContestCreated(Contest $contest): void
    {
        $contestId = $contest->getId()?->toRfc4122() ?? 'n/a';
        $merchant = $contest->getMerchant();
        $merchantId = $merchant?->getId()?->toRfc4122() ?? 'n/a';
        $merchantName = $merchant?->getCompanyName() ?? 'n/a';
        $contestTitle = $contest->getTitle() ?: 'n/a';
        $contestDescription = $contest->getDescription() ?: 'n/a';
        $contestStartAt = $contest->getStartAt()?->format(DATE_ATOM) ?? 'n/a';
        $contestEndAt = $contest->getEndAt()?->format(DATE_ATOM) ?? 'n/a';
        $contestDrawAt = $contest->getDrawAt()?->format(DATE_ATOM) ?? 'n/a';

        $rewards = $contest->getRewards()->toArray();
        usort(
            $rewards,
            static fn ($left, $right): int => $left->getRank() <=> $right->getRank(),
        );

        $rewardLines = array_map(
            static fn ($reward): string => sprintf('%d. %s', $reward->getRank(), $reward->getTitle()),
            $rewards,
        );

        $rewardListText = $rewardLines !== []
            ? implode("\n", array_map(static fn (string $line): string => sprintf('- %s', $line), $rewardLines))
            : '- Aucun lot renseigné';

        $this->sendMessage(
            subject: sprintf('[Contest] Nouveau jeu concours créé: %s', $contestTitle),
            textBody: implode("\n", [
                'Un nouveau jeu concours vient d\'être créé.',
                '',
                sprintf('Contest ID: %s', $contestId),
                sprintf('Titre: %s', $contestTitle),
                sprintf('Description: %s', $contestDescription),
                sprintf('Début: %s', $contestStartAt),
                sprintf('Fin: %s', $contestEndAt),
                sprintf('Tirage: %s', $contestDrawAt),
                'Lots:',
                $rewardListText,
                sprintf('Merchant ID: %s', $merchantId),
                sprintf('Merchant: %s', $merchantName),
            ]),
            htmlBody: $this->twig->render('emails/signup_alert_contest_created.html.twig', [
                'email_title' => 'Nouveau jeu concours créé',
                'email_eyebrow' => 'Alerte concours',
                'email_accent' => 'CONTEST',
                'summary' => sprintf('Le concours "%s" a été créé pour le commerce "%s".', $contestTitle, $merchantName),
                'primary_value' => $contestTitle,
                'primary_label' => 'Concours',
                'secondary_value' => $merchantName,
                'secondary_label' => 'Merchant',
                'contest_description' => $contestDescription,
                'contest_start_at' => $contestStartAt,
                'contest_end_at' => $contestEndAt,
                'contest_draw_at' => $contestDrawAt,
                'contest_rewards' => $rewardLines,
            ]),
            context: [
                'event' => 'contest_created',
                'contest_id' => $contestId,
                'merchant_id' => $merchantId,
            ],
            logger: $this->merchantLogger,
        );
    }

    /**
     * @param array<int, array{service:string, message:string, technical_message?:string}> $errors
     * @param array<string, int> $promotionalResult
     * @param array<string, int> $contestResult
     */
    public function notifyDailyDispatchErrorReport(
        \DateTimeImmutable $date,
        array $errors,
        array $promotionalResult,
        array $contestResult,
    ): void {
        if ($errors === []) {
            return;
        }

        $errorLines = array_map(function (array $error): string {
            $serviceLabel = $this->getDispatchServiceLabel((string) ($error['service'] ?? ''));
            $message = trim((string) ($error['message'] ?? ''));
            if ($message === '') {
                $message = 'Une anomalie a été détectée sur ce traitement.';
            }

            return sprintf('- %s : %s', $serviceLabel, $message);
        }, $errors);

        $promotionalSummary = sprintf(
            'Bons plans envoyés : début=%d, fin proche=%d, flash J-1=%d, flash J=%d',
            (int) ($promotionalResult['start_notifications_sent_for_offers'] ?? 0),
            (int) ($promotionalResult['ending_soon_notifications_sent_for_offers'] ?? 0),
            (int) ($promotionalResult['flash_day_before_notifications_sent_for_offers'] ?? 0),
            (int) ($promotionalResult['flash_day_of_notifications_sent_for_offers'] ?? 0),
        );

        $contestSummary = sprintf(
            'Concours envoyés : J-1=%d, début=%d, fin proche=%d, jour du tirage=%d',
            (int) ($contestResult['day_before_notifications_sent_for_contests'] ?? 0),
            (int) ($contestResult['start_notifications_sent_for_contests'] ?? 0),
            (int) ($contestResult['ending_soon_notifications_sent_for_contests'] ?? 0),
            (int) ($contestResult['draw_day_notifications_sent_for_contests'] ?? 0),
        );

        $this->sendMessage(
            subject: sprintf('[Notifications quotidiennes] Anomalies détectées - %s', $date->format('Y-m-d')),
            textBody: implode("\n", [
                'Le traitement quotidien des notifications a rencontré des anomalies.',
                '',
                sprintf('Date : %s', $date->format('Y-m-d')),
                $promotionalSummary,
                $contestSummary,
                '',
                'Points à vérifier :',
                ...$errorLines,
            ]),
            htmlBody: $this->twig->render('emails/signup_alert_base.html.twig', [
                'email_title' => 'Anomalies sur les notifications quotidiennes',
                'email_eyebrow' => 'Alerte suivi envois',
                'email_accent' => 'NOTIFICATIONS',
                'summary' => sprintf('Le traitement quotidien du %s a rencontré %d anomalie(s).', $date->format('Y-m-d'), count($errors)),
                'primary_value' => (string) count($errors),
                'primary_label' => 'Nombre d\'anomalies',
                'secondary_value' => $date->format('Y-m-d'),
                'secondary_label' => 'Date',
            ]),
            context: [
                'event' => 'daily_dispatch_errors',
                'date' => $date->format('Y-m-d'),
                'error_count' => count($errors),
                'promotional_result' => $promotionalResult,
                'contest_result' => $contestResult,
                'errors' => $errors,
            ],
            logger: $this->merchantLogger,
        );
    }

    private function sendMessage(string $subject, string $textBody, string $htmlBody, array $context, LoggerInterface $logger): void
    {
        $email = (new Email())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($this->alertRecipient)
            ->subject($subject)
            ->text($textBody)
            ->html($htmlBody);

        $logger->info('Signup alert email send started.', $context + [
            'alert_recipient' => $this->alertRecipient,
            'subject' => $subject,
        ]);

        try {
            $this->mailer->send($email);

            $logger->info('Signup alert email sent.', $context + [
                'alert_recipient' => $this->alertRecipient,
                'subject' => $subject,
            ]);
        } catch (TransportExceptionInterface|\Throwable $exception) {
            $logger->error('Failed to send signup alert email.', $context + [
                'exception' => $exception->getMessage(),
                'alert_recipient' => $this->alertRecipient,
                'subject' => $subject,
            ]);
        }
    }

    private function getDispatchServiceLabel(string $service): string
    {
        return match ($service) {
            'promotional_offer_dispatcher' => 'Envoi des bons plans',
            'contest_dispatcher' => 'Envoi des notifications concours',
            default => 'Traitement des notifications',
        };
    }
}