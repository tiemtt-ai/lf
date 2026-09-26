<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A processing job committed a `ready` revision of a Media File's derived
 * output (Knowledge Sync Contract A2).
 *
 * Media-owned and consumer-agnostic, like MediaFileDeleted: it carries
 * identifiers only, is dispatched after the persisting transaction commits,
 * and is an accelerator, not a delivery guarantee. Listeners re-check the
 * current revision through Media Read; a missed event is recovered by the
 * consumer's own reconciliation.
 */
final class MediaRevisionReady
{
    use Dispatchable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $mediaFileId,
    ) {}
}
