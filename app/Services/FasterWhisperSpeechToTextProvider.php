<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FasterWhisperSpeechToTextProvider implements MediaProcessingProvider
{
    public function __construct(private readonly DocumentProcessRunner $runner) {}

    public function process(object $mediaFile, object $job): array
    {
        if ($job->job_type !== 'speech_to_text' || $mediaFile->file_type !== 'audio') {
            throw new RuntimeException('unsupported_source');
        }

        $mimeTypes = (array) config('media.processing.speech_to_text.mime_types', []);
        if (! in_array((string) $mediaFile->mime_type, $mimeTypes, true)) {
            throw new RuntimeException('unsupported_source');
        }
        if ((int) $mediaFile->file_size_bytes > (int) config('media.processing.speech_to_text.max_bytes')) {
            throw new RuntimeException('audio_limit_exceeded');
        }
        if ($mediaFile->duration_seconds === null || (int) $mediaFile->duration_seconds <= 0) {
            throw new RuntimeException('corrupt_source');
        }
        if ((int) $mediaFile->duration_seconds > (int) config('media.processing.speech_to_text.max_duration_seconds')) {
            throw new RuntimeException('audio_limit_exceeded');
        }

        $locale = $this->profileValue((string) $job->output_profile, 'locale');
        $diarization = $this->profileValue((string) $job->output_profile, 'diarization');
        if (! in_array($locale, (array) config('media.processing.speech_to_text.locales', []), true)) {
            throw new RuntimeException('locale_unavailable');
        }
        if ($diarization !== (string) config('media.processing.speech_to_text.diarization', 'off')) {
            throw new RuntimeException('unsupported_output_profile');
        }

        $python = (string) config('media.processing.speech_to_text.python_binary');
        $script = (string) config('media.processing.speech_to_text.script');
        $model = (string) config('media.processing.speech_to_text.model_path');
        if (! is_executable($python) || ! is_file($script) || ! is_dir($model)) {
            throw new RuntimeException('provider_unavailable');
        }

        $directory = $this->temporaryDirectory();
        try {
            $extension = strtolower((string) ($mediaFile->extension ?? 'audio'));
            $source = $directory.'/source.'.preg_replace('/[^a-z0-9]/', '', $extension);
            $output = $directory.'/result.json';
            $this->copySource($mediaFile, $source);

            $envelope = json_decode($this->runner->run([
                $python, $script,
                '--source', $source,
                '--locale', (string) $locale,
                '--model', $model,
                '--output', $output,
                '--compute-type', (string) config('media.processing.speech_to_text.compute_type', 'int8'),
                '--threads', (string) config('media.processing.speech_to_text.threads', 0),
            ], (int) config('media.processing.speech_to_text.timeout_seconds', 3300)), true);
            if (($envelope['status'] ?? null) !== 'ready') {
                throw new RuntimeException((string) ($envelope['error_code'] ?? 'provider_command_failed'));
            }

            $size = is_file($output) ? filesize($output) : false;
            $maxOutput = (int) config('media.processing.speech_to_text.max_output_bytes', 16777216);
            if ($size === false || $size > $maxOutput) {
                throw new RuntimeException($size === false ? 'provider_command_failed' : 'transcript_invalid');
            }
            $result = json_decode((string) file_get_contents($output), true);
            if (! is_array($result) || ($result['status'] ?? null) !== 'ready') {
                throw new RuntimeException((string) ($result['error_code'] ?? 'provider_command_failed'));
            }

            return $result;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function copySource(object $mediaFile, string $destination): void
    {
        $source = Storage::disk($mediaFile->storage_disk)->readStream($mediaFile->storage_key);
        $target = fopen($destination, 'wb');
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
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/lf-stt-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('provider_unavailable');
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    private function profileValue(string $profile, string $key): ?string
    {
        foreach (array_filter(explode(';', $profile)) as $pair) {
            [$candidate, $value] = explode('=', $pair, 2);
            if ($candidate === $key) {
                return $value;
            }
        }

        return null;
    }
}
