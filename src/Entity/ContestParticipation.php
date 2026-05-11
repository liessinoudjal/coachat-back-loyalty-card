<?php

namespace App\Entity;

use App\Repository\ContestParticipationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContestParticipationRepository::class)]
#[ORM\Table(name: 'contest_participation')]
#[ORM\Index(name: 'IDX_CONTEST_PARTICIPATION_CONTEST_CUSTOMER', columns: ['contest_id', 'customer_id'])]
#[ORM\Index(name: 'IDX_CONTEST_PARTICIPATION_WINNING_ENTRY', columns: ['contest_id', 'is_winning_entry'])]
#[ORM\HasLifecycleCallbacks]
class ContestParticipation
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
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Transaction $transaction = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isWinningEntry = false;

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

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): self
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function isWinningEntry(): bool
    {
        return $this->isWinningEntry;
    }

    public function setIsWinningEntry(bool $isWinningEntry): self
    {
        $this->isWinningEntry = $isWinningEntry;

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
}
