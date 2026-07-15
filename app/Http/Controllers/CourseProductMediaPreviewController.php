<?php

namespace App\Http\Controllers;

use App\Services\CourseProductMediaAuthorizer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CourseProductMediaPreviewController extends Controller
{
    public function __construct(private readonly CourseProductMediaAuthorizer $authorizer) {}

    public function show(Request $request, int $productId, string $slot, int $mediaFileId)
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);
        $media = $this->authorizer->authorize($customerId, $productId, $mediaFileId, $slot);
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="'.addcslashes($media->display_name, '\\"').'"',
        ];

        if (config('filesystems.disks.'.$media->storage_disk.'.driver') === 'local') {
            return response()->file(Storage::disk($media->storage_disk)->path($media->storage_key), $headers);
        }

        return Storage::disk($media->storage_disk)->response($media->storage_key, $media->display_name, $headers);
    }
}
