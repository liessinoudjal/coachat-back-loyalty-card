<?php

namespace App\Entity;

use App\Enum\GoogleReviewRewardStatus;
use App\Repository\GoogleReviewRewardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GoogleReviewRewardRepository::class)]
#[ORM\Table(name: 'google_review_reward')]
#[ORM\UniqueConstraint(name: 'UNIQ_GOOGLE_REVIEW_REWARD_SESSION', columns: ['session_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_GOOGLE_REVIEW_REWARD_QR_TOKEN', columns: ['qr_token'])]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_REWARD_CUSTOMER_MERCHANT', columns: ['customer_id', 'merchant_id'])]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_REWARD_STATUS', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class GoogleReviewReward
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\OneToOne(inversedBy: 'reward')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GoogleReviewSession $session = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Merchant $merchant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Customer $customer = null;

    #[ORM\Column(length: 255)]
    private string $rewardLabel = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rewardDescription = null;

    #[ORM\Column(length: 128, unique: true)]
    private string $qrToken = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $qrPayload = null;

    #[ORM\Column(enumType: GoogleReviewRewardStatus::class, length: 32)]
    private GoogleReviewRewardStatus $status = GoogleReviewRewardStatus::ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $redeemedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $redeemedBy = null;

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
        $this->status = GoogleReviewRewardStatus::ACTIVE;
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

    public function getSession(): ?GoogleReviewSession
    {
        return $this->session;
    }

    public function setSession(?GoogleReviewSession $session): static
    {
        $this->session = $session;

        if ($session !== null && $session->getReward() !== $this) {
            $session->setReward($this);
        }

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

    public function getRewardLabel(): string
    {
        return $this->rewardLabel;
    }

    public function setRewardLabel(string $rewardLabel): static
    {
        $this->rewardLabel = $rewardLabel;

        return $this;
    }

    public function getRewardDescription(): ?string
    {
        return $this->rewardDescription;
    }

    public function setRewardDescription(?string $rewardDescription): static
    {
        $this->rewardDescription = $rewardDescription;

        return $this;
    }

    public function getQrToken(): string
    {
        return $this->qrToken;
    }

    public function setQrToken(string $qrToken): static
    {
        $this->qrToken = $qrToken;

        return $this;
    }

    public function getQrPayload(): ?string
    {
        return $this->qrPayload;
    }

    public function setQrPayload(?string $qrPayload): static
    {
        $this->qrPayload = $qrPayload;

        return $this;
    }

    public function getStatus(): GoogleReviewRewardStatus
    {
        return $this->status;
    }

    public function setStatus(GoogleReviewRewardStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRedeemedAt(): ?\DateTimeImmutable
    {
        return $this->redeemedAt;
    }

    public function setRedeemedAt(?\DateTimeImmutable $redeemedAt): static
    {
        $this->redeemedAt = $redeemedAt;

        return $this;
    }

    public function getRedeemedBy(): ?User
    {
        return $this->redeemedBy;
    }

    public function setRedeemedBy(?User $redeemedBy): static
    {
        $this->redeemedBy = $redeemedBy;

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