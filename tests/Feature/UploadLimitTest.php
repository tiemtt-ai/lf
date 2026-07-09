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
        $this->assertStringContainsString('class="admin-upload-hint"', $html);
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
        $this->assertStringContainsString('Max size: 1 MB', $html);
    }
}
