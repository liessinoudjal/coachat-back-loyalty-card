<?php

namespace App\Controller;

use App\Repository\MerchantRepository;
use App\Service\GeocoderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Temporary endpoint for backfilling geolocation data on existing merchants.
 * Called by cron-job.org until all merchants are geocoded.
 *
 * TODO: remove or disable (GEOCODE_BATCH_ENABLED=false) once backfill is complete.
 */
final class GeocodeBackfillController extends AbstractController
{
    private const DEFAULT_BATCH_SIZE = 20;
    private const MAX_BATCH_SIZE = 50;
    private const REQUEST_DELAY_US = 250_000; // 250ms between BAN API calls

    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly GeocoderService $geocoderService,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $geocodeBatchSecret,
        private readonly bool $geocodeBatchEnabled,
    ) {
    }

    #[Route('/internal/geocode-merchants-batch', name: 'internal_geocode_merchants_batch', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->geocodeBatchEnabled) {
            return new JsonResponse(['error' => 'endpoint_disabled'], 404);
        }

        $providedSecret = trim((string) $request->query->get('secret', ''));
        $expectedSecret = trim($this->geocodeBatchSecret);

        if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $batchSize = min(
            (int) $request->query->get('batch_size', self::DEFAULT_BATCH_SIZE),
            self::MAX_BATCH_SIZE,
        );

        $merchants = $this->merchantRepository->findNotGeocodedWithAddress($batchSize);
        $remainingBefore = $this->merchantRepository->countNotGeocodedWithAddress();

        $geocoded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($merchants as $index => $merchant) {
            // Delay between requests to respect BAN API rate limits.
            if ($index > 0) {
                usleep(self::REQUEST_DELAY_US);
            }

            $success = $this->geocoderService->geocodeMerchant($merchant);

            if ($success) {
                ++$geocoded;
                $this->entityManager->persist($merchant);
            } else {
                // Distinguish "no address" (skipped) from actual failure (failed).
                $hasAddress = $merchant->getAddress() !== null && $merchant->getAddress() !== '';
                $hasAddress ? ++$failed : ++$skipped;
            }
        }

        $this->entityManager->flush();

        $processed = count($merchants);
        $remaining = max(0, $remainingBefore - $geocoded);

        return new JsonResponse([
            'processed' => $processed,
            'geocoded' => $geocoded,
            'skipped' => $skipped,
            'failed' => $failed,
            'remaining' => $remaining,
        ]);
    }
}
