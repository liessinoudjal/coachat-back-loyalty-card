<?php

namespace App\Exception;

final class CustomerMerchantLimitReachedException extends \RuntimeException
{
    public function __construct(
        private readonly string $merchantId,
        private readonly string $merchantName,
        private readonly int $currentCustomers,
        private readonly int $maxCustomers,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'customer_limit_reached');
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function getMerchantName(): string
    {
        return $this->merchantName;
    }

    public function getCurrentCustomers(): int
    {
        return $this->currentCustomers;
    }

    public function getMaxCustomers(): int
    {
        return $this->maxCustomers;
    }
}