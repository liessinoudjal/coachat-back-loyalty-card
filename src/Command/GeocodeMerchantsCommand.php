<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MerchantRepository;
use App\Service\GeocoderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geocode:merchants',
    description: 'Geocode merchants that have an address but no coordinates yet, using the French BAN API.',
)]
final class GeocodeMerchantsCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 50;
    private const REQUEST_DELAY_US = 250_000; // 250ms between BAN API calls

    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly GeocoderService $geocoderService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'batch',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Maximum number of merchants to process. 0 = all. Default: %d.', self::DEFAULT_BATCH_SIZE),
                self::DEFAULT_BATCH_SIZE,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate geocoding without writing to the database.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');
        $batchSize = (int) $input->getOption('batch');

        if ($batchSize < 0) {
            $io->error('--batch must be 0 (all) or a positive integer.');

            return Command::FAILURE;
        }

        $limit = $batchSize === 0 ? PHP_INT_MAX : $batchSize;
        $remaining = $this->merchantRepository->countNotGeocodedWithAddress();

        if ($remaining === 0) {
            $io->success('All merchants with an address are already geocoded. Nothing to do.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Geocoding merchants (%d remaining total)', $remaining));

        if ($isDryRun) {
            $io->caution('DRY-RUN mode — no data will be written to the database.');
        }

        $merchants = $this->merchantRepository->findNotGeocodedWithAddress($limit);
        $geocoded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($merchants as $index => $merchant) {
            if ($index > 0) {
                usleep(self::REQUEST_DELAY_US);
            }

            $io->write(sprintf(
                '  [%d/%d] %s (%s %s %s) ... ',
                $index + 1,
                count($merchants),
                $merchant->getCompanyName() ?? '(no name)',
                $merchant->getAddress() ?? '',
                $merchant->getPostalCode() ?? '',
                $merchant->getCity() ?? '',
            ));

            $hasAddress = $merchant->getAddress() !== null && $merchant->getAddress() !== '';

            if (!$hasAddress) {
                $io->writeln('<comment>SKIP (no address)</comment>');
                ++$skipped;
                continue;
            }

            $success = $this->geocoderService->geocodeMerchant($merchant);

            if ($success) {
                $io->writeln(sprintf(
                    '<info>OK (%.6f, %.6f — score %.2f)</info>',
                    $merchant->getLatitude(),
                    $merchant->getLongitude(),
                    $merchant->getGeocodeScore(),
                ));
                ++$geocoded;

                if (!$isDryRun) {
                    $this->entityManager->persist($merchant);
                }
            } else {
                $io->writeln('<error>FAILED (see alert email)</error>');
                ++$failed;
            }
        }

        if (!$isDryRun && $geocoded > 0) {
            $this->entityManager->flush();
        }

        $remainingAfter = $this->merchantRepository->countNotGeocodedWithAddress();
        if ($isDryRun) {
            $remainingAfter = $remaining;
        }

        $io->newLine();
        $io->table(
            ['Metric', 'Value'],
            [
                ['Processed', count($merchants)],
                ['Geocoded', $geocoded],
                ['Skipped (no address)', $skipped],
                ['Failed', $failed],
                ['Remaining after run', $remainingAfter],
            ],
        );

        if ($failed > 0) {
            $io->warning(sprintf('%d merchant(s) failed geocoding — check the alert emails sent to coachat.', $failed));
        }

        if ($remainingAfter > 0 && !$isDryRun) {
            $io->note(sprintf('%d merchant(s) still need geocoding. Run the command again to continue.', $remainingAfter));
        }

        if ($remainingAfter === 0 && !$isDryRun) {
            $io->success('All merchants are now geocoded! You can disable the HTTP batch endpoint (GEOCODE_BATCH_ENABLED=false).');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
