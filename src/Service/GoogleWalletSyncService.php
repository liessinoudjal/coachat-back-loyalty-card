<?php

namespace App\Service;

use App\Entity\LoyaltyCard;
use App\Enum\LoyaltyProgramType;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Syncs a LoyaltyCard's current value to a Google Wallet loyalty object via
 * the Google Wallet REST API.
 *
 * Requires env vars:
 *   GOOGLE_WALLET_ENABLED=true
 *   GOOGLE_WALLET_ISSUER_ID=your_issuer_id
 *   GOOGLE_WALLET_SERVICE_ACCOUNT_KEY_PATH=/path/to/service-account-key.json
 */
class GoogleWalletSyncService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/';
    private const SCOPE = 'https://www.googleapis.com/auth/wallet_object.issuer';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $issuerId,
        private readonly string $serviceAccountKeyPath,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Patch the Google Wallet loyalty object with the card's current value.
     */
    public function syncCard(LoyaltyCard $card): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $accessToken = $this->getAccessToken();
            $this->patchLoyaltyObject($card, $accessToken);
        } catch (\Throwable) {
            // Do not block the transaction if Google sync fails
        }
    }

    private function getAccessToken(): string
    {
        $keyFileContent = file_get_contents($this->serviceAccountKeyPath);
        $keyData = json_decode($keyFileContent, true);
        $serviceAccountEmail = $keyData['client_email'];
        $privateKey = $keyData['private_key'];

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($privateKey),
            InMemory::plainText(''),
        );

        $now = new \DateTimeImmutable();

        $assertion = $config->builder()
            ->issuedBy($serviceAccountEmail)
            ->permittedFor(self::TOKEN_URL)
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->withClaim('scope', self::SCOPE)
            ->getToken($config->signer(), $config->signingKey())
            ->toString();

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
        ]);

        return $response->toArray()['access_token'];
    }

    private function patchLoyaltyObject(LoyaltyCard $card, string $accessToken): void
    {
        $program = $card->getLoyaltyProgram();
        $isStamp = $program->getType() === LoyaltyProgramType::STAMP;
        $current = $card->getCurrentValue() ?? 0;
        $target = $card->getTargetValue() ?? ($isStamp ? $program->getStampTarget() : $program->getPointsTarget()) ?? 0;

        $objectId = $this->issuerId . '.loyalty-' . $card->getWalletToken();

        $body = [
            'loyaltyPoints' => [
                'balance' => [
                    $isStamp ? 'string' : 'int' => $isStamp
                        ? ($current . '/' . $target . ' tampons')
                        : $current,
                ],
                'label' => $isStamp ? 'Tampons' : 'Points',
            ],
            'state' => $card->isCompleted() ? 'COMPLETED' : 'ACTIVE',
        ];

        $this->httpClient->request(
            'PATCH',
            self::API_BASE . urlencode($objectId),
            [
                'headers' => [
                    'authorization' => 'Bearer ' . $accessToken,
                    'content-type' => 'application/json',
                ],
                'json' => $body,
            ]
        );
    }
}
