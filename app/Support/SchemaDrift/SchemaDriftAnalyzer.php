<?php

namespace App\Support\SchemaDrift;

use Symfony\Component\Finder\Finder;

class SchemaDriftAnalyzer
{
    public const SEVERITIES = ['BLOCKER', 'HIGH', 'MEDIUM', 'LOW', 'INFO'];

    public function loadContract(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Schema contract not found: {$path}");
        }

        $contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $issues = $this->validateContract($contract, dirname($path, 2));

        if ($issues !== []) {
            throw new \RuntimeException(implode(PHP_EOL, array_column($issues, 'message')));
        }

        return $contract;
    }

    public function validateContract(array $contract, string $docsRoot): array
    {
        $findings = [];
        if (($contract['schema_version'] ?? null) !== '1.0') {
            $findings[] = $this->finding('BLOCKER', 'contract.schema_version', 'schema_version must be 1.0.');
        }
        if (($contract['database_family'] ?? null) !== 'mysql') {
            $findings[] = $this->finding('BLOCKER', 'contract.database_family', 'database_family must be mysql.');
        }

        $allowed = ['implemented', 'planned', 'not_implemented', 'historical', 'ignored'];
        $names = [];
        $paths = [];
        foreach (($contract['tables'] ?? []) as $index => $table) {
            $label = 'tables['.$index.']';
            $name = $table['name'] ?? null;
            $path = $table['documentation'] ?? null;
            $status = $table['implementation_status'] ?? null;
            if (! is_string($name) || $name === '') {
                $findings[] = $this->finding('BLOCKER', 'contract.table_name', "{$label} has no table name.");
            } elseif (isset($names[$name])) {
                $findings[] = $this->finding('BLOCKER', 'contract.duplicate_table', "Duplicate table contract: {$name}.");
            }
            $names[$name] = true;
            if (! is_string($path) || ! is_file($docsRoot.'/'.$path)) {
                $findings[] = $this->finding('BLOCKER', 'contract.documentation', "{$label} has an invalid documentation path: ".($path ?? 'null').'.');
            } elseif (isset($paths[$path])) {
                $findings[] = $this->finding('BLOCKER', 'contract.duplicate_documentation', "Duplicate documentation record: {$path}.");
            }
            $paths[$path] = true;
            foreach (($table['additional_documentation'] ?? []) as $additionalPath) {
                if (! is_string($additionalPath) || ! is_file($docsRoot.'/'.$additionalPath)) {
                    $findings[] = $this->finding('BLOCKER', 'contract.documentation', "{$label} has an invalid additional documentation path.");
                } elseif (isset($paths[$additionalPath])) {
                    $findings[] = $this->finding('BLOCKER', 'contract.duplicate_documentation', "Duplicate documentation record: {$additionalPath}.");
                }
                $paths[$additionalPath] = true;
            }
            if (! in_array($status, $allowed, true)) {
                $findings[] = $this->finding('BLOCKER', 'contract.implementation_status', "{$label} has invalid implementation_status.");
            }
            foreach (['columns', 'primary_key', 'indexes', 'foreign_keys', 'checks', 'triggers', 'views'] as $field) {
                if (! isset($table[$field]) || ! is_array($table[$field])) {
                    $findings[] = $this->finding('BLOCKER', 'contract.shape', "{$label}.{$field} must be an array.");
                }
            }
        }
        $orderedNames = array_column($contract['tables'] ?? [], 'name');
        $sortedNames = $orderedNames;
        sort($sortedNames, SORT_STRING);
        if ($orderedNames !== $sortedNames) {
            $findings[] = $this->finding('BLOCKER', 'contract.order', 'Table records must use deterministic name ordering.');
        }

        $tableDocuments = collect(iterator_to_array(Finder::create()->files()->in($docsRoot.'/database')->name('*.md')))
            ->filter(fn ($file) => $file->getFilename() !== 'README.md' && $file->getRelativePath() !== '')
            ->map(fn ($file) => 'database/'.str_replace('\\', '/', $file->getRelativePathname()))
            ->sort()->values()->all();
        foreach (array_diff($tableDocuments, array_keys($paths)) as $missing) {
            $findings[] = $this->finding('BLOCKER', 'contract.missing_document', "Table document is missing from contract: {$missing}.");
        }
        foreach (array_diff(array_keys($paths), $tableDocuments) as $unexpected) {
            $findings[] = $this->finding('BLOCKER', 'contract.unexpected_document', "Contract path is not a table document: {$unexpected}.");
        }

        foreach (($contract['allowlist'] ?? []) as $index => $entry) {
            foreach (['object', 'reason', 'owner', 'evidence', 'review_condition'] as $field) {
                if (! is_string($entry[$field] ?? null) || trim($entry[$field]) === '') {
                    $findings[] = $this->finding('BLOCKER', 'contract.allowlist', "allowlist[{$index}].{$field} is required.");
                }
            }
            if (str_contains((string) ($entry['object'] ?? ''), '*')) {
                $findings[] = $this->finding('BLOCKER', 'contract.allowlist_wildcard', "allowlist[{$index}] may not use wildcards.");
            }
        }

        return $findings;
    }

    public function migrationInventory(string $path, ?array $ledger = null): array
    {
        $files = collect(iterator_to_array(Finder::create()->files()->in($path)->depth('== 0')->name('*.php')));
        $names = $files->map(fn ($file) => $file->getFilenameWithoutExtension())->values()->all();
        $duplicates = array_keys(array_filter(array_count_values($names), fn ($count) => $count > 1));
        $timestamps = array_map(fn ($name) => substr($name, 0, 17), $names);
        $duplicateTimestamps = array_keys(array_filter(array_count_values($timestamps), fn ($count) => $count > 1));
        $createdTables = [];
        foreach ($files as $file) {
            preg_match_all('/Schema::create\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', (string) file_get_contents($file->getRealPath()), $matches);
            $createdTables = [...$createdTables, ...$matches[1]];
        }
        $createdTables = array_values(array_unique($createdTables));
        sort($createdTables);
        $result = compact('names', 'duplicates', 'duplicateTimestamps', 'createdTables');
        if ($ledger !== null) {
            $result['pending'] = array_values(array_diff($names, $ledger));
            $result['missing_source'] = array_values(array_diff($ledger, $names));
        }

        return $result;
    }

    public function compare(array $contract, array $actual, string $source = 'database'): array
    {
        $findings = [];
        $expected = collect($contract['tables'])->keyBy('name');
        $actualTables = collect($actual['tables'] ?? [])->keyBy('name');
        $allowlisted = collect($contract['allowlist'] ?? [])->pluck('object')->all();

        foreach ($expected as $name => $table) {
            $status = $table['implementation_status'];
            if ($status !== 'implemented') {
                if ($actualTables->has($name)) {
                    $findings[] = $this->finding($status === 'planned' || $status === 'not_implemented' ? 'HIGH' : 'INFO', 'table.status', "{$name} is {$status} in documentation but exists in {$source}.", $name, $source);
                } else {
                    $findings[] = $this->finding('INFO', 'table.deferred', "{$name} is {$status} and absent as expected.", $name, $source);
                }

                continue;
            }
            if (! $actualTables->has($name)) {
                $findings[] = $this->finding('BLOCKER', 'table.missing', "Implemented table {$name} is missing from {$source}.", $name, $source);

                continue;
            }
            $findings = [...$findings, ...$this->compareTable($table, $actualTables[$name], $source)];
        }

        foreach ($actualTables->keys()->diff($expected->keys()) as $name) {
            if (! in_array('table:'.$name, $allowlisted, true)) {
                $findings[] = $this->finding('MEDIUM', 'table.unexpected', "Unexpected table {$name} exists in {$source}.", $name, $source);
            }
        }

        $expectedViews = $this->semanticSet($contract['views'] ?? [], 'views');
        $actualViews = $this->semanticSet($actual['views'] ?? [], 'views');
        foreach (array_diff($expectedViews, $actualViews) as $missing) {
            $findings[] = $this->finding('MEDIUM', 'view.missing', "Expected view is missing from {$source}: {$missing}.", source: $source);
        }
        foreach (array_diff($actualViews, $expectedViews) as $unexpected) {
            $findings[] = $this->finding('MEDIUM', 'view.unexpected', "Unexpected view exists in {$source}: {$unexpected}.", source: $source);
        }

        return $findings;
    }

    private function compareTable(array $expected, array $actual, string $source): array
    {
        $findings = [];
        $name = $expected['name'];
        $actualColumns = collect($actual['columns'] ?? [])->keyBy('name');
        foreach ($expected['columns'] as $column) {
            if (! $actualColumns->has($column['name'])) {
                $findings[] = $this->finding('BLOCKER', 'column.missing', "{$name}.{$column['name']} is missing.", $name.'.'.$column['name'], $source);

                continue;
            }
            $found = $actualColumns[$column['name']];
            foreach (['type', 'nullable', 'default', 'unsigned', 'auto_increment', 'generated'] as $property) {
                if (array_key_exists($property, $column) && $this->normalize($column[$property], $property) !== $this->normalize($found[$property] ?? null, $property)) {
                    $severity = in_array($property, ['type', 'nullable', 'default'], true) ? 'HIGH' : 'MEDIUM';
                    $findings[] = $this->finding($severity, 'column.'.$property, "{$name}.{$column['name']} {$property} differs.", $name.'.'.$column['name'], $source, $column[$property], $found[$property] ?? null);
                }
            }
        }
        foreach (['primary_key', 'indexes', 'foreign_keys', 'checks', 'triggers', 'views'] as $kind) {
            $expectedObjects = $this->semanticSet($expected[$kind] ?? [], $kind);
            $actualObjects = $this->semanticSet($actual[$kind] ?? [], $kind);
            foreach (array_diff($expectedObjects, $actualObjects) as $missing) {
                $severity = $kind === 'primary_key' ? 'BLOCKER' : (in_array($kind, ['indexes', 'checks'], true) ? 'MEDIUM' : 'HIGH');
                $findings[] = $this->finding($severity, $kind.'.missing', "{$name} is missing {$kind} semantic: {$missing}.", $name, $source);
            }
        }

        return $findings;
    }

    private function semanticSet(array $items, string $kind): array
    {
        if ($kind === 'primary_key') {
            return $items === [] ? [] : [implode(',', array_map('strtolower', $items))];
        }
        $values = array_map(function ($item) use ($kind) {
            if (is_string($item)) {
                return strtolower(preg_replace('/\s+/', ' ', trim($item)));
            }
            unset($item['name']);
            ksort($item);
            foreach ($item as $itemKey => &$value) {
                if (is_array($value)) {
                    $value = array_map(fn ($v) => strtolower((string) $v), $value);
                } elseif (is_string($value)) {
                    $value = strtolower(preg_replace('/\s+/', ' ', trim($value)));
                    if (in_array($itemKey, ['on_update', 'on_delete'], true) && $value === 'no action') {
                        $value = 'restrict';
                    }
                }
            }

            return $kind.':'.json_encode($item, JSON_UNESCAPED_SLASHES);
        }, $items);
        sort($values);

        return array_values(array_unique($values));
    }

    private function normalize(mixed $value, string $property): mixed
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
            $value = preg_replace('/\s+/', ' ', $value);
            if ($property === 'default') {
                $value = trim($value, "()'");
                $value = match ($value) {
                    'null' => null, 'current_timestamp()' => 'current_timestamp', default => $value
                };
            }
            if ($property === 'type') {
                $value = preg_replace('/\s+unsigned\b/', '', $value);
                $value = preg_replace('/\(.+\)$/', '', $value);
                $value = preg_replace('/\btinyint\b/', 'boolean', $value);
                $value = match ($value) {
                    'character varying' => 'varchar', 'double precision' => 'double', default => $value
                };
            }
        }

        return $value;
    }

    public function finding(string $severity, string $code, string $message, ?string $object = null, ?string $source = null, mixed $expected = null, mixed $actual = null): array
    {
        return array_filter(compact('severity', 'code', 'message', 'object', 'source', 'expected', 'actual'), fn ($value) => $value !== null);
    }
}
