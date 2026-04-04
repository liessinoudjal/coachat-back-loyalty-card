<?php

namespace App\Repository;

use App\Entity\DeviceRegistration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DeviceRegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceRegistration::class);
    }

    public function findByDevice(string $deviceLibraryIdentifier, string $passTypeIdentifier): array
    {
        return $this->findBy([
            'deviceLibraryIdentifier' => $deviceLibraryIdentifier,
            'passTypeIdentifier' => $passTypeIdentifier,
        ]);
    }

    public function findBySerial(string $serialNumber): array
    {
        return $this->findBy(['serialNumber' => $serialNumber]);
    }

    public function findOneByDeviceAndSerial(
        string $deviceLibraryIdentifier,
        string $serialNumber,
    ): ?DeviceRegistration {
        return $this->findOneBy([
            'deviceLibraryIdentifier' => $deviceLibraryIdentifier,
            'serialNumber' => $serialNumber,
        ]);
    }
}
