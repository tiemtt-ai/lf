<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0002 § Attendance Policy And Recording Amendment, part A (Owner 2026-11-17): a tenant's named attendance
 * policies and the Cohort's choice of one. Every existing tenant gets its default policy; a Cohort left at NULL
 * uses that default, so no Cohort row is rewritten.
 */
return new class extends Migration
{
    private const TABLE = 'core_liveclass_attendance_policies';

    public function up(): void
    {
        Schema::create('core_liveclass_attendance_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('name', 150);
            // 1 marks the tenant's default policy, NULL any other: NULLs never collide in the unique key below.
            $table->boolean('is_default')->nullable();
            $table->unsignedSmallInteger('opens_before_minutes')->default(15);
            $table->unsignedSmallInteger('late_after_minutes')->default(10);
            $table->unsignedSmallInteger('absent_after_minutes')->nullable()->default(30);
            $table->unsignedSmallInteger('auto_absent_after_minutes')->default(30);
            $table->boolean('count_from_actual_start')->default(true);
            $table->string('offline_recording', 20)->default('system');
            $table->string('online_recording', 20)->default('system');
            $table->boolean('makeup_enabled')->default(true);
            $table->unsignedSmallInteger('makeup_within_days')->nullable();
            $table->unsignedSmallInteger('teacher_edit_days')->default(7);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'name'], 'uk_clap_customer_name');
            $table->unique(['customer_id', 'is_default'], 'uk_clap_customer_default');
            $table->unique(['id', 'customer_id'], 'uk_clap_id_customer');
            $table->foreign('customer_id', 'fk_clap_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('created_by', 'fk_clap_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_clap_updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        if ($this->enforcesChecks()) {
            DB::unprepared('ALTER TABLE '.self::TABLE."
                ADD CONSTRAINT chk_clap_is_default CHECK (is_default = 1),
                ADD CONSTRAINT chk_clap_opens_before CHECK (opens_before_minutes <= 240),
                ADD CONSTRAINT chk_clap_late_after CHECK (late_after_minutes <= 240),
                ADD CONSTRAINT chk_clap_absent_after CHECK (absent_after_minutes IS NULL
                    OR (absent_after_minutes >= late_after_minutes AND absent_after_minutes <= 480)),
                ADD CONSTRAINT chk_clap_auto_absent CHECK (auto_absent_after_minutes <= 1440),
                ADD CONSTRAINT chk_clap_offline_recording CHECK (offline_recording IN ('system', 'manual')),
                ADD CONSTRAINT chk_clap_online_recording CHECK (online_recording IN ('system', 'manual')),
                ADD CONSTRAINT chk_clap_makeup_days CHECK (makeup_within_days IS NULL OR makeup_within_days BETWEEN 1 AND 365),
                ADD CONSTRAINT chk_clap_teacher_edit_days CHECK (teacher_edit_days <= 365)");
        }

        // The column defaults are the documented defaults of a policy.
        $now = now();
        DB::table(self::TABLE)->insertUsing(
            ['customer_id', 'name', 'is_default', 'created_at', 'updated_at'],
            DB::table('saas_customers')->selectRaw('id, ?, 1, ?, ?', ['Mặc định của trung tâm', $now, $now])
        );

        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            $table->unsignedBigInteger('attendance_policy_id')->nullable()->after('default_meeting_url');
            // The pair keeps a Cohort on a policy of its own tenant; a NULL policy is not checked. The index
            // serves the foreign key and "which Cohorts use this policy".
            $table->index(['attendance_policy_id', 'customer_id'], 'idx_ccc_attendance_policy_customer');
            $table->foreign(['attendance_policy_id', 'customer_id'], 'fk_ccc_attendance_policy')
                ->references(['id', 'customer_id'])->on(self::TABLE)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            $table->dropForeign('fk_ccc_attendance_policy');
            $table->dropIndex('idx_ccc_attendance_policy_customer');
            $table->dropColumn('attendance_policy_id');
        });
        Schema::dropIfExists(self::TABLE);
    }

    private function enforcesChecks(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
