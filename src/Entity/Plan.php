<?php

namespace App\Entity;

use App\Repository\PlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
class Plan
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 50, unique: true)]
    private string $slug;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private int $priceMonthly = 0;

    #[ORM\Column]
    private int $maxCustomers = 50;

    #[ORM\Column]
    private int $maxPrograms = 1;

    #[ORM\Column]
    private bool $hasWalletIntegration = false;

    #[ORM\Column]
    private bool $hasPushNotifications = false;

    #[ORM\Column]
    private bool $hasAdvancedStats = false;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePriceId = null;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPriceMonthly(): int
    {
        return $this->priceMonthly;
    }

    public function setPriceMonthly(int $priceMonthly): static
    {
        $this->priceMonthly = $priceMonthly;

        return $this;
    }

    public function getMaxCustomers(): int
    {
        return $this->maxCustomers;
    }

    public function setMaxCustomers(int $maxCustomers): static
    {
        $this->maxCustomers = $maxCustomers;

        return $this;
    }

    public function getMaxPrograms(): int
    {
        return $this->maxPrograms;
    }

    public function setMaxPrograms(int $maxPrograms): static
    {
        $this->maxPrograms = $maxPrograms;

        return $this;
    }

    public function isHasWalletIntegration(): bool
    {
        return $this->hasWalletIntegration;
    }

    public function setHasWalletIntegration(bool $hasWalletIntegration): static
    {
        $this->hasWalletIntegration = $hasWalletIntegration;

        return $this;
    }

    public function isHasPushNotifications(): bool
    {
        return $this->hasPushNotifications;
    }

    public function setHasPushNotifications(bool $hasPushNotifications): static
    {
        $this->hasPushNotifications = $hasPushNotifications;

        return $this;
    }

    public function isHasAdvancedStats(): bool
    {
        return $this->hasAdvancedStats;
    }

    public function setHasAdvancedStats(bool $hasAdvancedStats): static
    {
        $this->hasAdvancedStats = $hasAdvancedStats;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): static
    {
        $this->stripePriceId = $stripePriceId;

        return $this;
    }
}
