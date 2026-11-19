<?php

namespace App\Support;

final readonly class ActivityAccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?int $prerequisiteVersionActivityId = null,
        /** ISO-8601 moment, with the Session's offset, a not-yet-open Activity opens by the class schedule (ADR-0022 Amendment 1.3, Đợt 2). */
        public ?string $unlockAt = null,
    ) {}
}
