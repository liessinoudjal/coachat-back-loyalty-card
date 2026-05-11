<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ContestNotificationDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:contests:dispatch-dry-run',
    description: 'Preview contest notifications to be sent (no email sent, no DB update).',
)]
final class PreviewContestNotificationsCommand extends Command
{
    public function __construct(
        private readonly ContestNotificationDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'date',
            null,
            InputOption::VALUE_REQUIRED,
            'Preview date in Y-m-d format. Defaults to today.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dateOption = $input->getOption('date');
        $today = new \DateTimeImmutable('today');

        if (is_string($dateOption) && trim($dateOption) !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', trim($dateOption));
            if (!$parsed instanceof \DateTimeImmutable) {
                $io->error('Invalid --date value. Expected format: Y-m-d.');

                return Command::FAILURE;
            }

            $today = $parsed->setTime(0, 0, 0);
        }

        $result = $this->dispatcher->preview($today);

        $io->success(sprintf('Contest notification dry-run executed for %s.', $today->format('Y-m-d')));
        $io->table(
            ['Metric', 'Value'],
            [
                ['day_before_targeted_contests', (string) $result['day_before_targeted_contests']],
                ['start_targeted_contests', (string) $result['start_targeted_contests']],
                ['ending_soon_targeted_contests', (string) $result['ending_soon_targeted_contests']],
                ['day_before_notifications_would_be_sent_for_contests', (string) $result['day_before_notifications_would_be_sent_for_contests']],
                ['start_notifications_would_be_sent_for_contests', (string) $result['start_notifications_would_be_sent_for_contests']],
                ['ending_soon_notifications_would_be_sent_for_contests', (string) $result['ending_soon_notifications_would_be_sent_for_contests']],
            ],
        );

        return Command::SUCCESS;
    }
}
