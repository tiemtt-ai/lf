<?php

namespace App\Http\Controllers;

use App\Support\MediaInlineHeaders;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

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
            'Content-Disposition' => $this->inlineDisposition((string) $file->display_name),
        ] + MediaInlineHeaders::for($file->mime_type);

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

    /**
     * A `preview` of a ready Media File of this tenant: the PDF of an Office
     * document or the faststart copy of a video, served with the row's own
     * MIME type (a video gets Range requests). Archived is still served for the
     * life of an already signed URL: the board may have asked for it just
     * before a newer revision replaced it.
     */
    public function variant(Request $request, int $mediaFile, int $variant)
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        $file = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFile)->first();
        abort_if(! $file || $file->status !== 'ready', 404);
        $row = DB::table('media_variants')->where('customer_id', $customerId)->where('media_file_id', $file->id)
            ->where('id', $variant)->where('variant_type', 'preview')->whereIn('status', ['ready', 'archived'])->first();
        abort_if(! $row, 404);

        $video = str_starts_with((string) $row->mime_type, 'video/');
        $extension = $video ? pathinfo((string) $row->storage_key, PATHINFO_EXTENSION) : 'pdf';
        $name = pathinfo((string) $file->display_name, PATHINFO_FILENAME).'.'.$extension;
        $headers = [
            'Content-Type' => $video ? (string) $row->mime_type : 'application/pdf',
            'Content-Disposition' => $this->inlineDisposition($name),
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($this->isLocalDisk($file->storage_disk)) {
            $path = Storage::disk($file->storage_disk)->path($row->storage_key);
            abort_unless(is_file($path), 404);

            return response()->file($path, $headers);
        }

        return Storage::disk($file->storage_disk)->response($row->storage_key, $name, $headers);
    }

    /**
     * `inline` with the display name as RFC 6266 `filename*` (UTF-8, so Vietnamese
     * names survive) and an ASCII `filename` fallback. Control characters and path
     * separators are dropped first; quotes are escaped by HeaderUtils.
     */
    private function inlineDisposition(string $name): string
    {
        $name = trim(str_replace(['/', '\\'], '-', (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name)));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }
        $fallback = trim((string) preg_replace('/[^\x20-\x7E]|[%"]/', '', Str::ascii($name)));

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, $fallback !== '' ? $fallback : 'file');
    }

    private function isLocalDisk(string $disk): bool
    {
        return config('filesystems.disks.'.$disk.'.driver') === 'local';
    }
}
