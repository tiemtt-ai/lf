<?php

namespace Tests\Feature;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Exceptions\AiProviderGateException;
use App\Services\Ai\AiModelRunRecorder;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\Ai\UnavailableCommercialEntitlements;
use App\Services\Ai\UnavailableTenantSettings;
use App\Services\Ai\UnavailableUsageQuotaReserver;
use App\Services\AiProviderExecutionGate;
use App\Support\Ai\ProviderGateRequest;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Ai\FakeCommercialEntitlements;
use Tests\Support\Ai\FakeTenantSettings;
use Tests\Support\Ai\FakeUsageQuotaReserver;
use Tests\Support\Ai\SpyProviderAdapter;
use Tests\TestCase;

class AiProviderExecutionGateTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenantSettings $settings;

    private FakeCommercialEntitlements $entitlements;

    private FakeUsageQuotaReserver $quota;

    protected function setUp(): void
    {
        parent::setUp();

        // A reviewed allow-list with exactly one approved combination, so any
        // deviation in a test is a real denial rather than a missing fixture.
        config()->set('ai.providers', [
            'approved-provider' => [
                'managed' => false,
                'models' => ['approved-model'],
                'purposes' => ['knowledge_embedding'],
                'regions' => ['lf_managed'],
                'retention_classes' => ['none', 'transient'],
                'data_classes' => ['derived_text', 'derived_structure'],
            ],
        ]);

        $this->settings = new FakeTenantSettings;
        $this->entitlements = new FakeCommercialEntitlements;
        $this->quota = new FakeUsageQuotaReserver(10.0);

        $this->app->instance(TenantSettingSource::class, $this->settings);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->instance(CommercialEntitlements::class, $this->entitlements);
        $this->app->instance(UsageQuotaReserver::class, $this->quota);
    }

    public function test_fail_closed_defaults_block_when_no_saas_authority_is_bound(): void
    {
        // The shipped bindings, not the test fakes: nothing is approved, so a
        // fully well-formed request must still be refused.
        $this->app->bind(TenantSettingSource::class, UnavailableTenantSettings::class);
        $this->app->bind(CommercialEntitlements::class, UnavailableCommercialEntitlements::class);
        $this->app->bind(UsageQuotaReserver::class, UnavailableUsageQuotaReserver::class);
        $customerId = $this->tenant('default-closed');

        $decision = $this->gate()->authorize($this->request());

        $this->assertFalse($decision->allowed);
        $this->assertSame('AI_APPROVAL_REQUIRED', $decision->errorCode);
        $this->assertSame('tenant_approval', $decision->blockedStep);
        $this->assertSame('blocked', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('status'));
    }

    public function test_missing_allow_list_blocks_before_any_provider_call(): void
    {
        $this->tenant('no-allow-list');
        config()->set('ai.providers', []);
        $adapter = new SpyProviderAdapter;

        $decision = $this->gate()->execute($this->request(), fn () => $adapter);

        $this->assertFalse($decision->allowed);
        $this->assertSame('AI_APPROVAL_REQUIRED', $decision->errorCode);
        $this->assertSame('allow_list', $decision->blockedStep);
        $this->assertSame(0, $adapter->calls);
        $this->assertFalse($adapter->credentialResolved);
        $this->assertSame(0, $this->quota->reserveCalls, 'An unapproved provider must never touch tenant quota.');
    }

    #[DataProvider('tenantApprovalMismatches')]
    public function test_tenant_approval_must_match_exactly(array $approval, array $overrides): void
    {
        $customerId = $this->tenant('approval-mismatch');
        $this->settings->approve($customerId, 'ai.external_processing.approved-provider.knowledge_embedding', $approval);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $adapter = new SpyProviderAdapter;

        $decision = $this->gate()->execute($this->request($overrides), fn () => $adapter);

        $this->assertFalse($decision->allowed);
        $this->assertSame('AI_APPROVAL_REQUIRED', $decision->errorCode);
        $this->assertSame(0, $adapter->calls);
        $this->assertFalse($adapter->credentialResolved);
    }

    public static function tenantApprovalMismatches(): array
    {
        $full = [
            'approved' => true,
            'data_classes' => ['derived_text'],
            'execution_regions' => ['lf_managed'],
            'retention_classes' => ['transient'],
        ];

        return [
            'not approved' => [['approved' => false] + $full, []],
            'data class not approved' => [$full, ['dataClasses' => ['derived_structure']]],
            'region not approved' => [['execution_regions' => ['eu']] + $full, []],
            'retention not approved' => [['retention_classes' => ['none']] + $full, []],
            'no setting at all' => [[], []],
        ];
    }

    public function test_missing_entitlement_blocks_with_quota_exceeded(): void
    {
        $customerId = $this->tenant('no-entitlement');
        $this->approveTenant($customerId);
        $adapter = new SpyProviderAdapter;

        $decision = $this->gate()->execute($this->request(), fn () => $adapter);

        $this->assertSame('AI_QUOTA_EXCEEDED', $decision->errorCode);
        $this->assertSame('entitlement', $decision->blockedStep);
        $this->assertSame(0, $adapter->calls);
        $this->assertSame(0, $this->quota->reserveCalls);
    }

    public function test_exhausted_quota_blocks_with_quota_exceeded(): void
    {
        $customerId = $this->tenant('no-quota');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $this->quota = new FakeUsageQuotaReserver(0.0);
        $this->app->instance(UsageQuotaReserver::class, $this->quota);
        $adapter = new SpyProviderAdapter;

        $decision = $this->gate()->execute($this->request(), fn () => $adapter);

        $this->assertSame('AI_QUOTA_EXCEEDED', $decision->errorCode);
        $this->assertSame('quota', $decision->blockedStep);
        $this->assertSame(0, $adapter->calls);
        $this->assertFalse($adapter->credentialResolved);
    }

    public function test_safety_policy_blocks_and_hands_the_reservation_back(): void
    {
        $customerId = $this->tenant('safety');
        // The tenant approved personal data for this provider; purpose-level
        // safety policy still refuses it, and must win.
        $this->settings->approve($customerId, 'ai.external_processing.approved-provider.knowledge_embedding', [
            'approved' => true,
            'data_classes' => ['derived_text', 'personal_data'],
            'execution_regions' => ['lf_managed'],
            'retention_classes' => ['transient'],
        ]);
        config()->set('ai.providers.approved-provider.data_classes', ['derived_text', 'personal_data']);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $adapter = new SpyProviderAdapter;

        $before = $this->quota->balance();
        $decision = $this->gate()->execute($this->request(['dataClasses' => ['personal_data']]), fn () => $adapter);

        $this->assertSame('AI_SAFETY_BLOCKED', $decision->errorCode);
        $this->assertSame('safety', $decision->blockedStep);
        $this->assertSame(0, $adapter->calls);
        $this->assertSame(1, $this->quota->releaseCalls, 'A safety refusal must not charge the tenant.');
        $this->assertSame($before, $this->quota->balance());

        // The refusal must be auditable: which policy refused, and which
        // classification tripped it — never the payload itself.
        $safety = json_decode(
            DB::table('ai_model_runs')->where('customer_id', $customerId)->value('safety_metadata'),
            true
        );
        $this->assertSame('forbidden_data_classes', $safety['policy']);
        $this->assertSame(['personal_data'], $safety['refused_data_classes']);
        $this->assertSame('knowledge_embedding', $safety['purpose']);
    }

    public function test_a_fully_approved_request_reaches_the_adapter_and_only_then_resolves_a_credential(): void
    {
        $customerId = $this->tenant('allowed');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $adapter = new SpyProviderAdapter;

        $decision = $this->gate()->execute($this->request(), fn () => $adapter);

        $this->assertTrue($decision->allowed);
        $this->assertSame(1, $adapter->calls);
        $this->assertTrue($adapter->credentialResolved);
        $this->assertSame($customerId, $adapter->lastExecution->customerId);

        $run = DB::table('ai_model_runs')->where('customer_id', $customerId)->first();
        $this->assertSame('completed', $run->status);
        $this->assertSame(15, (int) $run->total_tokens);
        $this->assertSame(1, $this->quota->commitCalls);
    }

    public function test_every_attempt_creates_exactly_one_run_with_safe_provenance(): void
    {
        $customerId = $this->tenant('one-run');
        $request = $this->request();

        $this->gate()->authorize($request);
        $this->gate()->authorize($request);

        $runs = DB::table('ai_model_runs')->where('customer_id', $customerId)->get();
        $this->assertCount(1, $runs, 'A retried attempt reuses its audit row.');

        $run = $runs->first();
        $this->assertSame('blocked', $run->status);
        $this->assertSame('AI_APPROVAL_REQUIRED', $run->error_code);
        $this->assertSame('approved-provider', $run->provider);
        $this->assertSame('knowledge_embedding', $run->purpose);
        $this->assertNotEmpty($run->prompt_hash);
        $this->assertSame('request_envelope', json_decode($run->metadata, true)['prompt_hash_basis']);
    }

    public function test_no_secret_or_payload_reaches_the_run_record(): void
    {
        $customerId = $this->tenant('no-leak');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $this->gate()->execute($this->request(), fn () => new SpyProviderAdapter);

        $run = DB::table('ai_model_runs')->where('customer_id', $customerId)->first();
        $serialized = json_encode($run);

        foreach ([
            'sk-', 'Bearer ', 'X-Api-Key', 'password',
            'https://', 'X-Amz-Signature',           // signed delivery URLs
            'Nguyễn Văn A', 'chunk text', 'transcript',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        // A completed run carries no safety evidence because nothing was
        // refused; the safety test above proves a refusal does record it.
        $this->assertNull($run->safety_metadata);
    }

    public function test_one_tenant_cannot_use_another_tenants_approval_entitlement_or_run(): void
    {
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');

        // Everything is granted to A only.
        $this->approveTenant($tenantA);
        $this->entitlements->grant($tenantA, 'ai_knowledge_embedding');

        TenantContext::set((object) ['id' => $tenantB]);
        $adapter = new SpyProviderAdapter;
        $decision = $this->gate()->execute($this->request(), fn () => $adapter);

        $this->assertFalse($decision->allowed);
        $this->assertSame('AI_APPROVAL_REQUIRED', $decision->errorCode);
        $this->assertSame(0, $adapter->calls);
        $this->assertSame(0, DB::table('ai_model_runs')->where('customer_id', $tenantA)->count());
        $this->assertSame(1, DB::table('ai_model_runs')->where('customer_id', $tenantB)->count());
    }

    public function test_concurrent_reservations_cannot_oversubscribe_the_quota(): void
    {
        $customerId = $this->tenant('concurrent');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $this->quota = new FakeUsageQuotaReserver(1.0);
        $this->app->instance(UsageQuotaReserver::class, $this->quota);

        $second = null;
        // Re-enter the gate from inside the first reservation, which is the
        // interleaving a "read the balance, then call" design would lose to.
        $this->quota->onReserve(function () use (&$second): void {
            $second = $this->gate()->authorize($this->request(['correlationId' => 'second']));
        });

        $first = $this->gate()->authorize($this->request(['correlationId' => 'first']));

        // Which of the two wins is a race and not a guarantee; that exactly
        // one wins, and that the balance never goes negative, is the whole
        // point of reserving atomically instead of reading a balance.
        $allowed = array_filter([$first, $second], fn ($decision): bool => $decision->allowed);
        $blocked = array_filter([$first, $second], fn ($decision): bool => ! $decision->allowed);

        $this->assertCount(1, $allowed);
        $this->assertCount(1, $blocked);
        $this->assertSame('AI_QUOTA_EXCEEDED', array_values($blocked)[0]->errorCode);
        $this->assertSame('quota', array_values($blocked)[0]->blockedStep);
        $this->assertSame(0.0, $this->quota->balance());
        $this->assertSame(2, $this->quota->reserveCalls);
    }

    public function test_provider_failure_keeps_the_executing_reservation_and_records_a_stable_code(): void
    {
        $customerId = $this->tenant('provider-fail');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $before = $this->quota->balance();
        $adapter = new SpyProviderAdapter(new RuntimeException('provider said: secret-token sk-live-123'));

        try {
            $this->gate()->execute($this->request(), fn () => $adapter);
            $this->fail('The provider failure must surface to the caller.');
        } catch (AiProviderGateException $exception) {
            // The caller learns that the call failed and nothing else: the
            // provider's own message carried a key, and must not travel.
            $this->assertSame('AI_PROVIDER_CALL_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('sk-live-123', $exception->getMessage());
            $this->assertNull($exception->getPrevious(), 'Chaining would keep the leaked message alive in traces.');
        }

        // The gate does not even attempt a release past the provider boundary:
        // the ledger would refuse one, but correctness must not depend on that.
        $this->assertSame(0, $this->quota->releaseCalls);
        $this->assertLessThan($before, $this->quota->balance(), 'An ambiguous provider call must not be refunded.');
        $this->assertSame(0, $this->quota->commitCalls);

        $reservationId = array_key_first($this->quota->reservations);
        $this->quota->expire($reservationId);
        $this->assertSame(0, $this->quota->reconcileExpired($customerId), 'Generic expiry must ignore an executing reservation.');

        $run = DB::table('ai_model_runs')->where('customer_id', $customerId)->first();
        $this->assertSame('failed', $run->status);
        $this->assertSame('AI_PROVIDER_CALL_FAILED', $run->error_code);
        $this->assertStringNotContainsString('sk-live-123', json_encode($run));
    }

    public function test_adapter_factory_failure_is_sanitized_and_releases_the_reservation(): void
    {
        $customerId = $this->tenant('factory-fail');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $before = $this->quota->balance();

        try {
            $this->gate()->execute(
                $this->request(),
                fn () => throw new RuntimeException('credential sk-factory-secret'),
            );
            $this->fail('Adapter construction failure must surface as a safe code.');
        } catch (AiProviderGateException $exception) {
            $this->assertSame('AI_PROVIDER_CALL_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('sk-factory-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame($before, $this->quota->balance());
        $this->assertSame(1, $this->quota->releaseCalls);
        $this->assertSame('failed', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('status'));
    }

    public function test_quota_commit_failure_keeps_the_reservation_for_reconciliation(): void
    {
        $customerId = $this->tenant('commit-fail');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $this->quota->failCommitWith(new RuntimeException('ledger credential sk-ledger-secret'));
        $before = $this->quota->balance();

        try {
            $this->gate()->execute($this->request(), fn () => new SpyProviderAdapter);
            $this->fail('A failed quota commit must not report a completed Run.');
        } catch (AiProviderGateException $exception) {
            $this->assertSame('AI_QUOTA_COMMIT_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('sk-ledger-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertLessThan($before, $this->quota->balance());
        $this->assertSame(0, $this->quota->releaseCalls, 'A provider call that happened must not be refunded.');
        $this->assertSame('AI_QUOTA_COMMIT_FAILED', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('error_code'));

        $reservationId = array_key_first($this->quota->reservations);
        $this->quota->expire($reservationId);
        $this->assertSame(0, $this->quota->reconcileExpired($customerId), 'Generic expiry must never refund provider usage awaiting settlement.');
        $this->assertLessThan($before, $this->quota->balance());
    }

    public function test_usage_above_the_reserved_ceiling_is_not_truncated_or_refunded(): void
    {
        $customerId = $this->tenant('reservation-overage');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $before = $this->quota->balance();
        $adapter = new SpyProviderAdapter(measurements: ['quota_quantity' => 2.0]);

        try {
            $this->gate()->execute($this->request(['quotaQuantity' => 1.0]), fn () => $adapter);
            $this->fail('Usage above the reserved ceiling must fail closed.');
        } catch (AiProviderGateException $exception) {
            $this->assertSame('AI_QUOTA_RESERVATION_EXCEEDED', $exception->errorCode);
        }

        // The provider consumed 2.0 against a 1.0 hold. That measurement is
        // recorded in full — truncating it to fit the hold would under-report
        // Usage, which is Source Of Truth — and the breach shows up as a
        // distinct terminal status rather than a lost number.
        $reservation = $this->quota->reservations[array_key_first($this->quota->reservations)];
        $this->assertSame(1, $this->quota->commitCalls);
        $this->assertSame(2.0, $reservation['committed_quantity'], 'Actual usage must not be truncated.');
        $this->assertSame('committed_over_limit', $reservation['status']);
        $this->assertSame(0, $this->quota->releaseCalls);
        $this->assertLessThan($before, $this->quota->balance());

        // The hold reaches a terminal state instead of stranding: the previous
        // design left it in `settling` with no legal exit, because the schema
        // capped committed_quantity at the reservation.
        $this->assertSame('AI_QUOTA_RESERVATION_EXCEEDED', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('error_code'));
        $this->assertSame(['released' => 0, 'settled' => 0], $this->quota->reconcileUnsettled($customerId), 'A settled overage is not reconciliation work.');
    }

    public function test_a_reservation_abandoned_by_a_crash_is_reclaimed_by_reconciliation(): void
    {
        $customerId = $this->tenant('crash');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $before = $this->quota->balance();

        // authorize() reserves; the process then dies before execute().
        $decision = $this->gate()->authorize($this->request());
        $this->assertTrue($decision->allowed);
        $this->assertLessThan($before, $this->quota->balance());

        $this->quota->expire($decision->execution->reservation->reservationId);
        $reclaimed = $this->quota->reconcileExpired($customerId);

        $this->assertSame(1, $reclaimed);
        $this->assertSame($before, $this->quota->balance());
        $this->assertSame(0, $this->quota->reconcileExpired($customerId), 'Reconciliation must not double-refund.');
    }

    public function test_a_reused_run_uuid_cannot_rewrite_the_provenance_of_an_existing_run(): void
    {
        $customerId = $this->tenant('provenance');
        $runUuid = '22222222-2222-4222-8222-222222222222';

        $this->gate()->authorize($this->request(['runUuid' => $runUuid]));
        $stored = DB::table('ai_model_runs')->where('customer_id', $customerId)->first();
        $this->assertSame('approved-provider', $stored->provider);

        // Same run identity, different provider: rewriting the row would
        // relabel an audit record as belonging to a call that never happened.
        $this->expectException(AiProviderGateException::class);

        try {
            $this->gate()->authorize($this->request([
                'runUuid' => $runUuid,
                'provider' => 'some-other-provider',
            ]));
        } finally {
            $after = DB::table('ai_model_runs')->where('customer_id', $customerId)->first();
            $this->assertSame('approved-provider', $after->provider);
            $this->assertSame(1, DB::table('ai_model_runs')->where('customer_id', $customerId)->count());
        }
    }

    public function test_a_second_execute_cannot_hide_behind_the_first_calls_run_record(): void
    {
        $customerId = $this->tenant('one-call-one-run');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $request = $this->request();
        $first = new SpyProviderAdapter;
        $second = new SpyProviderAdapter;

        $this->gate()->execute($request, fn () => $first);

        try {
            $this->gate()->execute($request, fn () => $second);
            $this->fail('A completed run must not be reused for a second provider call.');
        } catch (AiProviderGateException $exception) {
            // Refused at the audit layer, before quota is even touched: a
            // finished run cannot be rewound to `queued` to shelter a second
            // call. `AI_RUN_ALREADY_EXECUTED` remains the narrower guard for two
            // callers racing while the run is still queued.
            $this->assertSame('AI_RUN_TRANSITION_CONFLICT', $exception->errorCode);
        }

        $this->assertSame(1, $first->calls);
        $this->assertSame(0, $second->calls, 'The second call must never reach a provider.');
        $this->assertSame(1, DB::table('ai_model_runs')->where('customer_id', $customerId)->count());
        $this->assertSame('completed', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('status'));
        $this->assertSame(1, $this->quota->commitCalls);
        $this->assertSame(1, $this->quota->reserveCalls, 'The refused attempt must not reserve again.');
    }

    public function test_two_callers_racing_on_one_queued_run_yield_a_single_provider_call(): void
    {
        $customerId = $this->tenant('race');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $decision = $this->gate()->authorize($this->request());
        $recorder = $this->app->make(AiModelRunRecorder::class);

        // Both callers saw the same queued run; only one may leave `queued`.
        $this->assertTrue($recorder->claimForExecution($customerId, $decision->execution->modelRunId));
        $this->assertFalse($recorder->claimForExecution($customerId, $decision->execution->modelRunId));
    }

    public function test_an_adapter_for_a_different_provider_is_refused_before_it_is_called(): void
    {
        $customerId = $this->tenant('adapter-mismatch');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        // Approved as `approved-provider`, but the adapter talks to someone else.
        $impostor = new SpyProviderAdapter(null, 'some-other-provider');

        try {
            $this->gate()->execute($this->request(), fn () => $impostor);
            $this->fail('An adapter must not serve a provider the gate never approved.');
        } catch (AiProviderGateException $exception) {
            $this->assertSame('AI_ADAPTER_MISMATCH', $exception->errorCode);
        }

        $this->assertSame(0, $impostor->calls);
        $this->assertFalse($impostor->credentialResolved);
        $this->assertSame($this->quota->commitCalls, 0);
        $this->assertSame('failed', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('status'));
    }

    public function test_an_adapter_serving_a_model_that_was_never_approved_is_refused(): void
    {
        $customerId = $this->tenant('model-mismatch');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        // Right provider, wrong model: the allow-list approves a combination,
        // not a vendor, so this is a decision nobody made.
        $wrongModel = new SpyProviderAdapter(null, 'approved-provider', 'some-unreviewed-model');

        try {
            $this->gate()->execute($this->request(), fn () => $wrongModel);
            $this->fail('A model outside the approved combination must be refused.');
        } catch (AiProviderGateException $exception) {
            $this->assertSame('AI_ADAPTER_MISMATCH', $exception->errorCode);
        }

        $this->assertSame(0, $wrongModel->calls);
        $this->assertFalse($wrongModel->credentialResolved);
        $this->assertSame(0, $this->quota->commitCalls);
        $this->assertSame(1, $this->quota->releaseCalls);
    }

    public function test_the_adapter_is_not_even_constructed_until_every_gate_step_passed(): void
    {
        $customerId = $this->tenant('lazy-adapter');
        // Nothing approved: the factory must never run, so a constructor that
        // resolves a credential never gets the chance.
        $constructed = false;

        $decision = $this->gate()->execute($this->request(), function () use (&$constructed): SpyProviderAdapter {
            $constructed = true;

            return new SpyProviderAdapter;
        });

        $this->assertFalse($decision->allowed);
        $this->assertFalse($constructed, 'A refused request must not build a provider adapter.');
    }

    public function test_an_audit_row_exists_before_any_quota_is_reserved(): void
    {
        $customerId = $this->tenant('audit-before-quota');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');

        $runsAtReserveTime = null;
        $this->quota->onReserve(function () use ($customerId, &$runsAtReserveTime): void {
            $runsAtReserveTime = DB::table('ai_model_runs')->where('customer_id', $customerId)->count();
        });

        $this->gate()->authorize($this->request());

        // A crash at this instant must still leave something to reconcile
        // against; consumed quota with no run record is unaccountable.
        $this->assertSame(1, $runsAtReserveTime);
    }

    public function test_prompt_scope_is_part_of_the_immutable_run_identity(): void
    {
        $customerId = $this->tenant('prompt-scope');
        $runUuid = '33333333-3333-4333-8333-333333333333';
        $prompt = ['promptTemplateId' => 900, 'promptVersion' => 1, 'promptHash' => 'sha256:p1'];

        $this->gate()->authorize($this->request($prompt + ['runUuid' => $runUuid, 'promptScopeCustomerId' => 0]));

        // Same run, same template, but re-scoped from the global prompt to a
        // tenant one: that is a different prompt, not an update.
        $this->expectException(AiProviderGateException::class);
        $this->gate()->authorize($this->request($prompt + ['runUuid' => $runUuid, 'promptScopeCustomerId' => $customerId]));
    }

    public function test_a_run_belonging_to_another_tenant_is_never_transitioned(): void
    {
        $tenantA = $this->tenant('transition-a');
        $this->approveTenant($tenantA);
        $this->entitlements->grant($tenantA, 'ai_knowledge_embedding');
        $decision = $this->gate()->authorize($this->request());
        $runId = $decision->execution->modelRunId;

        $recorder = $this->app->make(AiModelRunRecorder::class);
        $tenantB = $this->tenant('transition-b');

        $recorder->transition($tenantB, $runId, 'completed');

        $this->assertSame('queued', DB::table('ai_model_runs')->where('id', $runId)->value('status'));
        $this->assertFalse($recorder->claimForExecution($tenantB, $runId));
        $this->assertTrue($recorder->claimForExecution($tenantA, $runId));
    }

    public function test_a_hold_past_the_provider_boundary_has_a_terminal_state_via_reconciliation(): void
    {
        $customerId = $this->tenant('reconcile-unsettled');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $before = $this->quota->balance();
        $adapter = new SpyProviderAdapter(new RuntimeException('connection refused'));

        try {
            $this->gate()->execute($this->request(), fn () => $adapter);
        } catch (AiProviderGateException) {
            // expected
        }

        $reservationId = array_key_first($this->quota->reservations);
        $this->assertSame('executing', $this->quota->reservations[$reservationId]['status']);

        // Elapsed time alone must never terminate it, however long it sits.
        $this->quota->expire($reservationId);
        $this->assertSame(0, $this->quota->reconcileExpired($customerId));
        $this->assertSame(['released' => 0, 'settled' => 0], $this->quota->reconcileUnsettled($customerId), 'No evidence yet means the hold stays held.');
        $this->assertLessThan($before, $this->quota->balance());

        // Only positive evidence that nothing was consumed releases it, and it
        // lands in a status an auditor can tell apart from a plain release.
        $this->quota->proveNothingConsumed($reservationId);
        $this->assertSame(['released' => 1, 'settled' => 0], $this->quota->reconcileUnsettled($customerId));
        $this->assertSame('reconciled_released', $this->quota->reservations[$reservationId]['status']);
        $this->assertSame($before, $this->quota->balance());
        $this->assertSame(['released' => 0, 'settled' => 0], $this->quota->reconcileUnsettled($customerId), 'Reconciliation must not double-refund.');
    }

    public function test_the_gate_never_attempts_a_release_past_the_provider_boundary(): void
    {
        $customerId = $this->tenant('no-illegal-release');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        // The fake refuses a post-boundary release outright, mirroring the
        // Commercial contract. If the gate ever attempted one, that refusal
        // would surface here instead of a clean AI_PROVIDER_CALL_FAILED.
        $adapter = new SpyProviderAdapter(new RuntimeException('provider exploded'));

        try {
            $this->gate()->execute($this->request(), fn () => $adapter);
            $this->fail('The provider failure must surface.');
        } catch (AiProviderGateException $exception) {
            $this->assertSame('AI_PROVIDER_CALL_FAILED', $exception->errorCode);
        }

        $this->assertSame(0, $this->quota->releaseCalls);
    }

    public function test_a_caller_that_loses_the_claim_race_never_touches_the_shared_reservation(): void
    {
        $customerId = $this->tenant('claim-race-hold');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $recorder = $this->app->make(AiModelRunRecorder::class);
        $adapter = new SpyProviderAdapter;

        // Interleave a concurrent winner: it claims the run while this caller
        // is still inside its own reservation, which is the only window where
        // the claim can fail. Reservation is idempotent on the attempt, so both
        // callers are pointing at one hold.
        $this->quota->onReserve(function () use ($customerId, $recorder): void {
            $runId = DB::table('ai_model_runs')->where('customer_id', $customerId)->value('id');
            $recorder->claimForExecution($customerId, (int) $runId);
        });

        try {
            $this->gate()->execute($this->request(), fn () => $adapter);
            $this->fail('The losing caller must not proceed to a provider.');
        } catch (AiProviderGateException $exception) {
            $this->assertSame('AI_RUN_ALREADY_EXECUTED', $exception->errorCode);
        }

        // The loser owns nothing here: releasing would refund a hold the winner
        // is about to use, or be refused once the winner marks it executing.
        $this->assertSame(0, $this->quota->releaseCalls);
        $this->assertSame(0, $adapter->calls);
        $this->assertFalse($adapter->credentialResolved);

        $reservationId = array_key_first($this->quota->reservations);
        $this->assertSame('reserved', $this->quota->reservations[$reservationId]['status'], 'The shared hold must survive intact for the winner.');
        $this->assertSame('running', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('status'));
    }

    public function test_reconciliation_settles_consumption_whose_producer_died_before_settling(): void
    {
        $customerId = $this->tenant('reconcile-settle');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $adapter = new SpyProviderAdapter(new RuntimeException('killed mid-flight'));

        try {
            $this->gate()->execute($this->request(), fn () => $adapter);
        } catch (AiProviderGateException) {
            // The producer died after crossing the boundary.
        }

        $reservationId = array_key_first($this->quota->reservations);
        $this->assertSame('executing', $this->quota->reservations[$reservationId]['status']);

        // Reconciliation later proves the provider *did* consume 1.0. A
        // release-only path would hand the quota back and the measurement would
        // never reach Usage — the Source Of Truth — with nothing to signal it.
        $this->quota->proveConsumed($reservationId, 1.0);
        $outcome = $this->quota->reconcileUnsettled($customerId);

        $this->assertSame(['released' => 0, 'settled' => 1], $outcome);
        $this->assertSame('committed', $this->quota->reservations[$reservationId]['status']);
        $this->assertSame(1.0, $this->quota->reservations[$reservationId]['committed_quantity']);
        $this->assertSame(0, $this->quota->releaseCalls, 'Consumed usage must never be refunded.');
    }

    public function test_reconciliation_settles_an_overage_it_discovers_after_the_fact(): void
    {
        $customerId = $this->tenant('reconcile-overage');
        $this->approveTenant($customerId);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $adapter = new SpyProviderAdapter(new RuntimeException('killed mid-flight'));

        try {
            $this->gate()->execute($this->request(['quotaQuantity' => 1.0]), fn () => $adapter);
        } catch (AiProviderGateException) {
            // expected
        }

        $reservationId = array_key_first($this->quota->reservations);
        // The provider consumed more than the hold. Recording the true amount
        // matters more than making it fit.
        $this->quota->proveConsumed($reservationId, 2.5);

        $this->assertSame(['released' => 0, 'settled' => 1], $this->quota->reconcileUnsettled($customerId));
        $this->assertSame('committed_over_limit', $this->quota->reservations[$reservationId]['status']);
        $this->assertSame(2.5, $this->quota->reservations[$reservationId]['committed_quantity']);
    }

    // ---- fixtures -------------------------------------------------------

    private function gate(): AiProviderExecutionGate
    {
        return $this->app->make(AiProviderExecutionGate::class);
    }

    private function tenant(string $slug): int
    {
        $id = DB::table('saas_customers')->insertGetId([
            'name' => "Gate {$slug}",
            'slug' => "gate-{$slug}",
            'subdomain' => "gate-{$slug}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $id]);

        return $id;
    }

    private function approveTenant(int $customerId): void
    {
        $this->settings->approve($customerId, 'ai.external_processing.approved-provider.knowledge_embedding', [
            'approved' => true,
            'data_classes' => ['derived_text'],
            'execution_regions' => ['lf_managed'],
            'retention_classes' => ['transient'],
        ]);
    }

    private function request(array $overrides = []): ProviderGateRequest
    {
        return new ProviderGateRequest(
            provider: $overrides['provider'] ?? 'approved-provider',
            model: $overrides['model'] ?? 'approved-model',
            purpose: $overrides['purpose'] ?? 'knowledge_embedding',
            dataClasses: $overrides['dataClasses'] ?? ['derived_text'],
            executionRegion: $overrides['executionRegion'] ?? 'lf_managed',
            retentionClass: $overrides['retentionClass'] ?? 'transient',
            correlationId: $overrides['correlationId'] ?? '11111111-1111-4111-8111-111111111111',
            promptTemplateId: $overrides['promptTemplateId'] ?? null,
            promptScopeCustomerId: $overrides['promptScopeCustomerId'] ?? null,
            promptVersion: $overrides['promptVersion'] ?? null,
            promptHash: $overrides['promptHash'] ?? null,
            runUuid: $overrides['runUuid'] ?? null,
        );
    }
}
