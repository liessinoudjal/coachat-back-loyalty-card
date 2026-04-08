<?php

declare(strict_types=1);

namespace App\Exception;

class PortalTokenException extends \RuntimeException
{
    public function __construct(
        private readonly string $portalCode,
        int $httpStatus,
        string $message = '',
        private readonly ?string $details = null,
    ) {
        parent::__construct($message ?: $portalCode, $httpStatus);
    }

    public function getPortalCode(): string
    {
        return $this->portalCode;
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }
}
