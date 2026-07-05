<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaFileDeliveryController extends Controller
{
    public function show(Request $request, int $mediaFile): StreamedResponse
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        $file = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFile)
            ->first();

        abort_if(! $file || $file->status !== 'ready', 404);

        return Storage::disk($file->storage_disk)->response(
            $file->storage_key,
            $file->display_name,
            ['Content-Type' => $file->mime_type]
        );
    }
}
