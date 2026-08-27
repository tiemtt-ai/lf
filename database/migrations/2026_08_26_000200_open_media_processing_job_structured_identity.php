<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0019 v1.2 and media_processing_jobs v2.6.
 *
 * This migration also closes the physical drift recorded by DOC-CONFLICT-0020.
 * Existing history is audited, never rewritten, before constraints are added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $violations = [
            'output_type' => DB::table('media_processing_jobs')->whereNotNull('output_type')
                ->whereNotIn('output_type', $this->outputTypes())->count(),
            'virus_scan_output' => DB::table('media_processing_jobs')->where('job_type', 'virus_scan')
                ->where(fn ($query) => $query->whereNotNull('output_type')->orWhereNotNull('output_id'))->count(),
            'completed_before_started' => DB::table('media_processing_jobs')->whereNotNull('completed_at')
                ->whereNotNull('started_at')->whereColumn('completed_at', '<', 'started_at')->count(),
            'billable_pair' => DB::table('media_processing_jobs')
                ->where(fn ($query) => $query
                    ->where(fn ($q) => $q->whereNull('billable_units')->whereNotNull('billable_unit_type'))
                    ->orWhere(fn ($q) => $q->whereNotNull('billable_units')->whereNull('billable_unit_type')))
                ->count(),
        ];

        $blocking = array_filter($violations, fn (int $count): bool => $count > 0);
        if ($blocking !== []) {
            throw new RuntimeException('media_processing_jobs constraint preflight failed: '.json_encode($blocking));
        }

        DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_job_type');
        DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_job_type CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text','caption','virus_scan','compress','structured_extraction'))");
        DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_output_type CHECK (output_type IS NULL OR output_type IN ('transcript','caption','extracted_text','variant','extracted_region','extracted_table'))");
        DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_virus_output CHECK (job_type <> 'virus_scan' OR (output_type IS NULL AND output_id IS NULL))");
        DB::statement('ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_completed_order CHECK (completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at)');
        DB::statement('ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_billable_pair CHECK ((billable_units IS NULL AND billable_unit_type IS NULL) OR (billable_units IS NOT NULL AND billable_unit_type IS NOT NULL))');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $blocking = DB::table('media_processing_jobs')
            ->where('job_type', 'structured_extraction')
            ->orWhereIn('output_type', ['extracted_region', 'extracted_table'])
            ->count();
        if ($blocking > 0) {
            throw new RuntimeException(
                "Rollback refused: {$blocking} media_processing_jobs row(s) use structured extraction identity. "
                .'Historical jobs must not be rewritten to fit the older vocabulary.'
            );
        }

        foreach (['chk_mpj_billable_pair', 'chk_mpj_completed_order', 'chk_mpj_virus_output', 'chk_mpj_output_type'] as $constraint) {
            DB::statement("ALTER TABLE media_processing_jobs DROP CONSTRAINT {$constraint}");
        }
        DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_job_type');
        DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_job_type CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text','caption','virus_scan','compress'))");
    }

    /** @return array<int, string> */
    private function outputTypes(): array
    {
        return ['transcript', 'caption', 'extracted_text', 'variant', 'extracted_region', 'extracted_table'];
    }
};
