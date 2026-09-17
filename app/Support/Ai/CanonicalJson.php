<?php

namespace App\Support\Ai;

/**
 * Deterministic JSON for provenance hashes (Step 7 contract § Canonical context
 * fingerprint v1): object keys sorted, list order kept, explicit NULLs kept,
 * UTF-8 and slashes unescaped so the bytes do not depend on encoder flags.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Lowercase SHA-256 hex, the CHAR(64) form every Step 7 hash column stores. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::normalize(...), $value);
    }
}
