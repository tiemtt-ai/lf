<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DocsManifestLintCommandTest extends TestCase
{
    private string $docsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->docsRoot = sys_get_temp_dir().'/lf-manifest-lint-'.bin2hex(random_bytes(6));
        mkdir($this->docsRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->docsRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->docsRoot);

        parent::tearDown();
    }

    public function test_valid_manifest_passes(): void
    {
        $this->writeMarkdown('a.md');
        $this->writeManifest([$this->record('a.md')]);

        $this->assertManifestLintPasses();
    }

    public function test_missing_markdown_record_fails(): void
    {
        $this->writeMarkdown('a.md');
        $this->writeMarkdown('b.md');
        $this->writeManifest([$this->record('a.md')]);

        $this->assertManifestLintFails();
    }

    public function test_duplicate_path_fails(): void
    {
        $this->writeMarkdown('a.md');
        $this->writeManifest([$this->record('a.md'), $this->record('a.md')]);

        $this->assertManifestLintFails();
    }

    public function test_nonexistent_path_fails(): void
    {
        $this->writeMarkdown('a.md');
        $this->writeManifest([$this->record('missing.md')]);

        $this->assertManifestLintFails();
    }

    public function test_missing_locale_keywords_fails(): void
    {
        $this->writeMarkdown('a.md');
        $record = $this->record('a.md');
        unset($record['keywords']['vi']);
        $this->writeManifest([$record]);

        $this->assertManifestLintFails();
    }

    public function test_duplicate_keyword_in_locale_fails(): void
    {
        $this->writeMarkdown('a.md');
        $record = $this->record('a.md');
        $record['keywords']['en'] = ['routing', 'Routing'];
        $this->writeManifest([$record]);

        $this->assertManifestLintFails();
    }

    public function test_invalid_related_path_fails(): void
    {
        $this->writeMarkdown('a.md');
        $record = $this->record('a.md');
        $record['related_documents'] = ['missing.md'];
        $this->writeManifest([$record]);

        $this->assertManifestLintFails();
    }

    public function test_count_mismatch_fails(): void
    {
        $this->writeMarkdown('a.md');
        $this->writeManifest([$this->record('a.md')], 2);

        $this->assertManifestLintFails();
    }

    public function test_unsorted_records_fail(): void
    {
        $this->writeMarkdown('a.md');
        $this->writeMarkdown('b.md');
        $this->writeManifest([$this->record('b.md'), $this->record('a.md')]);

        $this->assertManifestLintFails();
    }

    private function record(string $path): array
    {
        return [
            'path' => $path,
            'title' => 'Test',
            'area' => 'root',
            'document_type' => 'index',
            'document_status' => 'Approved',
            'implementation_status' => 'Not Applicable',
            'metadata_complete' => true,
            'authority' => 'routing',
            'canonical_for' => [],
            'topics' => ['documentation-routing'],
            'keywords' => ['vi' => ['định tuyến tài liệu'], 'en' => ['documentation routing']],
            'identifiers' => [],
            'related_documents' => [],
            'superseded_by' => null,
            'routing_source' => $path,
        ];
    }

    private function writeMarkdown(string $path): void
    {
        file_put_contents($this->docsRoot.'/'.$path, <<<MARKDOWN
        # Test

        Version: 1.0

        Document Status: Approved

        Implementation Status: Not Applicable

        Last Updated: 2026-08-09

        Document Path: {$path}
        MARKDOWN);
    }

    private function writeManifest(array $documents, ?int $count = null): void
    {
        $manifest = [
            'schema_version' => '1.0',
            'generated_or_updated_at' => '2026-08-09',
            'root' => 'docs',
            'default_locale' => 'vi',
            'fallback_locale' => 'en',
            'source_of_truth' => 'docs/LF-INDEX.md',
            'document_count' => $count ?? count($documents),
            'documents' => $documents,
        ];

        file_put_contents(
            $this->docsRoot.'/LF-DOCUMENTATION-MANIFEST.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    private function assertManifestLintPasses(): void
    {
        $exit = Artisan::call('docs:lint', ['--path' => $this->docsRoot, '--manifest-only' => true]);
        $this->assertSame(0, $exit, Artisan::output());
    }

    private function assertManifestLintFails(): void
    {
        $exit = Artisan::call('docs:lint', ['--path' => $this->docsRoot, '--manifest-only' => true]);
        $this->assertSame(1, $exit, Artisan::output());
    }
}
