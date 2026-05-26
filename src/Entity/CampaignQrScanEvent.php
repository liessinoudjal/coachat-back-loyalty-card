<?php

namespace App\Entity;

use App\Repository\CampaignQrScanEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampaignQrScanEventRepository::class)]
#[ORM\Table(name: 'campaign_qr_scan_event')]
#[ORM\Index(name: 'IDX_QR_SCAN_CAMPAIGN_OCCURRED', columns: ['campaign_qr_code_id', 'occurred_at'])]
class CampaignQrScanEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CampaignQrCode $campaignQrCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $occurredAt = null;

    /** Truncated/hashed IP for analytics (no raw IP stored). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ipHash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referer = null;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaignQrCode(): ?CampaignQrCode
    {
        return $this->campaignQrCode;
    }

    public function setCampaignQrCode(?CampaignQrCode $campaignQrCode): static
    {
        $this->campaignQrCode = $campaignQrCode;
        return $this;
    }

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;
        return $this;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function setIpHash(?string $ipHash): static
    {
        $this->ipHash = $ipHash;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
        return $this;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function setReferer(?string $referer): static
    {
        $this->referer = $referer !== null ? mb_substr($referer, 0, 255) : null;
        return $this;
    }
}
