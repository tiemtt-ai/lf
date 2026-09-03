<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use App\Exceptions\DocumentCommandFailure;
use App\Exceptions\DocumentUsageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use ZipArchive;

class LocalDocumentProcessingProvider implements MediaProcessingProvider
{
    private float $deadline;

    private int $processedPages = 0;

    private ?array $usageScope = null;

    private string $usageUnit = 'page';

    public function __construct(private readonly DocumentProcessRunner $runner) {}

    public function process(object $mediaFile, object $job): array
    {
        if (! in_array($job->job_type, ['ocr', 'structured_extraction'], true) || $mediaFile->file_type !== 'document') {
            throw new RuntimeException('unsupported_source');
        }

        // Pending jobs recorded before D2/D4 must not publish new semantics under
        // their old version. Standalone provider fixtures have no persisted identity.
        if ($job->job_type === 'ocr' && isset($job->id)
            && ! str_contains((string) $job->processing_version, '+document-v2')
            && ! str_starts_with((string) $job->processing_version, 'document-v2-')) {
            throw new RuntimeException('unsupported_output_profile');
        }

        $this->processedPages = 0;
        $this->usageUnit = $job->job_type === 'structured_extraction' ? 'sheet' : 'page';
        $this->usageScope = isset($mediaFile->customer_id, $mediaFile->id, $job->id)
            ? ['customer_id' => $mediaFile->customer_id, 'media_file_id' => $mediaFile->id, 'id' => $job->id, 'job_type' => $job->job_type]
            : null;
        $this->deadline = microtime(true) + (int) config('media.processing.'.($job->job_type === 'structured_extraction' ? 'structured_extraction' : 'local_document').'.max_processing_seconds');
        $directory = $this->temporaryDirectory();

        try {
            $this->recordCompletedPages(0);
            $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) $mediaFile->storage_key, PATHINFO_EXTENSION)));
            $source = $directory.'/source'.($extension !== '' ? '.'.$extension : '');
            $this->copySource($mediaFile, $source);

            if (in_array($extension, ['xls', 'xlsx'], true)) {
                if ($extension === 'xls') {
                    $profile = $directory.'/libreoffice-profile';
                    mkdir($profile, 0700, true);
                    $this->runCommand([
                        config('media.processing.local_document.soffice_binary'),
                        '-env:UserInstallation=file://'.$profile,
                        '--headless', '--convert-to', 'xlsx', '--outdir', $directory, $source,
                    ], $this->commandTimeout('office_timeout_seconds'));
                    $source = $directory.'/source.xlsx';
                    if (! is_file($source)) {
                        throw new RuntimeException('corrupt_source');
                    }
                }
                $result = app(DocumentSpreadsheetReader::class)->read($source, function (int $count): void {
                    $this->assertDeadline();
                    $this->recordCompletedPages($count);
                }, $job->job_type === 'structured_extraction');
                if ($job->job_type === 'ocr') {
                    $result = ['units' => app(DocumentTextUnits::class)->validate($result['units'])];
                } elseif (! collect($result['units'])->contains(fn ($unit) => trim($unit['text']) !== '')) {
                    throw new RuntimeException('no_extractable_text');
                }
            } else {
                if ($job->job_type !== 'ocr') {
                    throw new RuntimeException('unsupported_source');
                }
                $units = match ($extension) {
                    'txt' => $this->textUnits($source),
                    'docx' => $this->docxUnits($source, $directory, $this->locale($job)),
                    'pdf' => $this->pdfUnits($source, $directory, $this->locale($job)),
                    'doc', 'ppt', 'pptx' => $this->officeUnits($source, $directory, $this->locale($job)),
                    default => throw new RuntimeException('unsupported_source'),
                };
                $result = ['units' => app(DocumentTextUnits::class)->validate($units)];
            }

            return $result + ['usage' => ['units' => $this->processedPages, 'unit_type' => $this->usageUnit]];
        } catch (Throwable $exception) {
            throw new DocumentUsageException($exception, $this->processedPages, $this->usageUnit);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** Absolute monotonic progress: rereading a page during fallback is not another unit. */
    private function recordCompletedPages(int $pages): void
    {
        $this->processedPages = max($this->processedPages, $pages);
        if ($this->usageScope === null) {
            return; // Standalone provider fixture without a persisted job.
        }
        $processing = DB::table('media_processing_jobs')->where($this->usageScope)->where('status', 'processing');
        $updated = (clone $processing)->where(fn ($q) => $q->whereNull('billable_units')
            ->orWhere('billable_units', '<', $this->processedPages))
            ->update(['billable_units' => $this->processedPages, 'billable_unit_type' => $this->usageUnit]);
        if ($updated === 0 && ! $processing->exists()) {
            throw new RuntimeException('provider_timeout');
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

        $this->recordCompletedPages(1);

        return $this->unit(trim($text), 1, 'embedded_text');
    }

    private function docxUnits(string $source, string $directory, string $locale): array
    {
        $archive = new ZipArchive;
        if ($archive->open($source) !== true) {
            throw new RuntimeException('corrupt_source');
        }

        $xmlPath = $directory.'/document.xml';
        try {
            $this->extractOoxmlPart($archive, 'word/document.xml', $xmlPath, 'source_expansion_limit_exceeded');
        } finally {
            $archive->close();
        }

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

        $this->recordCompletedPages(1);
        $units = $this->unit(implode("\n", $paragraphs), 1, 'embedded_text');

        return $units !== [] ? $units : $this->officeUnits($source, $directory, $locale);
    }

    /**
     * @param  string|null  $missingErrorCode  Error code when the part is absent;
     *                                         null marks the part optional.
     */
    private function extractOoxmlPart(ZipArchive $archive, string $name, string $destination, ?string $missingErrorCode): bool
    {
        $limit = (int) config('media.processing.local_document.max_docx_xml_bytes');
        $metadata = $archive->statName($name);
        if (! is_array($metadata) || ! isset($metadata['size'])) {
            if ($missingErrorCode === null) {
                return false;
            }
            throw new RuntimeException($missingErrorCode);
        }
        if ((int) $metadata['size'] > $limit) {
            throw new RuntimeException('source_expansion_limit_exceeded');
        }

        $input = $archive->getStream($name);
        $output = fopen($destination, 'wb');
        if (! is_resource($input) || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('corrupt_source');
        }

        $expandedBytes = 0;
        while (! feof($input)) {
            $chunk = fread($input, 65536);
            if ($chunk === false) {
                fclose($input);
                fclose($output);
                throw new RuntimeException('corrupt_source');
            }
            $expandedBytes += strlen($chunk);
            if ($expandedBytes > $limit || fwrite($output, $chunk) !== strlen($chunk)) {
                fclose($input);
                fclose($output);
                throw new RuntimeException('source_expansion_limit_exceeded');
            }
        }
        fclose($input);
        fclose($output);

        return true;
    }

    private function xmlReader(string $path): \XMLReader
    {
        $reader = new \XMLReader;
        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('corrupt_source');
        }

        return $reader;
    }

    private function officeUnits(string $source, string $directory, string $locale): array
    {
        $profile = $directory.'/libreoffice-profile';
        mkdir($profile, 0700, true);
        $this->runCommand([
            config('media.processing.local_document.soffice_binary'),
            '-env:UserInstallation=file://'.$profile,
            '--headless', '--convert-to', 'pdf', '--outdir', $directory, $source,
        ], $this->commandTimeout('office_timeout_seconds'));

        $pdfs = glob($directory.'/*.pdf') ?: [];
        if ($pdfs === []) {
            throw new RuntimeException('corrupt_source');
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
        $this->runCommand([
            config('media.processing.local_document.pdftotext_binary'), '-layout', $source, $textPath,
        ], $this->commandTimeout());

        $text = is_file($textPath) ? $this->readExtractedFile($textPath) : '';
        $pages = preg_split('/\f/u', $text) ?: [];

        // Each page decides its own extraction method. Returning the text-layer pages
        // as soon as any exist would silently drop every scanned page of a mixed
        // document — the reader gets a shorter document and no error.
        $units = [];
        $characters = 0;
        for ($page = 1; $page <= $pageCount; $page++) {
            $embedded = trim($pages[$page - 1] ?? '');
            $text = $embedded !== '' ? $embedded : $this->ocrPage($source, $directory, $locale, $page);
            $this->recordCompletedPages($page);
            $pageUnits = $this->unit($text, $page, $embedded !== '' ? 'embedded_text' : 'ocr', [], true);
            foreach ($pageUnits as $unit) {
                $characters += mb_strlen($unit['text'], 'UTF-8');
            }
            if ($characters > (int) config('media.processing.local_document.max_extracted_characters')) {
                throw new RuntimeException('extracted_text_too_large');
            }
            $units = array_merge($units, $pageUnits);
        }

        return $units;
    }

    private function ocrPage(string $source, string $directory, string $locale, int $page): string
    {
        $prefix = $directory.'/page-'.$page;
        $this->runCommand([
            config('media.processing.local_document.pdftoppm_binary'),
            '-f', (string) $page, '-l', (string) $page, '-singlefile',
            '-r', (string) config('media.processing.local_document.ocr_dpi'),
            '-png', $source, $prefix,
        ], $this->commandTimeout());

        return trim($this->runCommand([
            config('media.processing.local_document.tesseract_binary'),
            $prefix.'.png', 'stdout', '-l', $this->tesseractLocale($locale),
        ], $this->commandTimeout()));
    }

    private function pdfPageCount(string $source): int
    {
        $info = $this->runCommand([
            config('media.processing.local_document.pdfinfo_binary'), $source,
        ], $this->commandTimeout());
        if (! preg_match('/^Pages:\s+(\d+)$/mi', $info, $matches) || (int) $matches[1] < 1) {
            throw new RuntimeException('corrupt_source');
        }

        return (int) $matches[1];
    }

    /** Keep subprocess diagnostics out of stored error metadata. */
    private function runCommand(array $command, int $timeout): string
    {
        try {
            return $this->runner->run($command, $timeout);
        } catch (RuntimeException $exception) {
            if (! str_starts_with($exception->getMessage(), 'provider_command_failed')) {
                throw $exception;
            }
            $binary = (string) $command[0];
            if (! is_executable($binary) && (new ExecutableFinder)->find($binary) === null) {
                throw new RuntimeException('provider_unavailable', 0, $exception);
            }
            $failure = 'processing_failed';
            if ($exception instanceof DocumentCommandFailure) {
                $poppler = in_array(basename($binary), ['pdfinfo', 'pdftotext', 'pdftoppm'], true);
                // Poppler's documented codes distinguish input/permissions (1/3)
                // from output-file failures (2). Other failures stay unclassified.
                if ($exception->signal !== null || in_array($exception->exitCode, [126, 127, 137], true)
                    || ($poppler && $exception->exitCode === 2)) {
                    $failure = 'provider_unavailable';
                } elseif ($poppler && in_array($exception->exitCode, [1, 3], true)) {
                    $failure = 'corrupt_source';
                }
            }
            throw new RuntimeException($failure, 0, $exception);
        }
    }

    private function commandTimeout(string $configKey = 'command_timeout_seconds'): int
    {
        $remaining = $this->assertDeadline();

        $timeout = (int) config('media.processing.local_document.'.$configKey);
        if ($this->usageUnit === 'sheet') {
            $timeout = min($timeout, (int) config('media.processing.structured_extraction.command_timeout_seconds', 900));
        }

        return min($timeout, $remaining);
    }

    private function assertDeadline(): int
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('provider_timeout');
        }

        return $remaining;
    }

    /** @param array<string, string> $metadata */
    private function unit(string $text, int $page, string $method, array $metadata = [], bool $keepBlank = false): array
    {
        if ($text === '' && ! $keepBlank) {
            return [];
        }

        $limit = (int) config('media.processing.local_document.max_extracted_characters');
        if (mb_strlen($text) > $limit) {
            throw new RuntimeException('extracted_text_too_large');
        }

        return [[
            'locator_type' => 'page', 'locator_value' => (string) $page,
            'sequence' => $page, 'text' => $text, 'extraction_method' => $method,
            'metadata' => $metadata === [] ? null : $metadata,
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
            if ($key === 'locales' && is_string($value) && $value !== '') {
                return $value;
            }
            if ($key === 'locale' && is_string($value) && $value !== '') {
                return $value;
            }
        }
        throw new RuntimeException('missing_canonical_locale');
    }

    private function tesseractLocale(string $locale): string
    {
        $mapped = [];
        foreach (explode(',', $locale) as $item) {
            $mapped[] = match (strtolower(explode('-', $item)[0])) {
                'vi' => 'vie', 'ko' => 'kor', 'en' => 'eng',
                default => throw new RuntimeException('document_language_profile_unsupported'),
            };
        }

        return implode('+', array_values(array_unique($mapped)));
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
