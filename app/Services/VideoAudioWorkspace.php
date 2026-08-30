<?php

namespace App\Services;

/**
 * Thu muc tam chua audio tach tu video cua MOT job/attempt.
 *
 * Amendment Record 2.19 § 3: audio tam la workspace noi bo, khong phai Media
 * asset — khong tao `media_files`, khong len object storage, khong co signed URL.
 *
 * Duong dan phai DETERMINISTIC theo tenant, Media, job va attempt, va khong dung
 * ten file cua client. Hai ly do: worker bi giet truoc `finally` thi `failed()`
 * van suy ra duoc thu muc de don; va hai attempt khong duoc ghi de len nhau.
 *
 * Day la audio DAY DU cua hoc lieu nam ngoai moi kiem soat retention, nen bo sot
 * no khong phai chuyen rac o dia.
 */
class VideoAudioWorkspace
{
    public function directory(object $media, object $job): string
    {
        $root = rtrim((string) config('media.processing.video_audio.workspace_root', sys_get_temp_dir()), '/');

        return $root.'/lf-video-audio'
            .'/'.(int) $media->customer_id
            .'/'.(int) $media->id
            .'/'.(int) $job->id
            .'-'.(int) ($job->attempt ?? 1);
    }

    public function audioPath(object $media, object $job): string
    {
        return $this->directory($media, $job).'/source.wav';
    }

    public function create(object $media, object $job): string
    {
        $directory = $this->directory($media, $job);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('audio_extraction_failed');
        }
        @chmod($directory, 0700);

        return $directory;
    }

    /** Idempotent: goi bao nhieu lan cung duoc, ke ca khi chua tao. */
    public function purge(object $media, object $job): bool
    {
        return $this->remove($this->directory($media, $job));
    }

    private function remove(string $directory): bool
    {
        if (! is_dir($directory)) {
            return true;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->remove($path) : @unlink($path);
        }
        @rmdir($directory);

        return ! is_dir($directory);
    }
}
