<?php

namespace App\Entity;

use App\Repository\LoyaltyCardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LoyaltyCardRepository::class)]
class LoyaltyCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $qrCode = null;

    #[ORM\Column(length: 36, unique: true)]
    private ?string $walletToken = null;

    #[ORM\Column]
    private ?int $currentValue = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetValue = null;

    #[ORM\Column]
    private bool $isCompleted = false;

    #[ORM\ManyToOne(inversedBy: 'loyaltyCards')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Merchant $merchant = null;

    #[ORM\ManyToOne(inversedBy: 'loyaltyCards')]
    #[ORM\JoinColumn(nullable: false)]
    private ?LoyaltyProgram $loyaltyProgram = null;

    #[ORM\ManyToOne(inversedBy: 'loyaltyCards')]
    private ?Customer $customer = null;

    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'loyaltyCard', orphanRemoval: true)]
    private Collection $transactions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->walletToken = Uuid::v4()->toRfc4122();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQrCode(): ?string
    {
        return $this->qrCode;
    }

    public function setQrCode(string $qrCode): static
    {
        $this->qrCode = $qrCode;

        return $this;
    }

    public function getWalletToken(): ?string
    {
        return $this->walletToken;
    }

    public function setWalletToken(string $walletToken): static
    {
        $this->walletToken = $walletToken;

        return $this;
    }

    public function getPoints(): ?int
    {
        return $this->currentValue;
    }

    public function setPoints(int $points): static
    {
        $this->currentValue = $points;

        return $this;
    }

    public function getCurrentValue(): ?int
    {
        return $this->currentValue;
    }

    public function setCurrentValue(int $currentValue): static
    {
        $this->currentValue = $currentValue;

        return $this;
    }

    public function getTargetValue(): ?int
    {
        return $this->targetValue;
    }

    public function setTargetValue(?int $targetValue): static
    {
        $this->targetValue = $targetValue;

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): static
    {
        $this->isCompleted = $isCompleted;

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

    public function getLoyaltyProgram(): ?LoyaltyProgram
    {
        return $this->loyaltyProgram;
    }

    public function setLoyaltyProgram(?LoyaltyProgram $loyaltyProgram): static
    {
        $this->loyaltyProgram = $loyaltyProgram;

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

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setLoyaltyCard($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            // set the owning side to null (unless already changed)
            if ($transaction->getLoyaltyCard() === $this) {
                $transaction->setLoyaltyCard(null);
            }
        }

        return $this;
    }
}