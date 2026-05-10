<?php

namespace App\Service;

use App\Entity\Merchant;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GeocoderService
{
    private const BAN_API_URL = 'https://api-adresse.data.gouv.fr/search/';
    private const MIN_SCORE = 0.6;
    private const HTTP_TIMEOUT = 3.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $alertEmail,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    /**
     * Geocodes the merchant's address via the French BAN API and updates the entity in place.
     * Returns true if coordinates were successfully set, false otherwise.
     */
    public function geocodeMerchant(Merchant $merchant): bool
    {
        $address = trim(implode(' ', array_filter([
            $merchant->getAddress(),
            $merchant->getPostalCode(),
            $merchant->getCity(),
        ])));

        if ($address === '') {
            $this->logger->warning('GeocoderService: merchant has no address, skipping.', [
                'merchant_id' => (string) $merchant->getId(),
                'company_name' => $merchant->getCompanyName(),
            ]);

            return false;
        }

        try {
            $response = $this->httpClient->request('GET', self::BAN_API_URL, [
                'query' => ['q' => $address, 'limit' => 1],
                'timeout' => self::HTTP_TIMEOUT,
            ]);

            $data = $response->toArray();
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('GeocoderService: HTTP request to BAN API failed.', [
                'merchant_id' => (string) $merchant->getId(),
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            $this->sendFailureAlert($merchant, $address, 'Erreur réseau : ' . $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('GeocoderService: unexpected error during geocoding.', [
                'merchant_id' => (string) $merchant->getId(),
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            $this->sendFailureAlert($merchant, $address, 'Erreur inattendue : ' . $e->getMessage());

            return false;
        }

        $features = $data['features'] ?? [];

        if (empty($features)) {
            $this->logger->warning('GeocoderService: BAN API returned no results.', [
                'merchant_id' => (string) $merchant->getId(),
                'address' => $address,
            ]);
            $this->sendFailureAlert($merchant, $address, 'Aucun résultat retourné par l\'API BAN.');

            return false;
        }

        if (count($features) > 1) {
            $this->logger->info('GeocoderService: BAN API returned multiple results, using the first one.', [
                'merchant_id' => (string) $merchant->getId(),
                'address' => $address,
                'result_count' => count($features),
            ], $features);
            $this->sendFailureAlert($merchant, $address, 'Plusieurs résultats retournés par l\'API BAN, impossible de geolocaliser ce merchant correctement.');

            return false;
        }

        $feature = $features[0];
        $score = (float) ($feature['properties']['score'] ?? 0);
        $coordinates = $feature['geometry']['coordinates'] ?? null;

        if ($score < self::MIN_SCORE || !is_array($coordinates) || count($coordinates) < 2) {
            $this->logger->warning('GeocoderService: geocoding result rejected (score too low or invalid coordinates).', [
                'merchant_id' => (string) $merchant->getId(),
                'address' => $address,
                'score' => $score,
                'min_score' => self::MIN_SCORE,
            ]);
            $this->sendFailureAlert(
                $merchant,
                $address,
                sprintf('Score insuffisant (%.2f < %.2f) ou coordonnées invalides.', $score, self::MIN_SCORE),
            );

            return false;
        }
        if ($score < 0.9) {
            //update merchant adress info with responses from BAN API to improve future geocoding attempts, even if the score is low
            $merchant->setAddress($feature['properties']['name']);
            $merchant->setPostalCode($feature['properties']['postcode']);
            $merchant->setCity($feature['properties']['city']);
        }
      

        // BAN returns [longitude, latitude]
        $merchant->setLongitude((float) $coordinates[0]);
        $merchant->setLatitude((float) $coordinates[1]);
        $merchant->setGeocodeScore($score);
        $merchant->setGeocodedAt(new \DateTimeImmutable());

        $this->logger->info('GeocoderService: merchant successfully geocoded.', [
            'merchant_id' => (string) $merchant->getId(),
            'company_name' => $merchant->getCompanyName(),
            'latitude' => $merchant->getLatitude(),
            'longitude' => $merchant->getLongitude(),
            'score' => $score,
        ]);

        return true;
    }

    private function sendFailureAlert(Merchant $merchant, string $addressAttempted, string $reason): void
    {
        $subject = sprintf('[GÉOCODAGE] Échec pour le merchant "%s"', $merchant->getCompanyName());

        $body = implode("\n", [
            'Le géocodage d\'un merchant a échoué.',
            '',
            sprintf('Merchant ID   : %s', $merchant->getId()),
            sprintf('Nom           : %s', $merchant->getCompanyName()),
            sprintf('Adresse testée: %s', $addressAttempted),
            sprintf('Motif         : %s', $reason),
            '',
            'Action requise : vérifier et corriger manuellement l\'adresse du merchant dans l\'administration.',
        ]);

        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($this->alertEmail)
                ->subject($subject)
                ->text($body);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('GeocoderService: failed to send geocoding failure alert email.', [
                'exception' => $e->getMessage(),
                'merchant_id' => (string) $merchant->getId(),
            ]);
        }
    }
}
