<?php

namespace App\Entity;

use App\Enum\RewardStatus;
use App\Repository\RewardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RewardRepository::class)]
#[ORM\Table(name: 'reward')]
#[ORM\Index(name: 'IDX_REWARD_MERCHANT', columns: ['merchant_id'])]
#[ORM\Index(name: 'IDX_REWARD_CUSTOMER', columns: ['customer_id'])]
#[ORM\Index(name: 'IDX_REWARD_STATUS', columns: ['status'])]
#[ORM\Index(name: 'IDX_REWARD_CLAIM_TOKEN', columns: ['claim_qr_token'])]
#[ORM\Index(name: 'IDX_REWARD_GENERATED_AT', columns: ['generated_at'])]
#[ORM\UniqueConstraint(name: 'UNIQ_REWARD_CLAIM_QR_TOKEN', columns: ['claim_qr_token'])]
#[ORM\UniqueConstraint(name: 'UNIQ_REWARD_LOYALTY_CARD', columns: ['loyalty_card_id'])]
class Reward
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'rewards')]
    #[ORM\JoinColumn(nullable: false)]
    private ?LoyaltyCard $loyaltyCard = null;

    #[ORM\ManyToOne(inversedBy: 'rewards')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Merchant $merchant = null;

    #[ORM\ManyToOne(inversedBy: 'rewards')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Customer $customer = null;

    #[ORM\ManyToOne(inversedBy: 'rewards')]
    private ?LoyaltyProgram $loyaltyProgram = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rewardDescription = null;

    #[ORM\Column(enumType: RewardStatus::class)]
    private RewardStatus $status = RewardStatus::PENDING;

    #[ORM\Column(length: 128, unique: true)]
    private ?string $claimQrToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\ManyToOne]
    private ?User $claimedByMerchantUser = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cancelReason = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->generatedAt = new \DateTimeImmutable();
        $this->status = RewardStatus::PENDING;
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

    public function getLoyaltyCard(): ?LoyaltyCard
    {
        return $this->loyaltyCard;
    }

    public function setLoyaltyCard(?LoyaltyCard $loyaltyCard): static
    {
        $this->loyaltyCard = $loyaltyCard;

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

    public function getLoyaltyProgram(): ?LoyaltyProgram
    {
        return $this->loyaltyProgram;
    }

    public function setLoyaltyProgram(?LoyaltyProgram $loyaltyProgram): static
    {
        $this->loyaltyProgram = $loyaltyProgram;

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

    public function getStatus(): RewardStatus
    {
        return $this->status;
    }

    public function setStatus(RewardStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getClaimQrToken(): ?string
    {
        return $this->claimQrToken;
    }

    public function setClaimQrToken(string $claimQrToken): static
    {
        $this->claimQrToken = $claimQrToken;

        return $this;
    }

    public function getGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(\DateTimeImmutable $generatedAt): static
    {
        $this->generatedAt = $generatedAt;

        return $this;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeImmutable $claimedAt): static
    {
        $this->claimedAt = $claimedAt;

        return $this;
    }

    public function getClaimedByMerchantUser(): ?User
    {
        return $this->claimedByMerchantUser;
    }

    public function setClaimedByMerchantUser(?User $claimedByMerchantUser): static
    {
        $this->claimedByMerchantUser = $claimedByMerchantUser;

        return $this;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function setCancelReason(?string $cancelReason): static
    {
        $this->cancelReason = $cancelReason;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }
}
