<?php

namespace App\Entity;

use App\Enum\ContestStatus;
use App\Repository\ContestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ContestRepository::class)]
#[ORM\Table(name: 'contest')]
#[ORM\Index(name: 'IDX_CONTEST_MERCHANT_STATUS', columns: ['merchant_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class Contest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Merchant $merchant = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $drawAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startNotificationSentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endingSoonNotificationSentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dayBeforeNotificationSentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $drawDayNotificationSentAt = null;

    #[ORM\Column(enumType: ContestStatus::class, length: 32)]
    private ContestStatus $status = ContestStatus::SCHEDULED;

    #[ORM\OneToMany(mappedBy: 'contest', targetEntity: ContestReward::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['rank' => 'ASC'])]
    private Collection $rewards;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->rewards = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(?\DateTimeImmutable $startAt): self
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeImmutable $endAt): self
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getDrawAt(): ?\DateTimeImmutable
    {
        return $this->drawAt;
    }

    public function setDrawAt(?\DateTimeImmutable $drawAt): self
    {
        $this->drawAt = $drawAt;

        return $this;
    }

    public function getStartNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->startNotificationSentAt;
    }

    public function setStartNotificationSentAt(?\DateTimeImmutable $startNotificationSentAt): self
    {
        $this->startNotificationSentAt = $startNotificationSentAt;

        return $this;
    }

    public function getEndingSoonNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->endingSoonNotificationSentAt;
    }

    public function setEndingSoonNotificationSentAt(?\DateTimeImmutable $endingSoonNotificationSentAt): self
    {
        $this->endingSoonNotificationSentAt = $endingSoonNotificationSentAt;

        return $this;
    }

    public function getDayBeforeNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->dayBeforeNotificationSentAt;
    }

    public function setDayBeforeNotificationSentAt(?\DateTimeImmutable $dayBeforeNotificationSentAt): self
    {
        $this->dayBeforeNotificationSentAt = $dayBeforeNotificationSentAt;

        return $this;
    }

    public function getDrawDayNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->drawDayNotificationSentAt;
    }

    public function setDrawDayNotificationSentAt(?\DateTimeImmutable $drawDayNotificationSentAt): self
    {
        $this->drawDayNotificationSentAt = $drawDayNotificationSentAt;

        return $this;
    }

    public function getStatus(): ContestStatus
    {
        return $this->status;
    }

    public function setStatus(ContestStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return Collection<int, ContestReward>
     */
    public function getRewards(): Collection
    {
        return $this->rewards;
    }

    public function addReward(ContestReward $reward): self
    {
        if (!$this->rewards->contains($reward)) {
            $this->rewards->add($reward);
            $reward->setContest($this);
        }

        return $this;
    }

    public function removeReward(ContestReward $reward): self
    {
        if ($this->rewards->removeElement($reward)) {
            if ($reward->getContest() === $this) {
                $reward->setContest(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
