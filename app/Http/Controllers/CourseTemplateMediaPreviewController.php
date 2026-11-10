<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplateMediaPreviewAuthorizer;
use App\Support\MediaFileResponse;
use App\Support\MediaInlineHeaders;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CourseTemplateMediaPreviewController extends Controller
{
    public function __construct(
        private readonly CourseTemplateMediaPreviewAuthorizer $authorizer
    ) {}

    public function show(
        Request $request,
        int $templateId,
        string $slot,
        int $mediaFileId
    ) {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId || ! $request->user(), 404);

        $media = $this->authorizer->authorize(
            $request->user(),
            $customerId,
            $templateId,
            $mediaFileId,
            $slot
        );
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $media->mime_type,
        ] + MediaInlineHeaders::for($media->mime_type);

        return MediaFileResponse::inline($media->storage_disk, $media->storage_key, (string) $media->display_name, $headers);
    }
}
