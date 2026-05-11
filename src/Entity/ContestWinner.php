<?php

namespace App\Entity;

use App\Repository\ContestWinnerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContestWinnerRepository::class)]
#[ORM\Table(name: 'contest_winner')]
#[ORM\Index(name: 'IDX_CONTEST_WINNER_QR_TOKEN', columns: ['qr_code_token'])]
#[ORM\Index(name: 'IDX_CONTEST_WINNER_CONTEST_CUSTOMER', columns: ['contest_id', 'customer_id'])]
#[ORM\Index(name: 'IDX_CONTEST_WINNER_CLAIMED', columns: ['is_claimed'])]
#[ORM\HasLifecycleCallbacks]
class ContestWinner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Contest $contest = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContestReward $reward = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $qrCodeToken = '';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isClaimed = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContest(): ?Contest
    {
        return $this->contest;
    }

    public function setContest(?Contest $contest): self
    {
        $this->contest = $contest;

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

    public function getReward(): ?ContestReward
    {
        return $this->reward;
    }

    public function setReward(?ContestReward $reward): self
    {
        $this->reward = $reward;

        return $this;
    }

    public function getQrCodeToken(): string
    {
        return $this->qrCodeToken;
    }

    public function setQrCodeToken(string $qrCodeToken): self
    {
        $this->qrCodeToken = $qrCodeToken;

        return $this;
    }

    public function isClaimed(): bool
    {
        return $this->isClaimed;
    }

    public function setIsClaimed(bool $isClaimed): self
    {
        $this->isClaimed = $isClaimed;

        return $this;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeImmutable $claimedAt): self
    {
        $this->claimedAt = $claimedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function markAsClaimed(): self
    {
        $this->isClaimed = true;
        $this->claimedAt = new \DateTimeImmutable();

        return $this;
    }
}
