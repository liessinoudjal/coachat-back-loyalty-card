<?php

namespace App\Exception;

final class GoogleReviewException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly int $statusCode,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}