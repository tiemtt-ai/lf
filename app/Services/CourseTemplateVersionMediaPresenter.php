<?php

namespace App\Services;

use Throwable;

class CourseTemplateVersionMediaPresenter
{
    public function __construct(
        private readonly CourseTemplateVersionMediaAuthorizer $authorizer,
        private readonly MediaThumbnailPresenter $thumbnails,
        private readonly TrustedVideoUrlService $trustedVideos
    ) {}

    public function present(object $version): array
    {
        $image = $this->uploaded($version, 'image', $version->intro_image_media_file_id_snapshot);
        $document = $this->uploaded($version, 'document', $version->intro_document_media_file_id_snapshot);
        $video = match ($version->intro_video_source_snapshot) {
            null => $this->empty(),
            'upload' => $this->uploaded($version, 'video', $version->intro_video_media_file_id_snapshot),
            'embed' => $this->embedded($version),
            default => $this->unavailable(),
        };

        return compact('image', 'video', 'document');
    }

    private function uploaded(object $version, string $slot, ?int $mediaFileId): array
    {
        if (! $mediaFileId) {
            return $this->empty();
        }

        $media = $this->authorizer->resolve(
            (int) $version->customer_id,
            (int) $version->template_id,
            (int) $version->id,
            $mediaFileId,
            $slot
        );

        if (! $media) {
            return $this->unavailable();
        }

        $url = route('admin.course-templates.versions.media.preview', [
            $version->template_id,
            $version->id,
            $slot,
            $media->id,
        ]);
        $media->signed_url = $url;
        $thumbnail = match ($slot) {
            'image' => $this->thumbnails->image($media),
            'video' => $this->thumbnails->uploadedVideo($media),
            'document' => $this->thumbnails->document($media),
        };

        return compact('media', 'thumbnail', 'url') + ['state' => 'available', 'kind' => $slot];
    }

    private function embedded(object $version): array
    {
        if ($version->intro_video_media_file_id_snapshot
            || ! $version->intro_video_embed_url_snapshot
            || ! in_array($version->intro_video_provider_snapshot, ['youtube', 'vimeo'], true)) {
            return $this->unavailable();
        }

        try {
            $normalized = $this->trustedVideos->normalize($version->intro_video_embed_url_snapshot);
        } catch (Throwable) {
            return $this->unavailable();
        }

        if ($normalized['provider'] !== $version->intro_video_provider_snapshot) {
            return $this->unavailable();
        }

        return [
            'state' => 'available',
            'kind' => 'embed',
            'url' => $this->trustedVideos->embedUrl($normalized['url']),
            'provider' => $normalized['provider'],
            'thumbnail' => $this->thumbnails->embeddedVideo($normalized['url']),
        ];
    }

    private function empty(): array
    {
        return ['state' => 'empty'];
    }

    private function unavailable(): array
    {
        return ['state' => 'unavailable'];
    }
}
