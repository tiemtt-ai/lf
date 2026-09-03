<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use App\Exceptions\DocumentUsageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DoclingStructuredExtractionProvider implements MediaProcessingProvider
{
    /** @var array<string, array<int, array{width: float, height: float}>> */
    private array $pageSizeCache = [];

    private float $deadline;

    public function __construct(
        private readonly DocumentProcessRunner $runner,
        private readonly RegionCropStorage $crops = new RegionCropStorage,
    ) {}

    public function process(object $mediaFile, object $job): array
    {
        if ($job->job_type !== 'structured_extraction' || $mediaFile->file_type !== 'document') {
            throw new RuntimeException('unsupported_source');
        }
        $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) $mediaFile->storage_key, PATHINFO_EXTENSION)));
        if ($extension !== 'pdf') {
            throw new RuntimeException('unsupported_source');
        }

        $this->deadline = microtime(true) + (int) config('media.processing.structured_extraction.max_processing_seconds', 3300);
        $this->pageSizeCache = [];
        $directory = $this->temporaryDirectory();
        $completedPages = 0;
        try {
            $this->checkpoint($mediaFile, $job, 0);
            $source = $directory.'/source.pdf';
            $this->copySource($mediaFile, $source);
            $maxPages = (int) config('media.processing.structured_extraction.max_pages', 100);
            $pageCount = $this->pageCount($source);
            if ($pageCount > $maxPages) {
                throw new RuntimeException('page_limit_exceeded');
            }

            $python = (string) config('media.processing.docling.python_binary');
            $script = (string) config('media.processing.docling.script');
            $artifacts = (string) config('media.processing.docling.artifacts_path');
            $resultPath = $directory.'/result.json';
            if (! is_executable($python) || ! is_file($script) || ! is_dir($artifacts)) {
                throw new RuntimeException('provider_unavailable');
            }

            $output = $this->runCommand([
                $python, $script, '--source', $source,
                '--locales', app(DocumentLanguageProfile::class)->serialize(
                    app(DocumentLanguageProfile::class)->fromProfile((string) $job->output_profile)
                ),
                '--artifacts', $artifacts, '--max-pages', (string) $maxPages,
                '--output', $resultPath,
            ], (int) config('media.processing.docling.timeout_seconds', 3300));
            $envelope = json_decode($output, true);
            if (isset($envelope['completed_pages'])) {
                if (! is_int($envelope['completed_pages']) || $envelope['completed_pages'] < 0 || $envelope['completed_pages'] > $pageCount) {
                    throw new RuntimeException('provider_command_failed');
                }
                $completedPages = $envelope['completed_pages'];
                $this->checkpoint($mediaFile, $job, $completedPages);
            }
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

            if (isset($result['page_count']) && $result['page_count'] !== $pageCount) {
                throw new RuntimeException('provider_command_failed');
            }
            $completedPages = $pageCount;
            $this->checkpoint($mediaFile, $job, $completedPages);
            $result['usage'] = ['units' => $completedPages, 'unit_type' => 'page'];
            $result['regions'] = $this->fillFigureText($source, $result['regions'] ?? []);
            $result['regions'] = $this->renderCrops($mediaFile, $job, $source, $result['regions']);
            $result['regions'] = $this->enrichRegionSignals($result['regions'], (string) $job->output_profile);

            $this->remainingSeconds();

            return $result;
        } catch (Throwable $exception) {
            throw new DocumentUsageException($exception, $completedPages);
        } finally {
            $this->pageSizeCache = [];
            $this->removeDirectory($directory);
        }
    }

    private function checkpoint(object $media, object $job, int $pages): void
    {
        if (! isset($media->customer_id, $media->id, $job->id)) {
            return;
        }
        $query = DB::table('media_processing_jobs')->where('customer_id', $media->customer_id)
            ->where('media_file_id', $media->id)->where('id', $job->id)->where('job_type', 'structured_extraction')->where('status', 'processing');
        $updated = (clone $query)->where(fn ($q) => $q->whereNull('billable_units')->orWhere('billable_units', '<', $pages))
            ->update(['billable_units' => $pages, 'billable_unit_type' => 'page']);
        if ($updated === 0 && ! $query->exists()) {
            throw new RuntimeException('provider_timeout');
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
     * renderCrops se thu OCR tren crop theo locale da chon.
     *
     * @param  array<int, array<string, mixed>>  $regions
     * @return array<int, array<string, mixed>>
     */
    private function fillFigureText(string $source, array $regions): array
    {
        $figures = array_filter($regions, static fn (array $region): bool => in_array(
            $region['role'] ?? null, ['figure', 'image', 'chart', 'diagram', 'geometry'], true
        ) && is_array($region['bbox'] ?? null));
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
                $text = trim($this->runCommand([
                    $binary, '-f', (string) $page, '-l', (string) $page,
                    '-x', (string) $x, '-y', (string) $y, '-W', (string) $width, '-H', (string) $height,
                    '-layout', $source, '-',
                ], 30));
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'provider_timeout') {
                    throw $exception;
                }

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

        $roles = (array) config('media.processing.structured_extraction.crop_roles', ['figure']);
        $figures = array_filter($regions, static fn (array $region): bool => in_array($region['role'] ?? null, $roles, true)
            && is_array($region['bbox'] ?? null));
        if ($figures === []) {
            return $regions;
        }

        $pageSizes = $this->pageSizes($source);
        $dpi = (int) config('media.processing.structured_extraction.crop_dpi', 200);
        $maxBytes = (int) config('media.processing.structured_extraction.max_crop_bytes_per_document', 67108864);
        $binary = (string) config('media.processing.local_document.pdftoppm_binary', 'pdftoppm');
        $locale = app(DocumentLanguageProfile::class)->serialize(
            app(DocumentLanguageProfile::class)->fromProfile((string) $job->output_profile)
        );
        $disk = (string) $mediaFile->storage_disk;
        $scale = $dpi / 72.0;
        $directory = $this->temporaryDirectory();
        $totalBytes = 0;

        try {
            foreach ($figures as $index => $region) {
                $this->remainingSeconds();
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
                $this->runCommand([
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

                $key = $this->crops->key($mediaFile, $job, $locale, $page, (int) $region['ordinal']);
                $stream = fopen($file, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('provider_command_failed');
                }
                try {
                    if (! $this->crops->put($mediaFile, $job, $key, $stream)) {
                        throw new RuntimeException('provider_command_failed');
                    }
                } finally {
                    fclose($stream);
                }

                $regions[$index]['crop'] = [
                    'storage_key' => $key,
                    'mime_type' => 'image/png',
                    'width' => (int) $dimensions[0],
                    'height' => (int) $dimensions[1],
                    'bytes' => $bytes,
                ];

                if ($this->figureTextNeedsOcr($regions[$index])) {
                    $regions[$index] = $this->ocrCrop($regions[$index], $file, $locale);
                }

                @unlink($file);
            }

            return $regions;
        } catch (Throwable $exception) {
            // Da co crop len storage truoc khi hong. Khong xoa thi chung thanh
            // object mo coi khong row nao tham chieu, va co the chua PII.
            //
            // Cleanup KHONG duoc thay the loi goc: `structured_extraction_too_large`
            // la thu job dich sang error code on dinh, con loi cua lenh xoa thi
            // khong. Doi loi o day se lam ca chuoi chan doan noi sai nguyen nhan.
            try {
                $this->crops->purgeRevision($mediaFile, $job, $locale);
            } catch (Throwable) {
                // Nuot co chu dich; sweeper la duong quay lai.
            }

            throw $exception;
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
        $language = collect(explode(',', $locale))->map(fn (string $item) => $languages[$item] ?? $languages[explode('-', $item)[0]] ?? null)
            ->filter()->unique()->implode('+');
        if ($language === '') {
            return $region;
        }

        try {
            $text = trim($this->runCommand([$binary, $file, 'stdout', '-l', $language], 60));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'provider_timeout') {
                throw $exception;
            }

            return $region;
        }

        if ($text === '') {
            return $region;
        }

        $existingText = trim((string) ($region['text'] ?? ''));
        if (mb_strlen($text) <= mb_strlen($existingText)) {
            return $region;
        }

        $region['text'] = $text;
        $region['char_count'] = mb_strlen($text);
        $region['extraction_method'] = 'ocr';
        $region['metadata'] = ($region['metadata'] ?? []) + ['ocr_engine' => 'tesseract', 'ocr_language' => $language];

        return $region;
    }

    /**
     * Docling/pdftotext co the tra mot ky tu rac cho figure tren trang scan.
     * Khong coi no la text day du: cho Tesseract thu doc crop, nhung ocrCrop()
     * chi thay the khi ket qua mang nhieu ky tu hon text hien co.
     *
     * @param  array<string, mixed>  $region
     */
    private function figureTextNeedsOcr(array $region): bool
    {
        $minimum = max(1, (int) config(
            'media.processing.structured_extraction.crop_ocr_min_text_characters',
            2,
        ));

        return mb_strlen(trim((string) ($region['text'] ?? ''))) < $minimum;
    }

    /**
     * Normalize observed text and attach only language signals supported by the
     * characters in that region. The requested profile is a candidate set, not
     * proof that every block uses that language.
     *
     * @param  array<int, array<string, mixed>>  $regions
     * @return array<int, array<string, mixed>>
     */
    private function enrichRegionSignals(array $regions, string $profile): array
    {
        $locales = app(DocumentLanguageProfile::class)->fromProfile($profile);
        foreach ($regions as &$region) {
            if (isset($region['text'])) {
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $region['text']);
                $region['text'] = ($text = trim((string) $text)) !== '' ? $text : null;
            }
            $text = (string) ($region['text'] ?? '');
            if ($text === '') {
                continue;
            }
            if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) {
                $region['script'] = 'Hang';
                $region['detected_locale'] = in_array('ko', $locales, true) ? 'ko' : null;
            } elseif (preg_match('/[\x{3040}-\x{30FF}]/u', $text)) {
                $region['script'] = 'Jpan';
                $region['detected_locale'] = in_array('ja', $locales, true) ? 'ja' : null;
            } elseif (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text)) {
                $region['script'] = 'Hani';
                $region['detected_locale'] = collect($locales)->first(fn (string $locale): bool => str_starts_with($locale, 'zh'));
            } elseif (preg_match('/\p{Latin}/u', $text)) {
                $region['script'] = 'Latn';
                if (in_array('vi', $locales, true) && preg_match('/[ăâđêôơưáàảãạấầẩẫậắằẳẵặéèẻẽẹếềểễệíìỉĩịóòỏõọốồổỗộớờởỡợúùủũụứừửữựýỳỷỹỵ]/iu', $text)) {
                    $region['detected_locale'] = 'vi';
                } elseif ($locales === ['en']) {
                    $region['detected_locale'] = 'en';
                }
            }
            $region['char_count'] = $region['text'] === null ? null : mb_strlen($region['text']);
        }
        unset($region);

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
        if (isset($this->pageSizeCache[$source])) {
            return $this->pageSizeCache[$source];
        }
        $binary = (string) config('media.processing.local_document.pdfinfo_binary', 'pdfinfo');
        $sizes = [];
        $default = null;

        $output = $this->runCommand([$binary, '-f', '1', '-l', '-1', $source], 60);
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

    private function remainingSeconds(): int
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('provider_timeout');
        }

        return $remaining;
    }

    private function runCommand(array $command, int $timeout): string
    {
        $output = $this->runner->run($command, min($timeout,
            (int) config('media.processing.structured_extraction.command_timeout_seconds', 900),
            $this->remainingSeconds()));
        $this->remainingSeconds();

        return $output;
    }

    private function pageCount(string $source): int
    {
        $output = $this->runCommand([(string) config('media.processing.local_document.pdfinfo_binary', 'pdfinfo'), $source], 30);
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
