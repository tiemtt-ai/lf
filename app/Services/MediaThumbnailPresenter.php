<?php

namespace App\Services;

class MediaThumbnailPresenter
{
    public function __construct(private readonly TrustedVideoUrlService $trustedVideos) {}

    public function image(?object $media): array
    {
        return $media && $media->status === 'ready' && ! empty($media->signed_url)
            ? $this->result('image', 'image', $media->signed_url)
            : $this->result('fallback', 'image');
    }

    public function uploadedVideo(?object $media): array
    {
        // No approved generated-variant runtime is migrated yet.
        return $this->result($media?->status === 'ready' ? 'fallback' : 'pending', 'video');
    }

    public function embeddedVideo(?string $trustedUrl): array
    {
        if (! $trustedUrl) {
            return $this->result('fallback', 'video');
        }

        $video = $this->trustedVideos->normalize($trustedUrl);

        if ($video['provider'] === 'youtube') {
            return $this->result(
                'provider_video_thumbnail',
                'video',
                'https://i.ytimg.com/vi/'.$video['id'].'/hqdefault.jpg'
            );
        }

        // Vimeo requires an approved cached provider-metadata integration.
        return $this->result('fallback', 'video');
    }

    public function document(?object $media): array
    {
        $extension = strtolower((string) ($media?->extension ?? ''));
        $mime = strtolower((string) ($media?->mime_type ?? ''));
        $kind = match (true) {
            $mime === 'application/pdf', $extension === 'pdf' => 'pdf',
            in_array($extension, ['doc', 'docx'], true) => 'word',
            in_array($extension, ['xls', 'xlsx'], true) => 'spreadsheet',
            in_array($extension, ['ppt', 'pptx'], true) => 'presentation',
            default => 'document',
        };

        return $this->result(
            $media && $media->status !== 'ready' ? 'pending' : 'file_type_icon',
            $kind
        );
    }

    private function result(string $state, string $kind, ?string $url = null): array
    {
        return compact('state', 'kind', 'url');
    }
}
