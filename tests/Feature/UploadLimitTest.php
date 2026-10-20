<?php

namespace Tests\Feature;

use App\Support\UploadLimit;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UploadLimitTest extends TestCase
{
    public function test_php_shorthand_size_parser_handles_k_m_and_g_values(): void
    {
        $this->assertSame(1024 * 1024, UploadLimit::parseIniSizeToKilobytes('1024M'));
        $this->assertSame(1024 * 1024, UploadLimit::parseIniSizeToKilobytes('1G'));
        $this->assertSame(512, UploadLimit::parseIniSizeToKilobytes('512K'));
        $this->assertSame(2 * 1024, UploadLimit::parseIniSizeToKilobytes(' 2m '));
        $this->assertSame(1536, UploadLimit::parseIniSizeToKilobytes(' 1.5 M '));
        $this->assertNull(UploadLimit::parseIniSizeToKilobytes('-1'));
        $this->assertNull(UploadLimit::parseIniSizeToKilobytes('0'));
    }

    public function test_effective_limit_chooses_the_smallest_valid_limit(): void
    {
        $this->assertSame(
            10 * 1024,
            UploadLimit::effectiveKilobytes(
                productLimitKilobytes: 100 * 1024,
                uploadMaxFilesize: '50M',
                postMaxSize: '10M'
            )
        );
    }

    public function test_human_readable_size_formats_common_units(): void
    {
        $this->assertSame('10 MB', UploadLimit::humanReadable(10 * 1024));
        $this->assertSame('100 MB', UploadLimit::humanReadable(100 * 1024));
        $this->assertSame('1 GB', UploadLimit::humanReadable(1024 * 1024));
    }

    public function test_upload_hint_component_renders_formats_and_effective_size(): void
    {
        app()->setLocale('vi');
        config(['media.max_upload_kilobytes' => 100 * 1024]);

        $html = Blade::render(
            '<x-upload-hint :formats="[\'MP4\', \'MOV\']" />'
        );

        $this->assertStringContainsString('Định dạng: MP4, MOV', $html);
        $this->assertStringContainsString('Tối đa:', $html);
        $this->assertStringContainsString(
            'class="admin-upload-hint authoring-media-help"',
            $html
        );
        $this->assertStringContainsString(
            UploadLimit::humanReadable(UploadLimit::effectiveKilobytes()),
            $html
        );
    }

    public function test_upload_hint_component_accepts_max_size_override(): void
    {
        app()->setLocale('en');

        $html = Blade::render(
            '<x-upload-hint :formats="[\'PDF\', \'DOCX\']" :max-size="1024" />'
        );

        $this->assertStringContainsString('Formats: PDF, DOCX', $html);
        $expectedSize = UploadLimit::humanReadable(
            UploadLimit::effectiveKilobytes(productLimitKilobytes: 1024)
        );
        $this->assertStringContainsString(
            'Formats: PDF, DOCX · Maximum: '.$expectedSize,
            $html
        );
    }

    public function test_policy_limits_video_to_one_gigabyte_and_other_media_to_five_hundred_megabytes(): void
    {
        // Even when the environment allows more, the policy caps apply (PHP's own limits are not involved here).
        config(['media.max_upload_kilobytes' => 4 * 1024 * 1024, 'media.video_max_upload_kilobytes' => 8 * 1024 * 1024]);
        $big = '10G';

        $this->assertSame(500 * 1024, UploadLimit::MAX_OTHER_KILOBYTES);
        $this->assertSame(1024 * 1024, UploadLimit::MAX_VIDEO_KILOBYTES);
        $this->assertSame(
            UploadLimit::MAX_OTHER_KILOBYTES,
            UploadLimit::effectiveKilobytes(uploadMaxFilesize: $big, postMaxSize: $big)
        );

        // The environment can still lower them.
        config(['media.max_upload_kilobytes' => 100 * 1024, 'media.video_max_upload_kilobytes' => 200 * 1024]);
        $this->assertSame(100 * 1024, UploadLimit::effectiveKilobytes(uploadMaxFilesize: $big, postMaxSize: $big));
    }

    public function test_video_and_other_upload_fields_use_their_own_limits_in_validation_and_hints(): void
    {
        $other = UploadLimit::effectiveKilobytes();
        $video = UploadLimit::effectiveVideoKilobytes();
        $this->assertLessThanOrEqual(UploadLimit::MAX_OTHER_KILOBYTES, $other);
        $this->assertLessThanOrEqual(UploadLimit::MAX_VIDEO_KILOBYTES, $video);
        $this->assertGreaterThanOrEqual($other, $video);

        app()->setLocale('vi');
        $videoHint = Blade::render('<x-upload-hint :formats="[\'MP4\']" :max-size="\\App\\Support\\UploadLimit::effectiveVideoKilobytes()" />');
        $otherHint = Blade::render('<x-upload-hint :formats="[\'PDF\']" />');
        $this->assertStringContainsString(UploadLimit::humanReadable($video), $videoHint);
        $this->assertStringContainsString(UploadLimit::humanReadable($other), $otherHint);
    }
}
