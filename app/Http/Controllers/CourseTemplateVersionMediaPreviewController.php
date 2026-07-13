<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplateVersionMediaAuthorizer;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

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
            'Content-Disposition' => 'inline; filename="'.addcslashes(
                $media->display_name,
                "\\\""
            ).'"',
        ];

        if (config('filesystems.disks.'.$media->storage_disk.'.driver') === 'local') {
            return response()->file(Storage::disk($media->storage_disk)->path($media->storage_key), $headers);
        }

        return Storage::disk($media->storage_disk)->response($media->storage_key, $media->display_name, $headers);
    }
}
