<?php

namespace App\Service;

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

        $subject = sprintf('Nouveau client inscrit via la carte Coachat : %s', $customerName);
        $textBody = implode("\n", [
            sprintf('Bonjour %s,', $merchantName),
            '',
            'Un nouveau client vient de rejoindre votre programme de fidélité depuis la carte interactive de l\'application Coachat.',
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
                'summary' => 'Un client vient de s\'inscrire depuis le parcours QR et a ete rattache a un merchant.',
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
}