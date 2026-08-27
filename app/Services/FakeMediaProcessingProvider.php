<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;

class FakeMediaProcessingProvider implements MediaProcessingProvider
{
    public function process(object $mediaFile, object $job): array
    {
        return match ($job->job_type) {
            'virus_scan' => ['clean' => ! config('media.processing.fake.virus_infected', false)],
            'ocr' => ['units' => [['locator_type' => 'page', 'locator_value' => '1', 'sequence' => 1, 'text' => 'Fake extracted text', 'extraction_method' => 'ocr']]],
            'speech_to_text' => ['units' => [['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'Fake transcript']]],
            'caption' => ['storage_key' => $mediaFile->storage_key.'.captions/'.$job->output_profile_hash.'.vtt'],
            'thumbnail', 'transcode', 'compress' => ['storage_key' => $mediaFile->storage_key.'.variants/'.$job->output_profile_hash],
            'structured_extraction' => str_contains($job->output_profile, 'structure=cells')
                ? ['tables' => [[
                    'locator_type' => 'sheet', 'locator_value' => '1', 'sequence' => 1,
                    'row_count' => 2, 'column_count' => 2, 'has_header' => true,
                    'extraction_method' => 'spreadsheet_cells',
                    'cells' => [
                        ['row' => 1, 'column' => 1, 'is_header' => true, 'text' => 'Header'],
                        ['row' => 2, 'column' => 1, 'text' => 'Value'],
                    ],
                ]]]
                : ['regions' => [
                    ['locator_value' => '1#1', 'page' => 1, 'ordinal' => 1, 'reading_order' => 1,
                        'role' => 'heading', 'text' => 'Fake heading', 'extraction_method' => 'embedded_text'],
                    ['locator_value' => '1#2', 'page' => 1, 'ordinal' => 2, 'reading_order' => 2,
                        'role' => 'paragraph', 'text' => 'Fake paragraph', 'extraction_method' => 'embedded_text'],
                ]],
            default => throw new \RuntimeException('Unsupported fake capability.'),
        };
    }
}
