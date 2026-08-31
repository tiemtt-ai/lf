<?php

namespace App\Services;

use RuntimeException;

/** Validate the complete OCR revision before any output is persisted. */
class DocumentTextUnits
{
    /** @return array<int, array<string, mixed>> */
    public function validate(mixed $units): array
    {
        if (! is_array($units) || $units === []) {
            throw new RuntimeException('no_extractable_text');
        }
        if (count($units) > (int) config('media.processing.local_document.max_pages')) {
            throw new RuntimeException('page_limit_exceeded');
        }

        $characters = 0;
        $hasText = false;
        $previousSequence = 0;
        $locators = [];
        foreach ($units as $unit) {
            if (! is_array($unit)
                || ! in_array($unit['locator_type'] ?? null, ['page', 'sheet'], true)
                || ! is_string($unit['locator_value'] ?? null)
                || preg_match('/^[1-9][0-9]*$/D', $unit['locator_value']) !== 1
                || ! is_int($unit['sequence'] ?? null) || $unit['sequence'] <= $previousSequence
                || ! is_string($unit['text'] ?? null)
                || ! mb_check_encoding($unit['text'], 'UTF-8')
                || ! in_array($unit['extraction_method'] ?? 'ocr', ['ocr', 'embedded_text', 'spreadsheet_cells'], true)) {
                throw new RuntimeException('corrupt_source');
            }
            $locator = $unit['locator_type'].':'.$unit['locator_value'];
            if (isset($locators[$locator])) {
                throw new RuntimeException('corrupt_source');
            }
            $locators[$locator] = true;
            $previousSequence = $unit['sequence'];
            $hasText = $hasText || trim($unit['text']) !== '';
            $characters += mb_strlen($unit['text'], 'UTF-8');
            if ($characters > (int) config('media.processing.local_document.max_extracted_characters')) {
                throw new RuntimeException('extracted_text_too_large');
            }
        }
        if (! $hasText) {
            throw new RuntimeException('no_extractable_text');
        }

        return array_values($units);
    }
}
