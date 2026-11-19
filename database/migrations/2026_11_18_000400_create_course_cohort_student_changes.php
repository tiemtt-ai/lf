<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0034: the append-only history of a class membership — a changed join date, leaving, being added back and
 * moving to another class. The membership table keeps one row per Enrollment and gets no column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_cohort_student_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('cohort_student_id');
            $table->unsignedBigInteger('cohort_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->string('change_type', 30);
            $table->dateTime('old_joined_at')->nullable();
            $table->dateTime('new_joined_at')->nullable();
            $table->dateTime('absent_from')->nullable();
            $table->dateTime('absent_to')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'cohort_student_id', 'created_at'], 'idx_cccsc_member');
            $table->index(['customer_id', 'cohort_id', 'enrollment_id'], 'idx_cccsc_cohort_enrollment');
            $table->foreign('customer_id', 'fk_cccsc_customer')->references('id')->on('saas_customers')
                ->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(['cohort_student_id', 'customer_id'], 'fk_cccsc_member')->references(['id', 'customer_id'])
                ->on('core_course_cohort_students')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('changed_by', 'fk_cccsc_changed_by')->references('id')->on('users')
                ->restrictOnUpdate()->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("ALTER TABLE core_course_cohort_student_changes
                ADD CONSTRAINT chk_cccsc_type CHECK (change_type IN ('joined_at_changed', 'left', 'rejoined', 'transferred')),
                ADD CONSTRAINT chk_cccsc_absence CHECK ((change_type = 'rejoined') = (absent_from IS NOT NULL AND absent_to IS NOT NULL)),
                ADD CONSTRAINT chk_cccsc_absence_order CHECK (absent_from IS NULL OR absent_to >= absent_from)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_cohort_student_changes');
    }
};
