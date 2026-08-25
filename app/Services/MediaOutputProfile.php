<?php

namespace App\Services;

use InvalidArgumentException;

class MediaOutputProfile
{
    public function canonicalLocale(string $locale): string
    {
        $parts = explode('-', str_replace('_', '-', trim($locale)));
        if ($parts === [] || ! preg_match('/^[A-Za-z]{2,3}$/', $parts[0])) {
            throw new InvalidArgumentException('Invalid BCP 47 locale.');
        }
        $parts[0] = strtolower($parts[0]);
        foreach (array_slice($parts, 1) as $index => $part) {
            if (! preg_match('/^[A-Za-z0-9]{2,8}$/', $part)) {
                throw new InvalidArgumentException('Invalid BCP 47 locale.');
            }
            $parts[$index + 1] = strlen($part) === 4
                ? ucfirst(strtolower($part))
                : (strlen($part) === 2 || (strlen($part) === 3 && ctype_digit($part))
                    ? strtoupper($part)
                    : strtolower($part));
        }

        return implode('-', $parts);
    }

    /** @param array<string, string> $values */
    public function canonical(array $values): string
    {
        ksort($values, SORT_STRING);

        return collect($values)->map(function (string $value, string $key): string {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $key) || strpbrk($value, '=;') !== false) {
                throw new InvalidArgumentException('Invalid output profile.');
            }

            return $key.'='.$value;
        })->implode(';');
    }

    public function hash(string $profile): string
    {
        return hash('sha256', $profile);
    }
}
