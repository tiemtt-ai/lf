<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class MediaMetadataProbe
{
    public function durationSeconds(
        UploadedFile $file,
        string $fileType
    ): ?int {
        if (! in_array($fileType, ['video', 'audio'], true)) {
            return null;
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $process = new Process([
                (string) config('media.ffprobe_binary', 'ffprobe'),
                '-v',
                'error',
                '-show_entries',
                'format=duration',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $path,
            ]);
            $process->setTimeout(
                (float) config('media.ffprobe_timeout_seconds', 15)
            );
            $process->run();

            if (! $process->isSuccessful()) {
                $this->logFailure($fileType, $process->getExitCode());

                return null;
            }

            $duration = filter_var(
                trim($process->getOutput()),
                FILTER_VALIDATE_FLOAT
            );

            if ($duration === false || ! is_finite($duration) || $duration <= 0) {
                $this->logFailure($fileType, $process->getExitCode());

                return null;
            }

            return (int) ceil($duration);
        } catch (Throwable $exception) {
            Log::warning('Media duration probe failed.', [
                'file_type' => $fileType,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function logFailure(string $fileType, ?int $exitCode): void
    {
        Log::warning('Media duration probe returned no usable duration.', [
            'file_type' => $fileType,
            'exit_code' => $exitCode,
        ]);
    }
}
