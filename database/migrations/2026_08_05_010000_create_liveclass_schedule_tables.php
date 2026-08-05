<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_liveclass_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('cohort_id');
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('timezone', 64);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_lcschedule_customer')
                ->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('cohort_id', 'fk_lcschedule_cohort')
                ->references('id')->on('core_course_cohorts')->restrictOnDelete();
            $table->foreign('created_by', 'fk_lcschedule_creator')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(
                ['customer_id', 'cohort_id', 'starts_on', 'ends_on'],
                'idx_lcschedule_cohort'
            );
        });

        Schema::create('core_liveclass_schedule_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('schedule_id');
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('sort_order');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_lcsslot_customer')
                ->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('schedule_id', 'fk_lcsslot_schedule')
                ->references('id')->on('core_liveclass_schedules')->restrictOnDelete();
            $table->foreign('created_by', 'fk_lcsslot_creator')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['customer_id', 'schedule_id', 'weekday', 'start_time', 'end_time'],
                'uk_lcsslot_exact'
            );
            $table->index(
                ['customer_id', 'schedule_id', 'sort_order', 'id'],
                'idx_lcsslot_schedule_order'
            );
            $table->index(
                ['customer_id', 'schedule_id', 'weekday', 'start_time', 'end_time'],
                'idx_lcsslot_overlap'
            );
        });

        Schema::create('core_liveclass_schedule_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('schedule_id');
            $table->date('excluded_on');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_lcsexclusion_customer')
                ->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('schedule_id', 'fk_lcsexclusion_schedule')
                ->references('id')->on('core_liveclass_schedules')->restrictOnDelete();
            $table->foreign('created_by', 'fk_lcsexclusion_creator')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['customer_id', 'schedule_id', 'excluded_on'],
                'uk_lcsexclusion_date'
            );
            $table->index(
                ['customer_id', 'schedule_id', 'excluded_on'],
                'idx_lcsexclusion_schedule'
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE core_liveclass_schedule_slots ADD CONSTRAINT chk_lcsslot_weekday CHECK (weekday BETWEEN 1 AND 7)');
            DB::statement('ALTER TABLE core_liveclass_schedule_slots ADD CONSTRAINT chk_lcsslot_time CHECK (end_time > start_time)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('core_liveclass_schedule_exclusions');
        Schema::dropIfExists('core_liveclass_schedule_slots');
        Schema::dropIfExists('core_liveclass_schedules');
    }
};
