<?php

namespace Tests\Feature;

use App\Services\DocumentProcessRunner;
use App\Services\LocalDocumentProcessingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class LocalDocumentMixedPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media_local');
        config(['media.disk' => 'media_local', 'media.bucket' => 'test-media']);
        Storage::disk('media_local')->put('mixed.pdf', 'pdf');
    }

    public function test_pages_without_a_text_layer_are_ocred_instead_of_dropped(): void
    {
        // Pages 1 and 3 carry a text layer; pages 2 and 4 are scans. Before the
        // per-page fallback the provider returned pages 1 and 3 only.
        $units = $this->process($this->runner("alpha\n\f\n\fgamma\n\f"));

        $this->assertSame(['1', '2', '3', '4'], array_column($units, 'locator_value'));
        $this->assertSame(
            ['embedded_text', 'ocr', 'embedded_text', 'ocr'],
            array_column($units, 'extraction_method')
        );
        $this->assertSame(['alpha', 'ocr-page-2', 'gamma', 'ocr-page-4'], array_column($units, 'text'));
    }

    public function test_a_fully_text_layered_document_never_calls_the_ocr_binaries(): void
    {
        $runner = $this->runner("one\n\ftwo\n\fthree\n\ffour\n\f", ocr: false);

        $units = $this->process($runner);

        $this->assertSame(['embedded_text', 'embedded_text', 'embedded_text', 'embedded_text'], array_column($units, 'extraction_method'));
    }

    public function test_a_document_without_any_text_layer_is_fully_ocred(): void
    {
        $units = $this->process($this->runner(''));

        $this->assertSame(['ocr', 'ocr', 'ocr', 'ocr'], array_column($units, 'extraction_method'));
        $this->assertSame(['ocr-page-1', 'ocr-page-2', 'ocr-page-3', 'ocr-page-4'], array_column($units, 'text'));
    }

    private function runner(string $pdfText, bool $ocr = true): DocumentProcessRunner
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')
            ->withArgs(fn (array $command): bool => str_contains($command[0], 'pdfinfo'))
            ->andReturn("Pages: 4\n");
        $runner->shouldReceive('run')
            ->withArgs(fn (array $command): bool => str_contains($command[0], 'pdftotext'))
            ->andReturnUsing(function (array $command) use ($pdfText): string {
                file_put_contents($command[3], $pdfText);

                return '';
            });

        $expectation = $runner->shouldReceive('run')
            ->withArgs(fn (array $command): bool => str_contains($command[0], 'pdftoppm'))
            ->andReturn('');
        $ocr or $expectation->never();

        $expectation = $runner->shouldReceive('run')
            ->withArgs(fn (array $command): bool => str_contains($command[0], 'tesseract'))
            ->andReturnUsing(fn (array $command): string => 'ocr-'.basename($command[1], '.png'));
        $ocr or $expectation->never();

        return $runner;
    }

    /** @return array<int, array<string, mixed>> */
    private function process(DocumentProcessRunner $runner): array
    {
        return (new LocalDocumentProcessingProvider($runner))->process(
            (object) ['file_type' => 'document', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_key' => 'mixed.pdf'],
            (object) ['job_type' => 'ocr', 'output_profile' => 'layout=preserve;locale=vi'],
        )['units'];
    }
}
