<?php

namespace App\Service;

use App\Entity\LoyaltyCard;
use App\Enum\LoyaltyProgramType;
use PKPass\PKPass;

class ApplePassService
{
    public function __construct(
        private readonly string $certPath,
        private readonly string $certPassword,
        private readonly string $passTypeIdentifier,
        private readonly string $teamIdentifier,
        private readonly string $organizationName,
        private readonly string $baseUrl,
    ) {}

    public function generatePass(LoyaltyCard $card): string
    {
        $program = $card->getLoyaltyProgram();
        $customer = $card->getCustomer();
        $merchant = $card->getMerchant();

        $isStamp = $program->getType() === LoyaltyProgramType::STAMP;
        $current = $card->getCurrentValue() ?? 0;
        $target = $card->getTargetValue() ?? ($isStamp ? $program->getStampTarget() : $program->getPointsTarget()) ?? 0;

        if ($isStamp) {
            $primaryLabel = 'Tampons';
            $primaryValue = $current . '/' . $target;
            $secondaryLabel = 'Récompense';
            $secondaryValue = $program->getRewardDescription() ?? '';
        } else {
            $primaryLabel = 'Points';
            $primaryValue = (string) $current;
            $secondaryLabel = 'Objectif';
            $secondaryValue = (string) $target;
        }

        $passData = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $this->passTypeIdentifier,
            'serialNumber' => $card->getWalletToken(),
            'teamIdentifier' => $this->teamIdentifier,
            'organizationName' => $this->organizationName,
            'description' => $program->getName(),
            'backgroundColor' => 'rgb(21, 101, 192)',
            'foregroundColor' => 'rgb(255, 255, 255)',
            'labelColor' => 'rgb(200, 230, 255)',
            'storeCard' => [
                'primaryFields' => [
                    [
                        'key' => 'balance',
                        'label' => $primaryLabel,
                        'value' => $primaryValue,
                    ],
                ],
                'secondaryFields' => [
                    [
                        'key' => 'reward',
                        'label' => $secondaryLabel,
                        'value' => $secondaryValue,
                    ],
                ],
                'auxiliaryFields' => [
                    [
                        'key' => 'program',
                        'label' => 'Programme',
                        'value' => $program->getName(),
                    ],
                    [
                        'key' => 'merchant',
                        'label' => 'Commerçant',
                        'value' => $merchant?->getCompanyName() ?? '',
                    ],
                ],
                'backFields' => [
                    [
                        'key' => 'customer_name',
                        'label' => 'Client',
                        'value' => $customer?->getName() ?? 'Carte anonyme',
                    ],
                    [
                        'key' => 'program_desc',
                        'label' => 'Description',
                        'value' => $program->getDescription() ?? '',
                    ],
                    [
                        'key' => 'status',
                        'label' => 'Statut',
                        'value' => $card->isCompleted() ? 'Récompense disponible !' : 'En cours',
                    ],
                ],
            ],
            'barcode' => [
                'message' => $card->getQrCode(),
                'format' => 'PKBarcodeFormatQR',
                'messageEncoding' => 'iso-8859-1',
                'altText' => $card->getQrCode(),
            ],
            'webServiceURL' => $this->baseUrl . '/public/wallet/apple/update/',
            'authenticationToken' => $card->getWalletToken(),
        ];

        $pass = new PKPass($this->certPath, $this->certPassword);
        $pass->setData($passData);
        $pass->setName('loyalty-card-' . $card->getId());

        return $pass->create();
    }
}
