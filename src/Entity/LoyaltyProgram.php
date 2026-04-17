<?php

namespace App\Entity;

use App\Enum\LoyaltyProgramType;
use App\Repository\LoyaltyProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\SerializedName;

#[ORM\Entity(repositoryClass: LoyaltyProgramRepository::class)]
class LoyaltyProgram
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: LoyaltyProgramType::class)]
    private LoyaltyProgramType $type = LoyaltyProgramType::POINTS;

    #[ORM\Column(nullable: true)]
    #[SerializedName('points_per_euro')]
    private ?int $pointsPerEuro = null;

    #[ORM\Column(nullable: true)]
    #[SerializedName('points_target')]
    private ?int $pointsTarget = null;

    #[ORM\Column(nullable: true)]
    #[SerializedName('stamp_target')]
    private ?int $stampTarget = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rewardDescription = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\ManyToOne(inversedBy: 'loyaltyPrograms')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Merchant $merchant = null;

    #[ORM\OneToMany(targetEntity: LoyaltyCard::class, mappedBy: 'loyaltyProgram', orphanRemoval: true)]
    private Collection $loyaltyCards;

    #[ORM\OneToMany(targetEntity: Reward::class, mappedBy: 'loyaltyProgram')]
    private Collection $rewards;

    public function __construct()
    {
        $this->loyaltyCards = new ArrayCollection();
        $this->rewards = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getType(): LoyaltyProgramType
    {
        return $this->type;
    }

    public function setType(LoyaltyProgramType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPointsPerEuro(): ?int
    {
        return $this->pointsPerEuro;
    }

    public function setPointsPerEuro(?int $pointsPerEuro): static
    {
        $this->pointsPerEuro = $pointsPerEuro;

        return $this;
    }

    public function getPointsTarget(): ?int
    {
        return $this->pointsTarget;
    }

    public function setPointsTarget(?int $pointsTarget): static
    {
        $this->pointsTarget = $pointsTarget;

        return $this;
    }

    public function getStampTarget(): ?int
    {
        return $this->stampTarget;
    }

    public function setStampTarget(?int $stampTarget): static
    {
        $this->stampTarget = $stampTarget;

        return $this;
    }

    public function getRewardDescription(): ?string
    {
        return $this->rewardDescription;
    }

    public function setRewardDescription(?string $rewardDescription): static
    {
        $this->rewardDescription = $rewardDescription;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getMerchant(): ?Merchant
    {
        return $this->merchant;
    }

    public function setMerchant(?Merchant $merchant): static
    {
        if ($this->merchant === $merchant) {
            return $this;
        }

        $previousMerchant = $this->merchant;
        $this->merchant = $merchant;

        if ($previousMerchant !== null && $previousMerchant->getLoyaltyPrograms()->contains($this)) {
            $previousMerchant->removeLoyaltyProgram($this);
        }

        if ($merchant !== null && !$merchant->getLoyaltyPrograms()->contains($this)) {
            $merchant->addLoyaltyProgram($this);
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
            $loyaltyCard->setLoyaltyProgram($this);
        }

        return $this;
    }

    public function removeLoyaltyCard(LoyaltyCard $loyaltyCard): static
    {
        if ($this->loyaltyCards->removeElement($loyaltyCard)) {
            // set the owning side to null (unless already changed)
            if ($loyaltyCard->getLoyaltyProgram() === $this) {
                $loyaltyCard->setLoyaltyProgram(null);
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
            $reward->setLoyaltyProgram($this);
        }

        return $this;
    }

    public function removeReward(Reward $reward): static
    {
        if ($this->rewards->removeElement($reward)) {
            if ($reward->getLoyaltyProgram() === $this) {
                $reward->setLoyaltyProgram(null);
            }
        }

        return $this;
    }
}