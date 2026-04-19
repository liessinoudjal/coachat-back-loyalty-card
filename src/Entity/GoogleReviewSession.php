<?php

namespace App\Entity;

use App\Enum\GoogleReviewSessionStatus;
use App\Repository\GoogleReviewSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GoogleReviewSessionRepository::class)]
#[ORM\Table(name: 'google_review_session')]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_SESSION_CUSTOMER_MERCHANT', columns: ['customer_id', 'merchant_id'])]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_SESSION_STATUS', columns: ['status'])]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_SESSION_CREATED_AT', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class GoogleReviewSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Merchant $merchant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MerchantGoogleReviewModule $module = null;

    #[ORM\Column(enumType: GoogleReviewSessionStatus::class, length: 32)]
    private GoogleReviewSessionStatus $status = GoogleReviewSessionStatus::READY_TO_LAUNCH;

    #[ORM\Column(options: ['default' => 0])]
    private int $launchCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $launchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $spunAt = null;

    #[ORM\OneToOne(mappedBy: 'session')]
    private ?GoogleReviewReward $reward = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->status = GoogleReviewSessionStatus::READY_TO_LAUNCH;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getMerchant(): ?Merchant
    {
        return $this->merchant;
    }

    public function setMerchant(?Merchant $merchant): static
    {
        $this->merchant = $merchant;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getModule(): ?MerchantGoogleReviewModule
    {
        return $this->module;
    }

    public function setModule(?MerchantGoogleReviewModule $module): static
    {
        $this->module = $module;

        return $this;
    }

    public function getStatus(): GoogleReviewSessionStatus
    {
        return $this->status;
    }

    public function setStatus(GoogleReviewSessionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getLaunchCount(): int
    {
        return $this->launchCount;
    }

    public function setLaunchCount(int $launchCount): static
    {
        $this->launchCount = $launchCount;

        return $this;
    }

    public function incrementLaunchCount(): static
    {
        ++$this->launchCount;

        return $this;
    }

    public function getLaunchedAt(): ?\DateTimeImmutable
    {
        return $this->launchedAt;
    }

    public function setLaunchedAt(?\DateTimeImmutable $launchedAt): static
    {
        $this->launchedAt = $launchedAt;

        return $this;
    }

    public function getReturnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function setReturnedAt(?\DateTimeImmutable $returnedAt): static
    {
        $this->returnedAt = $returnedAt;

        return $this;
    }

    public function getSpunAt(): ?\DateTimeImmutable
    {
        return $this->spunAt;
    }

    public function setSpunAt(?\DateTimeImmutable $spunAt): static
    {
        $this->spunAt = $spunAt;

        return $this;
    }

    public function getReward(): ?GoogleReviewReward
    {
        return $this->reward;
    }

    public function setReward(?GoogleReviewReward $reward): static
    {
        $this->reward = $reward;

        if ($reward !== null && $reward->getSession() !== $this) {
            $reward->setSession($this);
        }

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}