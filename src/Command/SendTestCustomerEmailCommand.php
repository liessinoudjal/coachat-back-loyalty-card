<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\NotificationType;
use App\Service\EmailNotificationStrategy;
use App\Service\SignupAlertMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mail:test-customer',
    description: 'Send customer test emails to Mailpit using mocked merchant/customer data.',
)]
#[When(env: 'dev')]
final class SendTestCustomerEmailCommand extends Command
{
    public function __construct(
        private readonly EmailNotificationStrategy $emailNotificationStrategy,
        private readonly SignupAlertMailer $signupAlertMailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional single template type: customer_signup, card_created, card_completed, points_added, reward_claimed. If omitted, all templates are sent.',
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                'Customer destination email address.',
                'customer.test@mailpit.local',
            )
            ->addOption(
                'customer-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Mock customer display name.',
                'Client Test',
            )
            ->addOption(
                'merchant-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Mock merchant company name.',
                'Merchant Demo',
            )
            ->addOption(
                'merchant-email',
                null,
                InputOption::VALUE_REQUIRED,
                'Mock merchant email.',
                'merchant.demo@mailpit.local',
            )
            ->addOption(
                'google-review-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Mock Google review URL injected in all customer templates.',
                'https://g.page/r/demo/review',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Send all email templates in one run (customer notifications + signup alerts).',
            )
            ->addOption(
                'customer-only',
                null,
                InputOption::VALUE_NONE,
                'With --all, send only customer notification templates.',
            )
            ->addOption(
                'alerts-only',
                null,
                InputOption::VALUE_NONE,
                'With --all, send only signup alert templates.',
            )
            ->addOption(
                'dashboard-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Dashboard URL injected into email context.',
                'http://localhost:5173',
            )
            ->addOption(
                'value-added',
                null,
                InputOption::VALUE_REQUIRED,
                'Used by points_added template.',
                '10',
            )
            ->addOption(
                'current-value',
                null,
                InputOption::VALUE_REQUIRED,
                'Used by points_added template.',
                '10',
            )
            ->addOption(
                'target-value',
                null,
                InputOption::VALUE_REQUIRED,
                'Used by points_added/card_created templates.',
                '100',
            )
            ->addOption(
                'unit-label',
                null,
                InputOption::VALUE_REQUIRED,
                'Used by points_added/card_created templates.',
                'points',
            )
            ->addOption(
                'program-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Used by card_created/card_completed templates.',
                'Programme fidelite',
            )
            ->addOption(
                'reward-description',
                null,
                InputOption::VALUE_REQUIRED,
                'Used by card_completed/reward_claimed templates.',
                'Un cafe offert',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $appEnv = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod');
        if ($appEnv !== 'dev') {
            $io->error(sprintf('This command is restricted to APP_ENV=dev. Current env: %s.', $appEnv));

            return Command::FAILURE;
        }

        $typeValue = $input->getOption('type');
        $sendAll = (bool) $input->getOption('all');
        $customerOnly = (bool) $input->getOption('customer-only');
        $alertsOnly = (bool) $input->getOption('alerts-only');

        if ($typeValue !== null && !is_string($typeValue)) {
            $io->error('Option --type must be a string.');

            return Command::FAILURE;
        }

        // Default behavior: no --type means send every template.
        if ($typeValue === null || trim($typeValue) === '') {
            $sendAll = true;
        }

        if ($customerOnly && $alertsOnly) {
            $io->error('Options --customer-only and --alerts-only cannot be used together.');

            return Command::FAILURE;
        }

        if (($customerOnly || $alertsOnly) && !$sendAll) {
            $io->error('Options --customer-only and --alerts-only require batch mode (omit --type, or use --all).');

            return Command::FAILURE;
        }

        $customerEmail = trim((string) $input->getOption('to'));
        if ($customerEmail === '' || filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $io->error(sprintf('Invalid customer destination email "%s".', $customerEmail));

            return Command::FAILURE;
        }

        $customerName = trim((string) $input->getOption('customer-name'));
        if ($customerName === '') {
            $customerName = 'Client Test';
        }

        $merchantName = trim((string) $input->getOption('merchant-name'));
        if ($merchantName === '') {
            $merchantName = 'Merchant Demo';
        }

        $merchantEmail = trim((string) $input->getOption('merchant-email'));
        if ($merchantEmail === '' || filter_var($merchantEmail, FILTER_VALIDATE_EMAIL) === false) {
            $io->error(sprintf('Invalid merchant email "%s".', $merchantEmail));

            return Command::FAILURE;
        }

        $googleReviewUrl = trim((string) $input->getOption('google-review-url'));
        if ($googleReviewUrl === '' || filter_var($googleReviewUrl, FILTER_VALIDATE_URL) === false) {
            $io->error(sprintf('Invalid Google review URL "%s".', $googleReviewUrl));

            return Command::FAILURE;
        }

        $merchant = (new Merchant())
            ->setCompanyName($merchantName)
            ->setEmail($merchantEmail);

        $customer = (new Customer())
            ->setName($customerName)
            ->setEmail($customerEmail)
            ->setMerchant($merchant)
            ->addMerchant($merchant);

        $context = [
            'dashboard_url' => (string) $input->getOption('dashboard-url'),
            'value_added' => (int) $input->getOption('value-added'),
            'current_value' => (int) $input->getOption('current-value'),
            'target_value' => (int) $input->getOption('target-value'),
            'unit_label' => (string) $input->getOption('unit-label'),
            'program_name' => (string) $input->getOption('program-name'),
            'reward_description' => (string) $input->getOption('reward-description'),
            'reward_ready' => false,
            'show_google_review_invite' => true,
            'google_review_url' => $googleReviewUrl,
            'google_review_display_name' => 'Avis Google',
            'google_review_merchant_name' => $merchantName,
        ];

        $recipient = $customer->getEmail() ?? 'unknown';

        if ($sendAll) {
            $customerTypes = [
                NotificationType::CUSTOMER_SIGNUP,
                NotificationType::CARD_CREATED,
                NotificationType::CARD_COMPLETED,
                NotificationType::POINTS_ADDED,
                NotificationType::REWARD_CLAIMED,
            ];

            if (!$alertsOnly) {
                foreach ($customerTypes as $type) {
                    $this->emailNotificationStrategy->send($merchant, $customer, $type, $context);
                    $io->writeln(sprintf('Sent customer template: %s', $type->value));
                }
            }

            if (!$customerOnly) {
                $this->signupAlertMailer->notifyMerchantSignup($merchant);
                $io->writeln('Sent alert template: signup_alert_merchant');

                $this->signupAlertMailer->notifyCustomerSignup($customer, $merchant);
                $io->writeln('Sent alert template: signup_alert_customer');
            }

            $io->success('Batch email send completed.');
            $io->writeln(sprintf('Customer recipient: %s', $recipient));
            $io->writeln('Alert recipient: SIGNUP_ALERT_EMAIL (env).');
            $io->writeln('Data mode: mocked (no DB read).');

            return Command::SUCCESS;
        }

        try {
            $type = NotificationType::from((string) $typeValue);
        } catch (\ValueError) {
            $io->error(sprintf('Unsupported type "%s".', (string) $typeValue));
            $io->writeln('Allowed values: customer_signup, card_created, card_completed, points_added, reward_claimed.');

            return Command::FAILURE;
        }

        if ($type === NotificationType::MERCHANT_SIGNUP) {
            $io->error('merchant_signup is not a customer email template in EmailNotificationStrategy.');

            return Command::FAILURE;
        }

        $this->emailNotificationStrategy->send($merchant, $customer, $type, $context);

        $io->success('Test email sent through Symfony mailer.');
        $io->writeln(sprintf('Recipient: %s', $recipient));
        $io->writeln(sprintf('Type: %s', $type->value));
        $io->writeln('Data mode: mocked (no DB read).');

        return Command::SUCCESS;
    }
}
