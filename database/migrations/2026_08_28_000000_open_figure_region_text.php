<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gỡ `chk_mer_figure_text` để region `role = 'figure'` mang được text quan sát
 * trong bbox của chính nó.
 *
 * ADR-0019 § D7 điểm 3 buộc Media ghi "chữ và số nằm trong vùng — nhãn trục,
 * chú thích, text trong khối", trong khi CHECK này cấm đúng điều đó. Mâu thuẫn
 * được đăng ký tại DOC-CONFLICT-0022 và Owner quyết "Có" ngày 2026-08-28;
 * `media_extracted_regions.md` lên v1.4 tương ứng.
 *
 * Đo trên PDF ALLIVA 16 trang trước khi sửa: 62/62 figure region có `text` NULL,
 * và không một text region nào nằm trong bbox của chúng — text theo trang 47.852
 * ký tự nhưng text ở tầng region chỉ 13.373.
 *
 * Chỉ gỡ ràng buộc, không đổi cột và không đụng dữ liệu. Text hiện có vẫn NULL
 * cho tới lần chạy lại structured extraction kế tiếp, và lần chạy đó sinh
 * revision mới theo đúng luật stale — bản cũ chuyển `archived`, không bị ghi đè.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE media_extracted_regions DROP CONSTRAINT chk_mer_figure_text');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Rollback chỉ khôi phục được khi chưa có figure nào mang text; nếu đã có,
        // ràng buộc cũ không còn đúng với dữ liệu và phải xử lý riêng.
        DB::statement(
            'ALTER TABLE media_extracted_regions'
            ." ADD CONSTRAINT chk_mer_figure_text CHECK (role <> 'figure' OR text IS NULL)"
        );
    }
};
