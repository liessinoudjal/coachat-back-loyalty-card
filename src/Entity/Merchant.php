<?php

namespace App\Entity;

use App\Repository\MerchantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Core\Annotation\ApiProperty;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MerchantRepository::class)]
class Merchant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[SerializedName('company_name')]
    private ?string $companyName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\ManyToOne(targetEntity: MerchantEstablishmentType::class)]
    #[ORM\JoinColumn(name: 'establishment_type_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MerchantEstablishmentType $establishmentType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instagramUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tiktokUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $trialEndsAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $currentPeriodStartAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $currentPeriodEndAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $acceptedTerms = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $acceptedTermsVersion = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $acceptedTermsAcceptedAt = null;

    #[ORM\Column(length: 50)]
    private string $subscriptionStatus = 'trial';

    #[ORM\OneToOne(inversedBy: 'merchant', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\OneToMany(targetEntity: LoyaltyProgram::class, mappedBy: 'merchant', orphanRemoval: true)]
    private Collection $loyaltyPrograms;

    #[ORM\OneToMany(targetEntity: LoyaltyCard::class, mappedBy: 'merchant', orphanRemoval: true)]
    private Collection $loyaltyCards;

    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'merchant', orphanRemoval: true)]
    private Collection $transactions;

    #[ORM\OneToMany(targetEntity: Reward::class, mappedBy: 'merchant', orphanRemoval: true)]
    private Collection $rewards;

    #[ORM\OneToMany(targetEntity: Customer::class, mappedBy: 'staffMerchant')]
    private Collection $staffCustomers;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: true, referencedColumnName: 'id', columnDefinition: 'VARCHAR(36) DEFAULT NULL')]
    private ?Plan $plan = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $geocodedAt = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $geocodeScore = null;
    /**
     * Compte gratuit accordé manuellement par un super-admin : exonère ce
     * merchant des plafonds de plan (clients, programmes, …) et des
     * blocages liés au subscription_status (canceled / trial expiré).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isFreeAccount = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $freeAccountGrantedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'free_account_granted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $freeAccountGrantedBy = null;
    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->loyaltyPrograms = new ArrayCollection();
        $this->loyaltyCards = new ArrayCollection();
        $this->transactions = new ArrayCollection();
        $this->rewards = new ArrayCollection();
        $this->staffCustomers = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getEstablishmentType(): ?MerchantEstablishmentType
    {
        return $this->establishmentType;
    }

    public function setEstablishmentType(?MerchantEstablishmentType $establishmentType): static
    {
        $this->establishmentType = $establishmentType;

        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): static
    {
        $this->logoUrl = $logoUrl;

        return $this;
    }

    public function getInstagramUrl(): ?string
    {
        return $this->instagramUrl;
    }

    public function setInstagramUrl(?string $instagramUrl): static
    {
        $this->instagramUrl = $instagramUrl;

        return $this;
    }

    public function getTiktokUrl(): ?string
    {
        return $this->tiktokUrl;
    }

    public function setTiktokUrl(?string $tiktokUrl): static
    {
        $this->tiktokUrl = $tiktokUrl;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeInterface
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeInterface $trialEndsAt): static
    {
        $this->trialEndsAt = $this->toMutableDateTime($trialEndsAt);

        return $this;
    }

    public function getCurrentPeriodStartAt(): ?\DateTimeInterface
    {
        return $this->currentPeriodStartAt;
    }

    public function setCurrentPeriodStartAt(?\DateTimeInterface $currentPeriodStartAt): static
    {
        $this->currentPeriodStartAt = $this->toMutableDateTime($currentPeriodStartAt);

        return $this;
    }

    public function getCurrentPeriodEndAt(): ?\DateTimeInterface
    {
        return $this->currentPeriodEndAt;
    }

    public function setCurrentPeriodEndAt(?\DateTimeInterface $currentPeriodEndAt): static
    {
        $this->currentPeriodEndAt = $this->toMutableDateTime($currentPeriodEndAt);

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
        $this->acceptedTermsAcceptedAt = $this->toMutableDateTime($acceptedTermsAcceptedAt);

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getGeocodedAt(): ?\DateTimeImmutable
    {
        return $this->geocodedAt;
    }

    public function setGeocodedAt(?\DateTimeImmutable $geocodedAt): static
    {
        $this->geocodedAt = $geocodedAt;

        return $this;
    }

    public function getGeocodeScore(): ?float
    {
        return $this->geocodeScore;
    }

    public function setGeocodeScore(?float $geocodeScore): static
    {
        $this->geocodeScore = $geocodeScore;

        return $this;
    }

    private function toMutableDateTime(?\DateTimeInterface $dateTime): ?\DateTime
    {
        if ($dateTime === null) {
            return null;
        }

        if ($dateTime instanceof \DateTime) {
            return $dateTime;
        }

        return new \DateTime($dateTime->format('Y-m-d H:i:s'), $dateTime->getTimezone());
    }

    public function getSubscriptionStatus(): string
    {
        return $this->subscriptionStatus;
    }

    public function setSubscriptionStatus(string $subscriptionStatus): static
    {
        $this->subscriptionStatus = $subscriptionStatus;

        return $this;
    }
    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return Collection<int, LoyaltyProgram>
     */
    public function getLoyaltyPrograms(): Collection
    {
        return $this->loyaltyPrograms;
    }

    #[ApiProperty(readable: true)]
    public function getActiveLoyaltyProgramCount(): int
    {
        return $this->loyaltyPrograms->filter(fn($p) => $p->isActive())->count();
    }

    public function addLoyaltyProgram(LoyaltyProgram $loyaltyProgram): static
    {
        if (!$this->loyaltyPrograms->contains($loyaltyProgram)) {
            $this->loyaltyPrograms->add($loyaltyProgram);
            $loyaltyProgram->setMerchant($this);
        }

        return $this;
    }

    public function removeLoyaltyProgram(LoyaltyProgram $loyaltyProgram): static
    {
        if ($this->loyaltyPrograms->removeElement($loyaltyProgram)) {
            // set the owning side to null (unless already changed)
            if ($loyaltyProgram->getMerchant() === $this) {
                $loyaltyProgram->setMerchant(null);
            }
        }

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
            $loyaltyCard->setMerchant($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Customer>
     */
    public function getStaffCustomers(): Collection
    {
        return $this->staffCustomers;
    }

    public function addStaffCustomer(Customer $customer): static
    {
        if (!$this->staffCustomers->contains($customer)) {
            $this->staffCustomers->add($customer);
            $customer->setStaffMerchant($this);
        }

        return $this;
    }

    public function removeStaffCustomer(Customer $customer): static
    {
        if ($this->staffCustomers->removeElement($customer)) {
            if ($customer->getStaffMerchant() === $this) {
                $customer->setStaffMerchant(null);
            }
        }

        return $this;
    }

    public function removeLoyaltyCard(LoyaltyCard $loyaltyCard): static
    {
        if ($this->loyaltyCards->removeElement($loyaltyCard)) {
            // set the owning side to null (unless already changed)
            if ($loyaltyCard->getMerchant() === $this) {
                $loyaltyCard->setMerchant(null);
            }
        }

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
            $transaction->setMerchant($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            // set the owning side to null (unless already changed)
            if ($transaction->getMerchant() === $this) {
                $transaction->setMerchant(null);
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
            $reward->setMerchant($this);
        }

        return $this;
    }

    public function removeReward(Reward $reward): static
    {
        if ($this->rewards->removeElement($reward)) {
            if ($reward->getMerchant() === $this) {
                $reward->setMerchant(null);
            }
        }

        return $this;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function isFreeAccount(): bool
    {
        return $this->isFreeAccount;
    }

    public function setIsFreeAccount(bool $isFreeAccount): static
    {
        $this->isFreeAccount = $isFreeAccount;

        return $this;
    }

    public function getFreeAccountGrantedAt(): ?\DateTimeInterface
    {
        return $this->freeAccountGrantedAt;
    }

    public function setFreeAccountGrantedAt(?\DateTimeInterface $freeAccountGrantedAt): static
    {
        $this->freeAccountGrantedAt = $freeAccountGrantedAt;

        return $this;
    }

    public function getFreeAccountGrantedBy(): ?User
    {
        return $this->freeAccountGrantedBy;
    }

    public function setFreeAccountGrantedBy(?User $freeAccountGrantedBy): static
    {
        $this->freeAccountGrantedBy = $freeAccountGrantedBy;

        return $this;
    }
}