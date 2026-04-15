<?php

namespace App\Service;

class LegalTermsVersionProvider
{
    public function __construct(
        private readonly string $currentVersion,
    ) {
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }
}
