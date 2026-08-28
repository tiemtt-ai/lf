<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crop cua mot vung: anh cat dung bbox cua chinh region do.
 *
 * Crop nam tren region chu khong phai media_variants vi no thuoc ve MOT VUNG
 * TRONG MOT REVISION — sinh cung revision, archived cung revision, va mat nghia
 * neu tach khoi bbox da tao ra no. Xem media_extracted_regions.md § Anh crop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_extracted_regions', function ($table): void {
            $table->string('crop_storage_key', 512)->nullable()->after('provider');
            $table->string('crop_mime_type', 100)->nullable()->after('crop_storage_key');
            $table->unsignedInteger('crop_width')->nullable()->after('crop_mime_type');
            $table->unsignedInteger('crop_height')->nullable()->after('crop_width');
            $table->unsignedInteger('crop_bytes')->nullable()->after('crop_height');
            $table->unique(['customer_id', 'crop_storage_key'], 'uq_mer_crop_storage_key');
        });

        // SQLite khong ALTER them CHECK duoc. Cot va UNIQUE van phai co o moi
        // driver, neu khong thi test chay tren SQLite se do o tang insert va
        // khong noi duoc gi ve dung ràng buoc.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE media_extracted_regions ADD CONSTRAINT chk_mer_crop_complete CHECK ('
            .' (crop_storage_key IS NULL AND crop_mime_type IS NULL AND crop_width IS NULL'
            .'  AND crop_height IS NULL AND crop_bytes IS NULL)'
            .' OR'
            .' (crop_storage_key IS NOT NULL AND crop_mime_type IS NOT NULL'
            .'  AND crop_width > 0 AND crop_height > 0 AND crop_bytes > 0))');

        DB::statement('ALTER TABLE media_extracted_regions ADD CONSTRAINT chk_mer_crop_needs_bbox'
            .' CHECK (crop_storage_key IS NULL OR bbox_x IS NOT NULL)');

        DB::statement('ALTER TABLE media_extracted_regions ADD CONSTRAINT chk_mer_crop_mime'
            ." CHECK (crop_mime_type IS NULL OR crop_mime_type IN ('image/png'))");
    }

    /**
     * Preflight: drop nam cot nay se xoa moi tham chieu toi crop, trong khi file
     * PNG van nam nguyen tren storage. Khong ai con biet chung thuoc ve dau —
     * ke ca crop chua PII.
     *
     * KHONG co escape hatch bang bien moi truong. Mot bien env dat nham trong CI
     * hay .env production se lam thao tac pha huy nay chay im lang. Duong dung la
     * don storage truoc, roi rollback moi chay.
     */
    public function down(): void
    {
        $withCrop = DB::table('media_extracted_regions')->whereNotNull('crop_storage_key')->count();
        if ($withCrop > 0) {
            throw new RuntimeException(
                "Rollback bi chan: {$withCrop} region dang tham chieu crop tren storage.\n"
                ."Don storage truoc — 'php artisan media:purge-deleted-storage' cho Media da xoa,\n"
                .'va xoa crop cua cac revision con lai theo runbook deletion cua ADR-0018.'
            );
        }

        if (DB::getDriverName() !== 'sqlite') {
            foreach (['chk_mer_crop_complete', 'chk_mer_crop_needs_bbox', 'chk_mer_crop_mime'] as $check) {
                DB::statement('ALTER TABLE media_extracted_regions DROP CONSTRAINT '.$check);
            }
        }

        Schema::table('media_extracted_regions', function ($table): void {
            $table->dropUnique('uq_mer_crop_storage_key');
            $table->dropColumn(['crop_storage_key', 'crop_mime_type', 'crop_width', 'crop_height', 'crop_bytes']);
        });
    }
};
