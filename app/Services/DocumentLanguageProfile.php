<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DocumentLanguageProfile
{
    /** @param string|array<int, string> $input @return array<int, string> */
    public function canonical(string|array $input): array
    {
        $raw = is_array($input) ? $input : explode(',', $input);
        if ($raw === [] || count($raw) > 3) {
            throw new InvalidArgumentException('document_language_profile_invalid');
        }

        $locales = [];
        foreach ($raw as $locale) {
            if (! is_string($locale) || trim($locale) === '') {
                throw new InvalidArgumentException('document_language_profile_invalid');
            }
            try {
                $canonical = app(MediaOutputProfile::class)->canonicalLocale(trim($locale));
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('document_language_profile_invalid');
            }
            if (in_array($canonical, $locales, true)) {
                throw new InvalidArgumentException('document_language_profile_invalid');
            }
            if (! in_array($canonical, (array) config('media.processing.document.locales', []), true)) {
                throw new InvalidArgumentException('document_language_profile_unsupported');
            }
            $locales[] = $canonical;
        }
        sort($locales, SORT_STRING);

        return $locales;
    }

    /** @param array<int, string> $locales */
    public function serialize(array $locales): string
    {
        return implode(',', $this->canonical($locales));
    }

    /** @return array<int, string> */
    public function fromProfile(string $profile): array
    {
        $parsed = app(MediaOutputProfile::class)->parse($profile);
        if (isset($parsed['locales'])) {
            return $this->canonical($parsed['locales']);
        }
        if (isset($parsed['locale'])) {
            return $this->canonical($parsed['locale']);
        }
        throw new InvalidArgumentException('document_language_profile_invalid');
    }

    /** @param array<int, string> $locales */
    public function persistForJob(int $customerId, int $jobId, array $locales): void
    {
        foreach ($this->canonical($locales) as $index => $locale) {
            DB::table('media_processing_job_locales')->insertOrIgnore([
                'customer_id' => $customerId,
                'processing_job_id' => $jobId,
                'ordinal' => $index + 1,
                'locale' => $locale,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
