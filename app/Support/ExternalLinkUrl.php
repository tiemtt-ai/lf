<?php

namespace App\Support;

/**
 * ADR-0035: the link of an "Link nền tảng ngoài" Activity. HTTPS only, at most 1000 characters, a host, and no
 * user name or password in the URL. Used when the Activity is saved and again when the Template is published.
 */
final class ExternalLinkUrl
{
    public const MAX_LENGTH = 1000;

    public static function isValid(?string $url): bool
    {
        if ($url === null || $url === '' || mb_strlen($url) > self::MAX_LENGTH || $url !== trim($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
