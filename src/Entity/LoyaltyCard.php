<?php

namespace App\Entity;

use App\Repository\LoyaltyCardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LoyaltyCardRepository::class)]
class LoyaltyCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private ?string $walletToken = null;

    #[ORM\Column]
    private ?int $currentValue = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetValue = null;

    #[ORM\Column]
    private bool $isCompleted = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $visible = true;

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

    #[ORM\OneToMany(targetEntity: Reward::class, mappedBy: 'loyaltyCard', orphanRemoval: true)]
    private Collection $rewards;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->rewards = new ArrayCollection();
        $this->walletToken = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

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

    /**
     * @return Collection<int, Reward>
     */
    public function getRewards(): Collection
    {
        return $this->rewards;
    }

    public function addReward(Reward $reward): static
    {
        if (!$this->rewards->contains($reward)) {
            $this->rewards->add($reward);
            $reward->setLoyaltyCard($this);
        }

        return $this;
    }

    public function removeReward(Reward $reward): static
    {
        if ($this->rewards->removeElement($reward)) {
            if ($reward->getLoyaltyCard() === $this) {
                $reward->setLoyaltyCard(null);
            }
        }

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
}