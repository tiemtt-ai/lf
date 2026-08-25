<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseMediaOwnerContextAuthorizer
{
    public function authorized(int $customerId, string $ownerType, int $ownerId, int $actorId): bool
    {
        $actor = DB::table('users')->where('customer_id', $customerId)
            ->where('id', $actorId)->where('status', 'active')->first();
        if (! $actor) {
            return false;
        }
        if (! in_array($ownerType, ['course_activity', 'course_version_activity'], true)) {
            return false;
        }

        $templateId = $ownerType === 'course_activity'
            ? DB::table('core_course_template_activities')->where('customer_id', $customerId)->where('id', $ownerId)->value('template_id')
            : DB::table('core_course_template_version_activities as a')->join('core_course_template_versions as v', function ($join): void {
                $join->on('v.id', '=', 'a.template_version_id')->on('v.customer_id', '=', 'a.customer_id');
            })->where('a.customer_id', $customerId)->where('a.id', $ownerId)->value('v.template_id');
        if (! $templateId) {
            return false;
        }
        if ($actor->role === 'customer_admin') {
            return true;
        }
        if ($actor->role !== 'teacher') {
            return false;
        }

        return DB::table('core_course_template_teachers')->where('customer_id', $customerId)
            ->where('template_id', $templateId)->where('teacher_id', $actor->id)->where('status', 'active')->exists();
    }
}
