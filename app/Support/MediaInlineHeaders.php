<?php

namespace App\Support;

/**
 * Security headers for a Media File served `inline` from the tenant's own origin.
 *
 * An uploaded file is somebody's content. Opened directly in a tab, an SVG (or any
 * type the browser treats as a document) would run its scripts with the session of
 * whoever opened the link. Types the browser only displays or plays are left alone;
 * everything else is sandboxed: no scripts, no access to the tenant origin.
 * A PDF is left alone as well: a sandbox stops the browser's own PDF viewer.
 */
class MediaInlineHeaders
{
    private const PASSIVE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    /** @return array<string, string> */
    public static function for(?string $mimeType): array
    {
        $mimeType = strtolower(trim(explode(';', (string) $mimeType)[0]));
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        if (in_array($mimeType, self::PASSIVE_TYPES, true)
            || str_starts_with($mimeType, 'audio/') || str_starts_with($mimeType, 'video/')) {
            return $headers;
        }

        return $headers + ['Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'"];
    }
}
