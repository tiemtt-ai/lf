<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ADR-0023: each Cohort's own order of its Version's Lessons and Activities. No rows means the Version's order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_cohort_lesson_orders', function (Blueprint $table): void {
            $this->orderColumns($table, 'cclo', 'version_lesson_id', 'core_course_template_version_lessons');
        });
        Schema::create('core_course_cohort_activity_orders', function (Blueprint $table): void {
            $this->orderColumns($table, 'ccao', 'version_activity_id', 'core_course_template_version_activities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_cohort_activity_orders');
        Schema::dropIfExists('core_course_cohort_lesson_orders');
    }

    private function orderColumns(Blueprint $table, string $prefix, string $itemColumn, string $itemTable): void
    {
        $table->id();
        $table->unsignedBigInteger('customer_id');
        $table->unsignedBigInteger('cohort_id');
        $table->unsignedBigInteger($itemColumn);
        $table->unsignedInteger('sort_order');
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->timestamps();

        $table->unique(['customer_id', 'cohort_id', $itemColumn], "uk_{$prefix}_cohort_item");
        $table->index(['customer_id', $itemColumn], "idx_{$prefix}_item");
        $table->foreign('customer_id', "fk_{$prefix}_customer")->references('id')->on('saas_customers')->restrictOnDelete();
        $table->foreign(['cohort_id', 'customer_id'], "fk_{$prefix}_cohort")
            ->references(['id', 'customer_id'])->on('core_course_cohorts')->cascadeOnDelete();
        $table->foreign($itemColumn, "fk_{$prefix}_item")->references('id')->on($itemTable)->restrictOnDelete();
        $table->foreign('updated_by', "fk_{$prefix}_updated_by")->references('id')->on('users')->nullOnDelete();
    }
};
