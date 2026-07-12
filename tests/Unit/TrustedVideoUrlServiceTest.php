<?php

namespace Tests\Unit;

use App\Services\TrustedVideoUrlService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedVideoUrlServiceTest extends TestCase
{
    #[DataProvider('validUrls')]
    public function test_normalizes_supported_urls(string $input, array $expected): void
    {
        $this->assertSame($expected, (new TrustedVideoUrlService)->normalize($input));
    }

    public static function validUrls(): array
    {
        $youtube = ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'provider' => 'youtube', 'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&utm_source=x', $youtube],
            ['https://youtu.be/dQw4w9WgXcQ?t=3', $youtube],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ', $youtube],
            ['https://vimeo.com/123456789?share=copy', ['url' => 'https://vimeo.com/123456789', 'provider' => 'vimeo', 'embed_url' => 'https://player.vimeo.com/video/123456789']],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TrustedVideoUrlService)->normalize($url);
    }

    public static function invalidUrls(): array
    {
        return [
            ['http://youtube.com/watch?v=dQw4w9WgXcQ'],
            ['https://youtube.com.attacker.example/watch?v=dQw4w9WgXcQ'],
            ['https://youtube.com@attacker.example/watch?v=dQw4w9WgXcQ'],
            ['https://example.com/video/123'],
            ['https://youtube.com/watch?v=missing'],
            ['https://vimeo.com/not-a-number'],
            ['<iframe src="https://youtu.be/dQw4w9WgXcQ"></iframe>'],
            ['not a url'],
        ];
    }

    public function test_generates_trusted_embed_url(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', (new TrustedVideoUrlService)->embedUrl('https://youtu.be/dQw4w9WgXcQ'));
    }
}
