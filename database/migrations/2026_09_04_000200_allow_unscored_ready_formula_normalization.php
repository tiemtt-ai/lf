<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE media_extracted_formulas DROP CONSTRAINT chk_mef_ready');
        DB::statement("ALTER TABLE media_extracted_formulas ADD CONSTRAINT chk_mef_ready CHECK ((normalization_status = 'ready' AND normalized_format IS NOT NULL AND normalized_value IS NOT NULL) OR (normalization_status <> 'ready' AND normalized_value IS NULL))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $unscored = DB::table('media_extracted_formulas')
            ->where('normalization_status', 'ready')->whereNull('confidence_score')->count();
        if ($unscored > 0) {
            throw new RuntimeException("Cannot restore scored-only formula normalization while {$unscored} ready row(s) have NULL confidence; remove the owning revision(s) first so cascade clears their evidence.");
        }

        DB::statement('ALTER TABLE media_extracted_formulas DROP CONSTRAINT chk_mef_ready');
        DB::statement("ALTER TABLE media_extracted_formulas ADD CONSTRAINT chk_mef_ready CHECK ((normalization_status = 'ready' AND normalized_format IS NOT NULL AND normalized_value IS NOT NULL AND confidence_score IS NOT NULL) OR (normalization_status <> 'ready' AND normalized_value IS NULL))");
    }
};
