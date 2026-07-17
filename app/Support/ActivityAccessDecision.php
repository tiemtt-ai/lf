<?php

namespace App\Support;

final readonly class ActivityAccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?int $prerequisiteVersionActivityId = null,
    ) {}
}
