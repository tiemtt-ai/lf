<?php

use App\Services\Ai\DatabaseUsageQuotaReserver;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Dedicated child process for the two-connection test, never a web entry point.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'] ?? '', 'lf_')) {
    throw new RuntimeException('Dedicated test database required');
}
config(['database.connections.quota_child' => $input['connection']]);
DB::setDefaultConnection('quota_child');
DB::statement('SET innodb_lock_wait_timeout = 1');
Carbon::setTestNow($input['now']);
try {
    $h = (new DatabaseUsageQuotaReserver)->reserve(
        $input['customer'], 31, $input['attempt'], 'ai_tokens', 'input_token', 60, 'token');
    echo json_encode(['reserved' => $h !== null], JSON_THROW_ON_ERROR);
} catch (QueryException $e) {
    echo json_encode(['driver_error' => $e->errorInfo[1] ?? null], JSON_THROW_ON_ERROR);
}
