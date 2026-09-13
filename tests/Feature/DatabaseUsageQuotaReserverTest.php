<?php

namespace Tests\Feature;

use App\Contracts\Ai\UsageReconciliationEvidenceReader;
use App\Services\Ai\DatabaseUsageQuotaReserver;
use App\Support\Ai\QuotaReservationHandle;
use App\Support\Ai\UsageReconciliationEvidence;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Actual migrations and store; run on both SQLite and MariaDB CI. */
class DatabaseUsageQuotaReserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-10 12:00:00', 'UTC'));
        DB::table('saas_customers')->insert([
            'id' => 11, 'name' => 'Quota fixture', 'slug' => 'quota-fixture',
            'subdomain' => 'quota-fixture', 'status' => 'active',
        ]);
        DB::table('saas_entitlements')->insert([
            'customer_id' => 11, 'feature_key' => 'ai_tokens', 'status' => 'active',
            'entitlement_type' => 'decimal', 'entitlement_value' => '100',
            'quota_unit' => 'token', 'quota_period_type' => 'daily', 'quota_timezone' => 'UTC',
            'source_type' => 'plan_feature', 'source_id' => 1,
            'effective_from' => '2026-01-01 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_reserve_returns_the_persisted_usage_type_and_reuses_the_hold(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $handle = $store->reserve(11, 31, 'attempt-1', 'ai_tokens', 'input_token', 12, 'token');
        $this->assertNotNull($handle);
        $this->assertSame('input_token', $handle->usageType);
        $this->assertSame(12.0, $handle->quantity);
        $this->assertSame(11, $handle->customerId);
        $this->assertSame($handle->reservationId,
            $store->reserve(11, 31, 'attempt-1', 'ai_tokens', 'input_token', 12, 'token')->reservationId);
        $this->assertSame(1, DB::table('saas_usage_reservations')->count());
    }

    public function test_initial_lease_does_not_exceed_the_period_end(): void
    {
        $this->travelTo(now()->utc()->setTime(23, 58));
        $handle = (new DatabaseUsageQuotaReserver)->reserve(11, 31, 'attempt-1', 'ai_tokens', 'input_token', 12, 'token');
        $row = DB::table('saas_usage_reservations')->first();
        $this->assertSame($row->period_end_at, $row->max_lease_expires_at);
        $this->assertSame($row->max_lease_expires_at, $row->lease_expires_at);
        $this->assertSame('2026-09-11 00:00:00', $handle->expiresAt->format('Y-m-d H:i:s'));
    }

    public function test_capacity_is_shared_across_usage_types_but_not_tenants(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $this->assertNotNull($store->reserve(11, 31, 'attempt-1', 'ai_tokens', 'input_token', 90, 'token'));
        $this->assertNull($store->reserve(11, 32, 'attempt-2', 'ai_tokens', 'output_token', 11, 'token'));
        $this->assertNull($store->reserve(12, 33, 'attempt-3', 'ai_tokens', 'input_token', 1, 'token'));
        $this->assertSame(1, DB::table('saas_usage_reservations')->count());
    }

    public function test_expiry_uses_utc_and_never_reclaims_an_executing_hold(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $handle = $store->reserve(11, 31, 'attempt-1', 'ai_tokens', 'input_token', 12, 'token');
        $executing = $store->reserve(11, 32, 'attempt-2', 'ai_tokens', 'input_token', 12, 'token');
        $store->markExecuting($executing);
        $this->assertSame(now()->utc()->addMinutes(15)->getTimestamp(), $handle->expiresAt->getTimestamp());
        $this->travel(14)->minutes();
        $this->assertSame(0, $store->reconcileExpired(11));
        $this->travel(1)->minutes();
        $this->assertSame(0, $store->reconcileExpired(12));
        $this->assertSame(1, $store->reconcileExpired(11));
        $this->assertSame('executing', DB::table('saas_usage_reservations')
            ->where('reservation_uuid', $executing->reservationId)->value('status'));
    }

    public function test_settlement_replay_is_idempotent_across_period_and_entitlement_expiry(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $h = $store->reserve(11, 31, 'settlement', 'ai_tokens', 'input_token', 12, 'token');
        $store->markExecuting($h);
        $store->markSettling($h);
        $store->commit($h, 120);
        $store->commit($h, 120);
        DB::table('saas_entitlements')->where('customer_id', 11)->update(['status' => 'expired']);
        $this->travel(2)->days();
        $replay = $store->reserve(11, 31, 'settlement', 'ai_tokens', 'input_token', 12, 'token');
        $this->assertSame($h->reservationId, $replay->reservationId);
        $this->assertSame('committed_over_limit', $replay->status);
        $this->assertSame(120.0, $replay->committedQuantity);
        $this->assertSame(1, DB::table('saas_usage_events')->count());
        $this->expectExceptionMessage('LF_USAGE_SETTLEMENT_CONFLICT');
        $store->commit($h, 100);
    }

    public function test_reconciliation_handles_positive_zero_and_unknown_receipts(): void
    {
        $reader = new class implements UsageReconciliationEvidenceReader
        {
            public array $quantities = [];

            public function read(QuotaReservationHandle $h): ?UsageReconciliationEvidence
            {
                if (! array_key_exists($h->runUuid, $this->quantities)) {
                    return null;
                }

                return new UsageReconciliationEvidence($h->customerId, $h->reservationId,
                    $h->runUuid, $h->usageType, $h->unit, $this->quantities[$h->runUuid],
                    now()->utc()->toDateTimeImmutable(), hash('sha256', $h->runUuid));
            }
        };
        $store = new DatabaseUsageQuotaReserver($reader);
        foreach (['zero', 'positive', 'unknown'] as $uuid) {
            $h = $store->reserve(11, 31, $uuid, 'ai_tokens', 'input_token', 12, 'token');
            $store->markExecuting($h);
        }
        $reader->quantities = ['zero' => 0, 'positive' => 15];
        $this->assertSame(['released' => 0, 'settled' => 0], $store->reconcileUnsettled(12));
        $this->assertSame(['released' => 1, 'settled' => 1], $store->reconcileUnsettled(11));
        $this->assertSame(['released' => 0, 'settled' => 0], $store->reconcileUnsettled(11));
        $this->assertSame('executing', DB::table('saas_usage_reservations')->where('source_uuid', 'unknown')->value('status'));
        $row = DB::table('saas_usage_reservations')->where('source_uuid', 'positive')->first();
        $this->assertSame('committed_over_limit', $row->status);
        $this->assertNotNull($row->reconciled_at);
        $this->assertSame(hash('sha256', 'positive'), json_decode($row->metadata, true)['reconciliation_receipt_hash']);
        $this->assertEquals(15, DB::table('saas_usage_events')->value('quantity'));
    }

    public function test_zero_response_terminates_without_invalid_zero_usage_event(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $h = $store->reserve(11, 31, 'zero', 'ai_tokens', 'input_token', 12, 'token');
        $store->markExecuting($h);
        $store->markSettling($h);
        $store->commit($h, 0);
        $store->commit($h, 0);
        $this->assertSame('reconciled_released', DB::table('saas_usage_reservations')->value('status'));
        $this->assertSame(0, DB::table('saas_usage_events')->count());
    }

    public function test_release_refuses_a_hold_past_the_boundary(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $h = $store->reserve(11, 31, 'release', 'ai_tokens', 'input_token', 12, 'token');
        $store->markExecuting($h);
        $this->expectExceptionMessage('LF_RESERVATION_PAST_PROVIDER_BOUNDARY');
        $store->release($h);
    }

    public function test_expired_lease_cannot_cross_provider_boundary(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $h = $store->reserve(11, 31, 'expired', 'ai_tokens', 'input_token', 12, 'token');
        $this->travel(15)->minutes();
        $this->expectExceptionMessage('LF_RESERVATION_UNEXPECTED_STATUS');
        $store->markExecuting($h);
    }

    public function test_non_utc_period_ends_at_local_midnight(): void
    {
        DB::table('saas_entitlements')->update(['quota_timezone' => 'Asia/Ho_Chi_Minh']);
        $this->travelTo(Carbon::parse('2026-09-10 16:58:00', 'UTC'));
        $h = (new DatabaseUsageQuotaReserver)->reserve(11, 31, 'local-midnight', 'ai_tokens', 'input_token', 12, 'token');
        $this->assertSame('2026-09-10 17:00:00', $h->expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10', DB::table('saas_usage_reservations')->value('period_key'));
    }

    public function test_renewal_preserves_original_cap_and_refuses_expired_lease(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $h = $store->reserve(11, 31, 'renew', 'ai_tokens', 'input_token', 12, 'token');
        for ($i = 0; $i < 11; $i++) {
            $this->travel(10)->minutes();
            $h = $store->renew($h);
        }
        $this->assertSame('2026-09-10 14:00:00', $h->expiresAt->format('Y-m-d H:i:s'));
        $this->travel(10)->minutes();
        $this->expectExceptionMessage('LF_USAGE_LEASE_NOT_RENEWABLE');
        $store->renew($h);
    }

    public function test_wrong_receipt_cannot_release_another_attempt(): void
    {
        $reader = new class implements UsageReconciliationEvidenceReader
        {
            public function read(QuotaReservationHandle $h): ?UsageReconciliationEvidence
            {
                return new UsageReconciliationEvidence($h->customerId + 1, $h->reservationId,
                    $h->runUuid, $h->usageType, $h->unit, 0,
                    now()->utc()->toDateTimeImmutable(), hash('sha256', 'receipt'));
            }
        };
        $store = new DatabaseUsageQuotaReserver($reader);
        $h = $store->reserve(11, 31, 'wrong-receipt', 'ai_tokens', 'input_token', 12, 'token');
        $store->markExecuting($h);
        $this->expectExceptionMessage('LF_USAGE_EVIDENCE_MISMATCH');
        $store->reconcileUnsettled(11);
    }

    public function test_usage_insert_failure_cannot_mark_the_hold_committed(): void
    {
        $store = new DatabaseUsageQuotaReserver;
        $h = $store->reserve(11, 31, 'insert-failure', 'ai_tokens', 'input_token', 12, 'token');
        $store->markExecuting($h);
        $store->markSettling($h);
        // Force the immutable reservation/event unique key to reject append.
        DB::table('saas_usage_events')->insert([
            'customer_id' => 11, 'event_uuid' => (string) Str::uuid(),
            'reservation_uuid' => $h->reservationId, 'event_kind' => 'measurement',
            'feature_key' => 'ai_tokens', 'usage_type' => 'input_token', 'quantity' => 10,
            'unit' => 'token', 'source_type' => 'ai_model_run', 'source_id' => 31,
            'occurred_at' => now()->utc(), 'created_at' => now()->utc(),
        ]);
        try {
            $store->commit($h, 10);
            $this->fail('Expected append conflict');
        } catch (QueryException) {
            $row = DB::table('saas_usage_reservations')->where('reservation_uuid', $h->reservationId)->first();
            $this->assertSame('settling', $row->status);
            $this->assertNull($row->usage_event_id);
            $this->assertNull($row->committed_quantity);
        }
    }
}
