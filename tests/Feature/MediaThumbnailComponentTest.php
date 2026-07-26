<?php

namespace Tests\Feature;

use App\Services\MediaThumbnailPresenter;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MediaThumbnailComponentTest extends TestCase
{
    public function test_audio_thumbnail_uses_the_headphones_icon_instead_of_circle_fallback(): void
    {
        $html = Blade::render(
            '<x-media-thumbnail :presentation="$presentation" />',
            [
                'presentation' => [
                    'state' => 'file_type_icon',
                    'kind' => 'audio',
                    'url' => null,
                ],
            ]
        );

        $this->assertStringContainsString('data-media-thumbnail-kind="audio"', $html);
        $this->assertStringContainsString('M4 14v-2a8 8 0 0 1 16 0v2', $html);
        $this->assertStringNotContainsString('<circle cx="12" cy="12" r="8"', $html);
    }

    public function test_youtube_thumbnail_is_resolved_server_side_and_rendered_as_image(): void
    {
        $presentation = app(MediaThumbnailPresenter::class)
            ->embeddedVideo('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $html = $this->render($presentation);

        $this->assertSame('provider_video_thumbnail', $presentation['state']);
        $this->assertSame('video', $presentation['kind']);
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $presentation['url']);
        $this->assertStringContainsString('data-media-thumbnail-state="provider_video_thumbnail"', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('x-on:error=', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_vimeo_upload_and_pdf_use_honest_fallback_states_without_generated_runtime(): void
    {
        $presenter = app(MediaThumbnailPresenter::class);
        $vimeo = $presenter->embeddedVideo('https://vimeo.com/12345');
        $upload = $presenter->uploadedVideo((object) ['status' => 'ready']);
        $pdf = $presenter->document((object) ['status' => 'ready', 'mime_type' => 'application/pdf', 'extension' => 'pdf']);

        $this->assertSame(['state' => 'fallback', 'kind' => 'video', 'url' => null], $vimeo);
        $this->assertSame(['state' => 'fallback', 'kind' => 'video', 'url' => null], $upload);
        $this->assertSame(['state' => 'file_type_icon', 'kind' => 'pdf', 'url' => null], $pdf);
        $this->assertStringNotContainsString('<img', $this->render($vimeo));
    }

    public function test_image_pending_and_office_states_are_explicit(): void
    {
        $presenter = app(MediaThumbnailPresenter::class);
        $image = $presenter->image((object) ['status' => 'ready', 'signed_url' => '/signed/image']);
        $pending = $presenter->uploadedVideo((object) ['status' => 'processing']);
        $office = $presenter->document((object) ['status' => 'ready', 'mime_type' => 'application/octet-stream', 'extension' => 'xlsx']);

        $this->assertSame('image', $image['state']);
        $this->assertStringContainsString('src="/signed/image"', $this->render($image));
        $this->assertSame('pending', $pending['state']);
        $this->assertSame('spreadsheet', $office['kind']);
    }

    public function test_compact_css_and_modal_markup_are_separate(): void
    {
        $css = file_get_contents(resource_path('css/admin/admin-pages.css'));
        $form = file_get_contents(resource_path('views/course-templates/partials/form.blade.php'));

        foreach (['width: 64px;', 'height: 64px;', 'min-width: 64px;', 'max-width: 64px;', 'flex: 0 0 64px;', 'overflow: hidden;'] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
        $this->assertStringContainsString('.media-thumbnail-compact > .media-thumbnail-image', $css);
        $this->assertStringContainsString('class="media-library-modal-image"', $form);
    }

    private function render(array $presentation): string
    {
        return Blade::render('<x-media-thumbnail :presentation="$presentation" alt="Media" />', compact('presentation'));
    }
}
