<?php

/**
 * Standalone, destructive-test-database-only physical concurrency probe.
 * Run AFTER migrate on a dedicated lf_step7_* or lf_ai_authoring_* database.
 * Requires APP_ENV=testing and PROCESS privilege to observe InnoDB lock waits.
 * Leaves content-free test fixtures for the caller to drop with that database.
 * No provider call, no schema creation, no cleanup bypass of immutability.
 */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || ! preg_match('/^lf_(step7|ai_authoring)_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Refusing a non-dedicated authoring test database.');
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 60');
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;

if (($argv[1] ?? '') === 'child') {
    $case = $argv[2];
    $data = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(['connection' => $connectionId])."\n";
    flush();
    try {
        $result = match ($case) {
            'duplicate' => DB::table('ai_authoring_generation_requests')->insert($data),
            'claim' => DB::table('ai_authoring_generation_requests')->where('id', $data['id'])->where('status', 'pending')->update(['status' => 'running']),
            'source' => DB::table('ai_authoring_proposal_sources')->insert($data),
        };
        echo json_encode(['result' => $result])."\n";
    } catch (QueryException $e) {
        echo json_encode(['sqlstate' => $e->errorInfo[0], 'driver_code' => $e->errorInfo[1], 'error' => $e->errorInfo[2]])."\n";
    }
    exit(0);
}

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** Observe a real engine lock wait BEFORE the parent commits. No timing guess. */
function competitor(string $case, array $data, int $parentConnection): Process
{
    $process = new Process([PHP_BINARY, __FILE__, 'child', $case, json_encode($data, JSON_THROW_ON_ERROR)], base_path());
    $process->setTimeout(75);
    $process->start();
    $deadline = microtime(true) + 30;
    do {
        foreach (explode("\n", $process->getOutput()) as $line) {
            $row = json_decode($line, true);
            if (isset($row['connection'])) {
                ensure($row['connection'] !== $parentConnection, 'Connections must differ.');
                $wait = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id WHERE r.trx_mysql_thread_id = ? AND b.trx_mysql_thread_id = ?', [$row['connection'], $parentConnection]);
                if ((int) $wait->n > 0) {
                    return $process;
                }
            }
        }
        ensure($process->isRunning(), 'Competitor did not block: '.$process->getOutput().$process->getErrorOutput());
        usleep(200000);
    } while (microtime(true) < $deadline);
    $diagnostic = ['output' => $process->getOutput(), 'stderr' => $process->getErrorOutput(),
        'waits' => DB::select('SELECT requesting_trx_id, blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS'),
        'transactions' => DB::select('SELECT trx_id, trx_mysql_thread_id, trx_state FROM information_schema.INNODB_TRX'),
        'processes' => DB::select('SELECT ID, DB, COMMAND, STATE FROM information_schema.PROCESSLIST WHERE DB = ?', [DB::getDatabaseName()])];
    $process->stop();
    throw new RuntimeException('No physical InnoDB wait observed: '.json_encode($diagnostic));
}

function outcome(Process $process): array
{
    $process->wait();
    ensure($process->isSuccessful(), $process->getErrorOutput());
    $rows = array_values(array_filter(explode("\n", trim($process->getOutput()))));

    return json_decode(end($rows), true, 512, JSON_THROW_ON_ERROR);
}

$uuid = (string) Str::uuid();
$now = now()->format('Y-m-d H:i:s.u');
$customer = DB::table('saas_customers')->insertGetId(['name' => 'Concurrency fixture', 'slug' => $uuid, 'subdomain' => $uuid, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$actor = DB::table('users')->insertGetId(['customer_id' => $customer, 'name' => 'Fixture', 'email' => $uuid.'@example.test', 'password' => 'not-used', 'role' => 'customer_admin', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$run = DB::table('ai_model_runs')->insertGetId(['customer_id' => $customer, 'run_uuid' => (string) Str::uuid(), 'prompt_hash' => str_repeat('a', 64), 'purpose' => 'authoring_proposal', 'provider' => 'fixture', 'model' => 'fixture', 'status' => 'completed', 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
$request = ['customer_id' => $customer, 'request_uuid' => (string) Str::uuid(), 'command_hash' => str_repeat('a', 64), 'mode' => 'generated', 'actor_id' => $actor, 'template_id' => 1, 'activity_id' => 2, 'run_uuid' => (string) Str::uuid(), 'model_run_id' => $run, 'prompt_contract_id' => 'fixture', 'prompt_version' => 1, 'prompt_hash' => str_repeat('a', 64), 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now];
$child = null;
try {
    DB::beginTransaction();
    $requestId = DB::table('ai_authoring_generation_requests')->insertGetId($request);
    $duplicate = $request;
    $duplicate['run_uuid'] = (string) Str::uuid();
    $child = competitor('duplicate', $duplicate, $connectionId);
    DB::commit();
    $result = outcome($child);
    ensure(($result['driver_code'] ?? null) === 1062, 'Duplicate attempt was not rejected after waiting.');
    ensure(DB::table('ai_authoring_generation_requests')->where('customer_id', $customer)->count() === 1, 'Duplicate request persisted.');
    echo "PASS duplicate UUID: observed lock wait -> one committed request\n";

    DB::beginTransaction();
    ensure(DB::table('ai_authoring_generation_requests')->where('id', $requestId)->where('status', 'pending')->update(['status' => 'running']) === 1, 'First claim failed.');
    $child = competitor('claim', ['id' => $requestId], $connectionId);
    DB::commit();
    ensure((outcome($child)['result'] ?? null) === 0, 'Losing claim changed the request.');
    echo "PASS claim: observed lock wait -> losing CAS updates zero rows\n";

    $media = DB::table('media_files')->insertGetId(['customer_id' => $customer, 'uploaded_by' => $actor, 'file_type' => 'document', 'mime_type' => 'application/pdf', 'original_name' => 'fixture.pdf', 'display_name' => 'Fixture', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'test', 'storage_key' => $uuid.'.pdf', 'file_size_bytes' => 1, 'visibility' => 'private', 'status' => 'ready', 'created_at' => $now, 'updated_at' => $now]);
    $proposal = DB::table('ai_authoring_proposals')->insertGetId(['customer_id' => $customer, 'proposal_uuid' => (string) Str::uuid(), 'generation_request_uuid' => $request['request_uuid'], 'item_ordinal' => 1, 'template_id' => 1, 'activity_id' => 2, 'model_run_id' => $run, 'creation_mode' => 'generated', 'kind' => 'summary', 'context_schema_version' => '1', 'context_hash' => str_repeat('a', 64), 'course_context_hash' => str_repeat('a', 64), 'status' => 'pending_review', 'created_by' => $actor, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('ai_authoring_proposal_revisions')->insert(['customer_id' => $customer, 'proposal_id' => $proposal, 'revision_no' => 1, 'origin' => 'generated', 'payload_schema_version' => 1, 'payload' => '{}', 'payload_hash' => str_repeat('a', 64), 'created_by' => $actor, 'created_at' => $now]);
    $source = ['customer_id' => $customer, 'proposal_id' => $proposal, 'media_file_id' => $media, 'source_ordinal' => 1, 'usage_type' => 'document', 'content_type' => 'extracted_text', 'source_fingerprint' => str_repeat('a', 64), 'processing_version' => 'v1', 'locator' => '{"page":1}', 'anchor_hash' => str_repeat('a', 64), 'created_at' => $now];
    DB::table('ai_authoring_proposal_sources')->insert($source);
    DB::beginTransaction();
    DB::table('ai_authoring_proposals')->where('id', $proposal)->update(['sources_sealed_at' => $now, 'source_count' => 1, 'source_set_hash' => str_repeat('a', 64)]);
    $source['source_ordinal'] = 2;
    $source['anchor_hash'] = str_repeat('b', 64);
    $child = competitor('source', $source, $connectionId);
    DB::commit();
    $result = outcome($child);
    ensure(str_contains($result['error'] ?? '', 'LF_PROPOSAL_SOURCES_SEALED'), 'Waiting source insert bypassed seal.');
    ensure(DB::table('ai_authoring_proposal_sources')->where('proposal_id', $proposal)->count() === 1, 'Source set changed.');
    echo "PASS source seal: observed lock wait -> insert rechecks committed seal\n";
    echo 'Server: '.DB::selectOne('SELECT VERSION() AS version')->version."\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if ($child?->isRunning()) {
        $child->stop();
    }
}
