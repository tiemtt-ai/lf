<?php

namespace App\Services;

use App\Contracts\Ai\AiProviderAdapter;
use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Exceptions\AiProviderGateException;
use App\Services\Ai\AiModelRunRecorder;
use App\Support\Ai\AllowedExecution;
use App\Support\Ai\ProviderGateDecision;
use App\Support\Ai\ProviderGateRequest;
use App\Support\TenantContext;
use Closure;
use Throwable;

/**
 * The single gate every model/provider adapter passes through before a network
 * call — LF-AI § "Provider execution gate" (Approved 2026-09-08).
 *
 * Order is part of the contract, not an implementation detail:
 *
 *   1. reviewed provider/model/purpose allow-list
 *   2. tenant `ai.external_processing.<provider>.<purpose>` approval
 *   3. Commercial entitlement for the purpose
 *   4. atomic usage/quota reservation
 *   5. safety and data-class policy
 *
 * The order is followed exactly, including reserving quota (step 4) before
 * evaluating safety (step 5). That means a safety refusal happens while a
 * reservation is already held, so the gate releases it on that path; the
 * alternative — reordering the contract locally to avoid the release — would
 * make the implementation and the approved contract disagree.
 *
 * Every path writes exactly one `ai_model_runs` row. A blocked attempt is
 * still an attempt, and an attempt nobody can account for is exactly what
 * ADR-0020 § D2 forbids.
 */
class AiProviderExecutionGate
{
    public function __construct(
        private readonly ExternalProcessingApprovals $approvals,
        private readonly CommercialEntitlements $entitlements,
        private readonly UsageQuotaReserver $quota,
        private readonly AiModelRunRecorder $runs,
    ) {}

    /**
     * Decide, and record the attempt. Never performs a network call itself and
     * never resolves a credential.
     */
    public function authorize(ProviderGateRequest $request): ProviderGateDecision
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiProviderGateException('unauthorized');

        // Steps 1-2 are pure decisions: nothing is reserved or consumed, so a
        // refusal here costs the tenant nothing. They are written out rather
        // than looped because the order is the contract, and an auditor must
        // be able to read it without resolving a dynamic dispatch.
        if (! $this->vocabularyIsClosed($request)) {
            return $this->block($customerId, $request, 'AI_APPROVAL_REQUIRED', 'vocabulary');
        }

        if (! $this->allowListPermits($request)) {
            return $this->block($customerId, $request, 'AI_APPROVAL_REQUIRED', 'allow_list');
        }

        // Step 2. ADR-0018 forbids reading an LF-managed provider as external
        // approval, so `managed` grants no exemption: the tenant setting is
        // consulted for every provider alike.
        if (! $this->approvals->approves($customerId, $request)) {
            return $this->block($customerId, $request, 'AI_APPROVAL_REQUIRED', 'tenant_approval');
        }

        if (! $this->entitlements->hasActiveEntitlement($customerId, $this->featureKey($request))) {
            return $this->block($customerId, $request, 'AI_QUOTA_EXCEEDED', 'entitlement');
        }

        // The audit row is written before anything is reserved. Reserving
        // first leaves a window where a crash produces consumed quota that no
        // run record accounts for — the one state nobody can reconcile from
        // either side.
        $modelRunId = $this->runs->record($customerId, $request, 'queued');

        $reservation = $this->quota->reserve(
            $customerId,
            $modelRunId,
            $this->runs->runUuid($request),
            $this->featureKey($request),
            $request->usageType,
            $request->quotaQuantity,
            $request->quotaUnit,
        );

        if ($reservation === null) {
            return $this->block($customerId, $request, 'AI_QUOTA_EXCEEDED', 'quota');
        }

        // Step 5 runs last, as the contract orders it. Because it runs after a
        // reservation exists, a refusal here must hand the quota back — a
        // safety refusal is not a reason to charge the tenant.
        $safety = $this->safetyVerdict($request);
        if ($safety['allowed'] !== true) {
            $this->quota->release($reservation);

            return $this->block($customerId, $request, 'AI_SAFETY_BLOCKED', 'safety', [
                'safety_metadata' => $safety['evidence'],
            ]);
        }

        return ProviderGateDecision::allowed(new AllowedExecution(
            $customerId,
            $modelRunId,
            $this->runs->runUuid($request),
            $request,
            $reservation,
        ));
    }

    /**
     * Run an adapter behind the gate. The factory is invoked only after every
     * step passed, so this is the only supported way to reach a provider — and
     * the adapter does not even exist until the gate says yes.
     *
     * The reservation records both sides of the provider boundary. Generic
     * expiry is safe only before execution starts; once marked executing,
     * provider-aware reconciliation must decide whether usage occurred.
     */
    public function execute(ProviderGateRequest $request, Closure $adapterFactory): ProviderGateDecision
    {
        $decision = $this->authorize($request);
        if (! $decision->allowed) {
            return $decision;
        }

        $execution = $decision->execution;

        // Claimed atomically: a run may leave `queued` once, so a second
        // execute() on the same attempt cannot make a second provider call
        // hide behind the first one's audit row.
        if (! $this->runs->claimForExecution($customerId = $execution->customerId, $execution->modelRunId)) {
            // Deliberately no release. Reservation is idempotent on the attempt
            // identity, so this hold is the same row the winning caller is
            // using: releasing it would either refund a hold that is about to
            // cross the provider boundary, or be refused because the winner
            // already marked it executing. The loser owns nothing here — the
            // winner settles.
            throw new AiProviderGateException('AI_RUN_ALREADY_EXECUTED');
        }

        $crossedProviderBoundary = false;

        try {
            // Construction is inside the protected boundary because credential
            // resolution commonly happens there and may itself throw.
            $adapter = $adapterFactory();

            if (! $adapter instanceof AiProviderAdapter
                || $adapter->provider() !== $request->provider
                || ! $adapter->supportsModel($request->model)) {
                throw new AiProviderGateException('AI_ADAPTER_MISMATCH');
            }

            // Persist immediately before the adapter crosses the provider
            // boundary. Factory/validation failures are still provably pre-call
            // and may release; after this point only provider-aware
            // reconciliation may decide whether usage occurred.
            $this->quota->markExecuting($execution->reservation);
            $crossedProviderBoundary = true;
            $measurements = $adapter->execute($execution);
        } catch (Throwable $exception) {
            // Release only what provably never reached a provider. Leaving the
            // ledger to refuse a post-boundary release would also work, but it
            // would make correctness depend on an implementation detail the
            // interface cannot enforce. The caller knows which side of the
            // boundary it failed on, so the caller decides here; anything past
            // the boundary is left for provider-aware reconciliation.
            if (! $crossedProviderBoundary) {
                $this->quota->release($execution->reservation);
            }
            $errorCode = $exception instanceof AiProviderGateException
                && $exception->errorCode === 'AI_ADAPTER_MISMATCH'
                    ? 'AI_ADAPTER_MISMATCH'
                    : 'AI_PROVIDER_CALL_FAILED';
            $this->runs->transition($customerId, $execution->modelRunId, 'failed', $errorCode);

            // The original exception is deliberately not re-thrown and not
            // chained. A provider SDK routinely puts the request it was given —
            // and sometimes the key it was given — into the message, and a
            // chained previous keeps that string alive in every log and stack
            // trace. Callers get the stable code; an adapter that wants
            // diagnostics must redact them itself before surfacing them.
            throw new AiProviderGateException($errorCode);
        }

        try {
            $this->quota->markSettling($execution->reservation);
            $actualQuantity = (float) ($measurements['quota_quantity'] ?? $request->quotaQuantity);

            // Settle the true quantity first, even when it overshoots the hold.
            // The provider already consumed it; refusing to record it would
            // under-report Usage, which is Source Of Truth, and would strand the
            // hold with no legal terminal state. Commercial commits it as
            // `committed_over_limit` and enforces the breach on the next
            // reservation — the producer still learns it overshot, below.
            $this->quota->commit($execution->reservation, $actualQuantity);

            if ($actualQuantity > $execution->reservation->quantity) {
                $this->runs->transition($customerId, $execution->modelRunId, 'failed', 'AI_QUOTA_RESERVATION_EXCEEDED');

                throw new AiProviderGateException('AI_QUOTA_RESERVATION_EXCEEDED');
            }
        } catch (Throwable $exception) {
            // The provider call already happened. Do not release and undercount
            // it: keep the reservation for Commercial reconciliation.
            $errorCode = $exception instanceof AiProviderGateException
                && $exception->errorCode === 'AI_QUOTA_RESERVATION_EXCEEDED'
                    ? 'AI_QUOTA_RESERVATION_EXCEEDED'
                    : 'AI_QUOTA_COMMIT_FAILED';
            if ($errorCode === 'AI_QUOTA_COMMIT_FAILED') {
                $this->runs->transition($customerId, $execution->modelRunId, 'failed', $errorCode);
            }

            throw new AiProviderGateException($errorCode);
        }
        $this->runs->transition($customerId, $execution->modelRunId, 'completed', null, $this->safeMeasurements($measurements));

        return $decision;
    }

    /** @param array<string,mixed> $extra */
    private function block(int $customerId, ProviderGateRequest $request, string $code, string $step, array $extra = []): ProviderGateDecision
    {
        $modelRunId = $this->runs->record($customerId, $request, 'blocked', $code, [
            'metadata' => ['blocked_step' => $step],
        ] + $extra);

        return ProviderGateDecision::blocked($modelRunId, $this->runs->runUuid($request), $code, $step);
    }

    /** Every value must come from a closed vocabulary before anything else. */
    private function vocabularyIsClosed(ProviderGateRequest $request): bool
    {
        $classes = $request->normalizedDataClasses();

        return $classes !== []
            && in_array($request->purpose, (array) config('ai.purposes', []), true)
            && in_array($request->executionRegion, (array) config('ai.regions', []), true)
            && in_array($request->retentionClass, (array) config('ai.retention_classes', []), true)
            && array_diff($classes, (array) config('ai.data_classes', [])) === [];
    }

    /** Step 1 — reviewed allow-list. An absent provider is a denial. */
    private function allowListPermits(ProviderGateRequest $request): bool
    {
        $provider = config("ai.providers.{$request->provider}");
        if (! is_array($provider)) {
            return false;
        }

        return in_array($request->model, (array) ($provider['models'] ?? []), true)
            && in_array($request->purpose, (array) ($provider['purposes'] ?? []), true)
            && in_array($request->executionRegion, (array) ($provider['regions'] ?? []), true)
            && in_array($request->retentionClass, (array) ($provider['retention_classes'] ?? []), true)
            && array_diff($request->normalizedDataClasses(), (array) ($provider['data_classes'] ?? [])) === [];
    }

    /**
     * Step 5 — safety and data-class policy for this purpose.
     *
     * Returns the reason as well as the verdict, so a refusal can be audited
     * later without re-deriving it. The evidence names *classifications* only —
     * which data class was refused, which retention ceiling applied — never the
     * payload that carried them.
     *
     * @return array{allowed:bool,evidence:array<string,mixed>}
     */
    private function safetyVerdict(ProviderGateRequest $request): array
    {
        $default = (array) config('ai.safety.default', []);
        $policy = (array) config("ai.safety.purposes.{$request->purpose}", []) + $default;

        $refused = array_values(array_intersect(
            $request->normalizedDataClasses(),
            (array) ($policy['forbidden_data_classes'] ?? [])
        ));
        if ($refused !== []) {
            return ['allowed' => false, 'evidence' => [
                'policy' => 'forbidden_data_classes',
                'purpose' => $request->purpose,
                'refused_data_classes' => $refused,
            ]];
        }

        $ceiling = $policy['max_retention_class'] ?? null;
        if ($ceiling === null) {
            return ['allowed' => true, 'evidence' => []];
        }

        $order = (array) config('ai.retention_classes', []);
        $requested = array_search($request->retentionClass, $order, true);
        $allowed = array_search($ceiling, $order, true);

        if ($requested === false || $allowed === false || $requested > $allowed) {
            return ['allowed' => false, 'evidence' => [
                'policy' => 'max_retention_class',
                'purpose' => $request->purpose,
                'requested_retention_class' => $request->retentionClass,
                'max_retention_class' => $ceiling,
            ]];
        }

        return ['allowed' => true, 'evidence' => []];
    }

    private function featureKey(ProviderGateRequest $request): string
    {
        return (string) config("ai.usage_feature_keys.{$request->purpose}", 'ai_'.$request->purpose);
    }

    /** @param array<string,mixed> $measurements @return array<string,mixed> */
    private function safeMeasurements(array $measurements): array
    {
        return array_intersect_key($measurements, array_flip([
            'input_tokens', 'output_tokens', 'total_tokens', 'latency_ms', 'estimated_cost', 'currency',
        ]));
    }
}
