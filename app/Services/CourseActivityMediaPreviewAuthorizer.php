<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseActivityMediaPreviewAuthorizer
{
    public function authorize(
        object $user,
        int $customerId,
        int $templateId,
        int $activityId,
        string $slot,
        int $mediaFileId
    ): object {
        abort_unless(CourseActivityMediaRules::isUploadedType($slot), 404);

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->first();
        abort_if(! $template, 404);
        $this->authorizeTemplateAccess($user, $customerId, $template);

        $activity = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $activityId)
            ->first();
        abort_if(! $activity || $activity->activity_type !== $slot, 404);

        $activeRelationships = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('owner_type', CourseActivityMediaRules::OWNER_TYPE)
            ->where('owner_id', $activityId)
            ->where('usage_type', $slot)
            ->where('status', 'active')
            ->get();

        abort_if(
            $activeRelationships->count() !== 1
                || (int) $activeRelationships->first()->media_file_id !== $mediaFileId,
            404
        );

        $media = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFileId)
            ->where('status', 'ready')
            ->first();

        abort_if(! $media || ! CourseActivityMediaRules::isCompatible($media, $slot), 404);

        return $media;
    }

    private function authorizeTemplateAccess(object $user, int $customerId, object $template): void
    {
        if ($user->role === 'customer_admin') {
            return;
        }

        $authorizedTeacher = $user->role === 'teacher'
            && (
                (int) $template->created_by === (int) $user->id
                || DB::table('core_course_template_teachers')
                    ->where('customer_id', $customerId)
                    ->where('template_id', $template->id)
                    ->where('teacher_id', $user->id)
                    ->where('status', 'active')
                    ->exists()
            );

        abort_unless($authorizedTeacher, 404);
    }
}
