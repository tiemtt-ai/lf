<?php

namespace App\Http\Controllers;

use App\Services\CourseProductMediaAuthorizer;
use App\Support\MediaFileResponse;
use App\Support\MediaInlineHeaders;
use App\Support\TenantContext;
use Illuminate\Http\Request;

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
        ] + MediaInlineHeaders::for($media->mime_type);

        return MediaFileResponse::inline($media->storage_disk, $media->storage_key, (string) $media->display_name, $headers);
    }
}
