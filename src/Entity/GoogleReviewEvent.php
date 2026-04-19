<?php

namespace App\Entity;

use App\Enum\GoogleReviewEventType;
use App\Repository\GoogleReviewEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GoogleReviewEventRepository::class)]
#[ORM\Table(name: 'google_review_event')]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_EVENT_MERCHANT_DATE', columns: ['merchant_id', 'created_at'])]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_EVENT_SESSION', columns: ['session_id'])]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_EVENT_TYPE', columns: ['event_type'])]
class GoogleReviewEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Merchant $merchant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MerchantGoogleReviewModule $module = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GoogleReviewSession $session = null;

    #[ORM\Column(enumType: GoogleReviewEventType::class, length: 32)]
    private GoogleReviewEventType $eventType;

    #[ORM\Column(length: 64)]
    private string $source = 'system';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->eventType = GoogleReviewEventType::MODULE_VIEWED;
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

    public function getMerchant(): ?Merchant
    {
        return $this->merchant;
    }

    public function setMerchant(?Merchant $merchant): static
    {
        $this->merchant = $merchant;

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

    public function getModule(): ?MerchantGoogleReviewModule
    {
        return $this->module;
    }

    public function setModule(?MerchantGoogleReviewModule $module): static
    {
        $this->module = $module;

        return $this;
    }

    public function getSession(): ?GoogleReviewSession
    {
        return $this->session;
    }

    public function setSession(?GoogleReviewSession $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getEventType(): GoogleReviewEventType
    {
        return $this->eventType;
    }

    public function setEventType(GoogleReviewEventType $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

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