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
                'xlsx' => $this->xlsxUnits($source, $directory, $this->locale($job)),
                'pdf' => $this->pdfUnits($source, $directory, $this->locale($job)),
                'doc', 'xls', 'ppt', 'pptx' => $this->officeUnits($source, $directory, $this->locale($job)),
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

        $units = $this->unit(implode("\n", $paragraphs), 1, 'embedded_text');

        return $units !== [] ? $units : $this->officeUnits($source, $directory, $locale);
    }

    /**
     * Spreadsheets are read as cells, not as a LibreOffice PDF rendering: a
     * rendering loses the sheet a value came from and the row/column it sat in.
     * One unit per worksheet keeps the frozen `page` locator vocabulary while the
     * sheet name travels in metadata.
     */
    private function xlsxUnits(string $source, string $directory, string $locale): array
    {
        $archive = new ZipArchive;
        if ($archive->open($source) !== true) {
            throw new RuntimeException('corrupt_source');
        }

        try {
            $sharedStrings = $this->extractOoxmlPart($archive, 'xl/sharedStrings.xml', $directory.'/sharedStrings.xml', null)
                ? $this->sharedStrings($directory.'/sharedStrings.xml')
                : [];
            $sheets = $this->workbookSheets($archive, $directory);
            if (count($sheets) > (int) config('media.processing.local_document.max_pages')) {
                throw new RuntimeException('page_limit_exceeded');
            }

            $units = [];
            $characters = 0;
            foreach ($sheets as $index => [$name, $part]) {
                $this->assertDeadline();
                $path = $directory.'/sheet-'.($index + 1).'.xml';
                if (! $this->extractOoxmlPart($archive, $part, $path, null)) {
                    continue;
                }
                $text = $this->sheetText($path, $sharedStrings, $characters);
                $units = array_merge($units, $this->unit($text, $index + 1, 'embedded_text', ['sheet_name' => $name]));
            }
        } finally {
            $archive->close();
        }

        return $units !== [] ? $units : $this->officeUnits($source, $directory, $locale);
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function workbookSheets(ZipArchive $archive, string $directory): array
    {
        $workbookPath = $directory.'/workbook.xml';
        $relationshipPath = $directory.'/workbook.xml.rels';
        $this->extractOoxmlPart($archive, 'xl/workbook.xml', $workbookPath, 'corrupt_source');
        $this->extractOoxmlPart($archive, 'xl/_rels/workbook.xml.rels', $relationshipPath, 'corrupt_source');

        $targets = [];
        $reader = $this->xmlReader($relationshipPath);
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                $targets[(string) $reader->getAttribute('Id')] = ltrim((string) $reader->getAttribute('Target'), '/');
            }
        }
        $reader->close();

        $sheets = [];
        $reader = $this->xmlReader($workbookPath);
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'sheet') {
                continue;
            }
            $relationshipId = $reader->getAttribute('r:id')
                ?? $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $target = $targets[(string) $relationshipId] ?? null;
            if ($target !== null) {
                $sheets[] = [
                    (string) $reader->getAttribute('name'),
                    str_starts_with($target, 'xl/') ? $target : 'xl/'.$target,
                ];
            }
        }
        $reader->close();

        return $sheets;
    }

    /** @return array<int, string> */
    private function sharedStrings(string $path): array
    {
        $reader = $this->xmlReader($path);
        $strings = [];
        $current = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                if ($reader->isEmptyElement) {
                    $strings[] = '';

                    continue;
                }
                $current = '';
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $current;
                $current = null;
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @param  int  $characters  Running total across the workbook, so the character
     *                           cap fails the whole revision instead of truncating one sheet.
     */
    private function sheetText(string $path, array $sharedStrings, int &$characters): string
    {
        $limit = (int) config('media.processing.local_document.max_extracted_characters');
        $reader = $this->xmlReader($path);
        $lines = [];
        $cells = [];
        $column = 0;
        $type = '';

        try {
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'row') {
                    $cells = [];
                } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'c') {
                    $column = $this->columnIndex((string) $reader->getAttribute('r'));
                    $type = (string) $reader->getAttribute('t');
                } elseif ($reader->nodeType === \XMLReader::ELEMENT && in_array($reader->localName, ['v', 't'], true)) {
                    $value = $this->cellValue($reader->readString(), $reader->localName === 't' ? 'inlineStr' : $type, $sharedStrings);
                    if ($value !== '') {
                        $cells[$column] = $value;
                    }
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'row') {
                    $line = $this->rowLine($cells);
                    if ($line === '') {
                        continue;
                    }
                    $characters += mb_strlen($line) + 1;
                    if ($characters > $limit) {
                        throw new RuntimeException('extracted_text_too_large');
                    }
                    $lines[] = $line;
                }
            }
        } finally {
            $reader->close();
        }

        return implode("\n", $lines);
    }

    /** @param array<int, string> $sharedStrings */
    private function cellValue(string $raw, string $type, array $sharedStrings): string
    {
        return match ($type) {
            's' => $sharedStrings[(int) $raw] ?? '',
            'b' => $raw === '1' ? 'TRUE' : 'FALSE',
            default => trim($raw),
        };
    }

    /** @param array<int, string> $cells */
    private function rowLine(array $cells): string
    {
        if ($cells === []) {
            return '';
        }
        ksort($cells, SORT_NUMERIC);
        $line = [];
        for ($column = 0; $column <= array_key_last($cells); $column++) {
            $line[] = $cells[$column] ?? '';
        }

        return trim(implode("\t", $line)) === '' ? '' : implode("\t", $line);
    }

    private function columnIndex(string $reference): int
    {
        $letters = rtrim($reference, '0123456789');
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            if ($letter < 'A' || $letter > 'Z') {
                return 0;
            }
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
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

        // Each page decides its own extraction method. Returning the text-layer pages
        // as soon as any exist would silently drop every scanned page of a mixed
        // document — the reader gets a shorter document and no error.
        $units = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $embedded = trim($pages[$page - 1] ?? '');
            $units = array_merge($units, $embedded !== ''
                ? $this->unit($embedded, $page, 'embedded_text')
                : $this->unit($this->ocrPage($source, $directory, $locale, $page), $page, 'ocr'));
        }

        return $units;
    }

    private function ocrPage(string $source, string $directory, string $locale, int $page): string
    {
        $prefix = $directory.'/page-'.$page;
        $this->runner->run([
            config('media.processing.local_document.pdftoppm_binary'),
            '-f', (string) $page, '-l', (string) $page, '-singlefile',
            '-r', (string) config('media.processing.local_document.ocr_dpi'),
            '-png', $source, $prefix,
        ], $this->commandTimeout());

        return trim($this->runner->run([
            config('media.processing.local_document.tesseract_binary'),
            $prefix.'.png', 'stdout', '-l', $this->tesseractLocale($locale),
        ], $this->commandTimeout()));
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
        $remaining = $this->assertDeadline();

        return min((int) config('media.processing.local_document.'.$configKey), $remaining);
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
    private function unit(string $text, int $page, string $method, array $metadata = []): array
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
