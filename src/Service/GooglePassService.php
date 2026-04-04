<?php

namespace App\Service;

use App\Entity\LoyaltyCard;
use App\Enum\LoyaltyProgramType;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class GooglePassService
{
    private const SAVE_URL = 'https://pay.google.com/gp/v/save/';

    public function __construct(
        private readonly string $googleServiceAccountEmail,
        private readonly string $googleServiceAccountKeyPath,
        private readonly string $googleIssuerId,
        private readonly string $googleClassSuffix,
    ) {}

    public function generateSaveUrl(LoyaltyCard $card): string
    {
        $program = $card->getLoyaltyProgram();
        $customer = $card->getCustomer();
        $merchant = $card->getMerchant();

        $isStamp = $program->getType() === LoyaltyProgramType::STAMP;
        $current = $card->getCurrentValue() ?? 0;
        $target = $card->getTargetValue() ?? ($isStamp ? $program->getStampTarget() : $program->getPointsTarget()) ?? 0;

        $classId = $this->googleIssuerId . '.' . $this->googleClassSuffix;
        $objectId = $this->googleIssuerId . '.loyalty-' . $card->getWalletToken();

        $loyaltyObject = [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'accountId' => $card->getWalletToken(),
            'accountName' => $customer?->getName() ?? 'Client fidèle',
            'loyaltyPoints' => [
                'balance' => [
                    $isStamp ? 'string' : 'int' => $isStamp
                        ? ($current . '/' . $target . ' tampons')
                        : $current,
                ],
                'label' => $isStamp ? 'Tampons' : 'Points',
            ],
            'secondaryLoyaltyPoints' => $isStamp ? null : [
                'balance' => ['int' => $target],
                'label' => 'Objectif',
            ],
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $card->getWalletToken(),
                'alternateText' => $card->getWalletToken(),
            ],
            'textModulesData' => [
                [
                    'header' => 'Programme',
                    'body' => $program->getName(),
                    'id' => 'program',
                ],
                [
                    'header' => 'Commerçant',
                    'body' => $merchant?->getCompanyName() ?? '',
                    'id' => 'merchant',
                ],
                [
                    'header' => 'Récompense',
                    'body' => $program->getRewardDescription() ?? '',
                    'id' => 'reward',
                ],
            ],
        ];

        // Remove null secondary points for STAMP
        if ($loyaltyObject['secondaryLoyaltyPoints'] === null) {
            unset($loyaltyObject['secondaryLoyaltyPoints']);
        }

        $payload = [
            'iss' => $this->googleServiceAccountEmail,
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'payload' => [
                'loyaltyObjects' => [$loyaltyObject],
            ],
        ];

        $keyFileContent = file_get_contents($this->googleServiceAccountKeyPath);
        $keyData = json_decode($keyFileContent, true);
        $privateKey = $keyData['private_key'] ?? $keyFileContent;

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($privateKey),
            InMemory::plainText('') // public key not needed for signing
        );

        $now = new \DateTimeImmutable();
        $token = $config->builder()
            ->issuedBy($this->googleServiceAccountEmail)
            ->permittedFor('google')
            ->issuedAt($now)
            ->withClaim('typ', 'savetowallet')
            ->withClaim('payload', $payload['payload'])
            ->getToken($config->signer(), $config->signingKey());

        return self::SAVE_URL . $token->toString();
    }
}
