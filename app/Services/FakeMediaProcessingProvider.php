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
            default => throw new \RuntimeException('Unsupported fake capability.'),
        };
    }
}
