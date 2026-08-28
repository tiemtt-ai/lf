<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DoclingStructuredExtractionProvider implements MediaProcessingProvider
{
    /** @var array<string, array<int, array{width: float, height: float}>> */
    private array $pageSizeCache = [];

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
            $result['regions'] = $this->renderCrops($mediaFile, $job, $source, $result['regions']);

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
     * Cat anh cua tung vung `figure` va day len storage.
     *
     * ADR-0019 § D7 diem 4 buoc Media cung cap crop va citation cho moi khoi do
     * hoa. Crop la tien ich de consumer NHIN THAY vung ma khong phai tu render
     * lai trang; no khong thay the `bbox`, va khong noi gi ve noi dung.
     *
     * Duong luu chua ca `source_fingerprint`, `processing_version` va `locale` —
     * dinh danh day du cua mot revision. Chi dat `processing_version` la chua du:
     * file bi thay noi dung ma extractor giu nguyen version se ghi de crop cua
     * ban cu, trong khi row cu van `archived` va van tro toi key do.
     *
     * Vuot tran thi FAIL CA REVISION, khong cat bot. Mot bo crop thieu vai vung
     * khien consumer khong phan biet duoc "vung nay khong co crop" voi "vung nay
     * chua duoc cat".
     *
     * @param  array<int, array<string, mixed>>  $regions
     * @return array<int, array<string, mixed>>
     */
    private function renderCrops(object $mediaFile, object $job, string $source, array $regions): array
    {
        if (! (bool) config('media.processing.structured_extraction.crop_enabled', true)) {
            return $regions;
        }

        $figures = array_filter($regions, static fn (array $region): bool => ($region['role'] ?? null) === 'figure'
            && is_array($region['bbox'] ?? null));
        if ($figures === []) {
            return $regions;
        }

        $pageSizes = $this->pageSizes($source);
        $dpi = (int) config('media.processing.structured_extraction.crop_dpi', 200);
        $maxBytes = (int) config('media.processing.structured_extraction.max_crop_bytes_per_document', 67108864);
        $binary = (string) config('media.processing.local_document.pdftoppm_binary', 'pdftoppm');
        $locale = (string) $this->profileValue((string) $job->output_profile, 'locale');
        $disk = (string) $mediaFile->storage_disk;
        $scale = $dpi / 72.0;
        $directory = $this->temporaryDirectory();
        $totalBytes = 0;

        try {
            foreach ($figures as $index => $region) {
                $page = (int) ($region['page'] ?? 0);
                $size = $pageSizes[$page] ?? null;
                if ($size === null) {
                    continue;
                }
                $bbox = $region['bbox'];
                $x = (int) floor((float) $bbox['x'] * $size['width'] * $scale);
                $y = (int) floor((float) $bbox['y'] * $size['height'] * $scale);
                $width = (int) ceil((float) $bbox['width'] * $size['width'] * $scale);
                $height = (int) ceil((float) $bbox['height'] * $size['height'] * $scale);
                if ($width <= 0 || $height <= 0) {
                    continue;
                }

                $prefix = $directory.'/crop';
                $this->runner->run([
                    $binary, '-f', (string) $page, '-l', (string) $page, '-r', (string) $dpi,
                    '-x', (string) $x, '-y', (string) $y, '-W', (string) $width, '-H', (string) $height,
                    '-png', '-singlefile', $source, $prefix,
                ], 60);

                $file = $prefix.'.png';
                $bytes = is_file($file) ? (int) filesize($file) : 0;
                if ($bytes <= 0) {
                    throw new RuntimeException('provider_command_failed');
                }
                $totalBytes += $bytes;
                if ($totalBytes > $maxBytes) {
                    throw new RuntimeException('structured_extraction_too_large');
                }
                $dimensions = getimagesize($file);
                if ($dimensions === false) {
                    throw new RuntimeException('provider_command_failed');
                }

                $key = $this->cropKey($mediaFile, $job, $locale, $page, (int) $region['ordinal']);
                $stream = fopen($file, 'rb');
                if ($stream === false || Storage::disk($disk)->put($key, $stream) === false) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    throw new RuntimeException('provider_command_failed');
                }
                fclose($stream);

                $regions[$index]['crop'] = [
                    'storage_key' => $key,
                    'mime_type' => 'image/png',
                    'width' => (int) $dimensions[0],
                    'height' => (int) $dimensions[1],
                    'bytes' => $bytes,
                ];

                if (($regions[$index]['text'] ?? null) === null) {
                    $regions[$index] = $this->ocrCrop($regions[$index], $file, $locale);
                }

                @unlink($file);
            }

            return $regions;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * Trang scan khong co text layer thi `pdftotext` tra rong. Doc chu bang OCR
     * tren chinh anh crop la duong duy nhat con lai de § D7 diem 3 dung cho trang
     * scan — va no chi chay khi vung do that su khong co text san.
     *
     * `provider` cua row van la extractor cua revision; engine OCR that ghi vao
     * metadata de khong noi sai nguon.
     *
     * Locale khong co trong bang ngon ngu thi BO QUA, khong fallback sang 'eng'.
     * OCR sai ngon ngu khong tra ve rong — no tra ve chuoi rac trong giong text
     * that, va consumer khong co cach nao phan biet.
     *
     * @param  array<string, mixed>  $region
     * @return array<string, mixed>
     */
    private function ocrCrop(array $region, string $file, string $locale): array
    {
        if (! (bool) config('media.processing.structured_extraction.crop_ocr_enabled', true)) {
            return $region;
        }

        $binary = (string) config('media.processing.local_document.tesseract_binary', 'tesseract');
        $languages = (array) config('media.processing.structured_extraction.crop_ocr_languages', []);
        $language = $languages[$locale] ?? $languages[explode('-', $locale)[0]] ?? null;
        if ($language === null) {
            return $region;
        }

        try {
            $text = trim($this->runner->run([$binary, $file, 'stdout', '-l', $language], 60));
        } catch (RuntimeException) {
            return $region;
        }

        if ($text === '') {
            return $region;
        }

        $region['text'] = $text;
        $region['char_count'] = mb_strlen($text);
        $region['extraction_method'] = 'ocr';
        $region['metadata'] = ($region['metadata'] ?? []) + ['ocr_engine' => 'tesseract', 'ocr_language' => $language];

        return $region;
    }

    private function cropKey(object $mediaFile, object $job, string $locale, int $page, int $ordinal): string
    {
        $safe = static fn (string $value): string => preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? $value;

        return 'tenants/'.(int) $mediaFile->customer_id
            .'/media/'.(int) $mediaFile->id
            .'/regions/'.$safe((string) $job->source_fingerprint)
            .'/'.$safe((string) $job->processing_version)
            .'/'.$safe($locale)
            .'/'.$page.'-'.$ordinal.'.png';
    }

    /**
     * Kich thuoc tung trang theo point, de doi bbox chuan hoa 0..1 sang toa do
     * ma `pdftotext` nhan.
     *
     * @return array<int, array{width: float, height: float}>
     */
    private function pageSizes(string $source): array
    {
        if (isset($this->pageSizeCache[$source])) {
            return $this->pageSizeCache[$source];
        }
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

        return $this->pageSizeCache[$source] = $sizes;
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
