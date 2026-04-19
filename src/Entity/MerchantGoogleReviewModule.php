<?php

namespace App\Entity;

use App\Repository\MerchantGoogleReviewModuleRepository;
use App\Service\GoogleReviewUrlValidator;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: MerchantGoogleReviewModuleRepository::class)]
#[ORM\Table(name: 'merchant_google_review_module')]
#[ORM\UniqueConstraint(name: 'UNIQ_GOOGLE_REVIEW_MODULE_MERCHANT', columns: ['merchant_id'])]
#[ORM\Index(name: 'IDX_GOOGLE_REVIEW_MODULE_ENABLED', columns: ['is_enabled'])]
#[ORM\HasLifecycleCallbacks]
class MerchantGoogleReviewModule
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Merchant $merchant = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isEnabled = false;

    #[ORM\Column(length: 255, options: ['default' => 'Avis Google'])]
    #[Assert\Length(max: 255)]
    private string $displayName = 'Avis Google';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $googleReviewUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googlePlaceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googlePlaceName = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $showInCustomerDashboard = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showQrCode = true;

    #[ORM\Column(type: Types::JSON)]
    private array $rewardOptions = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getGoogleReviewUrl(): ?string
    {
        return $this->googleReviewUrl;
    }

    public function setGoogleReviewUrl(?string $googleReviewUrl): static
    {
        $this->googleReviewUrl = $googleReviewUrl;

        return $this;
    }

    public function getGooglePlaceId(): ?string
    {
        return $this->googlePlaceId;
    }

    public function setGooglePlaceId(?string $googlePlaceId): static
    {
        $this->googlePlaceId = $googlePlaceId;

        return $this;
    }

    public function getGooglePlaceName(): ?string
    {
        return $this->googlePlaceName;
    }

    public function setGooglePlaceName(?string $googlePlaceName): static
    {
        $this->googlePlaceName = $googlePlaceName;

        return $this;
    }

    public function isShowInCustomerDashboard(): bool
    {
        return $this->showInCustomerDashboard;
    }

    public function setShowInCustomerDashboard(bool $showInCustomerDashboard): static
    {
        $this->showInCustomerDashboard = $showInCustomerDashboard;

        return $this;
    }

    public function isShowQrCode(): bool
    {
        return $this->showQrCode;
    }

    public function setShowQrCode(bool $showQrCode): static
    {
        $this->showQrCode = $showQrCode;

        return $this;
    }

    public function getRewardOptions(): array
    {
        return $this->rewardOptions;
    }

    public function setRewardOptions(array $rewardOptions): static
    {
        $this->rewardOptions = array_values($rewardOptions);

        return $this;
    }

    /**
     * @return list<array{id: string, label: string, description: ?string, active: bool, order: int}>
     */
    public function getActiveRewardOptions(): array
    {
        return array_values(array_filter(
            $this->rewardOptions,
            static fn (mixed $option): bool => is_array($option) && (($option['active'] ?? false) === true),
        ));
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->googleReviewUrl !== null && $this->googleReviewUrl !== '' && !GoogleReviewUrlValidator::isAllowedGoogleReviewUrl($this->googleReviewUrl)) {
            $context->buildViolation('google_review_url_invalid')
                ->atPath('googleReviewUrl')
                ->addViolation();
        }

        if (!$this->hasValidRewardOptionsStructure()) {
            $context->buildViolation('google_review_rewards_invalid')
                ->atPath('rewardOptions')
                ->addViolation();
        }

        if ($this->isEnabled && ($this->googleReviewUrl === null || trim($this->googleReviewUrl) === '')) {
            $context->buildViolation('google_review_url_missing')
                ->atPath('googleReviewUrl')
                ->addViolation();
        }

        if ($this->isEnabled && $this->getActiveRewardOptions() === []) {
            $context->buildViolation('google_review_rewards_missing')
                ->atPath('rewardOptions')
                ->addViolation();
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function hasValidRewardOptionsStructure(): bool
    {
        foreach ($this->rewardOptions as $option) {
            if (!is_array($option)) {
                return false;
            }

            $id = $option['id'] ?? null;
            $label = $option['label'] ?? null;
            $description = $option['description'] ?? null;
            $active = $option['active'] ?? null;
            $order = $option['order'] ?? null;

            if (!is_string($id) || trim($id) === '') {
                return false;
            }

            if (!is_string($label) || trim($label) === '') {
                return false;
            }

            if ($description !== null && !is_string($description)) {
                return false;
            }

            if (!is_bool($active)) {
                return false;
            }

            if (!is_int($order)) {
                return false;
            }
        }

        return true;
    }
}