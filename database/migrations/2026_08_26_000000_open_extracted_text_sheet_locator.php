<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0019 § D1 opens `locator_type` to `sheet`; DOC-CONFLICT-0017 option (a)
 * opens `extraction_method` to `spreadsheet_cells`.
 *
 * Both CHECKs move in this one migration on purpose. They touch the same table
 * and the same spreadsheet revision, so splitting them would force a second
 * `processing_version` generation for no gain — and would let a half-applied
 * pair reach production, where the first `spreadsheet_cells` row fails.
 */
return new class extends Migration
{
    private const LOCATOR = "CHECK (locator_type IN ('page','sheet'))";

    private const METHOD = "CHECK (extraction_method IN ('ocr','embedded_text','spreadsheet_cells'))";

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE media_extracted_texts DROP CONSTRAINT chk_met_locator');
        DB::statement('ALTER TABLE media_extracted_texts ADD CONSTRAINT chk_met_locator '.self::LOCATOR);
        DB::statement('ALTER TABLE media_extracted_texts DROP CONSTRAINT chk_met_method');
        DB::statement('ALTER TABLE media_extracted_texts ADD CONSTRAINT chk_met_method '.self::METHOD);
    }

    /**
     * Fail-closed. Narrowing a CHECK while rows already use the wider vocabulary
     * would either be rejected by the engine or, worse, tempt a "fix" that
     * rewrites `sheet` back to `page` — which silently points every citation
     * already handed to a consumer at the wrong unit.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $blocking = DB::table('media_extracted_texts')
            ->where('locator_type', 'sheet')
            ->orWhere('extraction_method', 'spreadsheet_cells')
            ->count();

        if ($blocking > 0) {
            throw new RuntimeException(
                "Rollback refused: {$blocking} media_extracted_texts row(s) already use "
                .'locator_type=sheet or extraction_method=spreadsheet_cells. Narrowing the '
                .'CHECK would require rewriting them, and rewriting a locator invalidates '
                .'every citation already issued for that revision. Archive and remove those '
                .'revisions deliberately before rolling back.'
            );
        }

        DB::statement('ALTER TABLE media_extracted_texts DROP CONSTRAINT chk_met_locator');
        DB::statement("ALTER TABLE media_extracted_texts ADD CONSTRAINT chk_met_locator CHECK (locator_type = 'page')");
        DB::statement('ALTER TABLE media_extracted_texts DROP CONSTRAINT chk_met_method');
        DB::statement("ALTER TABLE media_extracted_texts ADD CONSTRAINT chk_met_method CHECK (extraction_method IN ('ocr','embedded_text'))");
    }
};
