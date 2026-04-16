<?php

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Merchant $merchant = null;

    #[ORM\OneToOne(inversedBy: 'customer')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /**
     * @var Collection<int, Merchant>
     */
    #[ORM\ManyToMany(targetEntity: Merchant::class)]
    #[ORM\JoinTable(name: 'customer_merchants')]
    private Collection $merchants;

    #[ORM\OneToMany(targetEntity: LoyaltyCard::class, mappedBy: 'customer')]
    private Collection $loyaltyCards;

    #[ORM\OneToMany(targetEntity: Reward::class, mappedBy: 'customer', orphanRemoval: true)]
    private Collection $rewards;

    #[ORM\OneToMany(targetEntity: CustomerMerchantNotificationPreference::class, mappedBy: 'customer', orphanRemoval: true)]
    private Collection $notificationPreferences;

    public function __construct()
    {
        $this->loyaltyCards = new ArrayCollection();
        $this->rewards = new ArrayCollection();
        $this->merchants = new ArrayCollection();
        $this->notificationPreferences = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        if ($this->user === $user) {
            return $this;
        }

        $previousUser = $this->user;
        $this->user = $user;

        if ($previousUser !== null && $previousUser->getCustomer() === $this) {
            $previousUser->setCustomer(null);
        }

        if ($user !== null && $user->getCustomer() !== $this) {
            $user->setCustomer($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Merchant>
     */
    public function getMerchants(): Collection
    {
        return $this->merchants;
    }

    public function addMerchant(Merchant $merchant): static
    {
        if (!$this->merchants->contains($merchant)) {
            $this->merchants->add($merchant);
        }

        return $this;
    }

    public function removeMerchant(Merchant $merchant): static
    {
        $this->merchants->removeElement($merchant);

        return $this;
    }

    /**
     * @return Collection<int, LoyaltyCard>
     */
    public function getLoyaltyCards(): Collection
    {
        return $this->loyaltyCards;
    }

    public function addLoyaltyCard(LoyaltyCard $loyaltyCard): static
    {
        if (!$this->loyaltyCards->contains($loyaltyCard)) {
            $this->loyaltyCards->add($loyaltyCard);
            $loyaltyCard->setCustomer($this);
        }

        return $this;
    }

    public function removeLoyaltyCard(LoyaltyCard $loyaltyCard): static
    {
        if ($this->loyaltyCards->removeElement($loyaltyCard)) {
            // set the owning side to null (unless already changed)
            if ($loyaltyCard->getCustomer() === $this) {
                $loyaltyCard->setCustomer(null);
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
            $reward->setCustomer($this);
        }

        return $this;
    }

    public function removeReward(Reward $reward): static
    {
        if ($this->rewards->removeElement($reward)) {
            if ($reward->getCustomer() === $this) {
                $reward->setCustomer(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, CustomerMerchantNotificationPreference>
     */
    public function getNotificationPreferences(): Collection
    {
        return $this->notificationPreferences;
    }

    public function addNotificationPreference(CustomerMerchantNotificationPreference $notificationPreference): static
    {
        if (!$this->notificationPreferences->contains($notificationPreference)) {
            $this->notificationPreferences->add($notificationPreference);
            $notificationPreference->setCustomer($this);
        }

        return $this;
    }

    public function removeNotificationPreference(CustomerMerchantNotificationPreference $notificationPreference): static
    {
        if ($this->notificationPreferences->removeElement($notificationPreference)) {
            if ($notificationPreference->getCustomer() === $this) {
                $notificationPreference->setCustomer(null);
            }
        }

        return $this;
    }
}