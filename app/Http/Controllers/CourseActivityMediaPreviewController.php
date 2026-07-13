<?php

namespace App\Http\Controllers;

use App\Services\CourseActivityMediaPreviewAuthorizer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'Content-Disposition' => 'inline; filename="'.addcslashes($media->original_name, "\\\"").'"',
        ];

        if (config('filesystems.disks.'.$media->storage_disk.'.driver') === 'local') {
            return response()->file(
                Storage::disk($media->storage_disk)->path($media->storage_key),
                $headers
            );
        }

        return Storage::disk($media->storage_disk)->response(
            $media->storage_key,
            $media->original_name,
            $headers
        );
    }
}
