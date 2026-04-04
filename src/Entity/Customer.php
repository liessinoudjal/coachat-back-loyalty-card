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

    #[ORM\OneToMany(targetEntity: LoyaltyCard::class, mappedBy: 'customer')]
    private Collection $loyaltyCards;

    public function __construct()
    {
        $this->loyaltyCards = new ArrayCollection();
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
}