<?php

namespace Tests\Integration;

use App\Services\Ai\DatabaseUsageQuotaReserver;
use App\Support\Ai\QuotaReservationHandle;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SaasUsageQuotaPacketMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Physical packet proof requires MariaDB.');
        }
        $this->travelTo(Carbon::parse('2026-09-12 12:00:00', 'UTC'));
        $slug = 'physical-'.Str::uuid();
        $this->customer = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
        ]);
        DB::table('saas_entitlements')->insert($this->entitlement());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function entitlement(): array
    {
        return ['customer_id' => $this->customer, 'feature_key' => 'ai_tokens', 'status' => 'active',
            'entitlement_type' => 'decimal', 'entitlement_value' => '100', 'quota_unit' => 'token',
            'quota_period_type' => 'daily', 'quota_timezone' => 'UTC', 'source_type' => 'plan_feature',
            'source_id' => 1, 'effective_from' => '2026-01-01 00:00:00'];
    }

    private function hold(): QuotaReservationHandle
    {
        return (new DatabaseUsageQuotaReserver)->reserve($this->customer, 31, (string) Str::uuid(), 'ai_tokens', 'input_token', 12, 'token');
    }

    public static function invalidRows(): array
    {
        return [
            'unknown state' => [['status' => 'processing']],
            'empty reserve' => [['reserved_quantity' => 0]],
            'lease exceeds cap' => [['lease_expires_at' => '2026-09-14 00:00:00']],
            'cap exceeds period' => [['max_lease_expires_at' => '2026-09-14 00:00:00']],
            'finite period null end' => [['period_end_at' => null]],
            'negative actual' => [['committed_quantity' => -1]],
            'executing without marker' => [['status' => 'executing']],
            'settling without response' => [['status' => 'settling', 'execution_started_at' => '2026-09-12 12:00:00']],
            'reconciled marker before terminal' => [['reconciled_at' => '2026-09-12 12:00:00']],
            'release after execution' => [['status' => 'released', 'execution_started_at' => '2026-09-12 12:00:00', 'settled_at' => '2026-09-12 12:00:00']],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_status_and_lease_checks_reject_invalid_rows(array $changes): void
    {
        $h = $this->hold();
        $this->expectException(QueryException::class);
        DB::table('saas_usage_reservations')->where('reservation_uuid', $h->reservationId)->update($changes);
    }

    public function test_active_slot_allows_closed_history_but_not_two_open_rows(): void
    {
        foreach ([1, 2, 3] as $i) {
            DB::table('saas_entitlements')->insert($this->entitlement() + ['effective_to' => '2026-02-01 00:00:00']);
        }
        $this->assertSame(3, DB::table('saas_entitlements')->where('customer_id', $this->customer)->whereNull('active_slot')->count());
        $this->expectException(QueryException::class);
        DB::table('saas_entitlements')->insert($this->entitlement());
    }

    public function test_attempt_unique_key_survives_period_change(): void
    {
        $h = $this->hold();
        $row = (array) DB::table('saas_usage_reservations')->where('reservation_uuid', $h->reservationId)->first();
        unset($row['id']);
        $row['reservation_uuid'] = (string) Str::uuid();
        $row['period_key'] = '2026-09-13';
        $this->expectException(QueryException::class);
        DB::table('saas_usage_reservations')->insert($row);
    }

    public function test_tenant_foreign_key_rejects_wrong_entitlement(): void
    {
        $h = $this->hold();
        $slug = 'other-'.Str::uuid();
        $other = DB::table('saas_customers')->insertGetId(['name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active']);
        $this->expectException(QueryException::class);
        DB::table('saas_usage_reservations')->where('reservation_uuid', $h->reservationId)->update(['customer_id' => $other]);
    }

    private function settle(float $quantity = 10): object
    {
        $store = new DatabaseUsageQuotaReserver;
        $h = $this->hold();
        $store->markExecuting($h);
        $store->markSettling($h);
        $store->commit($h, $quantity);

        return DB::table('saas_usage_events')->where('customer_id', $this->customer)->first();
    }

    public static function mutations(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    #[DataProvider('mutations')]
    public function test_usage_event_is_physically_immutable(string $mutation): void
    {
        $event = $this->settle();
        $this->expectExceptionMessage('LF_USAGE_EVENT_IMMUTABLE');
        $query = DB::table('saas_usage_events')->where('id', $event->id);
        $mutation === 'delete' ? $query->delete() : $query->update(['quantity' => 9]);
    }

    public function test_reversal_preserves_reservation_but_double_settlement_is_blocked(): void
    {
        $event = (array) $this->settle();
        $id = $event['id'];
        unset($event['id']);
        $event['event_uuid'] = (string) Str::uuid();
        $event['event_kind'] = 'reversal';
        $event['reverses_event_id'] = $id;
        DB::table('saas_usage_events')->insert($event);
        $this->assertSame(2, DB::table('saas_usage_events')->where('customer_id', $this->customer)->count());
        $event['event_uuid'] = (string) Str::uuid();
        $event['event_kind'] = 'measurement';
        $event['reverses_event_id'] = null;
        $this->expectException(QueryException::class);
        DB::table('saas_usage_events')->insert($event);
    }

    public function test_over_limit_state_cannot_be_disguised_as_normal_commit(): void
    {
        $this->settle(13);
        $this->expectException(QueryException::class);
        DB::table('saas_usage_reservations')->where('customer_id', $this->customer)->update(['status' => 'committed']);
    }

    public function test_rollback_preflights_before_dropping_any_table_or_trigger(): void
    {
        $migration = require database_path('migrations/2026_09_12_000100_create_saas_usage_quota_packet.php');
        try {
            $migration->down();
            $this->fail('Expected fail-closed rollback');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('LF_USAGE_PACKET_ROLLBACK_REFUSED', $e->getMessage());
        }
        foreach (['saas_entitlements', 'saas_usage_events', 'saas_usage_counters', 'saas_usage_reservations'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertEquals(2, DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', 'saas_usage_events')->count());
    }

    public function test_timestamps_have_explicit_semantics(): void
    {
        $cols = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereIn('TABLE_NAME', ['saas_usage_events', 'saas_usage_counters', 'saas_entitlements'])->get();
        $occurred = $cols->first(fn ($c) => $c->COLUMN_NAME === 'occurred_at');
        $this->assertSame('datetime', $occurred->DATA_TYPE);
        $this->assertStringNotContainsString('on update', strtolower($occurred->EXTRA));
        $updated = $cols->first(fn ($c) => $c->TABLE_NAME === 'saas_usage_counters' && $c->COLUMN_NAME === 'updated_at');
        $this->assertStringContainsString('on update current_timestamp(6)', strtolower($updated->EXTRA));
    }

    public function test_two_connections_serialize_capacity_on_the_entitlement_lock(): void
    {
        $this->assertSame(1, DB::transactionLevel(), 'This test must own the outer fixture transaction.');
        DB::commit(); // Make the tenant/entitlement visible to the child connection.
        try {
            DB::beginTransaction();
            $store = new DatabaseUsageQuotaReserver;
            $this->assertNotNull($store->reserve($this->customer, 31, (string) Str::uuid(), 'ai_tokens', 'input_token', 60, 'token'));
            $run = function (): array {
                $process = new Process([PHP_BINARY, base_path('tests/Support/Ai/quota_store_worker.php')], base_path(), ['APP_ENV' => 'testing']);
                $process->setInput(json_encode(['connection' => DB::connection()->getConfig(), 'customer' => $this->customer,
                    'attempt' => (string) Str::uuid(), 'now' => now()->utc()->toIso8601String()], JSON_THROW_ON_ERROR));
                $process->setTimeout(20)->mustRun();

                return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            };
            $this->assertSame(['driver_error' => 1205], $run(), 'Actual child SQL must wait on the parent lock.');
            DB::commit();
            $this->assertSame(['reserved' => false], $run(), 'Retry must see 60 consumed capacity, not grant another 60 of 100.');
            $this->assertEquals(60, DB::table('saas_usage_reservations')->where('customer_id', $this->customer)->sum('reserved_quantity'));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            // Only this test's never-executed holds; no Usage Event to erase.
            DB::table('saas_usage_reservations')->where('customer_id', $this->customer)->delete();
            DB::table('saas_entitlements')->where('customer_id', $this->customer)->delete();
            DB::table('saas_customers')->where('id', $this->customer)->delete();
            DB::beginTransaction(); // Restore RefreshDatabase teardown's transaction.
        }
    }
}
