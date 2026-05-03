<?php

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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

    #[ORM\ManyToOne(inversedBy: 'staffCustomers')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Merchant $staffMerchant = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $staffAssignedAt = null;

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

    #[ORM\Column(options: ['default' => false])]
    private bool $acceptedTerms = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $acceptedTermsVersion = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $acceptedTermsAcceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->loyaltyCards = new ArrayCollection();
        $this->rewards = new ArrayCollection();
        $this->merchants = new ArrayCollection();
        $this->notificationPreferences = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getStaffMerchant(): ?Merchant
    {
        return $this->staffMerchant;
    }

    public function setStaffMerchant(?Merchant $staffMerchant): static
    {
        $this->staffMerchant = $staffMerchant;

        return $this;
    }

    public function getStaffAssignedAt(): ?\DateTimeImmutable
    {
        return $this->staffAssignedAt;
    }

    public function setStaffAssignedAt(?\DateTimeImmutable $staffAssignedAt): static
    {
        $this->staffAssignedAt = $staffAssignedAt;

        return $this;
    }

    public function isStaffForMerchant(Merchant $merchant): bool
    {
        return $this->staffMerchant === $merchant;
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

    public function isAcceptedTerms(): bool
    {
        return $this->acceptedTerms;
    }

    public function setAcceptedTerms(bool $acceptedTerms): static
    {
        $this->acceptedTerms = $acceptedTerms;

        return $this;
    }

    public function getAcceptedTermsVersion(): ?string
    {
        return $this->acceptedTermsVersion;
    }

    public function setAcceptedTermsVersion(?string $acceptedTermsVersion): static
    {
        $this->acceptedTermsVersion = $acceptedTermsVersion;

        return $this;
    }

    public function getAcceptedTermsAcceptedAt(): ?\DateTimeInterface
    {
        return $this->acceptedTermsAcceptedAt;
    }

    public function setAcceptedTermsAcceptedAt(?\DateTimeInterface $acceptedTermsAcceptedAt): static
    {
        $this->acceptedTermsAcceptedAt = $acceptedTermsAcceptedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}