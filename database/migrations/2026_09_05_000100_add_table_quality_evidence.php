<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-0019 v1.9: revision-bound table completeness from observed cell geometry and PDF ink. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_extracted_tables', function (Blueprint $table): void {
            $table->string('quality_status', 20)->default('undetermined')->after('has_header');
        });
        Schema::table('media_table_cells', function (Blueprint $table): void {
            $table->decimal('bbox_x', 9, 6)->nullable()->after('is_header');
            $table->decimal('bbox_y', 9, 6)->nullable()->after('bbox_x');
            $table->decimal('bbox_width', 9, 6)->nullable()->after('bbox_y');
            $table->decimal('bbox_height', 9, 6)->nullable()->after('bbox_width');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE media_extracted_tables ADD CONSTRAINT chk_met_quality_status CHECK (quality_status IN ('complete','incomplete','undetermined'))");
            DB::statement('ALTER TABLE media_table_cells ADD CONSTRAINT chk_mtc_bbox CHECK ((bbox_x IS NULL AND bbox_y IS NULL AND bbox_width IS NULL AND bbox_height IS NULL) OR (bbox_x IS NOT NULL AND bbox_y IS NOT NULL AND bbox_width IS NOT NULL AND bbox_height IS NOT NULL AND bbox_x >= 0 AND bbox_y >= 0 AND bbox_width > 0 AND bbox_height > 0 AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1))');
        }
    }

    public function down(): void
    {
        $measured = DB::table('media_extracted_tables')->where('quality_status', '<>', 'undetermined')->count();
        $geometry = DB::table('media_table_cells')->whereNotNull('bbox_x')->count();
        if ($measured > 0 || $geometry > 0) {
            throw new RuntimeException(
                "Rollback refused: {$measured} measured table(s) and {$geometry} cell bbox row(s) are revision evidence. "
                .'Delete the owning revisions deliberately before rolling back.'
            );
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_extracted_tables DROP CONSTRAINT chk_met_quality_status');
            DB::statement('ALTER TABLE media_table_cells DROP CONSTRAINT chk_mtc_bbox');
        }
        Schema::table('media_table_cells', function (Blueprint $table): void {
            $table->dropColumn(['bbox_x', 'bbox_y', 'bbox_width', 'bbox_height']);
        });
        Schema::table('media_extracted_tables', function (Blueprint $table): void {
            $table->dropColumn('quality_status');
        });
    }
};
