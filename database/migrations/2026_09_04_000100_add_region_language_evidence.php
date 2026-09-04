<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0019 v1.8: mot region song ngu giu du moi chu viet quan sat duoc thay vi
 * bi ep ve mot locale. `media_extracted_regions.detected_locale`/`script` giu
 * nguyen y nghia va bang dung row `ordinal = 1`, nen consumer cu khong doi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_region_languages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('region_id');
            $table->unsignedTinyInteger('ordinal');
            $table->string('script', 20);
            $table->string('locale', 20)->nullable();
            $table->unsignedInteger('char_count');
            $table->timestamps(6);
            $table->unique(['customer_id', 'region_id', 'ordinal'], 'uk_mrl_region_ordinal');
            $table->unique(['customer_id', 'region_id', 'script'], 'uk_mrl_region_script');
            $table->index(['customer_id', 'locale'], 'idx_mrl_locale');
            $table->foreign(['region_id', 'customer_id'], 'fk_mrl_region_tenant')
                ->references(['id', 'customer_id'])->on('media_extracted_regions')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_region_languages ADD CONSTRAINT chk_mrl_ordinal CHECK (ordinal BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE media_region_languages ADD CONSTRAINT chk_mrl_char_count CHECK (char_count >= 1)');
        }
    }

    /**
     * Fail-closed, theo tien le cua `2026_08_26_000000_open_extracted_text_sheet_locator`
     * va `2026_08_31_000200_add_document_dispatch_generation`.
     *
     * Row o day la bang chung ngon ngu da quan sat duoc cua mot revision, khong
     * phai cache co the dung lai. Drop bang khi da co row nghia la xoa han phan
     * evidence song ngu ma khong revision nao dung lai duoc — chay lai provider
     * chi tao revision moi, khong khoi phuc duoc ban da phat cho consumer.
     */
    public function down(): void
    {
        $evidence = DB::table('media_region_languages')->count();

        if ($evidence > 0) {
            throw new RuntimeException(
                "Rollback refused: {$evidence} region language evidence row(s) must be retained. "
                .'Archiving a revision does NOT clear them: the rows stay attached to their regions '
                .'so archived citations keep resolving. Delete the owning revisions deliberately — '
                .'the FK cascades from media_extracted_regions — before rolling back.'
            );
        }

        Schema::dropIfExists('media_region_languages');
    }
};
