<?php

namespace App\Entity;

use App\Enum\ContestRewardType;
use App\Repository\ContestRewardCardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ContestRewardCardRepository::class)]
#[ORM\Table(name: 'contest_reward_card')]
#[ORM\Index(name: 'IDX_CRC_CUSTOMER', columns: ['customer_id'])]
#[ORM\Index(name: 'IDX_CRC_MERCHANT', columns: ['merchant_id'])]
#[ORM\HasLifecycleCallbacks]
class ContestRewardCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContestWinner $contestWinner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Merchant $merchant = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $walletToken;

    #[ORM\Column(enumType: ContestRewardType::class, length: 32)]
    private ContestRewardType $type;

    /**
     * Snapshot of the reward title at draw time.
     */
    #[ORM\Column(length: 255)]
    private string $title = '';

    /**
     * Snapshot of the reward description at draw time.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rewardDescription = null;

    #[ORM\Column]
    private int $targetValue = 0;

    #[ORM\Column]
    private int $currentValue = 0;

    #[ORM\Column]
    private bool $isCompleted = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->walletToken = Uuid::v4()->toRfc4122();
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContestWinner(): ?ContestWinner
    {
        return $this->contestWinner;
    }

    public function setContestWinner(?ContestWinner $contestWinner): self
    {
        $this->contestWinner = $contestWinner;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getMerchant(): ?Merchant
    {
        return $this->merchant;
    }

    public function setMerchant(?Merchant $merchant): self
    {
        $this->merchant = $merchant;

        return $this;
    }

    public function getWalletToken(): string
    {
        return $this->walletToken;
    }

    public function setWalletToken(string $walletToken): self
    {
        $this->walletToken = $walletToken;

        return $this;
    }

    public function getType(): ContestRewardType
    {
        return $this->type;
    }

    public function setType(ContestRewardType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getRewardDescription(): ?string
    {
        return $this->rewardDescription;
    }

    public function setRewardDescription(?string $rewardDescription): self
    {
        $this->rewardDescription = $rewardDescription;

        return $this;
    }

    public function getTargetValue(): int
    {
        return $this->targetValue;
    }

    public function setTargetValue(int $targetValue): self
    {
        $this->targetValue = $targetValue;

        return $this;
    }

    public function getCurrentValue(): int
    {
        return $this->currentValue;
    }

    public function setCurrentValue(int $currentValue): self
    {
        $this->currentValue = $currentValue;

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): self
    {
        $this->isCompleted = $isCompleted;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
