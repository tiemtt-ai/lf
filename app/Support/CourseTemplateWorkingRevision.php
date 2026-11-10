<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The working draft of a Course Template changed: move its revision counter.
 *
 * Publish compares the revision the admin confirmed with the locked Template
 * (LF-Core-Course, Course Template lifecycle), so every change to what a Version
 * snapshots has to move it: Sections, Lessons and Activities as much as the
 * Template's own fields. Call it inside the transaction that holds the Template lock.
 */
class CourseTemplateWorkingRevision
{
    public static function bump(int $customerId, int $templateId): void
    {
        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->increment('working_revision', 1, ['updated_at' => now()]);
    }
}
