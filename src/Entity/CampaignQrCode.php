<?php

namespace App\Entity;

use App\Repository\CampaignQrCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampaignQrCodeRepository::class)]
#[ORM\Table(name: 'campaign_qr_code')]
#[ORM\Index(name: 'IDX_CAMPAIGN_QR_SLUG', columns: ['slug'])]
class CampaignQrCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** Reference template key picked by the super admin (e.g. 'partner-light', 'partner-dark'). */
    #[ORM\Column(length: 40)]
    private string $templateKey = 'partner-light';

    /** Target relative path on the front (defaults to '/'). */
    #[ORM\Column(length: 255)]
    private string $targetPath = '/';

    /** UTM source/campaign baked into the redirect URL for analytics. */
    #[ORM\Column(length: 80)]
    private string $utmSource = 'qr';

    #[ORM\Column(length: 80)]
    private string $utmMedium = 'print';

    #[ORM\Column(length: 80)]
    private string $utmCampaign = '';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $scanCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    public function setTemplateKey(string $templateKey): static
    {
        $this->templateKey = $templateKey;
        return $this;
    }

    public function getTargetPath(): string
    {
        return $this->targetPath;
    }

    public function setTargetPath(string $targetPath): static
    {
        $this->targetPath = $targetPath;
        return $this;
    }

    public function getUtmSource(): string
    {
        return $this->utmSource;
    }

    public function setUtmSource(string $utmSource): static
    {
        $this->utmSource = $utmSource;
        return $this;
    }

    public function getUtmMedium(): string
    {
        return $this->utmMedium;
    }

    public function setUtmMedium(string $utmMedium): static
    {
        $this->utmMedium = $utmMedium;
        return $this;
    }

    public function getUtmCampaign(): string
    {
        return $this->utmCampaign;
    }

    public function setUtmCampaign(string $utmCampaign): static
    {
        $this->utmCampaign = $utmCampaign;
        return $this;
    }

    public function getScanCount(): int
    {
        return $this->scanCount;
    }

    public function setScanCount(int $scanCount): static
    {
        $this->scanCount = $scanCount;
        return $this;
    }

    public function incrementScanCount(): static
    {
        $this->scanCount += 1;
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

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;
        return $this;
    }
}
