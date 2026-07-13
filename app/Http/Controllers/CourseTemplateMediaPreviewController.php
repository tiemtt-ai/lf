<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplateMediaPreviewAuthorizer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'Content-Disposition' => 'inline; filename="'.$media->display_name.'"',
        ];

        if (config('filesystems.disks.'.$media->storage_disk.'.driver') === 'local') {
            return response()->file(
                Storage::disk($media->storage_disk)->path($media->storage_key),
                $headers
            );
        }

        return Storage::disk($media->storage_disk)->response(
            $media->storage_key,
            $media->display_name,
            $headers
        );
    }
}
