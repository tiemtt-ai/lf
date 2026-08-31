<?php

use App\Services\DocumentProcessRunner;
use App\Services\LocalDocumentProcessingProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// This probe can run only against a random disposable review database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! preg_match('/^lf_document_review_[a-f0-9]{16}$/D', (string) config('database.connections.mysql.database'))) {
    throw new RuntimeException('Disposable testing database required.');
}
config(['filesystems.disks.media_local.root' => getenv('DOCUMENT_REVIEW_STORAGE_ROOT')]);
if (in_array('--block', $argv, true)) {
    $app->instance(LocalDocumentProcessingProvider::class, new class(app(DocumentProcessRunner::class)) extends LocalDocumentProcessingProvider
    {
        public function process(object $mediaFile, object $job): array
        {
            // Extract real TXT and persist its usage checkpoint, then inject
            // a crash before the result reaches the output writer.
            parent::process($mediaFile, $job);
            while (true) {
                usleep(100000);
            }
        }
    });
}
exit(Artisan::call('queue:work', [
    'connection' => 'database', '--queue' => 'document-recovery-probe',
    '--once' => ! in_array('--drain', $argv, true),
    '--stop-when-empty' => in_array('--drain', $argv, true), '--sleep' => 0,
]));
