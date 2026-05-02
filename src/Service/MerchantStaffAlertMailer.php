<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class MerchantStaffAlertMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $merchantLogger,
        private readonly string $fromEmail,
        private readonly string $fromName,
            private readonly string $appFrontBaseUrl,
    ) {
    }

    public function notifyMerchantRolePromoted(Customer $customer, Merchant $merchant): void
    {
        $customerEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail() ?? 'n/a';
        $customerName = $customer->getName() ?? $customer->getUser()?->getName() ?? 'n/a';
        $merchantName = $merchant->getCompanyName() ?? 'n/a';

        $this->sendMessage(
            to: $customerEmail,
            subject: sprintf('Vous êtes promu administrateur chez %s', $merchantName),
            textBody: implode("\n", [
                sprintf('Bonjour %s,', $customerName),
                '',
                sprintf('Vous avez été promu administrateur du commerce %s.', $merchantName),
                '',
                'En tant qu\'administrateur, vous pouvez maintenant :',
                '- Gérer les cartes de fidélité',
                '- Ajouter et gérer les équipiers',
                '- Consulter les statistiques détaillées',
                '- Modifier les réglages du commerce',
                '',
                'Accédez à votre dashboard pour explorer vos nouvelles fonctionnalités.',
                '',
                'Cordialement,',
                'L\'équipe Coachat',
            ]),
            htmlBody: $this->twig->render('emails/merchant_role_promoted.html.twig', [
                'email_title' => 'Promotion confirmée',
                'email_eyebrow' => 'Alerte rôle',
                'email_accent' => 'Administrateur',
                'summary' => 'Vous avez été promu administrateur du commerce.',
                'primary_label' => 'Nom du commerce',
                'primary_value' => $merchantName,
                'secondary_label' => 'Email',
                'secondary_value' => $customerEmail,
                    'dashboard_url' => rtrim($this->appFrontBaseUrl, '/'),
                'customer_name' => $customerName,
                'merchant_name' => $merchantName,
                'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                'show_google_review_invite' => false,
            ]),
            context: [
                'action' => 'merchant_role_promoted',
                'customer_id' => $customer->getId(),
                'customer_email' => $customerEmail,
                'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                'merchant_name' => $merchantName,
            ],
        );
    }

    public function notifyMerchantRoleDemoted(Customer $customer, Merchant $merchant): void
    {
        $customerEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail() ?? 'n/a';
        $customerName = $customer->getName() ?? $customer->getUser()?->getName() ?? 'n/a';
        $merchantName = $merchant->getCompanyName() ?? 'n/a';

        $this->sendMessage(
            to: $customerEmail,
            subject: sprintf('Changement de vos permissions chez %s', $merchantName),
            textBody: implode("\n", [
                sprintf('Bonjour %s,', $customerName),
                '',
                sprintf('Votre rôle a été modifié chez %s.', $merchantName),
                '',
                'Vous êtes maintenant équipier et vos permissions ont été réduites.',
                'Vous conservez l\'accès à votre espace équipier pour consulter les informations du commerce.',
                '',
                'Si vous avez des questions, veuillez contacter votre responsable.',
                '',
                'Cordialement,',
                'L\'équipe Coachat',
            ]),
                htmlBody: $this->twig->render('emails/merchant_role_demoted.html.twig', [
                    'email_title' => 'Changement de permissions',
                    'email_eyebrow' => 'Alerte rôle',
                    'email_accent' => 'Équipier',
                    'summary' => 'Votre rôle a été modifié.',
                    'primary_label' => 'Nom du commerce',
                    'primary_value' => $merchantName,
                    'secondary_label' => 'Email',
                    'secondary_value' => $customerEmail,
                        'dashboard_url' => rtrim($this->appFrontBaseUrl, '/'),
                    'customer_name' => $customerName,
                    'merchant_name' => $merchantName,
                    'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                    'show_google_review_invite' => false,
                ]),
            context: [
                'action' => 'merchant_role_demoted',
                'customer_id' => $customer->getId(),
                'customer_email' => $customerEmail,
                'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                'merchant_name' => $merchantName,
            ],
        );
    }

    public function notifyNewOwner(Customer $customer, Merchant $merchant, User $formerOwner): void
    {
        $customerEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail() ?? 'n/a';
        $customerName = $customer->getName() ?? $customer->getUser()?->getName() ?? 'n/a';
        $merchantName = $merchant->getCompanyName() ?? 'n/a';
        $formerOwnerName = $formerOwner->getName() ?? 'n/a';

        $this->sendMessage(
            to: $customerEmail,
            subject: sprintf('Vous êtes maintenant propriétaire de %s', $merchantName),
            textBody: implode("\n", [
                sprintf('Bonjour %s,', $customerName),
                '',
                sprintf('Vous êtes maintenant le propriétaire du compte commerce %s.', $merchantName),
                '',
                'En tant que propriétaire, vous avez les permissions maximales sur le commerce :',
                '- Accès complet à la gestion des cartes de fidélité',
                '- Gestion complète des équipiers et des administrateurs',
                '- Accès aux statistiques et rapports détaillés',
                '- Modification de tous les réglages du commerce',
                '- Transfert de la propriété à un autre administrateur',
                '',
                sprintf('L\'ancien propriétaire (%s) a conservé ses accès administrateur.', $formerOwnerName),
                '',
                'Accédez immédiatement à votre dashboard pour confirmer cette accélération.',
                '',
                'Bienvenue cher propriétaire !',
                'L\'équipe Coachat',
            ]),
                htmlBody: $this->twig->render('emails/ownership_transfer_new_owner.html.twig', [
                    'email_title' => 'Nouveau propriétaire',
                    'email_eyebrow' => 'Alerte propriété',
                    'email_accent' => 'Propriétaire',
                    'summary' => 'Vous êtes maintenant propriétaire du commerce.',
                    'primary_label' => 'Nom du commerce',
                    'primary_value' => $merchantName,
                    'secondary_label' => 'Statut',
                    'secondary_value' => 'Propriétaire',
                        'dashboard_url' => rtrim($this->appFrontBaseUrl, '/'),
                    'customer_name' => $customerName,
                    'merchant_name' => $merchantName,
                    'former_owner_name' => $formerOwnerName,
                    'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                    'show_google_review_invite' => false,
                ]),
            context: [
                'action' => 'ownership_transfer_new_owner',
                'customer_id' => $customer->getId(),
                'customer_email' => $customerEmail,
                'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                'merchant_name' => $merchantName,
                'former_owner_id' => $formerOwner->getId(),
            ],
        );
    }

    public function notifyFormerOwner(User $formerOwner, Merchant $merchant, User $newOwner): void
    {
        $formerOwnerEmail = $formerOwner->getEmail() ?? 'n/a';
        $formerOwnerName = $formerOwner->getName() ?? 'n/a';
        $merchantName = $merchant->getCompanyName() ?? 'n/a';
        $newOwnerName = $newOwner->getName() ?? 'n/a';

        $this->sendMessage(
            to: $formerOwnerEmail,
            subject: sprintf('Transfert de propriété pour %s', $merchantName),
            textBody: implode("\n", [
                sprintf('Bonjour %s,', $formerOwnerName),
                '',
                sprintf('La propriété du compte commerce %s a été transférée.', $merchantName),
                '',
                sprintf('Le nouveau propriétaire est : %s.', $newOwnerName),
                '',
                'Votre rôle est passé à administrateur. Vous conservez un accès complet aux fonctionnalités administrateur du commerce.',
                'Vous ne pouvez désormais plus transférer la propriété ou modifier les permissions de propriétaire.',
                '',
                'Si vous avez des questions concerning ce transfert, veuillez contacter votre équipe de gestion.',
                '',
                'Cordialement,',
                'L\'équipe Coachat',
            ]),
                htmlBody: $this->twig->render('emails/ownership_transfer_former_owner.html.twig', [
                    'email_title' => 'Perte de propriété',
                    'email_eyebrow' => 'Alerte propriété',
                    'email_accent' => 'Administrateur',
                    'summary' => 'La propriété a été transférée.',
                    'primary_label' => 'Nom du commerce',
                    'primary_value' => $merchantName,
                    'secondary_label' => 'Nouveau propriétaire',
                    'secondary_value' => $newOwnerName,
                        'dashboard_url' => rtrim($this->appFrontBaseUrl, '/'),
                    'former_owner_name' => $formerOwnerName,
                    'merchant_name' => $merchantName,
                    'new_owner_name' => $newOwnerName,
                    'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                    'show_google_review_invite' => false,
                ]),
            context: [
                'action' => 'ownership_transfer_former_owner',
                'former_owner_id' => $formerOwner->getId(),
                'former_owner_email' => $formerOwnerEmail,
                'new_owner_id' => $newOwner->getId(),
                'merchant_id' => $merchant->getId()?->toRfc4122() ?? 'n/a',
                'merchant_name' => $merchantName,
            ],
        );
    }

    private function sendMessage(string $to, string $subject, string $textBody, string $htmlBody, array $context): void
    {
        $email = (new Email())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($subject)
            ->text($textBody)
            ->html($htmlBody);

        $this->merchantLogger->info('Staff alert email send started.', $context + [
            'recipient' => $to,
            'subject' => $subject,
        ]);

        try {
            $this->mailer->send($email);

            $this->merchantLogger->info('Staff alert email sent.', $context + [
                'recipient' => $to,
                'subject' => $subject,
            ]);
        } catch (TransportExceptionInterface|\Throwable $exception) {
            $this->merchantLogger->error('Failed to send staff alert email.', $context + [
                'recipient' => $to,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
