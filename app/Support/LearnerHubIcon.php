<?php

namespace App\Support;

/** Line icons of the "Khóa học của tôi" hub tiles (standard B2B look), shared by its pages. */
final class LearnerHubIcon
{
    private const PATHS = [
        'courses' => '<path d="M4 5.5c2.7-1.2 5.3-1.2 8 0v14c-2.7-1.2-5.3-1.2-8 0zM20 5.5c-2.7-1.2-5.3-1.2-8 0v14c2.7-1.2 5.3-1.2 8 0z"/>',
        'classes' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 21h8M12 16v5m-2-14 5 3-5 3z"/>',
        'exams' => '<path d="M9 4H6a2 2 0 0 0-2 2v14h16V6a2 2 0 0 0-2-2h-3"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 12 2 2 6-6M8 17h8"/>',
        'results' => '<path d="M4 3v17h17M8 16v-4m5 4V8m5 8V5"/>',
    ];

    public static function svg(string $name, int $size = 23): string
    {
        return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            .self::PATHS[$name].'</svg>';
    }
}
