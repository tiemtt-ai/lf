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
                    'quality_status' => 'undetermined',
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
                        'role' => 'paragraph', 'text' => 'Fake paragraph', 'extraction_method' => 'embedded_text',
                        'detected_locale' => 'vi', 'script' => 'Latn',
                        'languages' => [['script' => 'Latn', 'locale' => 'vi', 'char_count' => 13]]],
                    ['locator_value' => '1#3', 'page' => 1, 'ordinal' => 3, 'reading_order' => 3,
                        'role' => 'formula', 'text' => 'x^2 + H2O = 0', 'extraction_method' => 'embedded_text',
                        'bbox' => ['x' => .1, 'y' => .2, 'width' => .4, 'height' => .1],
                        'crop' => ['storage_key' => 'fake/formula-'.$job->id.'.png', 'mime_type' => 'image/png', 'width' => 400, 'height' => 100, 'bytes' => 512],
                        'formula' => ['raw_text' => 'x^2 + H2O = 0', 'normalized_format' => 'latex',
                            'normalized_value' => 'x^2 + H_2O', 'normalization_status' => 'ready',
                            'confidence_score' => 20]],
                    // ADR-0019 v1.8: label `formula` khong kem toan tu quan sat
                    // duoc thi khong sinh evidence child, va cung khong duoc lam
                    // hong ca revision.
                    ['locator_value' => '1#4', 'page' => 1, 'ordinal' => 4, 'reading_order' => 4,
                        'role' => 'formula', 'text' => '가고 있다 가다', 'extraction_method' => 'embedded_text',
                        'bbox' => ['x' => .1, 'y' => .4, 'width' => .4, 'height' => .1],
                        'formula' => ['raw_text' => '가고 있다 가다', 'normalization_status' => 'unavailable']],
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
