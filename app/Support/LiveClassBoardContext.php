<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * One authorized view of a live Session's Whiteboard (ADR-0002 § LiveClass
 * Whiteboard Amendment). Only LiveClassWhiteboardService builds it, after the
 * tenant, Cohort, Session and presenter or viewer checks have passed; Media
 * trusts it to issue board signed URLs without its own admin check.
 *
 * `role` is the actor's User role (teacher, customer_admin, student);
 * `canPresent` says whether the actor may hold the active-presenter lock.
 * `deliveryMode` is the Session's `delivery_mode`; it only picks a viewer's
 * default listening mode.
 */
final class LiveClassBoardContext
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $userId,
        public readonly string $role,
        public readonly int $sessionId,
        public readonly int $cohortId,
        public readonly int $versionId,
        public readonly ?int $versionLessonId,
        public readonly string $sessionTitle,
        public readonly string $cohortName,
        public readonly ?string $lessonTitle,
        public readonly bool $canPresent = true,
        public readonly ?CarbonImmutable $plannedEndAt = null,
        public readonly string $deliveryMode = 'online',
        public readonly ?string $meetingUrl = null,
    ) {}
}
