<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\DB;

class FakeMediaProcessingProvider implements MediaProcessingProvider
{
    public function process(object $mediaFile, object $job): array
    {
        return match ($job->job_type) {
            'virus_scan' => ['clean' => ! config('media.processing.fake.virus_infected', false)],
            'ocr' => ['units' => [['locator_type' => 'page', 'locator_value' => '1', 'sequence' => 1, 'text' => 'Fake extracted text', 'extraction_method' => 'ocr']]],
            'speech_to_text' => ['units' => [['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'Fake transcript']]],
            // Caption do job sinh ra PHAI khai transcript revision da dung:
            // `chk_mc_provenance` cuong che dieu do o database that, va
            // media_captions.md dat bat bien ton-tai-revision o tang persist.
            // Fake tra NULL se sinh mot row khong the ton tai tren MariaDB.
            'caption' => [
                'storage_key' => $mediaFile->storage_key.'.captions/'.$job->output_profile_hash.'.vtt',
                'transcript_processing_version' => $this->readyTranscriptRevision($mediaFile, $job),
            ],
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

    private function readyTranscriptRevision(object $mediaFile, object $job): ?string
    {
        $locale = null;
        foreach (array_filter(explode(';', (string) $job->output_profile)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key === 'locale') {
                $locale = $value;
            }
        }

        return $locale === null ? null : DB::table('media_transcripts')
            ->where('customer_id', $mediaFile->customer_id)
            ->where('media_file_id', $mediaFile->id)
            ->where('locale', $locale)
            ->where('source_fingerprint', $job->source_fingerprint)
            ->where('status', 'ready')
            ->value('processing_version');
    }
}
