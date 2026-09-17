<?php

namespace App\Services;

use App\Exceptions\CourseAuthoringContextException;
use App\Services\Ai\AuthoringProposalRecords;
use Illuminate\Support\Facades\DB;

/**
 * Step 7 generation request recovery (contract § P2 recovery and receipt
 * coherence).
 *
 * A pending request may be cancelled by an active admin, but only while it is
 * still pending — before any provider claim. A running request is never
 * assumed abandoned because time passed: recovery needs the operator's
 * assertion that the old worker is stopped, then reads the SAME Model Run. No
 * retained provider output exists, so a completed run without committed items
 * fails as proposal_generation_output_unavailable. Nothing is re-executed and
 * no usage is refunded here.
 */
final class AiAuthoringRequestRecoveryService
{
    public function __construct(
        private readonly AuthoringProposalRecords $records,
        private readonly CourseAuthoringContextService $course,
    ) {}

    /** @return array<string,mixed> */
    public function cancelPending(int $adminId, string $requestUuid): array
    {
        $request = $this->records->request($requestUuid);
        if ($request === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            return DB::transaction(function () use ($adminId, $request): array {
                // Template -> request, the same order as claim; whichever commits first wins.
                $context = $this->course->proposalContext($adminId, (int) $request->activity_id, true);
                if ($context['actor_role'] !== 'admin') {
                    return $this->records->outcome('proposal_forbidden');
                }
                $locked = $this->records->request($request->request_uuid, true);
                if ($locked->status !== 'pending') {
                    return $this->records->outcome('proposal_revision_conflict', ['request_status' => $locked->status]);
                }
                $this->fail($locked, 'proposal_generation_cancelled_before_execution');

                return $this->records->outcome(null, ['request_status' => 'failed']);
            });
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }
    }

    /** @return array<string,mixed> */
    public function recoverRunning(int $adminId, string $requestUuid, bool $workerStopped): array
    {
        $request = $this->records->request($requestUuid);
        if ($request === null) {
            return $this->records->outcome('proposal_not_found');
        }
        if (! $workerStopped) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'worker_not_confirmed_stopped']);
        }
        try {
            return DB::transaction(function () use ($adminId, $request): array {
                $context = $this->course->proposalContext($adminId, (int) $request->activity_id, true);
                if ($context['actor_role'] !== 'admin') {
                    return $this->records->outcome('proposal_forbidden');
                }
                $locked = $this->records->request($request->request_uuid, true);
                if ($locked->status !== 'running') {
                    return $this->records->outcome('proposal_revision_conflict', ['request_status' => $locked->status]);
                }
                $run = DB::table('ai_model_runs')->where('customer_id', $locked->customer_id)
                    ->where('run_uuid', $locked->run_uuid)->lockForUpdate()->first(['id', 'status', 'error_code']);

                $code = match ($run?->status) {
                    null, 'queued', 'blocked', 'cancelled' => 'proposal_generation_cancelled_before_execution',
                    'failed' => (string) ($run->error_code ?? 'AI_PROVIDER_CALL_FAILED'),
                    'completed' => 'proposal_generation_output_unavailable',
                    // Execution outcome unknown: provider-aware reconciliation decides, not us.
                    default => null,
                };
                if ($code === null) {
                    return $this->records->outcome('proposal_revision_conflict', ['request_status' => 'running', 'run_status' => $run->status]);
                }
                $this->fail($locked, $code, $run?->id === null ? null : (int) $run->id);

                return $this->records->outcome(null, ['request_status' => 'failed', 'request_error_code' => $code]);
            });
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }
    }

    private function fail(object $request, string $code, ?int $runId = null): void
    {
        $now = $this->records->now();
        $update = ['status' => 'failed', 'error_code' => mb_substr($code, 0, 100), 'completed_at' => $now, 'updated_at' => $now];
        if ($request->model_run_id === null && $runId !== null) {
            $update['model_run_id'] = $runId;
        }
        DB::table('ai_authoring_generation_requests')->where('id', $request->id)->update($update);
    }
}
