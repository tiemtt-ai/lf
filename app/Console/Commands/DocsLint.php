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
    protected $signature = 'docs:lint
        {--path= : Alternate docs root for isolated validation}
        {--metadata-only : Run only metadata and ADR consistency checks}
        {--manifest-only : Run only documentation manifest checks}';

    protected $description = 'Lint LearnForge documentation (docs/) for catalog completeness, status vocabulary, and metadata.';

    private const ADR_STATUSES = ['Draft', 'Review', 'Approved', 'Frozen', 'Archived'];

    private const DOCUMENT_STATUSES = ['Draft', 'Review', 'Approved', 'Frozen', 'Archived'];

    private const IMPLEMENTATION_STATUSES = ['Not Implemented', 'Partial', 'Implemented', 'Not Applicable', 'Unknown'];

    private const MANIFEST_DOCUMENT_TYPES = [
        'index', 'directory_guide', 'governance', 'adr', 'domain_policy',
        'database_overview', 'table_schema', 'technical_standard',
        'business_document', 'implementation_rule', 'quality_standard',
        'architecture_review', 'conflict_register',
    ];

    private const MANIFEST_AUTHORITIES = [
        'routing', 'governance', 'architecture_decision', 'domain', 'database',
        'engineering', 'quality', 'historical',
    ];

    private string $docsPath;

    public function handle(): int
    {
        $requestedPath = rtrim((string) ($this->option('path') ?: base_path('docs')), '/');
        $this->docsPath = realpath($requestedPath) ?: $requestedPath;

        if (! is_dir($this->docsPath)) {
            $this->error("docs:lint path does not exist: {$this->docsPath}");

            return self::FAILURE;
        }

        if ($this->option('metadata-only') && $this->option('manifest-only')) {
            $this->error('docs:lint options --metadata-only and --manifest-only are mutually exclusive.');

            return self::FAILURE;
        }

        $issues = $this->option('manifest-only')
            ? $this->checkDocumentationManifest()
            : [
                ...($this->option('metadata-only') ? [] : $this->checkCatalogLinks()),
                ...$this->checkAdrStatusVocabulary(),
                ...$this->checkAdrSelfContradiction(),
                ...($this->option('metadata-only') ? [] : $this->checkSupersededHasLink()),
                ...$this->checkRequiredMetadata(),
                ...$this->checkConflictRegisterActiveCount(),
                ...($this->option('metadata-only') ? [] : $this->checkDocumentationManifest()),
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
     * Validate the canonical metadata header. Known pre-migration files may
     * only be skipped through the exact, reasoned allowlist in config/docs-lint.php.
     */
    private function checkRequiredMetadata(): array
    {
        $issues = [];
        $configuredAllowlist = $this->option('path') ? [] : config('docs-lint.legacy_metadata_allowlist', []);
        $seenAllowlist = [];

        foreach (Finder::create()->files()->in($this->docsPath)->name('*.md') as $file) {
            $content = file_get_contents($file->getRealPath());
            $relative = ltrim(str_replace($this->docsPath, '', $file->getRealPath()), '/');
            $head = implode("\n", array_slice(preg_split('/\R/', $content), 0, 20));

            if (array_key_exists($relative, $configuredAllowlist)) {
                $seenAllowlist[] = $relative;

                continue;
            }

            if (! preg_match('/^Version:\s*\S.+$/mi', $head)) {
                $issues[] = "$relative: thiếu metadata 'Version:'";
            }

            $isAdr = str_starts_with($relative, 'adr/ADR-');
            $statusPattern = $isAdr
                ? '/^(?:Document Status|Status):\s*(.+)$/mi'
                : '/^Document Status:\s*(.+)$/mi';

            if (! preg_match($statusPattern, $head, $statusMatch)) {
                $issues[] = "$relative: thiếu metadata 'Document Status:'".($isAdr ? " hoặc alias ADR 'Status:'" : '');
            } elseif (! in_array(trim($statusMatch[1]), self::DOCUMENT_STATUSES, true)) {
                $issues[] = "$relative: Document Status '{$statusMatch[1]}' không hợp lệ (".implode('/', self::DOCUMENT_STATUSES).')';
            }

            if (! preg_match('/^Implementation Status:\s*(.+)$/mi', $head, $implementationMatch)) {
                $issues[] = "$relative: thiếu metadata 'Implementation Status:'";
            } elseif (! in_array(trim($implementationMatch[1]), self::IMPLEMENTATION_STATUSES, true)) {
                $issues[] = "$relative: Implementation Status '{$implementationMatch[1]}' không hợp lệ (".implode('/', self::IMPLEMENTATION_STATUSES).')';
            }

            if (! preg_match('/^Last Updated:\s*(\d{4}-\d{2}-\d{2})$/mi', $head, $dateMatch)
                || ! $this->isValidDate($dateMatch[1] ?? '')) {
                $issues[] = "$relative: 'Last Updated' phải là ngày hợp lệ theo YYYY-MM-DD";
            }

            if (! preg_match('/^Document Path:\s*(.+)$/mi', $head, $pathMatch)) {
                $issues[] = "$relative: thiếu metadata 'Document Path:'";
            } elseif (trim($pathMatch[1]) !== $relative) {
                $issues[] = "$relative: Document Path '".trim($pathMatch[1])."' không trùng đường dẫn thật '$relative'";
            }
        }

        foreach (array_diff(array_keys($configuredAllowlist), $seenAllowlist) as $stale) {
            $issues[] = "$stale: legacy allowlist entry không còn trỏ tới file tồn tại";
        }

        if ($seenAllowlist !== []) {
            $this->comment(sprintf('[docs:lint] Legacy metadata debt: %d file trong allowlist tạm thời.', count($seenAllowlist)));
        }

        return $issues;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Derive the conflict-register summary from its table instead of trusting
     * a manually maintained claim. An alternate --path fixture may omit the
     * register; catalog checks cover its presence in the canonical docs tree.
     */
    private function checkConflictRegisterActiveCount(): array
    {
        $path = $this->docsPath.'/quality/LF-Documentation-Conflicts.md';
        if (! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if (! preg_match('/^Active items:\s*(\d+)\b/m', $content, $declaredMatch)) {
            return ['quality/LF-Documentation-Conflicts.md: thiếu dòng `Active items: <number>`'];
        }

        $active = 0;
        foreach (preg_split('/\R/', $content) as $line) {
            if (! preg_match('/^\|\s*DOC-CONFLICT-\d+\s*\|/', $line)) {
                continue;
            }

            $columns = array_map('trim', explode('|', trim($line, "| \t")));
            if (($columns[3] ?? null) !== 'RESOLVED') {
                $active++;
            }
        }

        $declared = (int) $declaredMatch[1];
        if ($declared !== $active) {
            return ["quality/LF-Documentation-Conflicts.md: Active items khai $declared nhưng bảng có $active item chưa RESOLVED"];
        }

        return [];
    }

    /**
     * Validate the machine-readable bilingual inventory without attempting to
     * judge keyword semantics or infer documentation authority.
     */
    private function checkDocumentationManifest(): array
    {
        $issues = [];
        $manifestPath = $this->docsPath.'/LF-DOCUMENTATION-MANIFEST.json';

        if (! is_file($manifestPath)) {
            return ['LF-DOCUMENTATION-MANIFEST.json: manifest không tồn tại'];
        }

        try {
            $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ['LF-DOCUMENTATION-MANIFEST.json: JSON không hợp lệ — '.$exception->getMessage()];
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            return ['LF-DOCUMENTATION-MANIFEST.json: root phải là JSON object'];
        }

        if (($manifest['schema_version'] ?? null) !== '1.0') {
            $issues[] = 'LF-DOCUMENTATION-MANIFEST.json: schema_version phải là 1.0';
        }

        foreach (['generated_or_updated_at', 'root', 'default_locale', 'fallback_locale', 'source_of_truth', 'document_count', 'documents'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                $issues[] = "LF-DOCUMENTATION-MANIFEST.json: thiếu field '$field'";
            }
        }

        if (($manifest['root'] ?? null) !== 'docs'
            || ($manifest['default_locale'] ?? null) !== 'vi'
            || ($manifest['fallback_locale'] ?? null) !== 'en'
            || ($manifest['source_of_truth'] ?? null) !== 'docs/LF-INDEX.md') {
            $issues[] = 'LF-DOCUMENTATION-MANIFEST.json: root/locale/source_of_truth không đúng canonical contract';
        }

        if (! is_string($manifest['generated_or_updated_at'] ?? null)
            || ! $this->isValidDate($manifest['generated_or_updated_at'] ?? '')) {
            $issues[] = 'LF-DOCUMENTATION-MANIFEST.json: generated_or_updated_at phải là ngày YYYY-MM-DD hợp lệ';
        }

        $documents = $manifest['documents'] ?? null;
        if (! is_array($documents) || ! array_is_list($documents)) {
            return [...$issues, 'LF-DOCUMENTATION-MANIFEST.json: documents phải là JSON array'];
        }

        if (! is_int($manifest['document_count'] ?? null) || $manifest['document_count'] !== count($documents)) {
            $issues[] = 'LF-DOCUMENTATION-MANIFEST.json: document_count không bằng số record thực tế';
        }

        $markdownPaths = [];
        foreach (Finder::create()->files()->in($this->docsPath)->name('*.md') as $file) {
            $markdownPaths[] = ltrim(str_replace($this->docsPath, '', $file->getRealPath()), '/');
        }
        sort($markdownPaths);

        $recordPaths = [];
        foreach ($documents as $index => $record) {
            $label = 'LF-DOCUMENTATION-MANIFEST.json record #'.($index + 1);
            if (! is_array($record) || array_is_list($record)) {
                $issues[] = "$label: record phải là object";

                continue;
            }

            foreach (['path', 'title', 'area', 'document_type', 'document_status', 'implementation_status', 'metadata_complete', 'authority', 'canonical_for', 'topics', 'keywords', 'identifiers', 'related_documents', 'superseded_by', 'routing_source'] as $field) {
                if (! array_key_exists($field, $record)) {
                    $issues[] = "$label: thiếu field '$field'";
                }
            }

            $path = $record['path'] ?? null;
            if (! is_string($path) || $path === '') {
                $issues[] = "$label: path phải là non-empty string";

                continue;
            }
            $label = "manifest record '$path'";
            $recordPaths[] = $path;

            foreach (['title', 'area', 'routing_source'] as $field) {
                if (! is_string($record[$field] ?? null) || trim($record[$field]) === '') {
                    $issues[] = "$label: $field phải là non-empty string";
                }
            }

            if (! in_array($record['document_type'] ?? null, self::MANIFEST_DOCUMENT_TYPES, true)) {
                $issues[] = "$label: document_type không hợp lệ";
            }
            if (! in_array($record['authority'] ?? null, self::MANIFEST_AUTHORITIES, true)) {
                $issues[] = "$label: authority không hợp lệ";
            }
            if (! is_bool($record['metadata_complete'] ?? null)) {
                $issues[] = "$label: metadata_complete phải là boolean";
            }

            $allowedDocumentStatuses = ($record['metadata_complete'] ?? false)
                ? self::DOCUMENT_STATUSES
                : [...self::DOCUMENT_STATUSES, 'Unknown'];
            if (! in_array($record['document_status'] ?? null, $allowedDocumentStatuses, true)) {
                $issues[] = "$label: document_status không hợp lệ";
            }
            if (! in_array($record['implementation_status'] ?? null, self::IMPLEMENTATION_STATUSES, true)) {
                $issues[] = "$label: implementation_status không hợp lệ";
            }

            if (is_file($this->docsPath.'/'.$path)) {
                $source = file_get_contents($this->docsPath.'/'.$path);
                $sourceHead = implode("\n", array_slice(preg_split('/\R/', $source), 0, 35));
                preg_match('/^#\s+(.+)$/m', $source, $sourceTitleMatch);
                if (($record['title'] ?? null) !== trim($sourceTitleMatch[1] ?? '')) {
                    $issues[] = "$label: title không khớp H1 của Markdown";
                }

                if ($record['metadata_complete'] ?? false) {
                    preg_match('/^(?:Document Status|Status):\s*(.+)$/mi', $sourceHead, $sourceStatusMatch);
                    preg_match('/^Implementation Status:\s*(.+)$/mi', $sourceHead, $sourceImplementationMatch);
                    if (($record['document_status'] ?? null) !== trim($sourceStatusMatch[1] ?? '')
                        || ($record['implementation_status'] ?? null) !== trim($sourceImplementationMatch[1] ?? '')) {
                        $issues[] = "$label: status metadata không khớp Markdown header";
                    }
                } elseif (($record['document_status'] ?? null) !== 'Unknown'
                    || ($record['implementation_status'] ?? null) !== 'Unknown') {
                    $issues[] = "$label: legacy metadata chưa đầy đủ phải dùng status Unknown";
                }
            }

            foreach (['canonical_for', 'topics', 'identifiers', 'related_documents'] as $field) {
                if (! is_array($record[$field] ?? null) || ! array_is_list($record[$field])) {
                    $issues[] = "$label: $field phải là array";
                }
            }

            $keywords = $record['keywords'] ?? null;
            if (! is_array($keywords) || array_is_list($keywords)) {
                $issues[] = "$label: keywords phải là object";
            } else {
                foreach (['vi', 'en'] as $locale) {
                    $values = $keywords[$locale] ?? null;
                    if (! is_array($values) || ! array_is_list($values) || $values === []) {
                        $issues[] = "$label: keywords.$locale phải là non-empty array";

                        continue;
                    }
                    $normalized = [];
                    foreach ($values as $keyword) {
                        if (! is_string($keyword) || trim($keyword) === '') {
                            $issues[] = "$label: keywords.$locale chứa giá trị rỗng/không phải string";

                            continue;
                        }
                        $key = mb_strtolower(trim($keyword));
                        if (in_array($key, $normalized, true)) {
                            $issues[] = "$label: keywords.$locale chứa duplicate '$keyword'";
                        }
                        $normalized[] = $key;
                    }
                }
            }

            $relatedPaths = is_array($record['related_documents'] ?? null) ? $record['related_documents'] : [];
            foreach (array_merge($relatedPaths, [$record['routing_source'] ?? null]) as $related) {
                if (! is_string($related) || $related === '' || ! str_ends_with($related, '.md') || ! is_file($this->docsPath.'/'.$related)) {
                    $issues[] = "$label: related/routing path không tồn tại: ".var_export($related, true);
                }
            }

            $supersededBy = $record['superseded_by'] ?? null;
            if ($supersededBy !== null) {
                if (! is_string($supersededBy) || ! is_file($this->docsPath.'/'.$supersededBy)) {
                    $issues[] = "$label: superseded_by không tồn tại";
                } elseif ($supersededBy === $path) {
                    $issues[] = "$label: superseded_by không được tự trỏ chính nó";
                }
            }
        }

        $duplicates = array_keys(array_filter(array_count_values($recordPaths), fn (int $count) => $count > 1));
        foreach ($duplicates as $duplicate) {
            $issues[] = "LF-DOCUMENTATION-MANIFEST.json: duplicate path '$duplicate'";
        }

        $sortedPaths = $recordPaths;
        sort($sortedPaths);
        if ($recordPaths !== $sortedPaths) {
            $issues[] = 'LF-DOCUMENTATION-MANIFEST.json: records phải sort tăng dần theo path';
        }

        foreach (array_diff($markdownPaths, array_unique($recordPaths)) as $missing) {
            $issues[] = "LF-DOCUMENTATION-MANIFEST.json: thiếu Markdown record '$missing'";
        }
        foreach (array_diff(array_unique($recordPaths), $markdownPaths) as $stale) {
            $issues[] = "LF-DOCUMENTATION-MANIFEST.json: record trỏ tới Markdown không tồn tại '$stale'";
        }

        return $issues;
    }

    private function adrFiles(): Finder
    {
        if (! is_dir("$this->docsPath/adr")) {
            return Finder::create()->files()->in($this->docsPath)->name('*.docs-lint-no-adr');
        }

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
