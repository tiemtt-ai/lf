<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0004 D7: observed language evidence belongs to each transcript timespan,
 * not to the requested profile and not to the Media File as a whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_spoken_languages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('transcript_id');
            $table->unsignedTinyInteger('ordinal');
            $table->string('locale', 20);
            $table->unsignedInteger('char_count');
            $table->timestamps(6);

            $table->unique(['customer_id', 'transcript_id', 'ordinal'], 'uk_msl_transcript_ordinal');
            $table->unique(['customer_id', 'transcript_id', 'locale'], 'uk_msl_transcript_locale');
            $table->index(['customer_id', 'locale'], 'idx_msl_locale');
            $table->foreign(['transcript_id', 'customer_id'], 'fk_msl_transcript_tenant')
                ->references(['id', 'customer_id'])->on('media_transcripts')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_spoken_languages ADD CONSTRAINT chk_msl_ordinal CHECK (ordinal BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE media_spoken_languages ADD CONSTRAINT chk_msl_char_count CHECK (char_count >= 1)');
        }
    }

    public function down(): void
    {
        $evidence = DB::table('media_spoken_languages')->count();
        if ($evidence > 0) {
            throw new RuntimeException(
                "Rollback refused: {$evidence} spoken-language evidence row(s) must be retained. "
                .'Delete the owning transcript revisions deliberately before rolling back.'
            );
        }

        Schema::dropIfExists('media_spoken_languages');
    }
};
