<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
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