<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caption duoc dung tu transcript (Owner quyet dinh 2026-08-29, DOC-CONFLICT-0024),
 * nen no phu thuoc MOT transcript revision cu the.
 *
 * `source_fingerprint` khong dien dat duoc dieu do: no la van tay cua BINARY GOC
 * nen khong doi khi transcript sinh revision moi. Thieu cot nay thi mot caption
 * dung tu transcript v1 van `ready` sau khi transcript len v2 — nguoi hoc xem phu
 * de cu trong khi AI doc ban moi, va khong truy duoc bang SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_captions', function ($table): void {
            $table->string('transcript_processing_version', 100)->nullable()->after('processing_version');
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Neo vao `processing_job_id` chu khong vao `status`: cot do nullable, nen
        // bang nay chua duoc ca caption KHONG do job sinh ra. Caption do Media sinh
        // ra thi bat buoc khai nguon; caption den tu duong khac thi khong co gi de khai.
        DB::statement('ALTER TABLE media_captions ADD CONSTRAINT chk_mc_transcript_provenance'
            .' CHECK (processing_job_id IS NULL OR transcript_processing_version IS NOT NULL)');
    }

    public function down(): void
    {
        $derived = DB::table('media_captions')->whereNotNull('transcript_processing_version')->count();
        if ($derived > 0) {
            throw new RuntimeException(
                "Rollback bi chan: {$derived} caption dang ghi transcript revision nguon.\n"
                .'Drop cot nay se mat provenance, va khong con cach nao biet caption nao stale khi transcript len revision moi.'
            );
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_captions DROP CONSTRAINT chk_mc_transcript_provenance');
        }

        Schema::table('media_captions', function ($table): void {
            $table->dropColumn('transcript_processing_version');
        });
    }
};
