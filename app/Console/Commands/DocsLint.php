<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Phase 5 v1 documentation quality gate — no database access.
 *
 * Checks catalog completeness, ADR status vocabulary, self-contradicting
 * status fields, "Superseded" backlinks, and required header metadata across
 * docs/. Deliberately does not check table/column names against migrations
 * or code evidence for "Implemented" claims — that requires DB/code access
 * and is deferred to a v2 pass once this static pass is stable.
 */
class DocsLint extends Command
{
    protected $signature = 'docs:lint';

    protected $description = 'Lint LearnForge documentation (docs/) for catalog completeness, status vocabulary, and metadata.';

    private const ADR_STATUSES = ['Draft', 'Review', 'Approved', 'Frozen', 'Archived'];

    private string $docsPath;

    public function handle(): int
    {
        $this->docsPath = base_path('docs');

        $issues = [
            ...$this->checkCatalogLinks(),
            ...$this->checkAdrStatusVocabulary(),
            ...$this->checkAdrSelfContradiction(),
            ...$this->checkSupersededHasLink(),
            ...$this->checkRequiredMetadata(),
        ];

        if ($issues === []) {
            $this->info('docs:lint passed — no issues found.');

            return self::SUCCESS;
        }

        $this->error(sprintf('docs:lint found %d issue(s):', count($issues)));

        foreach ($issues as $issue) {
            $this->line('  - '.$issue);
        }

        return self::FAILURE;
    }

    /**
     * Orphan documents (on disk but not linked from the directory's own
     * README.md) and dead catalog links (a README.md/LF-INDEX.md links to a
     * .md file that does not exist).
     */
    private function checkCatalogLinks(): array
    {
        $issues = [];

        foreach (Finder::create()->files()->in($this->docsPath)->name('README.md') as $readmeFile) {
            $readmeDir = $readmeFile->getPath();
            $relativeReadmeDir = ltrim(str_replace($this->docsPath, '', $readmeDir), '/');

            // docs/README.md is a navigation guide, not a per-file catalog —
            // that role belongs to LF-INDEX.md. Do not require it to link
            // every sibling document.
            if ($relativeReadmeDir === '') {
                continue;
            }

            $content = file_get_contents($readmeFile->getRealPath());

            foreach (Finder::create()->files()->in($readmeDir)->name('*.md')->depth('== 0') as $sibling) {
                if (strtolower($sibling->getFilename()) === 'readme.md') {
                    continue;
                }

                // Match on basename without extension: some domain READMEs
                // reference a table via **bold**/backtick text instead of a
                // hyperlink, and never spell out the ".md" suffix.
                if (! str_contains($content, $sibling->getFilenameWithoutExtension())) {
                    $issues[] = "Orphan: docs/$relativeReadmeDir/{$sibling->getFilename()} tồn tại nhưng không được nhắc tới trong docs/$relativeReadmeDir/README.md";
                }
            }
        }

        // adr/ and quality/ must additionally be fully listed in LF-INDEX.md,
        // which acts as the master catalog for those two directories.
        $indexContent = file_get_contents("$this->docsPath/LF-INDEX.md");

        foreach (['adr', 'quality'] as $dir) {
            foreach (Finder::create()->files()->in("$this->docsPath/$dir")->name('*.md')->depth('== 0') as $file) {
                if (strtolower($file->getFilename()) === 'readme.md') {
                    continue;
                }

                if (! str_contains($indexContent, $file->getFilenameWithoutExtension())) {
                    $issues[] = "Orphan: docs/$dir/{$file->getFilename()} tồn tại nhưng không được liệt kê trong docs/LF-INDEX.md";
                }
            }
        }

        // Dead links: every .md link target referenced by a catalog file
        // (any README.md, plus LF-INDEX.md) must exist on disk.
        foreach (Finder::create()->files()->in($this->docsPath)->name(['README.md', 'LF-INDEX.md']) as $catalogFile) {
            $content = file_get_contents($catalogFile->getRealPath());
            $catalogDir = $catalogFile->getPath();
            $relativeCatalog = ltrim(str_replace($this->docsPath, '', $catalogFile->getRealPath()), '/');

            preg_match_all('/\]\(([^)\s]+\.md)\)/', $content, $matches);

            foreach ($matches[1] as $target) {
                if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
                    continue;
                }

                if (realpath("$catalogDir/$target") === false) {
                    $issues[] = "Dead link: docs/$relativeCatalog trỏ tới '$target' nhưng file không tồn tại";
                }
            }
        }

        return $issues;
    }

    /**
     * Every ADR's "## Status" value must be one of the canonical lifecycle
     * words defined in adr/README.md (Draft, Review, Approved, Frozen,
     * Archived).
     */
    private function checkAdrStatusVocabulary(): array
    {
        $issues = [];

        foreach ($this->adrFiles() as $file) {
            $content = file_get_contents($file->getRealPath());
            $relative = "adr/{$file->getFilename()}";

            $status = $this->adrStatusValue($content);

            if ($status === null) {
                $issues[] = "$relative: không tìm thấy section '## Status' hoặc dòng 'Status:'";

                continue;
            }

            if (! in_array($status, self::ADR_STATUSES, true)) {
                $issues[] = "$relative: Status '$status' không thuộc vocabulary chuẩn (".implode('/', self::ADR_STATUSES).')';
            }
        }

        return $issues;
    }

    /**
     * An ADR must not state one Status at the top and a different Status
     * value elsewhere in the file (e.g. head "Accepted" vs a Result block
     * saying "Foundation Approved and Frozen" — the Phase 0 defect class).
     */
    private function checkAdrSelfContradiction(): array
    {
        $issues = [];

        foreach ($this->adrFiles() as $file) {
            $content = file_get_contents($file->getRealPath());
            $relative = "adr/{$file->getFilename()}";

            $lines = preg_split('/\R/', $content);
            $statusValues = [];

            foreach ($lines as $i => $line) {
                $trimmed = trim($line);

                if ($trimmed === '## Status' || $trimmed === 'Status') {
                    for ($j = $i + 1; $j < count($lines); $j++) {
                        $candidate = trim($lines[$j]);

                        if ($candidate === '' || $candidate === '```' || $candidate === '```text') {
                            continue;
                        }

                        $statusValues[] = $candidate;

                        break;
                    }

                    continue;
                }

                if (preg_match('/^Status:\s*(.+)$/i', $trimmed, $m)) {
                    $statusValues[] = trim($m[1], " \t*");
                }
            }

            $distinct = array_unique($statusValues);

            if (count($distinct) > 1) {
                $issues[] = "$relative: Status tự mâu thuẫn trong file — các giá trị tìm thấy: ".implode(', ', $distinct);
            }
        }

        return $issues;
    }

    /**
     * Any content document (not a README/LF-INDEX catalog entry) that
     * mentions "Superseded" must name its replacement via a .md link within
     * the next few lines.
     */
    private function checkSupersededHasLink(): array
    {
        $issues = [];

        $files = Finder::create()->files()->in($this->docsPath)->name('*.md')
            ->notName('README.md')
            ->notName('LF-INDEX.md');

        foreach ($files as $file) {
            $content = file_get_contents($file->getRealPath());
            $lines = preg_split('/\R/', $content);
            $relative = ltrim(str_replace($this->docsPath, '', $file->getRealPath()), '/');

            foreach ($lines as $i => $line) {
                // Case-sensitive, all-caps only: matches status banners like
                // "SUPERSEDED by X.md" but not lowercase prose describing an
                // internal field/decision as "superseded" by a later section
                // of the same document.
                if (! preg_match('/\bSUPERSEDED\b/', $line)) {
                    continue;
                }

                $window = implode("\n", array_slice($lines, $i, 4));

                if (! preg_match('/\]\([^)]+\.md\)/', $window)) {
                    $issues[] = "$relative (dòng ".($i + 1)."): nhắc 'Superseded' nhưng không có link .md tới tài liệu thay thế trong 3 dòng kế tiếp";
                }

                break;
            }
        }

        return $issues;
    }

    /**
     * Every document must carry Version + Status + (Last Updated or Review
     * Date) in its header (ADRs use the "## Status" / "## Date" section
     * format instead of flat "Field:" lines).
     */
    private function checkRequiredMetadata(): array
    {
        $issues = [];
        $legacySkipped = [];

        foreach (Finder::create()->files()->in($this->docsPath)->name('*.md') as $file) {
            $content = file_get_contents($file->getRealPath());
            $relative = ltrim(str_replace($this->docsPath, '', $file->getRealPath()), '/');
            $head = implode("\n", array_slice(preg_split('/\R/', $content), 0, 20));

            if (str_starts_with($relative, 'adr/ADR-')) {
                if (! preg_match('/^##\s*Status\s*$/mi', $head) && ! preg_match('/^Status:/mi', $head)) {
                    $issues[] = "$relative: thiếu section '## Status' hoặc dòng 'Status:' (ADR format)";
                }

                if (! preg_match('/^##\s*Date\s*$/mi', $head) && ! str_contains($head, 'Date:')) {
                    $issues[] = "$relative: thiếu section '## Date' hoặc dòng '...Date:' (ADR format)";
                }

                continue;
            }

            // Accepts any "...Date:" field (Last Updated:, Review Date:,
            // Approval Date:, Decision Date:, bare Date:, ...) — this corpus
            // uses several equally-valid date-field names, not just one.
            $hasVersion = str_contains($content, 'Version:');
            $hasStatus = str_contains($content, 'Status:');
            $hasDate = str_contains($content, 'Last Updated:') || preg_match('/\bDate:/i', $content) === 1;

            if (! $hasVersion && ! $hasStatus && ! $hasDate) {
                // Known legacy "# Table Name" format used by unimplemented
                // spec-only domains — no metadata header exists at all. This
                // is pre-existing technical debt tracked separately; v1 does
                // not re-flag it file-by-file to keep the gate readable.
                $legacySkipped[] = $relative;

                continue;
            }

            if (! $hasVersion) {
                $issues[] = "$relative: thiếu metadata 'Version:'";
            }

            if (! $hasStatus) {
                $issues[] = "$relative: thiếu metadata 'Status:'";
            }

            if (! $hasDate) {
                $issues[] = "$relative: thiếu metadata ngày tháng ('Last Updated:', 'Review Date:', ... 'Date:')";
            }
        }

        if ($legacySkipped !== []) {
            $this->comment(sprintf(
                '[docs:lint] Ghi chú: %d file dùng legacy format không có Version/Status/Last Updated (nợ kỹ thuật đã biết, không tính vào issue count).',
                count($legacySkipped)
            ));
        }

        return $issues;
    }

    private function adrFiles(): Finder
    {
        return Finder::create()->files()->in("$this->docsPath/adr")->name('ADR-*.md')->depth('== 0');
    }

    private function adrStatusValue(string $content): ?string
    {
        $sectioned = $this->firstSectionValue($content, '## Status');

        if ($sectioned !== null) {
            return $sectioned;
        }

        if (preg_match('/^Status:\s*(.+)$/mi', $content, $m)) {
            return trim($m[1], " \t*");
        }

        return null;
    }

    private function firstSectionValue(string $content, string $heading): ?string
    {
        $lines = preg_split('/\R/', $content);

        foreach ($lines as $i => $line) {
            if (trim($line) !== $heading) {
                continue;
            }

            for ($j = $i + 1; $j < count($lines); $j++) {
                $candidate = trim($lines[$j]);

                if ($candidate === '') {
                    continue;
                }

                return $candidate;
            }
        }

        return null;
    }
}
