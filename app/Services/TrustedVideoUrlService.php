<?php

namespace App\Services;

use InvalidArgumentException;

class TrustedVideoUrlService
{
    public function normalize(string $url): array
    {
        if ($url !== strip_tags($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid video URL.');
        }

        $parts = parse_url(trim($url));
        if (($parts['scheme'] ?? null) !== 'https') {
            throw new InvalidArgumentException('Video URL must use HTTPS.');
        }

        $host = strtolower($parts['host'] ?? '');
        $path = trim($parts['path'] ?? '', '/');
        parse_str($parts['query'] ?? '', $query);

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            $id = $path === 'watch' ? ($query['v'] ?? null) : (str_starts_with($path, 'shorts/') || str_starts_with($path, 'embed/') ? explode('/', $path)[1] ?? null : null);
            return $this->youtube($id);
        }

        if ($host === 'youtu.be') {
            return $this->youtube(explode('/', $path)[0] ?? null);
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            $segments = array_values(array_filter(explode('/', $path)));
            $id = end($segments);
            if (! is_string($id) || ! preg_match('/^\d+$/', $id)) {
                throw new InvalidArgumentException('Invalid Vimeo URL.');
            }
            return ['url' => 'https://vimeo.com/'.$id, 'provider' => 'vimeo', 'embed_url' => 'https://player.vimeo.com/video/'.$id];
        }

        throw new InvalidArgumentException('Unsupported video provider.');
    }

    private function youtube(?string $id): array
    {
        if (! $id || ! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            throw new InvalidArgumentException('Invalid YouTube URL.');
        }
        return ['url' => 'https://www.youtube.com/watch?v='.$id, 'provider' => 'youtube', 'embed_url' => 'https://www.youtube-nocookie.com/embed/'.$id];
    }

    public function embedUrl(string $url): string
    {
        return $this->normalize($url)['embed_url'];
    }
}
