<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_liveclass_session_schedule_origins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('session_id');
            $table->foreignId('schedule_id');
            $table->foreignId('schedule_slot_id');
            $table->date('source_local_date');
            $table->time('source_local_start_time');
            $table->time('source_local_end_time');
            $table->string('source_timezone', 64);
            $table->dateTime('source_start_at');
            $table->dateTime('source_end_at');
            $table->foreignId('created_by');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('customer_id', 'fk_lcsso_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('session_id', 'fk_lcsso_session')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->foreign('schedule_id', 'fk_lcsso_schedule')->references('id')->on('core_liveclass_schedules')->restrictOnDelete();
            $table->foreign('schedule_slot_id', 'fk_lcsso_slot')->references('id')->on('core_liveclass_schedule_slots')->restrictOnDelete();
            $table->foreign('created_by', 'fk_lcsso_creator')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['customer_id', 'session_id'], 'uk_lcsso_session');
            $table->unique(['customer_id', 'schedule_id', 'schedule_slot_id', 'source_local_date'], 'uk_lcsso_occurrence');
            $table->index(['customer_id', 'schedule_id', 'source_local_date'], 'idx_lcsso_schedule_date');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE core_liveclass_session_schedule_origins ADD CONSTRAINT chk_lcsso_local_time CHECK (source_local_end_time > source_local_start_time)');
            DB::statement('ALTER TABLE core_liveclass_session_schedule_origins ADD CONSTRAINT chk_lcsso_absolute_time CHECK (source_end_at > source_start_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('core_liveclass_session_schedule_origins');
    }
};
