<?php

namespace App\Support;

final readonly class LessonAccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?int $prerequisiteVersionLessonId = null,
        public ?string $unlockAt = null,
    ) {}
}
