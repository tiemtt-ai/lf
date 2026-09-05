<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FasterWhisperSpeechToTextProvider implements MediaProcessingProvider
{
    public function __construct(
        private readonly DocumentProcessRunner $runner,
        private readonly VideoAudioWorkspace $workspace = new VideoAudioWorkspace,
    ) {}

    public function process(object $mediaFile, object $job): array
    {
        $isVideo = $mediaFile->file_type === 'video';
        if ($job->job_type !== 'speech_to_text' || ! in_array($mediaFile->file_type, ['audio', 'video'], true)) {
            throw new RuntimeException('unsupported_source');
        }
        // Gate thu hai. Video STT mac dinh TAT cho toi khi DOC-CONFLICT-0027 dong:
        // tran thoi luong hien la provisional, do tren may dev dang throttle.
        // Kiem ca o day chu khong chi luc materialize, de mot job da nam trong
        // hang doi tu truoc khong lot qua sau khi gate duoc tat.
        if ($isVideo && ! (bool) config('media.processing.speech_to_text.video_enabled', false)) {
            throw new RuntimeException('video_stt_disabled');
        }
        if ($isVideo && ! app(VideoSttQualification::class)->isQualified()) {
            throw new RuntimeException('video_stt_unqualified');
        }

        // Video co tran rieng va ma loi rieng. Amendment Record 2.21 § 2: video
        // 120 phut can 3.432s xu ly, vuot provider deadline 3.300s — tu choi
        // truoc khi chay khac han chay 57 phut roi chet.
        $mimeTypes = (array) config($isVideo
            ? 'media.processing.speech_to_text.video_mime_types'
            : 'media.processing.speech_to_text.mime_types', []);
        if (! in_array((string) $mediaFile->mime_type, $mimeTypes, true)) {
            throw new RuntimeException('unsupported_source');
        }

        $limitError = $isVideo ? 'video_limit_exceeded' : 'audio_limit_exceeded';
        $maxBytes = (int) config($isVideo
            ? 'media.processing.speech_to_text.max_video_source_bytes'
            : 'media.processing.speech_to_text.max_bytes');
        $maxDuration = (int) config($isVideo
            ? 'media.processing.speech_to_text.max_video_duration_seconds'
            : 'media.processing.speech_to_text.max_duration_seconds');

        if ((int) $mediaFile->file_size_bytes > $maxBytes) {
            throw new RuntimeException($limitError);
        }
        if ($mediaFile->duration_seconds === null || (int) $mediaFile->duration_seconds <= 0) {
            throw new RuntimeException('corrupt_source');
        }
        if ((int) $mediaFile->duration_seconds > $maxDuration) {
            throw new RuntimeException($limitError);
        }

        try {
            $locales = app(SpeechLanguageProfile::class)->fromProfile((string) $job->output_profile);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage() === 'speech_language_profile_unsupported'
                ? 'locale_unavailable'
                : $exception->getMessage());
        }
        $diarization = $this->profileValue((string) $job->output_profile, 'diarization');
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
            if ($isVideo) {
                $source = $this->extractAudio($mediaFile, $job, $source);
            }

            // LF-Media-Processing-Contract § 5: moi job ghi `billable_units` khi
            // ket thuc, don vi `second` cho speech-to-text. Ghi TRUOC khi goi
            // engine, cung cach LocalDocumentProcessingProvider ghi so trang:
            // chi phi phat sinh tu luc engine chay, nen mot job chet giua chung
            // van phai de lai dau vet cua lan goi do. Preflight tu choi truoc
            // day (MIME, tran, locale) khong ton chi phi va giu NULL.
            $this->recordBillableSeconds($mediaFile, $job);

            $languageArguments = count($locales) === 1
                ? ['--locale', $locales[0]]
                : ['--locales', app(SpeechLanguageProfile::class)->serialize($locales)];
            $envelope = json_decode($this->runner->run([
                $python, $script,
                '--source', $source,
                ...$languageArguments,
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
            if ($isVideo) {
                $this->workspace->purge($mediaFile, $job);
            }
        }
    }

    /** Do bang `media_files.duration_seconds`, da duoc preflight bao dam > 0. */
    private function recordBillableSeconds(object $mediaFile, object $job): void
    {
        if (! isset($job->id, $job->customer_id)) {
            return; // Standalone provider fixture without a persisted job.
        }

        DB::table('media_processing_jobs')
            ->where('customer_id', $job->customer_id)
            ->where('id', $job->id)
            ->where('status', 'processing')
            ->update(['billable_units' => (int) $mediaFile->duration_seconds, 'billable_unit_type' => 'second']);
    }

    /**
     * Tach audio tu video vao workspace deterministic cua job/attempt.
     *
     * Argument set den tu VideoSpeechToTextProfile — cung nguon voi hash di
     * vao `processing_version`. Hai thu khong duoc phep lech nhau, neu khong
     * version se noi ve mot cau hinh khac cau hinh thuc su da chay.
     */
    private function extractAudio(object $mediaFile, object $job, string $source): string
    {
        // Worker la tien trinh SE chay lenh, nen day la noi duy nhat duoc phep
        // probe binary. Lech voi inventory thi fail-closed: mot transcript sinh
        // boi binary khac voi identity da ghi la du lieu noi doi ve chinh no.
        // KHONG do tim ffmpeg tren PATH.
        $profile = app(VideoSpeechToTextProfile::class);
        $profile->assertBinaryMatchesInventory();

        $this->workspace->create($mediaFile, $job);
        $destination = $this->workspace->audioPath($mediaFile, $job);

        try {
            $this->runner->run(
                $profile->arguments($source, $destination),
                (int) config('media.processing.video_audio.timeout_seconds', 600),
            );
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                $exception->getMessage() === 'provider_timeout' ? 'provider_timeout' : 'audio_extraction_failed'
            );
        }

        $bytes = is_file($destination) ? (int) filesize($destination) : 0;
        if ($bytes <= 0) {
            throw new RuntimeException('audio_extraction_failed');
        }
        if ($bytes > (int) config('media.processing.video_audio.max_output_bytes')) {
            throw new RuntimeException('audio_extraction_limit_exceeded');
        }

        return $destination;
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
