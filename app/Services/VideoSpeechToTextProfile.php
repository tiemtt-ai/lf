<?php

namespace App\Services;

/**
 * Canonical identity cua nhanh VIDEO speech-to-text.
 *
 * Class nay giu HAI thu, va do la ly do no khong con ten "extraction profile":
 * profile cua buoc tach audio (ffmpeg), VA execution identity cua buoc STT
 * (`threads`, `compute_type`). Ca hai cung quyet dinh transcript video ra sao.
 *
 * Audio STT khong dung class nay — identity audio hien hanh khong bi dung toi.
 *
 * LF-Media-Processing-Contract Amendment Record 2.19 § 1 (revision identity):
 * transcript cua video phu thuoc vao CACH audio duoc tach ra, nhung
 * `source_fingerprint` la van tay cua binary goc — no khong doi khi tham so
 * ffmpeg doi. Doi `-ar 16000` thanh `44100`, hoac nang ffmpeg, se lam transcript
 * doi noi dung ma khong sinh revision moi: output cu khong bi archive va he thong
 * tin rang khong co gi thay doi.
 *
 * Vi the profile nay di vao `processing_version`, va hash duoc tao TU CHINH
 * argument set truyen cho Process — khong phai mot nhan go tay.
 */
class VideoSpeechToTextProfile
{
    public function __construct(private readonly DocumentProcessRunner $runner) {}

    /**
     * Argument set chuan hoa, thu tu on dinh. Day vua la lenh duoc chay, vua la
     * nguon cua hash — hai thu khong duoc phep lech nhau.
     *
     * @return array<int, string>
     */
    public function arguments(string $source, string $destination): array
    {
        $config = (array) config('media.processing.video_audio', []);
        $arguments = [
            (string) $config['ffmpeg_binary'],
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $source,
            '-vn',
            '-acodec', (string) $config['codec'],
            '-sample_fmt', (string) $config['sample_format'],
            '-ar', (string) $config['sample_rate'],
            '-ac', (string) $config['channels'],
        ];
        foreach ((array) ($config['filters'] ?? []) as $filter) {
            $arguments[] = '-af';
            $arguments[] = (string) $filter;
        }
        $arguments[] = $destination;

        return $arguments;
    }

    /**
     * Nhan doc duoc cua profile, gan vao processing_version.
     *
     * Vi du: `ffmpeg-7.1+pcm_s16le-ar16000-ac1+a1b2c3d4`.
     */
    public function label(): string
    {
        $config = (array) config('media.processing.video_audio', []);

        return 'ffmpeg-'.$this->ffmpegVersion()
            .'+'.$config['codec'].'-ar'.$config['sample_rate'].'-ac'.$config['channels']
            .'+'.substr($this->hash(), 0, 8)
            .'+stt-'.substr($this->speechToTextExecutionHash(), 0, 8);
    }

    /**
     * Hash tu argument set voi source/destination duoc thay bang placeholder —
     * duong dan tam thay doi moi lan chay va khong phai mot tham so xu ly.
     */
    public function hash(): string
    {
        return hash('sha256', implode("\x1f", array_merge(
            $this->arguments('{source}', '{destination}'),
            ['ffmpeg='.$this->ffmpegVersion()],
        )));
    }

    /**
     * Execution identity rieng cua VIDEO STT.
     *
     * `threads` va `compute_type` la input thuc thi. Chúng khong chung minh
     * output se tai lap byte-for-byte — DOC-CONFLICT-0027 van giu cau hoi
     * non-determinism mo — nhung bo chung khoi identity se khien hai cau hinh
     * khac nhau tu nhan la cung mot revision.
     *
     * Audio STT khong dung label cua class nay, nen identity audio hien hanh
     * khong bi thay doi ngam.
     */
    public function speechToTextExecutionHash(): string
    {
        return hash('sha256', implode("\x1f", [
            'compute_type='.(string) config('media.processing.speech_to_text.compute_type', ''),
            'threads='.(int) config('media.processing.speech_to_text.threads', 0),
        ]));
    }

    /**
     * Version theo INVENTORY cua deployment.
     *
     * Khong probe binary o day: ham nay chay luc TAO job, co the tren mot node
     * khong phai node xu ly. Identity phai la thu deployment khai, va worker co
     * trach nhiem chung minh binary that khop — xem `assertBinaryMatchesInventory()`.
     */
    public function ffmpegVersion(): string
    {
        $declared = trim((string) config('media.processing.video_audio.ffmpeg_version', ''));

        return $declared === '' ? 'undeclared' : $declared;
    }

    /**
     * Worker goi truoc khi xu ly: binary that phai khop inventory.
     *
     * Day la noi duy nhat duoc phep probe, vi day la tien trinh se thuc su chay
     * lenh. Lech thi fail-closed — mot transcript sinh boi binary khac voi identity
     * da ghi la du lieu noi doi ve chinh no.
     */
    public function assertBinaryMatchesInventory(): void
    {
        $declared = $this->ffmpegVersion();
        if ($declared === 'undeclared') {
            throw new \RuntimeException('provider_unavailable');
        }

        $binary = (string) config('media.processing.video_audio.ffmpeg_binary');
        if (! is_executable($binary)) {
            throw new \RuntimeException('provider_unavailable');
        }

        try {
            $output = $this->runner->run([$binary, '-version'], 15);
        } catch (\RuntimeException) {
            throw new \RuntimeException('provider_unavailable');
        }

        $actual = preg_match('/^ffmpeg version (\S+)/', $output, $matches) === 1 ? $matches[1] : 'unknown';
        if ($actual !== $declared) {
            throw new \RuntimeException('extraction_profile_mismatch');
        }
    }
}
