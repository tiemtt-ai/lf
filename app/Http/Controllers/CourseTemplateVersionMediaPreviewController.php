<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplateVersionMediaAuthorizer;
use App\Support\MediaFileResponse;
use App\Support\MediaInlineHeaders;
use App\Support\TenantContext;

class CourseTemplateVersionMediaPreviewController extends Controller
{
    public function __construct(
        private readonly CourseTemplateVersionMediaAuthorizer $authorizer
    ) {}

    public function show(
        int $templateId,
        int $versionId,
        string $slot,
        int $mediaFileId
    ) {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        $media = $this->authorizer->authorize(
            $customerId,
            $templateId,
            $versionId,
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
