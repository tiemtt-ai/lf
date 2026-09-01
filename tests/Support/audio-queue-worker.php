<?php

use App\Services\DocumentProcessRunner;
use App\Services\FasterWhisperSpeechToTextProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// This probe can run only against a random disposable review database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! preg_match('/^lf_audio_review_[a-f0-9]{16}$/D', (string) config('database.connections.mysql.database'))) {
    throw new RuntimeException('Disposable testing database required.');
}
config(['filesystems.disks.media_local.root' => getenv('AUDIO_REVIEW_STORAGE_ROOT')]);
if (in_array('--block', $argv, true)) {
    $app->instance(FasterWhisperSpeechToTextProvider::class, new class(app(DocumentProcessRunner::class)) extends FasterWhisperSpeechToTextProvider
    {
        public function process(object $mediaFile, object $job): array
        {
            // Chay het engine that — bao gom buoc ghi `billable_units` truoc khi
            // goi engine — roi treo lai truoc khi ket qua toi writer. SIGKILL sau
            // do mo phong worker bi giet giua chung: `failed()` khong chay.
            parent::process($mediaFile, $job);
            // Moc bao "engine da xong, dang treo". Test PHAI cho moc nay roi moi
            // SIGKILL: giet som hon se de lai tien trinh Python mo coi van chay,
            // va no canh tranh CPU voi lan chay engine cua worker drain.
            DB::table('media_processing_jobs')->where('customer_id', $job->customer_id)
                ->where('id', $job->id)->update(['metadata' => json_encode(['probe' => 'blocked'])]);
            while (true) {
                usleep(100000);
            }
        }
    });
}
exit(Artisan::call('queue:work', [
    'connection' => 'database', '--queue' => 'audio-recovery-probe',
    '--once' => ! in_array('--drain', $argv, true),
    '--stop-when-empty' => in_array('--drain', $argv, true), '--sleep' => 0,
]));
