<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaFileDeliveryController extends Controller
{
    public function show(Request $request, int $mediaFile)
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        $file = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFile)
            ->first();

        abort_if(! $file || $file->status !== 'ready', 404);

        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="'.$file->display_name.'"',
        ];

        if ($this->isLocalDisk($file->storage_disk)) {
            return response()->file(
                Storage::disk($file->storage_disk)->path($file->storage_key),
                $headers
            );
        }

        return Storage::disk($file->storage_disk)->response(
            $file->storage_key,
            $file->display_name,
            $headers
        );
    }

    private function isLocalDisk(string $disk): bool
    {
        return config('filesystems.disks.'.$disk.'.driver') === 'local';
    }
}
