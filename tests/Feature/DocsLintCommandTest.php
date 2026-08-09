<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocsLintCommandTest extends TestCase
{
    private string $docsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->docsRoot = sys_get_temp_dir().'/lf-docs-lint-'.bin2hex(random_bytes(6));
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

    public function test_valid_metadata_passes(): void
    {
        $this->write('guide.md', $this->metadata('guide.md'));

        $this->assertLintPasses();
    }

    #[DataProvider('invalidMetadataProvider')]
    public function test_invalid_metadata_fails(callable $mutate): void
    {
        $this->write('guide.md', $mutate($this->metadata('guide.md')));

        $this->assertLintFails();
    }

    public static function invalidMetadataProvider(): array
    {
        return [
            'missing Document Path' => [fn (string $value): string => preg_replace('/^Document Path:.*\R?/m', '', $value)],
            'wrong Document Path' => [fn (string $value): string => str_replace('Document Path: guide.md', 'Document Path: wrong.md', $value)],
            'month-only date' => [fn (string $value): string => str_replace('2026-08-09', '2026-08', $value)],
            'invalid Document Status' => [fn (string $value): string => str_replace('Document Status: Approved', 'Document Status: Official', $value)],
            'invalid Implementation Status' => [fn (string $value): string => str_replace('Implementation Status: Partial', 'Implementation Status: Completed', $value)],
        ];
    }

    public function test_frozen_policy_may_be_not_implemented(): void
    {
        $this->write('policy.md', $this->metadata('policy.md', 'Frozen', 'Not Implemented'));

        $this->assertLintPasses();
    }

    public function test_approved_policy_may_be_partial(): void
    {
        $this->write('policy.md', $this->metadata('policy.md', 'Approved', 'Partial'));

        $this->assertLintPasses();
    }

    public function test_legacy_file_outside_allowlist_fails(): void
    {
        $this->write('legacy.md', "# Legacy\n");

        $this->assertLintFails();
    }

    public function test_adr_with_contradictory_status_fails(): void
    {
        $content = str_replace('Document Status: Approved', 'Status: Approved', $this->metadata('adr/ADR-9999-Test.md'));
        $this->write('adr/ADR-9999-Test.md', $content."\n## Status\n\nFrozen\n");

        $this->assertLintFails();
    }

    private function metadata(string $path, string $documentStatus = 'Approved', string $implementationStatus = 'Partial'): string
    {
        return <<<MARKDOWN
        # Test

        Version: 1.0

        Document Status: {$documentStatus}

        Implementation Status: {$implementationStatus}

        Last Updated: 2026-08-09

        Document Path: {$path}

        ---
        MARKDOWN;
    }

    private function write(string $path, string $content): void
    {
        $absolute = $this->docsRoot.'/'.$path;
        is_dir(dirname($absolute)) || mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, $content);
    }

    private function assertLintPasses(): void
    {
        $exit = Artisan::call('docs:lint', ['--path' => $this->docsRoot, '--metadata-only' => true]);
        $this->assertSame(0, $exit, Artisan::output());
    }

    private function assertLintFails(): void
    {
        $exit = Artisan::call('docs:lint', ['--path' => $this->docsRoot, '--metadata-only' => true]);
        $this->assertSame(1, $exit, Artisan::output());
    }
}
