<?php

namespace App\Service;

use App\Repository\DeviceRegistrationRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends APNs push notifications to Apple Wallet devices so they re-download
 * the updated .pkpass when a card's value changes.
 *
 * Requires env vars:
 *   APPLE_WALLET_ENABLED=true
 *   APPLE_WALLET_PASS_TYPE_IDENTIFIER=pass.com.yourcompany.loyaltycard
 *   APPLE_WALLET_TEAM_IDENTIFIER=YOUR_TEAM_ID
 *   APPLE_APNS_KEY_ID=XXXXXXXXXX
 *   APPLE_APNS_PRIVATE_KEY_PATH=/path/to/apns-key.p8
 */
class AppleWalletPushService
{
    private const APNS_URL = 'https://api.push.apple.com/3/device/';
    private const APNS_SANDBOX_URL = 'https://api.sandbox.push.apple.com/3/device/';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $passTypeIdentifier,
        private readonly string $teamIdentifier,
        private readonly string $keyId,
        private readonly string $privateKeyPath,
        private readonly DeviceRegistrationRepository $deviceRegistrationRepository,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Notify all registered devices for this walletToken to refresh the pass.
     */
    public function notifyUpdate(string $walletToken): void
    {
        if (!$this->enabled) {
            return;
        }

        $registrations = $this->deviceRegistrationRepository->findBySerial($walletToken);
        if (empty($registrations)) {
            return;
        }

        $jwt = $this->buildApnsJwt();

        foreach ($registrations as $registration) {
            try {
                $this->sendPush($registration->getPushToken(), $jwt);
            } catch (\Throwable) {
                // Do not block the transaction if a single push fails
            }
        }
    }

    private function buildApnsJwt(): string
    {
        $privateKey = file_get_contents($this->privateKeyPath);

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($privateKey),
            InMemory::plainText(''),
        );

        $now = new \DateTimeImmutable();

        $token = $config->builder()
            ->issuedBy($this->teamIdentifier)
            ->issuedAt($now)
            ->withHeader('kid', $this->keyId)
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    private function sendPush(string $pushToken, string $jwt): void
    {
        $this->httpClient->request('POST', self::APNS_URL . $pushToken, [
            'http_version' => '2.0',
            'headers' => [
                'authorization' => 'bearer ' . $jwt,
                'apns-topic' => $this->passTypeIdentifier,
                'apns-push-type' => 'background',
                'apns-priority' => '5',
                'content-type' => 'application/json',
            ],
            'body' => '{}',
        ]);
    }
}
