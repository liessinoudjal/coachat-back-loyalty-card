<?php

namespace App\Service;

final class GoogleReviewUrlValidator
{
    /**
     * @var list<string>
     */
    public const ALLOWED_HOSTS = [
        'google.com',
        'www.google.com',
        'maps.google.com',
        'g.page',
    ];

    public static function isAllowedGoogleReviewUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $parts = parse_url(trim($url));
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            return false;
        }

        if ($host === '' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return true;
    }

    public function normalize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $normalized = trim($url);

        return $normalized === '' ? null : $normalized;
    }
}