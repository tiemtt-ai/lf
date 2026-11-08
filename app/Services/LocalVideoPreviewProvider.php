<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use App\Exceptions\DocumentCommandFailure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * `local_video`: the faststart copy of an MP4/MOV video
 * (LF-Media-Processing-Contract § Video board playback copy). ffmpeg copies
 * every stream as it is and writes the `moov` index first; nothing is
 * re-encoded. The copy is checked, stored and verified here, so a returned key
 * always names a complete object.
 */
class LocalVideoPreviewProvider implements MediaProcessingProvider
{
    /** Duration may differ by this much from the source (seconds). */
    private const DURATION_TOLERANCE = 0.1;

    /** Size may differ by this fraction from the source. */
    private const SIZE_TOLERANCE = 0.01;

    private float $deadline;

    public function __construct(
        private readonly DocumentProcessRunner $runner,
        private readonly DocumentPreviewStorage $storage,
    ) {}

    /** @return array{storage_key: string, file_size_bytes: int, mime_type: string, duration_ms: int, usage: array{units: int, unit_type: string}} */
    public function process(object $mediaFile, object $job): array
    {
        if ($job->job_type !== 'transcode' || ! VideoBoardPreview::eligible($mediaFile)
            || (string) $job->output_profile !== VideoBoardPreview::PROFILE) {
            throw new RuntimeException('unsupported_source');
        }
        $ffmpeg = (string) config('media.processing.video_preview.ffmpeg_binary');
        $ffprobe = (string) config('media.ffprobe_binary', 'ffprobe');
        if (! $this->executable($ffmpeg) || ! $this->executable($ffprobe)) {
            throw new RuntimeException('provider_unavailable');
        }
        $this->deadline = microtime(true) + (int) config('media.processing.video_preview.max_processing_seconds', 3300);
        $directory = $this->temporaryDirectory();

        try {
            $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) $mediaFile->storage_key, PATHINFO_EXTENSION)));
            $source = $this->sourcePath($mediaFile, $directory.'/source.'.$extension);
            $sourceSize = filesize($source);
            if ($sourceSize === false || $sourceSize < 1) {
                throw new RuntimeException('source_unavailable');
            }
            $free = disk_free_space($directory);
            if ($free !== false && $free < $sourceSize * 1.1) {
                throw new RuntimeException('provider_unavailable');
            }

            $output = $directory.'/faststart.'.$extension;
            $this->run([
                $ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
                '-i', $source, '-map', '0', '-c', 'copy', '-movflags', '+faststart', $output,
            ], (int) config('media.processing.video_preview.timeout_seconds', 900));

            $size = is_file($output) ? filesize($output) : false;
            if ($size === false || $size < 1 || abs($size - $sourceSize) > $sourceSize * self::SIZE_TOLERANCE) {
                throw new RuntimeException('processing_failed');
            }
            $stream = fopen($output, 'rb');
            $indexAtEnd = $stream === false ? null : VideoBoardPreview::indexAtEnd($stream);
            if ($stream !== false) {
                fclose($stream);
            }
            if ($indexAtEnd !== false) {
                throw new RuntimeException('processing_failed');
            }
            $sourceDuration = $this->duration($ffprobe, $source);
            $copyDuration = $this->duration($ffprobe, $output);
            if (abs($sourceDuration - $copyDuration) > self::DURATION_TOLERANCE) {
                throw new RuntimeException('processing_failed');
            }
            $this->remaining();

            $key = $this->storage->key($mediaFile, $job);

            return [
                'storage_key' => $key,
                'file_size_bytes' => $this->storage->write($mediaFile, $key, $output),
                'mime_type' => (string) $mediaFile->mime_type,
                'duration_ms' => (int) round($copyDuration * 1000),
                'usage' => ['units' => (int) $sourceSize, 'unit_type' => 'byte'],
            ];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** A local file is read where it is; anything else is copied into the workspace. */
    private function sourcePath(object $mediaFile, string $copy): string
    {
        $disk = (string) $mediaFile->storage_disk;
        if (config('filesystems.disks.'.$disk.'.driver') === 'local') {
            $path = Storage::disk($disk)->path((string) $mediaFile->storage_key);
            if (! is_file($path)) {
                throw new RuntimeException('source_unavailable');
            }

            return $path;
        }
        $source = Storage::disk($disk)->readStream((string) $mediaFile->storage_key);
        $target = fopen($copy, 'wb');
        if (! is_resource($source) || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            throw new RuntimeException('source_unavailable');
        }
        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('source_unavailable');
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return $copy;
    }

    private function duration(string $ffprobe, string $path): float
    {
        $output = trim($this->run([
            $ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ], (int) config('media.ffprobe_timeout_seconds', 15)));
        if (! is_numeric($output) || (float) $output <= 0) {
            throw new RuntimeException('corrupt_source');
        }

        return (float) $output;
    }

    /** Subprocess diagnostics stay out of stored error metadata. */
    private function run(array $command, int $timeout): string
    {
        try {
            return $this->runner->run($command, min($timeout, $this->remaining()));
        } catch (RuntimeException $exception) {
            if (! str_starts_with($exception->getMessage(), 'provider_command_failed')) {
                throw $exception;
            }
            $failure = 'corrupt_source';
            if ($exception instanceof DocumentCommandFailure
                && ($exception->signal !== null || in_array($exception->exitCode, [126, 127, 137], true))) {
                $failure = 'provider_unavailable';
            }
            throw new RuntimeException($failure, 0, $exception);
        }
    }

    private function remaining(): int
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('provider_timeout');
        }

        return $remaining;
    }

    private function executable(string $binary): bool
    {
        return $binary !== '' && (is_executable($binary) || (new ExecutableFinder)->find($binary) !== null);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/lf-media-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('provider_unavailable');
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}
