<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// D6 approved; generations preserve cancelled executions and provider retry budget.
return new class extends Migration
{
    private array $scope = ['customer_id', 'media_file_id', 'job_type', 'source_fingerprint', 'processing_version', 'output_profile_hash'];

    public function up(): void
    {
        Schema::table('media_processing_jobs', function (Blueprint $table): void {
            $table->unsignedInteger('dispatch_generation')->default(1);
            $table->dropUnique('uk_mpj_profile_attempt');
            $table->unique([...$this->scope, 'dispatch_generation', 'attempt'], 'uk_mpj_profile_attempt');
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_generation CHECK (dispatch_generation >= 1)');
        }
    }

    public function down(): void
    {
        if (DB::table('media_processing_jobs')->where('dispatch_generation', '<>', 1)->exists()) {
            throw new RuntimeException('Rollback refused: dispatch generation history must be retained.');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_generation');
        }
        Schema::table('media_processing_jobs', function (Blueprint $table): void {
            $table->dropUnique('uk_mpj_profile_attempt');
            $table->dropColumn('dispatch_generation');
            $table->unique([...$this->scope, 'attempt'], 'uk_mpj_profile_attempt');
        });
    }
};
