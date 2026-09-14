<?php

namespace Tests\Feature;

use App\Contracts\Ai\UsageQuotaReserver;
use App\Services\Ai\DatabaseUsageQuotaReserver;
use App\Support\Ai\QuotaReservationHandle;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Ai\FakeUsageQuotaReserver;
use Tests\TestCase;

/**
 * One behavioural contract, run against both the test double and the real store.
 *
 * Gate and embedding tests trust FakeUsageQuotaReserver to behave like
 * DatabaseUsageQuotaReserver. That trust was misplaced twice: the fake minted a
 * second hold per attempt, then reported a settled hold as `reserved` and
 * settled a zero measurement as `committed`. Each divergence was found by hand.
 * Running every scenario against both implementations makes the next one fail
 * here instead.
 *
 * Scope is what this file exercises — the attempt lifecycle, settlement,
 * capacity accounting and refusals. Anything not asserted here is not claimed
 * to match (e.g. period boundaries and lease renewal caps, which only the store
 * models; see DatabaseUsageQuotaReserverTest).
 */
class UsageQuotaReserverContractTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = 100.0;

    protected function setUp(): void
    {
        parent::setUp();

        // The fake reads the same clock, so lease expiry can be driven by travel.
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00', 'UTC'));
        DB::table('saas_customers')->insert([
            'id' => 11, 'name' => 'Contract fixture', 'slug' => 'contract-fixture',
            'subdomain' => 'contract-fixture', 'status' => 'active',
        ]);
        DB::table('saas_entitlements')->insert([
            'customer_id' => 11, 'feature_key' => 'ai_tokens', 'status' => 'active',
            'entitlement_type' => 'decimal', 'entitlement_value' => (string) self::LIMIT,
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

    /** @return array<string,array{0:Closure():UsageQuotaReserver}> */
    public static function reservers(): array
    {
        return [
            'fake' => [static fn (): UsageQuotaReserver => new FakeUsageQuotaReserver(self::LIMIT)],
            'database' => [static fn (): UsageQuotaReserver => new DatabaseUsageQuotaReserver],
        ];
    }

    #[DataProvider('reservers')]
    public function test_the_same_attempt_resolves_to_one_hold(Closure $make): void
    {
        $reserver = $make();
        $first = $this->reserve($reserver, 'same', 12);
        $again = $this->reserve($reserver, 'same', 12);

        $this->assertNotNull($first);
        $this->assertSame($first->reservationId, $again->reservationId);
        $this->assertSame('reserved', $again->status);
        $this->assertNull($again->committedQuantity);
    }

    #[DataProvider('reservers')]
    public function test_a_settled_attempt_reports_its_settlement(Closure $make): void
    {
        $reserver = $make();
        $hold = $this->reserve($reserver, 'settled', 12);
        $this->settle($reserver, $hold, 7);

        $again = $this->reserve($reserver, 'settled', 12);

        // The replayed handle carries the settlement, not the original hold.
        $this->assertSame($hold->reservationId, $again->reservationId);
        $this->assertSame('committed', $again->status);
        $this->assertSame(7.0, $again->committedQuantity);
        $this->assertNotNull($again->usageEventId);
    }

    #[DataProvider('reservers')]
    public function test_an_overshoot_settles_over_limit_at_the_true_quantity(Closure $make): void
    {
        $reserver = $make();
        $hold = $this->reserve($reserver, 'overshoot', 12);
        $this->settle($reserver, $hold, 15);

        $again = $this->reserve($reserver, 'overshoot', 12);

        $this->assertSame('committed_over_limit', $again->status);
        $this->assertSame(15.0, $again->committedQuantity);
    }

    #[DataProvider('reservers')]
    public function test_a_zero_settlement_closes_the_attempt_unused(Closure $make): void
    {
        $reserver = $make();
        $hold = $this->reserve($reserver, 'zero', 12);
        $this->settle($reserver, $hold, 0);
        $reserver->commit($hold, 0);   // replaying the zero settlement is a no-op

        // Zero is positive evidence nothing was consumed: `reconciled_released`,
        // a closed-unused state. A new execution needs a new attempt identity.
        $this->assertNull($this->reserve($reserver, 'zero', 12));
    }

    #[DataProvider('reservers')]
    public function test_consumed_capacity_is_the_committed_not_the_reserved_quantity(Closure $make): void
    {
        $reserver = $make();
        $this->settle($reserver, $this->reserve($reserver, 'measured', 60), 20);

        // 100 − 20 consumed leaves exactly 80.
        $this->assertNotNull($this->reserve($reserver, 'fills-the-rest', 80));
        $this->assertNull($this->reserve($reserver, 'one-too-many', 1));
    }

    #[DataProvider('reservers')]
    public function test_a_zero_settlement_returns_all_capacity(Closure $make): void
    {
        $reserver = $make();
        $this->settle($reserver, $this->reserve($reserver, 'nothing-used', 60), 0);

        $this->assertNotNull($this->reserve($reserver, 'full-limit', self::LIMIT));
    }

    #[DataProvider('reservers')]
    public function test_settlement_replay_is_idempotent_but_a_different_quantity_conflicts(Closure $make): void
    {
        $reserver = $make();
        $hold = $this->reserve($reserver, 'replay', 12);
        $this->settle($reserver, $hold, 7);
        $reserver->commit($hold, 7);

        $this->assertRefused('LF_USAGE_SETTLEMENT_CONFLICT', fn () => $reserver->commit($hold, 8));
        $this->assertSame(7.0, $this->reserve($reserver, 'replay', 12)->committedQuantity);
    }

    #[DataProvider('reservers')]
    public function test_commit_is_refused_before_settling(Closure $make): void
    {
        $reserver = $make();
        $reserved = $this->reserve($reserver, 'still-reserved', 12);
        $executing = $this->reserve($reserver, 'still-executing', 12);
        $reserver->markExecuting($executing);

        $this->assertRefused('LF_RESERVATION_NOT_SETTLING', fn () => $reserver->commit($reserved, 5));
        $this->assertRefused('LF_RESERVATION_NOT_SETTLING', fn () => $reserver->commit($executing, 5));
    }

    #[DataProvider('reservers')]
    public function test_state_moves_only_reserved_then_executing_then_settling(Closure $make): void
    {
        $reserver = $make();
        $skipping = $this->reserve($reserver, 'skips-executing', 12);
        $repeated = $this->reserve($reserver, 'executes-twice', 12);
        $reserver->markExecuting($repeated);

        $this->assertRefused('LF_RESERVATION_UNEXPECTED_STATUS', fn () => $reserver->markSettling($skipping));
        $this->assertRefused('LF_RESERVATION_UNEXPECTED_STATUS', fn () => $reserver->markExecuting($repeated));
    }

    #[DataProvider('reservers')]
    public function test_an_expired_lease_cannot_cross_the_provider_boundary(Closure $make): void
    {
        $reserver = $make();
        $hold = $this->reserve($reserver, 'expired', 12);
        $this->travel(16)->minutes();

        $this->assertRefused('LF_RESERVATION_UNEXPECTED_STATUS', fn () => $reserver->markExecuting($hold));
    }

    #[DataProvider('reservers')]
    public function test_release_before_the_boundary_closes_the_attempt_and_returns_capacity(Closure $make): void
    {
        $reserver = $make();
        $hold = $this->reserve($reserver, 'released', 60);
        $reserver->release($hold);

        $this->assertNull($this->reserve($reserver, 'released', 60));
        $this->assertNotNull($this->reserve($reserver, 'after-release', self::LIMIT));
    }

    #[DataProvider('reservers')]
    public function test_release_after_the_boundary_is_refused(Closure $make): void
    {
        $reserver = $make();
        $hold = $this->reserve($reserver, 'crossed', 12);
        $reserver->markExecuting($hold);

        $this->assertRefused('LF_RESERVATION_PAST_PROVIDER_BOUNDARY', fn () => $reserver->release($hold));
    }

    #[DataProvider('reservers')]
    public function test_a_conflicting_reuse_of_an_attempt_is_refused(Closure $make): void
    {
        $reserver = $make();
        $this->reserve($reserver, 'conflict', 12);

        $this->assertRefused('LF_USAGE_RESERVATION_CONFLICT', fn () => $this->reserve($reserver, 'conflict', 13));
        $this->assertRefused('LF_USAGE_RESERVATION_CONFLICT',
            fn () => $reserver->reserve(11, 32, 'conflict', 'ai_tokens', 'input_token', 12, 'token'));
    }

    #[DataProvider('reservers')]
    public function test_invalid_quantities_are_refused(Closure $make): void
    {
        $reserver = $make();
        $this->assertRefused('LF_USAGE_INVALID_QUANTITY', fn () => $this->reserve($reserver, 'zero-hold', 0));

        $hold = $this->reserve($reserver, 'negative-settlement', 12);
        $reserver->markExecuting($hold);
        $reserver->markSettling($hold);
        $this->assertRefused('LF_USAGE_INVALID_QUANTITY', fn () => $reserver->commit($hold, -1));
    }

    private function reserve(UsageQuotaReserver $reserver, string $attempt, float $quantity): ?QuotaReservationHandle
    {
        return $reserver->reserve(11, 31, $attempt, 'ai_tokens', 'input_token', $quantity, 'token');
    }

    private function settle(UsageQuotaReserver $reserver, QuotaReservationHandle $hold, float $actual): void
    {
        $reserver->markExecuting($hold);
        $reserver->markSettling($hold);
        $reserver->commit($hold, $actual);
    }

    private function assertRefused(string $code, Closure $call): void
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            $this->assertSame($code, $exception->getMessage());

            return;
        }

        $this->fail("Expected refusal {$code}, but the call succeeded.");
    }
}
