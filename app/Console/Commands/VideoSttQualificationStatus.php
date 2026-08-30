<?php

namespace App\Console\Commands;

use App\Services\VideoSttQualification;
use Illuminate\Console\Command;

class VideoSttQualificationStatus extends Command
{
    protected $signature = 'media:video-stt-qualification {--json : Emit machine-readable status and current configuration snapshot}';

    protected $description = 'Check whether this deployment may enqueue Video STT under its current processing identity.';

    public function handle(VideoSttQualification $qualification): int
    {
        $status = $qualification->status();
        $payload = [
            ...$status,
            'processing_version' => $qualification->processingVersion(),
            'configuration_hash' => $qualification->configurationHash(),
            'configuration' => $qualification->configurationSnapshot(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->line('Video STT qualification: '.$status['code']);
            $this->line('Processing version: '.$payload['processing_version']);
            $this->line('Configuration hash: '.$payload['configuration_hash']);
        }

        return $status['qualified'] ? self::SUCCESS : self::FAILURE;
    }
}
