<?php

namespace Tests\Unit;

use App\Services\MediaMetadataProbe;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaMetadataProbeTest extends TestCase
{
    public function test_it_reads_audio_duration_and_ignores_non_timed_media(): void
    {
        $availability = new Process([
            (string) config('media.ffprobe_binary', 'ffprobe'),
            '-version',
        ]);
        $availability->run();

        if (! $availability->isSuccessful()) {
            $this->markTestSkipped('ffprobe is not available.');
        }

        $path = tempnam(sys_get_temp_dir(), 'lf-wav-');
        $sampleRate = 8000;
        $samples = str_repeat("\x80", $sampleRate);
        $header = 'RIFF'
            .pack('V', 36 + strlen($samples))
            .'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate, 1, 8)
            .'data'
            .pack('V', strlen($samples));
        file_put_contents($path, $header.$samples);

        try {
            $file = new UploadedFile(
                $path,
                'one-second.wav',
                'audio/wav',
                null,
                true
            );
            $probe = app(MediaMetadataProbe::class);

            $this->assertSame(1, $probe->durationSeconds($file, 'audio'));
            $this->assertNull($probe->durationSeconds($file, 'document'));
        } finally {
            @unlink($path);
        }
    }

    public function test_it_returns_null_when_ffprobe_cannot_read_the_file(): void
    {
        config(['media.ffprobe_binary' => '/usr/bin/false']);
        $path = tempnam(sys_get_temp_dir(), 'lf-invalid-media-');
        file_put_contents($path, 'not media');

        try {
            $file = new UploadedFile(
                $path,
                'invalid.mp4',
                'video/mp4',
                null,
                true
            );

            $this->assertNull(
                app(MediaMetadataProbe::class)
                    ->durationSeconds($file, 'video')
            );
        } finally {
            @unlink($path);
        }
    }
}
