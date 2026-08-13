<?php

namespace App\Services;

use DomainException;

final class LearningEvidenceSourceGate
{
    public const INITIAL_SOURCE = 'teacher_judgment';

    public function assertOpen(string $sourceType): void
    {
        if ($sourceType !== self::INITIAL_SOURCE) {
            throw new DomainException('LF_EVIDENCE_SOURCE_CLOSED');
        }
    }
}
