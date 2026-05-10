<?php

namespace App\EventSubscriber;

use App\Entity\Merchant;
use App\Service\GeocoderService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * Re-geocodes a Merchant whenever its address-related fields change.
 */
#[AsEntityListener(event: Events::prePersist, entity: Merchant::class)]
#[AsEntityListener(event: Events::preUpdate, entity: Merchant::class)]
final class MerchantGeocodingSubscriber
{
    /** Fields whose change triggers a re-geocoding. */
    private const ADDRESS_FIELDS = ['address', 'postalCode', 'city'];

    public function __construct(
        private readonly GeocoderService $geocoderService,
    ) {
    }

    public function prePersist(Merchant $merchant, LifecycleEventArgs $args): void
    {
        // Always geocode on first persist if address data is present.
        $this->geocoderService->geocodeMerchant($merchant);
    }

    public function preUpdate(Merchant $merchant, LifecycleEventArgs $args): void
    {
        /** @var \Doctrine\ORM\Event\PreUpdateEventArgs $args */
        foreach (self::ADDRESS_FIELDS as $field) {
            if ($args->hasChangedField($field)) {
                // Reset previous coordinates before re-geocoding.
                $merchant->setLatitude(null);
                $merchant->setLongitude(null);
                $merchant->setGeocodedAt(null);
                $merchant->setGeocodeScore(null);

                $this->geocoderService->geocodeMerchant($merchant);

                return;
            }
        }
    }
}
