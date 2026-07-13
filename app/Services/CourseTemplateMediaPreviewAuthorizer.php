<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseTemplateMediaPreviewAuthorizer
{
    private const SLOTS = [
        'image' => ['field' => 'intro_image_media_file_id', 'usage' => 'intro_image', 'type' => 'image'],
        'video' => ['field' => 'intro_video_media_file_id', 'usage' => 'intro_video', 'type' => 'video'],
        'document' => ['field' => 'intro_document_media_file_id', 'usage' => 'intro_document', 'type' => 'document'],
    ];

    public function authorize(
        object $user,
        int $customerId,
        int $templateId,
        int $mediaFileId,
        string $slot
    ): object {
        $definition = self::SLOTS[$slot] ?? null;

        abort_if(! $definition, 404);

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->first();

        abort_if(! $template, 404);
        $this->authorizeTemplateAccess($user, $customerId, $template);

        abort_if((int) $template->{$definition['field']} !== $mediaFileId, 404);
        abort_if($slot === 'video' && $template->intro_video_source !== 'upload', 404);

        $media = DB::table('media_files as media')
            ->join('media_file_usages as usages', function ($join) use (
                $customerId,
                $templateId,
                $definition
            ): void {
                $join->on('usages.media_file_id', '=', 'media.id')
                    ->where('usages.customer_id', $customerId)
                    ->where('usages.owner_type', 'course_template')
                    ->where('usages.owner_id', $templateId)
                    ->where('usages.usage_type', $definition['usage'])
                    ->where('usages.status', 'active');
            })
            ->where('media.customer_id', $customerId)
            ->where('media.id', $mediaFileId)
            ->where('media.file_type', $definition['type'])
            ->where('media.status', 'ready')
            ->select('media.*')
            ->first();

        abort_if(! $media, 404);

        return $media;
    }

    private function authorizeTemplateAccess(
        object $user,
        int $customerId,
        object $template
    ): void {
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
