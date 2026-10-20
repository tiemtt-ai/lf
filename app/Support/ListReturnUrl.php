<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Remembers the last list page (with its filters and page number) a user looked at, so a detail page can offer a
 * link back to exactly that list. Stored in the session under a key such as `course-templates.admin.course-templates`.
 */
class ListReturnUrl
{
    public static function remember(Request $request, string $key): void
    {
        if ($request->isMethod('get') && $request->hasSession()) {
            $request->session()->put("list_return.{$key}", $request->fullUrl());
        }
    }

    /** The remembered list URL, or the fallback when there is none (or it points to another host). */
    public static function get(Request $request, string $key, string $fallback): string
    {
        $url = $request->hasSession() ? $request->session()->get("list_return.{$key}") : null;

        return is_string($url) && parse_url($url, PHP_URL_HOST) === $request->getHost() ? $url : $fallback;
    }
}
