<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerPortalSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CustomerPortalSessionRepository::class)]
#[ORM\Table(name: 'customer_portal_session', indexes: [
    new ORM\Index(name: 'IDX_PORTAL_CUSTOMER', columns: ['customer_id']),
    new ORM\Index(name: 'IDX_PORTAL_EXPIRES_AT', columns: ['expires_at']),
], uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'UNIQ_PORTAL_TOKEN_HASH', columns: ['token_hash']),
])]
class CustomerPortalSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Customer $customer = null;

    /** HMAC-SHA256 of the raw portal token — never stored in clear. 64 hex chars. */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $issuedFromWalletToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    /** Preserved across refreshes to enforce the 24h rolling window. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $originalIssuedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** IPv4 (15) or IPv6 (45) address for audit. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255)]
    private string $scope = 'cards:read rewards:read';

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->issuedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+15 minutes');
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): static
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getIssuedFromWalletToken(): ?string
    {
        return $this->issuedFromWalletToken;
    }

    public function setIssuedFromWalletToken(?string $issuedFromWalletToken): static
    {
        $this->issuedFromWalletToken = $issuedFromWalletToken;
        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getOriginalIssuedAt(): ?\DateTimeImmutable
    {
        return $this->originalIssuedAt;
    }

    public function setOriginalIssuedAt(?\DateTimeImmutable $originalIssuedAt): static
    {
        $this->originalIssuedAt = $originalIssuedAt;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }
}
