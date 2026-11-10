<?php

namespace App\Http\Controllers;

use App\Services\CourseActivityMediaPreviewAuthorizer;
use App\Support\MediaFileResponse;
use App\Support\MediaInlineHeaders;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CourseActivityMediaPreviewController extends Controller
{
    public function __construct(
        private readonly CourseActivityMediaPreviewAuthorizer $authorizer
    ) {}

    public function show(
        Request $request,
        int $templateId,
        int $activityId,
        string $slot,
        int $mediaFileId
    ) {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId || ! $request->user(), 404);

        $media = $this->authorizer->authorize(
            $request->user(),
            $customerId,
            $templateId,
            $activityId,
            $slot,
            $mediaFileId
        );
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $media->mime_type,
        ] + MediaInlineHeaders::for($media->mime_type);

        return MediaFileResponse::inline($media->storage_disk, $media->storage_key, (string) $media->original_name, $headers);
    }
}
