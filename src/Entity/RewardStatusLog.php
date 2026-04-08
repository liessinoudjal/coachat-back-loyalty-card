<?php

namespace App\Entity;

use App\Enum\RewardStatus;
use App\Repository\RewardStatusLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RewardStatusLogRepository::class)]
#[ORM\Table(name: 'reward_status_log', indexes: [
    new ORM\Index(name: 'IDX_REWARD_STATUS_LOG_REWARD_DATE', columns: ['reward_id', 'changed_at']),
])]
class RewardStatusLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reward $reward = null;

    #[ORM\Column(enumType: RewardStatus::class, nullable: true)]
    private ?RewardStatus $fromStatus = null;

    #[ORM\Column(enumType: RewardStatus::class)]
    private ?RewardStatus $toStatus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $changedAt = null;

    #[ORM\ManyToOne]
    private ?User $actorMerchantUser = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReward(): ?Reward
    {
        return $this->reward;
    }

    public function setReward(?Reward $reward): static
    {
        $this->reward = $reward;

        return $this;
    }

    public function getFromStatus(): ?RewardStatus
    {
        return $this->fromStatus;
    }

    public function setFromStatus(?RewardStatus $fromStatus): static
    {
        $this->fromStatus = $fromStatus;

        return $this;
    }

    public function getToStatus(): ?RewardStatus
    {
        return $this->toStatus;
    }

    public function setToStatus(RewardStatus $toStatus): static
    {
        $this->toStatus = $toStatus;

        return $this;
    }

    public function getChangedAt(): ?\DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function setChangedAt(\DateTimeImmutable $changedAt): static
    {
        $this->changedAt = $changedAt;

        return $this;
    }

    public function getActorMerchantUser(): ?User
    {
        return $this->actorMerchantUser;
    }

    public function setActorMerchantUser(?User $actorMerchantUser): static
    {
        $this->actorMerchantUser = $actorMerchantUser;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

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
