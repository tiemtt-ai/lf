<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class LocalDocumentProcessingProvider implements MediaProcessingProvider
{
    private float $deadline;

    public function __construct(private readonly DocumentProcessRunner $runner) {}

    public function process(object $mediaFile, object $job): array
    {
        if ($job->job_type !== 'ocr' || $mediaFile->file_type !== 'document') {
            throw new RuntimeException('unsupported_source');
        }

        $this->deadline = microtime(true) + (int) config('media.processing.local_document.max_processing_seconds');
        $directory = $this->temporaryDirectory();

        try {
            $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) $mediaFile->storage_key, PATHINFO_EXTENSION)));
            $source = $directory.'/source'.($extension !== '' ? '.'.$extension : '');
            $this->copySource($mediaFile, $source);

            $units = match ($extension) {
                'txt' => $this->textUnits($source),
                'docx' => $this->docxUnits($source, $directory, $this->locale($job)),
                'pdf' => $this->pdfUnits($source, $directory, $this->locale($job)),
                'doc', 'xls', 'xlsx', 'ppt', 'pptx' => $this->officeUnits($source, $directory, $this->locale($job)),
                default => throw new RuntimeException('unsupported_source'),
            };

            if ($units === []) {
                throw new RuntimeException('no_extractable_text');
            }

            return ['units' => $units];
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

    private function textUnits(string $source): array
    {
        $text = $this->readExtractedFile($source);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('corrupt_source');
        }

        return $this->unit(trim($text), 1, 'embedded_text');
    }

    private function docxUnits(string $source, string $directory, string $locale): array
    {
        $archive = new ZipArchive;
        if ($archive->open($source) !== true) {
            throw new RuntimeException('corrupt_source');
        }

        $xmlPath = $directory.'/document.xml';
        $metadata = $archive->statName('word/document.xml');
        $expandedLimit = (int) config('media.processing.local_document.max_docx_xml_bytes');
        if (! is_array($metadata) || ! isset($metadata['size']) || (int) $metadata['size'] > $expandedLimit) {
            $archive->close();
            throw new RuntimeException('source_expansion_limit_exceeded');
        }
        $input = $archive->getStream('word/document.xml');
        $output = fopen($xmlPath, 'wb');
        if (! is_resource($input) || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            $archive->close();
            throw new RuntimeException('corrupt_source');
        }
        $expandedBytes = 0;
        while (! feof($input)) {
            $chunk = fread($input, 65536);
            if ($chunk === false) {
                fclose($input);
                fclose($output);
                $archive->close();
                throw new RuntimeException('corrupt_source');
            }
            $expandedBytes += strlen($chunk);
            if ($expandedBytes > $expandedLimit || fwrite($output, $chunk) !== strlen($chunk)) {
                fclose($input);
                fclose($output);
                $archive->close();
                throw new RuntimeException('source_expansion_limit_exceeded');
            }
        }
        fclose($input);
        fclose($output);
        $archive->close();

        $reader = new \XMLReader;
        if (! $reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('corrupt_source');
        }

        $paragraph = '';
        $paragraphs = [];
        $characterCount = 0;
        $limit = (int) config('media.processing.local_document.max_extracted_characters');
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't') {
                $value = $reader->readString();
                $characterCount += mb_strlen($value);
                if ($characterCount > $limit) {
                    $reader->close();
                    throw new RuntimeException('extracted_text_too_large');
                }
                $paragraph .= $value;
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'p') {
                if (trim($paragraph) !== '') {
                    $paragraphs[] = trim($paragraph);
                }
                $paragraph = '';
            }
        }
        $reader->close();

        $units = $this->unit(implode("\n", $paragraphs), 1, 'embedded_text');

        return $units !== [] ? $units : $this->officeUnits($source, $directory, $locale);
    }

    private function officeUnits(string $source, string $directory, string $locale): array
    {
        $profile = $directory.'/libreoffice-profile';
        mkdir($profile, 0700, true);
        $this->runner->run([
            config('media.processing.local_document.soffice_binary'),
            '-env:UserInstallation=file://'.$profile,
            '--headless', '--convert-to', 'pdf', '--outdir', $directory, $source,
        ], $this->commandTimeout('office_timeout_seconds'));

        $pdfs = glob($directory.'/*.pdf') ?: [];
        if ($pdfs === []) {
            throw new RuntimeException('provider_command_failed');
        }

        return $this->pdfUnits($pdfs[0], $directory, $locale);
    }

    private function pdfUnits(string $source, string $directory, string $locale): array
    {
        $pageCount = $this->pdfPageCount($source);
        if ($pageCount > (int) config('media.processing.local_document.max_pages')) {
            throw new RuntimeException('page_limit_exceeded');
        }

        $textPath = $directory.'/document.txt';
        $this->runner->run([
            config('media.processing.local_document.pdftotext_binary'), '-layout', $source, $textPath,
        ], $this->commandTimeout());

        $text = is_file($textPath) ? $this->readExtractedFile($textPath) : '';
        $pages = preg_split('/\f/u', $text) ?: [];
        $units = [];
        foreach ($pages as $index => $page) {
            $units = array_merge($units, $this->unit(trim($page), $index + 1, 'embedded_text'));
        }
        if ($units !== []) {
            return $units;
        }

        for ($page = 1; $page <= $pageCount; $page++) {
            $prefix = $directory.'/page-'.$page;
            $this->runner->run([
                config('media.processing.local_document.pdftoppm_binary'),
                '-f', (string) $page, '-l', (string) $page, '-singlefile',
                '-r', (string) config('media.processing.local_document.ocr_dpi'),
                '-png', $source, $prefix,
            ], $this->commandTimeout());
            $text = $this->runner->run([
                config('media.processing.local_document.tesseract_binary'),
                $prefix.'.png', 'stdout', '-l', $this->tesseractLocale($locale),
            ], $this->commandTimeout());
            $units = array_merge($units, $this->unit(trim($text), $page, 'ocr'));
        }

        return $units;
    }

    private function pdfPageCount(string $source): int
    {
        $info = $this->runner->run([
            config('media.processing.local_document.pdfinfo_binary'), $source,
        ], $this->commandTimeout());
        if (! preg_match('/^Pages:\s+(\d+)$/mi', $info, $matches) || (int) $matches[1] < 1) {
            throw new RuntimeException('corrupt_source');
        }

        return (int) $matches[1];
    }

    private function commandTimeout(string $configKey = 'command_timeout_seconds'): int
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('provider_timeout');
        }

        return min((int) config('media.processing.local_document.'.$configKey), $remaining);
    }

    private function unit(string $text, int $page, string $method): array
    {
        if ($text === '') {
            return [];
        }

        $limit = (int) config('media.processing.local_document.max_extracted_characters');
        if (mb_strlen($text) > $limit) {
            throw new RuntimeException('extracted_text_too_large');
        }

        return [[
            'locator_type' => 'page', 'locator_value' => (string) $page,
            'sequence' => $page, 'text' => $text, 'extraction_method' => $method,
        ]];
    }

    private function readExtractedFile(string $path): string
    {
        $limit = (int) config('media.processing.local_document.max_extracted_characters');
        $size = filesize($path);
        if ($size === false || $size > ($limit * 4)) {
            throw new RuntimeException('extracted_text_too_large');
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException('corrupt_source');
        }
        if (mb_strlen($text) > $limit) {
            throw new RuntimeException('extracted_text_too_large');
        }

        return $text;
    }

    private function locale(object $job): string
    {
        foreach (explode(';', (string) $job->output_profile) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($key === 'locale' && is_string($value) && $value !== '') {
                return $value;
            }
        }
        throw new RuntimeException('missing_canonical_locale');
    }

    private function tesseractLocale(string $locale): string
    {
        return match (strtolower(explode('-', $locale)[0])) {
            'vi' => 'vie+eng', 'ko' => 'kor+eng', 'en' => 'eng',
            default => throw new RuntimeException('unsupported_source'),
        };
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
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
