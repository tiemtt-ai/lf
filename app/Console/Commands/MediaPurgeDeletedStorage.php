<?php

namespace App\Console\Commands;

use App\Services\MediaStorageResidueSweeper;
use Illuminate\Console\Command;

/**
 * Ban CLI cua cung mot luat quet trong MediaStorageResidueSweeper.
 *
 * KHONG duoc dang ky vao lich chay tu dong. Quyet dinh cua Owner 2026-08-28:
 * khong co tac vu nao tu dong xoa file. Admin bam nut trong Quan ly Media,
 * hoac operator chay lenh nay bang tay.
 */
class MediaPurgeDeletedStorage extends Command
{
    protected $signature = 'media:purge-deleted-storage
        {--customer= : Chi quet mot tenant}
        {--limit=500 : So Media co residue xu ly toi da moi lan chay}
        {--dry-run : Chi bao cao, khong xoa}';

    protected $description = 'Xoa source/crop mo coi cua Media da deleted va cua revision structured that bai.';

    public function handle(MediaStorageResidueSweeper $sweeper): int
    {
        $customer = $this->option('customer') === null ? null : (int) $this->option('customer');
        $found = $sweeper->scan($customer, max(1, (int) $this->option('limit')));

        if ($found === []) {
            $this->info('Khong con object nao sot lai.');

            return self::SUCCESS;
        }

        $this->warn(count($found).' Media con object mo coi tren storage.');
        $failed = 0;

        foreach ($found as $item) {
            $id = $item['media']->id;
            if ($item['keys'] === []) {
                $failed++;
                $this->error("  media {$id}: khong kiem tra duoc storage");

                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("  media {$id}: ".count($item['keys']).' object con lai');

                continue;
            }
            $result = $sweeper->purge($item);
            if ($result['success']) {
                $this->line("  media {$id}: da xoa {$result['deleted_count']} object");

                continue;
            }
            $failed++;
            $this->error("  media {$id}: van that bai");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
