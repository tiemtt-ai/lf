<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `idempotency_key` = job_type : media_file_id : source_fingerprint :
 * processing_version : output_profile_hash : attempt.
 *
 * Amendment Record 2.19 § 1 buoc `processing_version` cua video STT chua ca
 * canonical ffmpeg extraction profile. Version vi the dai 75 ky tu thay vi 31,
 * va key dai 225 — vuot VARCHAR(191).
 *
 * Do rong moi duoc tinh tu BIEN cua schema, khong tu key dai nhat dang co:
 *
 *   job_type varchar(50)        50
 *   media_file_id bigint        20   (18446744073709551615)
 *   source_fingerprint char(64) 64
 *   processing_version vc(100) 100
 *   output_profile_hash char(64) 64
 *   attempt int unsigned        10
 *   5 dau phan cach              5
 *                             ----
 *                              313
 *
 * 255 KHONG du: no chi chua duoc processing_version toi 42 ky tu, chua toi mot
 * nua do rong cot. Chon 320: phu bien 313 va con du. 320 x 4 = 1.280 byte, xa
 * tran 3.072 byte cua index InnoDB.
 *
 * Con so 191 la lua chon cu tu thoi index utf8mb4 gioi han 767 byte, khong phai
 * mot rang buoc con hieu luc.
 *
 * Khong hash lai key: hash se doi moi key dang ton tai va tach doi moi retry
 * chain dang chay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE media_processing_jobs MODIFY idempotency_key VARCHAR(320) NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $tooLong = DB::table('media_processing_jobs')
            ->whereRaw('CHAR_LENGTH(idempotency_key) > 191')->count();
        if ($tooLong > 0) {
            throw new RuntimeException(
                "Rollback bi chan: {$tooLong} job co idempotency_key dai hon 191 ky tu.\n"
                .'Thu hep cot se cat cut key va lam hai chain khac nhau trung khoa.'
            );
        }

        DB::statement('ALTER TABLE media_processing_jobs MODIFY idempotency_key VARCHAR(191) NOT NULL');
    }
};
