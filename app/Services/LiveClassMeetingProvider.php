<?php

namespace App\Services;

/** The provider name stored beside a Session's join link. */
final class LiveClassMeetingProvider
{
    public static function fromUrl(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        return match (true) {
            str_contains($host, 'zoom.') => 'Zoom',
            $host === 'meet.google.com' => 'Google Meet',
            str_contains($host, 'teams.microsoft.com'), str_contains($host, 'teams.live.com') => 'Microsoft Teams',
            default => preg_replace('/^www\./', '', $host),
        };
    }
}
