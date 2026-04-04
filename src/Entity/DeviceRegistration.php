<?php

namespace App\Entity;

use App\Repository\DeviceRegistrationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceRegistrationRepository::class)]
#[ORM\UniqueConstraint(name: 'device_serial_unique', columns: ['device_library_identifier', 'serial_number'])]
class DeviceRegistration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $deviceLibraryIdentifier;

    #[ORM\Column(length: 255)]
    private string $pushToken;

    #[ORM\Column(length: 255)]
    private string $passTypeIdentifier;

    /** walletToken of the LoyaltyCard */
    #[ORM\Column(length: 36)]
    private string $serialNumber;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $deviceLibraryIdentifier,
        string $pushToken,
        string $passTypeIdentifier,
        string $serialNumber,
    ) {
        $this->deviceLibraryIdentifier = $deviceLibraryIdentifier;
        $this->pushToken = $pushToken;
        $this->passTypeIdentifier = $passTypeIdentifier;
        $this->serialNumber = $serialNumber;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDeviceLibraryIdentifier(): string { return $this->deviceLibraryIdentifier; }

    public function getPushToken(): string { return $this->pushToken; }

    public function setPushToken(string $pushToken): static
    {
        $this->pushToken = $pushToken;
        return $this;
    }

    public function getPassTypeIdentifier(): string { return $this->passTypeIdentifier; }

    public function getSerialNumber(): string { return $this->serialNumber; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
