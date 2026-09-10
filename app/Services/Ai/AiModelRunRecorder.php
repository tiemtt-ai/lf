<?php

namespace App\Services\Ai;

use App\Exceptions\AiProviderGateException;
use App\Support\Ai\ProviderGateRequest;
use Illuminate\Support\Facades\DB;

/**
 * Writes the one `ai_model_runs` row that every attempt owns — including the
 * attempts the gate stops before any network call.
 *
 * Nothing sensitive is written here. `metadata` and `safety_metadata` carry
 * classifications and identifiers only; raw source text, transcript, prompt
 * body, PII, signed URLs and credentials never reach this class, because
 * ProviderGateRequest cannot carry them in the first place.
 */
final class AiModelRunRecorder
{
    /**
     * Immutable run provenance per `ai_model_runs` (Amendment 2026-09-09): a
     * second write to the same run may advance status, timing, measurements
     * and metadata, never what the run was.
     */
    /**
     * Allowed status moves. `completed`, `failed` and `cancelled` are terminal:
     * a finished run is evidence that a provider call happened, and rewinding
     * it to `queued` would let a second call reuse the first one's audit row.
     *
     * `blocked` is not terminal in the same way — the same logical attempt may
     * legitimately be retried once a tenant gains the approval that stopped it,
     * so it may move back to `queued`.
     *
     * @var array<string,array<int,string>>
     */
    private const TRANSITIONS = [
        'queued' => ['queued', 'running', 'blocked', 'cancelled'],
        'running' => ['running', 'completed', 'failed', 'cancelled'],
        'blocked' => ['blocked', 'queued'],
        'completed' => ['completed'],
        'failed' => ['failed'],
        'cancelled' => ['cancelled'],
    ];

    private const IMMUTABLE_PROVENANCE = [
        'provider', 'model', 'purpose', 'correlation_id',
        'prompt_template_id', 'prompt_scope_customer_id', 'prompt_version', 'prompt_hash',
    ];

    /** Deterministic run identity so a retried attempt reuses its audit row. */
    public function runUuid(ProviderGateRequest $request): string
    {
        if ($request->runUuid !== null) {
            return $request->runUuid;
        }

        $hex = substr(hash('sha256', 'ai-model-run|'.$request->identity()), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    /**
     * Record an attempt. Idempotent on `(customer_id, run_uuid)`: a retry of
     * the same intent updates its row instead of creating a second one.
     *
     * @param  array<string,mixed>  $extra
     */
    public function record(
        int $customerId,
        ProviderGateRequest $request,
        string $status,
        ?string $errorCode = null,
        array $extra = [],
    ): int {
        $runUuid = $this->runUuid($request);
        $now = now();

        $row = [
            'customer_id' => $customerId,
            'run_uuid' => $runUuid,
            'user_id' => $request->userId,
            'prompt_template_id' => $request->promptTemplateId,
            'prompt_scope_customer_id' => $request->promptTemplateId === null
                ? null
                : ($request->promptScopeCustomerId ?? $customerId),
            'prompt_version' => $request->promptVersion,
            'prompt_hash' => $request->effectivePromptHash(),
            'purpose' => $request->purpose,
            'provider' => $request->provider,
            'model' => $request->model,
            'correlation_id' => $request->correlationId,
            'status' => $status,
            'error_code' => $errorCode,
            'metadata' => json_encode(
                $request->safeProvenance() + ($extra['metadata'] ?? []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'updated_at' => $now,
        ];

        if (in_array($status, ['completed', 'failed', 'blocked', 'cancelled'], true)) {
            $row['completed_at'] = $now;
        }
        if ($status === 'running') {
            $row['started_at'] = $now;
        }
        foreach (['input_tokens', 'output_tokens', 'total_tokens', 'latency_ms', 'estimated_cost', 'currency'] as $key) {
            if (array_key_exists($key, $extra)) {
                $row[$key] = $extra[$key];
            }
        }
        if (array_key_exists('safety_metadata', $extra)) {
            $row['safety_metadata'] = json_encode(
                $extra['safety_metadata'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $existing = DB::table('ai_model_runs')
            ->where('customer_id', $customerId)->where('run_uuid', $runUuid)->first();

        if ($existing !== null) {
            // Provenance is immutable. `run_uuid` is normally derived from
            // exactly these fields, so a disagreement can only mean a caller
            // supplied its own uuid and then changed what the run was for.
            // Rewriting the row would quietly relabel an audit record as
            // belonging to a different provider or purpose.
            foreach (self::IMMUTABLE_PROVENANCE as $column) {
                if ((string) ($existing->{$column} ?? '') !== (string) ($row[$column] ?? '')) {
                    throw new AiProviderGateException('AI_RUN_PROVENANCE_CONFLICT');
                }
                unset($row[$column]);
            }

            $this->assertTransition((string) $existing->status, $status);

            DB::table('ai_model_runs')->where('id', $existing->id)->update($row);

            return (int) $existing->id;
        }

        return (int) DB::table('ai_model_runs')->insertGetId($row + ['created_at' => $now]);
    }

    /**
     * Move a queued run to `running`, exactly once.
     *
     * The guard is a conditional update rather than a read-then-write, so two
     * callers racing on the same run cannot both believe they own it. A second
     * `execute()` on a run that already started is refused, which is what keeps
     * "one network call, one run record" true.
     */
    public function claimForExecution(int $customerId, int $modelRunId): bool
    {
        return DB::table('ai_model_runs')
            ->where('customer_id', $customerId)
            ->where('id', $modelRunId)
            ->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]) === 1;
    }

    /** Transition an existing run without touching its immutable provenance. */
    public function transition(int $customerId, int $modelRunId, string $status, ?string $errorCode = null, array $extra = []): void
    {
        $row = ['status' => $status, 'updated_at' => now()];
        if ($errorCode !== null) {
            $row['error_code'] = $errorCode;
        }
        if ($status === 'running') {
            $row['started_at'] = now();
        }
        if (in_array($status, ['completed', 'failed', 'blocked', 'cancelled'], true)) {
            $row['completed_at'] = now();
        }
        foreach (['input_tokens', 'output_tokens', 'total_tokens', 'latency_ms', 'estimated_cost', 'currency'] as $key) {
            if (array_key_exists($key, $extra)) {
                $row[$key] = $extra[$key];
            }
        }

        $current = DB::table('ai_model_runs')
            ->where('customer_id', $customerId)->where('id', $modelRunId)->value('status');
        if ($current !== null) {
            $this->assertTransition((string) $current, $status);
        }

        // Tenant-scoped like every other write in the codebase: an id alone is
        // not an authorization to touch a row.
        DB::table('ai_model_runs')
            ->where('customer_id', $customerId)
            ->where('id', $modelRunId)
            ->update($row);
    }

    private function assertTransition(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new AiProviderGateException('AI_RUN_TRANSITION_CONFLICT');
        }
    }
}
