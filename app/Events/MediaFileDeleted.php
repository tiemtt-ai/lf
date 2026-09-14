<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Media File became `deleted` and its Media-owned derived content was purged.
 *
 * Media-owned and consumer-agnostic: Media announces the tombstone and knows
 * nothing about who listens. Consumers that keep their own content derived from
 * the file (AI interpretations, ADR-0020 D5) erase it in their own domain.
 *
 * Carries identifiers only. A listener must not trust the event as proof of
 * deletion; it re-checks the tombstone through MediaService.
 */
final class MediaFileDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $mediaFileId,
    ) {}
}
