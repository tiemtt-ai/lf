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

            $result['regions'] = $this->fillFigureText($source, $result['regions'] ?? []);

            return $result;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * Lay chu nam trong bbox cua vung `figure` tu chinh text layer cua PDF.
     *
     * ADR-0019 § D7 diem 3 buoc Media ghi "chu va so nam trong vung — nhan truc,
     * chu thich, text trong khoi". Docling chay layout-only (`do_ocr=false`) va
     * khong tra text cho `picture`, nen truoc day 100% figure region co
     * `text = NULL`. Do tren ALLIVA: text theo trang 47.852 ky tu, text trong
     * region chi 13.373 — 72% khong truy duoc o tang vung.
     *
     * Cat theo bbox la phep hinh hoc, khong phai dien giai: no khang dinh "nhung
     * ky tu nay nam trong hinh chu nhat nay", khong khang dinh bieu do noi gi.
     * Ranh gioi voi ADR-0020 khong doi.
     *
     * Trang scan khong co text layer thi `pdftotext` tra rong va `text` giu NULL —
     * dung, vi nhanh do can OCR tren crop, thuoc hang muc A chua mo.
     *
     * @param  array<int, array<string, mixed>>  $regions
     * @return array<int, array<string, mixed>>
     */
    private function fillFigureText(string $source, array $regions): array
    {
        $figures = array_filter($regions, static fn (array $region): bool => ($region['role'] ?? null) === 'figure'
            && is_array($region['bbox'] ?? null));
        if ($figures === []) {
            return $regions;
        }

        $pageSizes = $this->pageSizes($source);
        $binary = (string) config('media.processing.local_document.pdftotext_binary', 'pdftotext');

        foreach ($figures as $index => $region) {
            $page = (int) ($region['page'] ?? 0);
            $size = $pageSizes[$page] ?? null;
            if ($size === null) {
                continue;
            }
            $bbox = $region['bbox'];
            $x = (int) floor((float) $bbox['x'] * $size['width']);
            $y = (int) floor((float) $bbox['y'] * $size['height']);
            $width = (int) ceil((float) $bbox['width'] * $size['width']);
            $height = (int) ceil((float) $bbox['height'] * $size['height']);
            if ($width <= 0 || $height <= 0) {
                continue;
            }

            try {
                $text = trim($this->runner->run([
                    $binary, '-f', (string) $page, '-l', (string) $page,
                    '-x', (string) $x, '-y', (string) $y, '-W', (string) $width, '-H', (string) $height,
                    '-layout', $source, '-',
                ], 30));
            } catch (RuntimeException) {
                continue;
            }

            if ($text === '') {
                continue;
            }
            $regions[$index]['text'] = $text;
            $regions[$index]['char_count'] = mb_strlen($text);
            $regions[$index]['extraction_method'] = 'embedded_text';
        }

        return $regions;
    }

    /**
     * Kich thuoc tung trang theo point, de doi bbox chuan hoa 0..1 sang toa do
     * ma `pdftotext` nhan.
     *
     * @return array<int, array{width: float, height: float}>
     */
    private function pageSizes(string $source): array
    {
        $binary = (string) config('media.processing.local_document.pdfinfo_binary', 'pdfinfo');
        $sizes = [];
        $default = null;

        $output = $this->runner->run([$binary, '-f', '1', '-l', '-1', $source], 60);
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^Page\s+(\d+)\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/i', $line, $m)) {
                $sizes[(int) $m[1]] = ['width' => (float) $m[2], 'height' => (float) $m[3]];
            } elseif ($default === null && preg_match('/^Page\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/i', $line, $m)) {
                $default = ['width' => (float) $m[1], 'height' => (float) $m[2]];
            }
        }

        if ($sizes === [] && $default !== null) {
            // pdfinfo chi in mot dong khi moi trang cung kich thuoc.
            for ($page = 1, $total = $this->pageCount($source); $page <= $total; $page++) {
                $sizes[$page] = $default;
            }
        }

        return $sizes;
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
