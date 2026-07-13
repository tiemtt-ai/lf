<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseTemplateVersionMediaAuthorizer
{
    private const SLOTS = [
        'image' => ['field' => 'intro_image_media_file_id_snapshot', 'usage' => 'intro_image', 'type' => 'image'],
        'video' => ['field' => 'intro_video_media_file_id_snapshot', 'usage' => 'intro_video', 'type' => 'video'],
        'document' => ['field' => 'intro_document_media_file_id_snapshot', 'usage' => 'intro_document', 'type' => 'document'],
    ];

    public function resolve(
        int $customerId,
        int $templateId,
        int $versionId,
        int $mediaFileId,
        string $slot
    ): ?object {
        $definition = self::SLOTS[$slot] ?? null;

        if (! $definition) {
            return null;
        }

        $version = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $versionId)
            ->first();

        if (! $version
            || (int) $version->{$definition['field']} !== $mediaFileId
            || ($slot === 'video' && $version->intro_video_source_snapshot !== 'upload')) {
            return null;
        }

        return DB::table('media_files as media')
            ->join('media_file_usages as usages', function ($join) use (
                $customerId,
                $versionId,
                $definition
            ): void {
                $join->on('usages.media_file_id', '=', 'media.id')
                    ->where('usages.customer_id', $customerId)
                    ->where('usages.owner_type', 'course_template_version')
                    ->where('usages.owner_id', $versionId)
                    ->where('usages.usage_type', $definition['usage'])
                    ->where('usages.status', 'active');
            })
            ->where('media.customer_id', $customerId)
            ->where('media.id', $mediaFileId)
            ->where('media.file_type', $definition['type'])
            ->where('media.status', 'ready')
            ->select('media.*')
            ->first();
    }

    public function authorize(
        int $customerId,
        int $templateId,
        int $versionId,
        int $mediaFileId,
        string $slot
    ): object {
        $media = $this->resolve(
            $customerId,
            $templateId,
            $versionId,
            $mediaFileId,
            $slot
        );

        abort_if(! $media, 404);

        return $media;
    }
}
