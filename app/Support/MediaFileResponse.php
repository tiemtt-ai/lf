<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a stored Media object `inline` through the storage disk abstraction.
 *
 * A `ready` row whose object is gone (deleted on disk, lost from the bucket) is a
 * 404, not a 500. The file name goes out as RFC 6266 `filename*` (UTF-8) with an
 * ASCII `filename` fallback, so a quote, CR/LF or Vietnamese name in a display
 * name never breaks or injects into the header.
 */
class MediaFileResponse
{
    /** @param  array<string, string>  $headers */
    public static function inline(string $disk, string $storageKey, string $name, array $headers = []): Response
    {
        $headers['Content-Disposition'] = self::inlineDisposition($name);

        if (config('filesystems.disks.'.$disk.'.driver') === 'local') {
            $path = Storage::disk($disk)->path($storageKey);
            abort_unless(is_file($path), 404);

            return response()->file($path, $headers);
        }

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($storageKey), 404);

        return $storage->response($storageKey, $name, $headers);
    }

    /**
     * `inline` with the name as `filename*` and an ASCII `filename` fallback.
     * Control characters and path separators are dropped first; quotes are
     * escaped by HeaderUtils.
     */
    public static function inlineDisposition(string $name): string
    {
        $name = trim(str_replace(['/', '\\'], '-', (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name)));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }
        $fallback = trim((string) preg_replace('/[^\x20-\x7E]|[%"]/', '', Str::ascii($name)));

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, $fallback !== '' ? $fallback : 'file');
    }
}
