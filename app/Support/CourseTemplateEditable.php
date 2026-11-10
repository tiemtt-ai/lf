<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An archived Course Template is read-only (LF-Core-Course, Course Template lifecycle).
 *
 * Every write to the working draft — its fields, Sections, Lessons, Activities,
 * teacher assignments and Learning Mapping Intents — takes the Template lock
 * through here, so the check runs on the locked row and an Archive committed
 * meanwhile is never overwritten. Removing a teacher stays allowed: it only
 * takes access away.
 */
final class CourseTemplateEditable
{
    /** Lock the Template row for a draft write; 404 when it is not this tenant's, refused when archived. */
    public static function lock(int $customerId, int $templateId): object
    {
        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->lockForUpdate()
            ->first();
        abort_if(! $template, 404);
        self::assert($template);

        return $template;
    }

    public static function assert(object $template): void
    {
        if ($template->status === CourseTemplateStatus::ARCHIVED) {
            throw ValidationException::withMessages([
                'template' => __('lf.LF_course_template_status_archived_readonly'),
            ]);
        }
    }
}
