<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DoclingStructuredExtractionProvider implements MediaProcessingProvider
{
    public function __construct(private readonly DocumentProcessRunner $runner) {}

    public function process(object $mediaFile, object $job): array
    {
        if ($job->job_type !== 'structured_extraction' || $mediaFile->file_type !== 'document') {
            throw new RuntimeException('unsupported_source');
        }
        $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) $mediaFile->storage_key, PATHINFO_EXTENSION)));
        if ($extension !== 'pdf') {
            throw new RuntimeException('unsupported_source');
        }

        $directory = $this->temporaryDirectory();
        try {
            $source = $directory.'/source.pdf';
            $this->copySource($mediaFile, $source);
            $maxPages = (int) config('media.processing.structured_extraction.max_pages', 100);
            if ($this->pageCount($source) > $maxPages) {
                throw new RuntimeException('page_limit_exceeded');
            }

            $python = (string) config('media.processing.docling.python_binary');
            $script = (string) config('media.processing.docling.script');
            $artifacts = (string) config('media.processing.docling.artifacts_path');
            $resultPath = $directory.'/result.json';
            if (! is_executable($python) || ! is_file($script) || ! is_dir($artifacts)) {
                throw new RuntimeException('provider_unavailable');
            }

            $output = $this->runner->run([
                $python, $script, '--source', $source,
                '--locale', (string) $this->profileValue((string) $job->output_profile, 'locale'),
                '--artifacts', $artifacts, '--max-pages', (string) $maxPages,
                '--output', $resultPath,
            ], (int) config('media.processing.docling.timeout_seconds', 3300));
            $envelope = json_decode($output, true);
            if (($envelope['status'] ?? null) === 'failed') {
                throw new RuntimeException((string) ($envelope['error_code'] ?? 'provider_command_failed'));
            }
            $maxOutputBytes = (int) config('media.processing.docling.max_output_bytes', 67108864);
            $resultSize = is_file($resultPath) ? filesize($resultPath) : false;
            if ($resultSize === false || $resultSize > $maxOutputBytes) {
                throw new RuntimeException($resultSize === false ? 'provider_command_failed' : 'structured_extraction_too_large');
            }
            $result = json_decode((string) file_get_contents($resultPath), true);
            if (! is_array($result)) {
                throw new RuntimeException('provider_command_failed');
            }
            if (($result['status'] ?? null) !== 'ready') {
                throw new RuntimeException((string) ($result['error_code'] ?? 'provider_command_failed'));
            }

            return $result;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function pageCount(string $source): int
    {
        $output = $this->runner->run([(string) config('media.processing.local_document.pdfinfo_binary', 'pdfinfo'), $source], 30);
        if (! preg_match('/^Pages:\s+(\d+)$/mi', $output, $matches)) {
            throw new RuntimeException('corrupt_source');
        }

        return (int) $matches[1];
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
        $directory = sys_get_temp_dir().'/lf-docling-'.bin2hex(random_bytes(12));
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
