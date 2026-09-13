<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner-approved Step 4 packet (2026-09-12); independent review waived, no provider activation. */
return new class extends Migration
{
    private const TABLES = ['saas_usage_reservations', 'saas_usage_counters', 'saas_usage_events', 'saas_entitlements'];

    public function up(): void
    {
        Schema::create('saas_entitlements', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->string('feature_key', 100);
            $t->string('entitlement_type', 50);
            $t->text('entitlement_value')->nullable();
            $t->string('quota_unit', 50)->nullable();
            $t->string('quota_period_type', 50)->nullable();
            $t->string('quota_timezone', 64)->nullable();
            $t->string('source_type', 50);
            $t->unsignedBigInteger('source_id');
            $t->dateTime('effective_from', 6);
            $t->dateTime('effective_to', 6)->nullable();
            $t->string('status', 50)->default('active');
            $t->string('active_slot', 100)->storedAs("CASE WHEN status='active' AND effective_to IS NULL THEN feature_key ELSE NULL END");
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index('customer_id', 'idx_se_customer');
            $t->index(['customer_id', 'feature_key', 'status'], 'idx_se_feature_status');
            $t->index(['customer_id', 'feature_key', 'effective_from', 'effective_to'], 'idx_se_window');
            $t->index(['source_type', 'source_id'], 'idx_se_source');
            $t->index('effective_to', 'idx_se_end');
            $t->unique(['id', 'customer_id'], 'uk_se_tenant');
            $t->unique(['customer_id', 'active_slot'], 'uk_se_active');
            $t->foreign('customer_id', 'fk_se_customer')->references('id')->on('saas_customers')->restrictOnDelete();
        });
        Schema::create('saas_usage_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->char('event_uuid', 36);
            $t->string('event_kind', 30)->default('measurement');
            $t->unsignedBigInteger('reverses_event_id')->nullable();
            $t->char('reservation_uuid', 36)->nullable();
            $t->string('feature_key', 100);
            $t->string('usage_type', 100);
            $t->decimal('quantity', 20, 6);
            $t->string('unit', 50);
            $t->string('source_type', 100);
            $t->unsignedBigInteger('source_id');
            $t->dateTime('occurred_at', 6);
            $t->string('correlation_id', 100)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('created_at', 6)->useCurrent();
            $t->unique(['id', 'customer_id'], 'uk_sue_tenant');
            $t->unique(['customer_id', 'event_uuid'], 'uk_sue_uuid');
            $t->unique(['customer_id', 'reverses_event_id'], 'uk_sue_reversal');
            $t->unique(['customer_id', 'reservation_uuid', 'event_kind'], 'uk_sue_reservation_kind');
            $t->index('customer_id', 'idx_sue_customer');
            $t->index(['customer_id', 'feature_key', 'occurred_at'], 'idx_sue_feature_time');
            $t->index(['customer_id', 'usage_type', 'occurred_at'], 'idx_sue_metric_time');
            $t->index(['customer_id', 'source_type', 'source_id'], 'idx_sue_source');
            $t->index(['customer_id', 'correlation_id'], 'idx_sue_correlation');
            $t->index('occurred_at', 'idx_sue_time');
            $t->index(['reverses_event_id', 'customer_id'], 'idx_sue_reverse_tenant');
            $t->foreign('customer_id', 'fk_sue_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $t->foreign(['reverses_event_id', 'customer_id'], 'fk_sue_reverse')->references(['id', 'customer_id'])->on('saas_usage_events')->restrictOnDelete();
        });
        Schema::create('saas_usage_counters', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->string('feature_key', 100);
            $t->string('period_type', 50);
            $t->string('period_key', 50);
            $t->decimal('usage_quantity', 20, 6)->default(0);
            $t->unsignedBigInteger('last_usage_event_id')->nullable();
            $t->string('unit', 50);
            $t->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $t->unique(['customer_id', 'feature_key', 'period_type', 'period_key', 'unit'], 'uk_suc_period');
            $t->index(['customer_id', 'period_type', 'period_key'], 'idx_suc_period');
            $t->index(['customer_id', 'feature_key'], 'idx_suc_feature');
            $t->index('updated_at', 'idx_suc_updated');
            $t->foreign('customer_id', 'fk_suc_customer')->references('id')->on('saas_customers')->restrictOnDelete();
        });
        Schema::create('saas_usage_reservations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->char('reservation_uuid', 36);
            $t->unsignedBigInteger('entitlement_id');
            $t->string('source_type', 100);
            $t->unsignedBigInteger('source_id');
            $t->char('source_uuid', 36);
            $t->string('feature_key', 100);
            $t->string('usage_type', 100);
            $t->string('period_type', 50);
            $t->string('period_key', 50);
            $t->dateTime('period_start_at', 6);
            $t->dateTime('period_end_at', 6)->nullable();
            $t->string('timezone_snapshot', 64);
            $t->string('unit', 50);
            $t->decimal('reserved_quantity', 20, 6);
            $t->decimal('committed_quantity', 20, 6)->nullable();
            $t->string('status', 30)->default('reserved');
            $t->unsignedBigInteger('usage_event_id')->nullable();
            $t->dateTime('lease_expires_at', 6);
            $t->dateTime('execution_started_at', 6)->nullable();
            $t->dateTime('provider_completed_at', 6)->nullable();
            $t->dateTime('max_lease_expires_at', 6);
            $t->dateTime('reconciled_at', 6)->nullable();
            $t->dateTime('settled_at', 6)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps(6);
            $t->unique(['id', 'customer_id'], 'uk_sur_tenant');
            $t->unique(['customer_id', 'reservation_uuid'], 'uk_sur_uuid');
            $t->unique(['customer_id', 'source_type', 'source_uuid', 'feature_key', 'usage_type', 'unit'], 'uk_sur_attempt');
            $t->unique(['customer_id', 'usage_event_id'], 'uk_sur_event');
            $t->index(['customer_id', 'feature_key', 'period_key', 'unit', 'status'], 'idx_sur_capacity');
            $t->index(['customer_id', 'status', 'lease_expires_at'], 'idx_sur_expiry');
            $t->index(['entitlement_id', 'customer_id'], 'idx_sur_entitlement');
            $t->index(['usage_event_id', 'customer_id'], 'idx_sur_event_tenant');
            $t->foreign('customer_id', 'fk_sur_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $t->foreign(['entitlement_id', 'customer_id'], 'fk_sur_entitlement')->references(['id', 'customer_id'])->on('saas_entitlements')->restrictOnDelete();
            $t->foreign(['usage_event_id', 'customer_id'], 'fk_sur_event')->references(['id', 'customer_id'])->on('saas_usage_events')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            foreach ($this->checks() as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($expression)");
                }
            }
            foreach (['bu' => 'UPDATE', 'bd' => 'DELETE'] as $suffix => $action) {
                DB::unprepared("CREATE TRIGGER trg_saas_usage_events_{$suffix}_immutable BEFORE $action ON saas_usage_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_USAGE_EVENT_IMMUTABLE'");
            }
        }
    }

    private function checks(): array
    {
        return [
            'saas_entitlements' => [
                'chk_se_quota' => "(entitlement_type IN ('integer','decimal','unlimited') AND quota_unit IS NOT NULL AND quota_period_type IS NOT NULL AND quota_timezone IS NOT NULL) OR (entitlement_type IN ('boolean','string') AND quota_unit IS NULL AND quota_period_type IS NULL AND quota_timezone IS NULL)",
            ],
            'saas_usage_events' => [
                'chk_sue_kind' => "event_kind IN ('measurement','reversal')",
                'chk_sue_quantity' => 'quantity > 0',
                'chk_sue_reversal' => "(event_kind = 'measurement' AND reverses_event_id IS NULL) OR (event_kind = 'reversal' AND reverses_event_id IS NOT NULL)",
            ],
            'saas_usage_reservations' => [
                'chk_sur_status' => "status IN ('reserved','executing','settling','committed','committed_over_limit','released','expired','reconciled_released')",
                'chk_sur_reserved' => 'reserved_quantity > 0',
                'chk_sur_lease' => 'lease_expires_at <= max_lease_expires_at',
                'chk_sur_cap' => 'period_end_at IS NULL OR max_lease_expires_at <= period_end_at',
                'chk_sur_actual' => 'committed_quantity IS NULL OR committed_quantity >= 0',
                'chk_sur_committed' => "status <> 'committed' OR committed_quantity <= reserved_quantity",
                'chk_sur_over_limit' => "status <> 'committed_over_limit' OR committed_quantity > reserved_quantity",
                // Explicit IS NOT NULL closes SQL UNKNOWN for a finite period.
                'chk_sur_period' => "(period_type = 'lifetime' AND period_end_at IS NULL) OR (period_type <> 'lifetime' AND period_end_at IS NOT NULL AND period_end_at > period_start_at)",
                'chk_sur_coherence' => "(status = 'reserved' AND committed_quantity IS NULL AND usage_event_id IS NULL AND execution_started_at IS NULL AND provider_completed_at IS NULL AND settled_at IS NULL) OR (status = 'executing' AND execution_started_at IS NOT NULL AND provider_completed_at IS NULL AND committed_quantity IS NULL AND usage_event_id IS NULL AND settled_at IS NULL) OR (status = 'settling' AND execution_started_at IS NOT NULL AND provider_completed_at IS NOT NULL AND committed_quantity IS NULL AND usage_event_id IS NULL AND settled_at IS NULL) OR (status IN ('committed','committed_over_limit') AND execution_started_at IS NOT NULL AND provider_completed_at IS NOT NULL AND committed_quantity IS NOT NULL AND usage_event_id IS NOT NULL AND settled_at IS NOT NULL) OR (status IN ('released','expired') AND committed_quantity IS NULL AND usage_event_id IS NULL AND execution_started_at IS NULL AND provider_completed_at IS NULL AND settled_at IS NOT NULL) OR (status = 'reconciled_released' AND committed_quantity IS NULL AND usage_event_id IS NULL AND execution_started_at IS NOT NULL AND reconciled_at IS NOT NULL AND settled_at IS NOT NULL)",
                'chk_sur_reconciled' => "reconciled_at IS NULL OR status IN ('committed','committed_over_limit','reconciled_released')",
            ],
        ];
    }

    public function down(): void
    {
        // Preflight the entire packet before any DDL, including trigger drops.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('LF_USAGE_PACKET_ROLLBACK_REFUSED: '.$table);
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
