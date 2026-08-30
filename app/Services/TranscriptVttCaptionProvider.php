<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Caption provider Phase 1: dung VTT TU transcript, khong chay model.
 *
 * Owner quyet dinh 2026-08-29 (DOC-CONFLICT-0024): caption duoc dung tu transcript
 * chu khong chay mot lan nhan dang giong noi rieng. Hai duong doc lap se cho hai
 * noi dung khac nhau tren cung mot video — nguoi hoc doc mot ban, AI doc ban khac.
 */
class TranscriptVttCaptionProvider implements MediaProcessingProvider
{
    public function __construct(
        private readonly TranscriptVttSerializer $serializer = new TranscriptVttSerializer,
        private readonly CaptionAssetStorage $assets = new CaptionAssetStorage,
    ) {}

    /**
     * @return array{storage_key: string, transcript_processing_version: string}
     */
    public function process(object $mediaFile, object $job): array
    {
        if ($job->job_type !== 'caption') {
            throw new RuntimeException('unsupported_source');
        }

        $format = $this->profileValue((string) $job->output_profile, 'format');
        if ($format !== (string) config('media.processing.caption.format', 'vtt')) {
            throw new RuntimeException('unsupported_output_profile');
        }
        $locale = $this->profileValue((string) $job->output_profile, 'locale');
        if ($locale === null || $locale === '') {
            throw new RuntimeException('missing_canonical_locale');
        }

        $revision = $this->transcriptRevision($mediaFile, $job, $locale);
        $segments = DB::table('media_transcripts')
            ->where('customer_id', $mediaFile->customer_id)
            ->where('media_file_id', $mediaFile->id)
            ->where('locale', $locale)
            ->where('source_fingerprint', $job->source_fingerprint)
            ->where('processing_version', $revision)
            ->where('status', 'ready')
            ->orderBy('id')
            ->get(['locator_value', 'text'])
            ->map(static fn ($row): array => ['locator_value' => $row->locator_value, 'text' => (string) $row->text])
            ->all();

        $key = $this->assets->key($mediaFile, $job, $locale, $format);
        $this->assets->write($mediaFile, $key, $this->serializer->serialize($segments));

        return ['storage_key' => $key, 'transcript_processing_version' => $revision];
    }

    /**
     * Chon DUNG MOT transcript revision `ready` de dung caption.
     *
     * CHECK vat ly chi bat `transcript_processing_version` co gia tri; no khong
     * chung minh revision do co that. Bat bien nay vi the phai nam o tang persist —
     * xem media_captions.md § CHECK khong chung minh transcript revision ton tai.
     *
     * Khong tim thay hoac tim thay nhieu hon mot: fail-closed, khong tao file va
     * khong ghi row. Doan sai revision se sinh phu de cua mot ban phien am khac
     * voi ban AI dang doc, va citation van hop le nen sai khong lo ra.
     */
    private function transcriptRevision(object $mediaFile, object $job, string $locale): string
    {
        $versions = DB::table('media_transcripts')
            ->where('customer_id', $mediaFile->customer_id)
            ->where('media_file_id', $mediaFile->id)
            ->where('locale', $locale)
            ->where('source_fingerprint', $job->source_fingerprint)
            ->where('status', 'ready')
            ->distinct()
            ->pluck('processing_version')
            ->all();

        if ($versions === []) {
            throw new RuntimeException('transcript_unavailable');
        }
        if (count($versions) > 1) {
            throw new RuntimeException('ambiguous_source');
        }

        return (string) $versions[0];
    }

    private function profileValue(string $profile, string $key): ?string
    {
        foreach (array_filter(explode(';', $profile)) as $pair) {
            [$candidate, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($candidate === $key) {
                return $value;
            }
        }

        return null;
    }
}
