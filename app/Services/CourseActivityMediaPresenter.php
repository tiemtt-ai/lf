<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseActivityMediaPresenter
{
    public function __construct(private readonly MediaThumbnailPresenter $thumbnails) {}

    public function present(int $customerId, object $activity): array
    {
        $type = (string) $activity->activity_type;

        if (! CourseActivityMediaRules::isUploadedType($type)) {
            return $this->result('empty', $type);
        }

        $relationships = DB::table('media_file_usages as usages')
            ->leftJoin('media_files as media', function ($join): void {
                $join->on('media.id', '=', 'usages.media_file_id')
                    ->on('media.customer_id', '=', 'usages.customer_id');
            })
            ->where('usages.customer_id', $customerId)
            ->where('usages.owner_type', CourseActivityMediaRules::OWNER_TYPE)
            ->where('usages.owner_id', $activity->id)
            ->where('usages.usage_type', $type)
            ->orderBy('usages.id')
            ->select([
                'usages.id as usage_id',
                'usages.status as usage_status',
                'media.id',
                'media.customer_id',
                'media.file_type',
                'media.mime_type',
                'media.original_name',
                'media.display_name',
                'media.extension',
                'media.status',
            ])
            ->get();

        if ($relationships->isEmpty()) {
            return $this->result('empty', $type);
        }

        $active = $relationships->where('usage_status', 'active');
        $media = $active->count() === 1 ? $active->first() : null;

        if (! $media
            || ! $media->id
            || (int) $media->customer_id !== $customerId
            || $media->status !== 'ready'
            || ! CourseActivityMediaRules::isCompatible($media, $type)) {
            return $this->result('unavailable', $type);
        }

        $thumbnail = match ($type) {
            'video' => $this->thumbnails->uploadedVideo($media),
            'document' => $this->thumbnails->document($media),
            default => ['state' => 'file_type_icon', 'kind' => 'audio', 'url' => null],
        };

        return $this->result('available', $type, $media, $thumbnail);
    }

    private function result(
        string $state,
        string $type,
        ?object $media = null,
        ?array $thumbnail = null
    ): array {
        return compact('state', 'type', 'media', 'thumbnail');
    }
}
