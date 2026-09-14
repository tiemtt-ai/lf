<?php

use App\Exceptions\AiProviderGateException;
use App\Services\Ai\AiModelRunRecorder;
use App\Services\Ai\DatabaseCommercialEntitlements;
use App\Services\Ai\DatabaseUsageQuotaReserver;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\AiProviderExecutionGate;
use App\Support\Ai\AllowedExecution;
use App\Support\Ai\ProviderGateRequest;
use App\Support\Ai\QuotaReservationHandle;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ai\FakeTenantSettings;

// Local test child only: never resolves credentials or invokes an adapter.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'] ?? '', 'lf_')) {
    throw new RuntimeException('Dedicated test database required');
}
config(['database.connections.gate_child' => $input['connection'], 'ai.providers' => []]);
DB::setDefaultConnection('gate_child');
DB::statement('SET innodb_lock_wait_timeout = 1');
TenantContext::set((object) ['id' => $input['customer']]);
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
try {
    if ($input['mode'] === 'claim') {
        $result = ['claimed' => (new AiModelRunRecorder)->claimForExecution($input['customer'], $input['run'])];
    } else {
        $request = new ProviderGateRequest(...$input['request']);
        $input['reservation']['expiresAt'] = new DateTimeImmutable($input['reservation']['expiresAt']);
        $prior = new AllowedExecution($input['customer'], $input['run'], $input['uuid'], $request,
            new QuotaReservationHandle(...$input['reservation']));
        $gate = new AiProviderExecutionGate(new SettingBackedExternalProcessingApprovals(new FakeTenantSettings),
            new DatabaseCommercialEntitlements, new DatabaseUsageQuotaReserver, new AiModelRunRecorder);
        $decision = $gate->execute($request, fn () => throw new RuntimeException('Provider must not run'), $prior);
        $result = ['allowed' => $decision->allowed, 'blocked_at' => $decision->blockedStep];
    }
} catch (QueryException $exception) {
    $result = ['driver_error' => $exception->errorInfo[1] ?? null];
} catch (AiProviderGateException $exception) {
    $result = ['error' => $exception->errorCode];
}
echo json_encode(['connection' => $connection, 'result' => $result], JSON_THROW_ON_ERROR);
