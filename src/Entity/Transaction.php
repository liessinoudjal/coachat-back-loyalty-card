<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $pointsEarned = null;

    #[ORM\Column]
    private ?int $pointsRedeemed = null;

    #[ORM\Column(nullable: true)]
    private ?int $amountAdded = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Merchant $merchant = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?LoyaltyCard $loyaltyCard = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPointsEarned(): ?int
    {
        return $this->pointsEarned;
    }

    public function setPointsEarned(int $pointsEarned): static
    {
        $this->pointsEarned = $pointsEarned;

        return $this;
    }

    public function getPointsRedeemed(): ?int
    {
        return $this->pointsRedeemed;
    }

    public function setPointsRedeemed(int $pointsRedeemed): static
    {
        $this->pointsRedeemed = $pointsRedeemed;

        return $this;
    }

    public function getAmountAdded(): ?int
    {
        return $this->amountAdded;
    }

    public function setAmountAdded(?int $amountAdded): static
    {
        $this->amountAdded = $amountAdded;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

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

    public function getLoyaltyCard(): ?LoyaltyCard
    {
        return $this->loyaltyCard;
    }

    public function setLoyaltyCard(?LoyaltyCard $loyaltyCard): static
    {
        $this->loyaltyCard = $loyaltyCard;

        return $this;
    }
}