<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0022: the course type belongs to the Course Template and is frozen into each Version.
 * Product no longer declares an offering type. Backfill infers the type from Activity
 * availability, the only source guaranteed to agree with the content it governs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->string('course_type', 50)->nullable()->after('category_id');
        });
        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->string('course_type_snapshot', 50)->nullable()->after('category_name_snapshot');
        });

        $this->backfill('core_course_templates', 'course_type', 'core_course_template_activities', 'template_id');
        $this->backfill('core_course_template_versions', 'course_type_snapshot', 'core_course_template_version_activities', 'template_version_id');

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->string('course_type', 50)->nullable(false)->change();
            $table->index(['customer_id', 'course_type'], 'idx_cct_course_type');
        });
        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->string('course_type_snapshot', 50)->nullable(false)->change();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("ALTER TABLE core_course_templates ADD CONSTRAINT chk_cct_course_type CHECK (course_type IN ('self_paced', 'scheduled', 'blended'))");
            DB::unprepared("ALTER TABLE core_course_template_versions ADD CONSTRAINT chk_cctv_course_type CHECK (course_type_snapshot IN ('self_paced', 'scheduled', 'blended'))");
        }

        Schema::table('core_course_products', function (Blueprint $table): void {
            $table->dropIndex('idx_ccp_v2_offering');
        });
        Schema::table('core_course_products', function (Blueprint $table): void {
            $table->dropColumn('offering_type');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_products', function (Blueprint $table): void {
            $table->string('offering_type', 50)->nullable()->after('category_id');
            $table->index(['customer_id', 'offering_type'], 'idx_ccp_v2_offering');
        });
        // Best-effort reverse mapping through the bound Version; Products without a Version stay NULL.
        DB::table('core_course_products as products')
            ->join('core_course_product_items as items', 'items.product_id', '=', 'products.id')
            ->join('core_course_template_versions as versions', 'versions.id', '=', 'items.version_id')
            ->where('items.status', 'active')
            ->select(['products.id', 'versions.course_type_snapshot'])
            ->get()
            ->each(fn ($row) => DB::table('core_course_products')->where('id', $row->id)->update([
                'offering_type' => match ($row->course_type_snapshot) {
                    'self_paced' => 'self_paced_course',
                    'blended' => 'blended_course',
                    default => 'live_online_course',
                },
            ]));

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('ALTER TABLE core_course_template_versions DROP CONSTRAINT chk_cctv_course_type');
            DB::unprepared('ALTER TABLE core_course_templates DROP CONSTRAINT chk_cct_course_type');
        }
        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->dropColumn('course_type_snapshot');
        });
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->dropIndex('idx_cct_course_type');
            $table->dropColumn('course_type');
        });
    }

    /**
     * All anytime (or no Activity) → self_paced; no anytime → scheduled; mixed → blended.
     * A live class is always Session-bound: Versions published before the availability rule
     * still carry the legacy `anytime` default on live classes.
     */
    private function backfill(string $table, string $column, string $activityTable, string $foreignKey): void
    {
        $counts = DB::table($activityTable)
            ->selectRaw("$foreignKey as owner_id, COUNT(*) as total, SUM(CASE WHEN available_anytime = 1 AND activity_type <> 'live_class' THEN 1 ELSE 0 END) as anytime_total")
            ->groupBy($foreignKey)
            ->get()
            ->keyBy('owner_id');

        DB::table($table)->orderBy('id')->select(['id'])->each(function ($row) use ($table, $column, $counts): void {
            $count = $counts->get($row->id);
            $type = match (true) {
                ! $count || (int) $count->anytime_total === (int) $count->total => 'self_paced',
                (int) $count->anytime_total === 0 => 'scheduled',
                default => 'blended',
            };
            DB::table($table)->where('id', $row->id)->update([$column => $type]);
        });
    }
};
