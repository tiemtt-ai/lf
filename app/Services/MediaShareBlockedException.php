<?php

namespace App\Services;

use RuntimeException;

/**
 * Revoking a share would take a Media away from teachers who still use it.
 */
class MediaShareBlockedException extends RuntimeException
{
    /**
     * @param  array<int, array{id: int, name: string, places: int}>  $users
     */
    public function __construct(public readonly array $users)
    {
        parent::__construct(__('lf.LF_media_share_blocked', [
            'people' => collect($users)->map(fn (array $user): string => $user['name'].' ('.$user['places'].')')->implode(', '),
        ]));
    }
}
