<?php

namespace App\Console\Commands;

use App\Support\SchemaDrift\MySqlSchemaInspector;
use App\Support\SchemaDrift\SchemaDriftAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchemaDrift extends Command
{
    protected $signature = 'schema:drift
        {--docs-only : Validate the documentation contract and migration inventory without connecting to a database}
        {--fresh : Build and inspect an isolated temporary MySQL/MariaDB database}
        {--connection= : Read-only comparison against an explicitly selected configured connection}
        {--allow-production-read : Explicitly allow metadata-only inspection when APP_ENV is production}
        {--format=text : Output format: text or json}
        {--contract= : Alternate contract path for isolated validation}';

    protected $description = 'Detect drift between database documentation, migrations, a fresh database, and an explicitly selected database.';

    public function handle(SchemaDriftAnalyzer $analyzer, MySqlSchemaInspector $inspector): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            return $this->failSafely($format, 'Option --format must be text or json.');
        }
        $modes = array_filter([$this->option('docs-only'), $this->option('fresh'), $this->option('connection') !== null]);
        if (count($modes) !== 1) {
            return $this->failSafely($format, 'Select exactly one mode: --docs-only, --fresh, or --connection=<name>.');
        }

        try {
            $contractPath = (string) ($this->option('contract') ?: config('schema-drift.contract'));
            $contract = $analyzer->loadContract($contractPath);
            $inventory = $analyzer->migrationInventory(database_path('migrations'));
            $findings = [];
            foreach ($inventory['duplicates'] as $name) {
                $findings[] = $analyzer->finding('BLOCKER', 'migration.duplicate_name', "Duplicate migration name: {$name}.");
            }
            foreach ($inventory['duplicateTimestamps'] as $timestamp) {
                if (! collect($contract['allowlist'] ?? [])->contains(fn ($entry) => ($entry['object'] ?? null) === 'migration_timestamp:'.$timestamp)) {
                    $findings[] = $analyzer->finding('BLOCKER', 'migration.duplicate_timestamp', "Duplicate migration timestamp: {$timestamp}.");
                }
            }
            $declaredTables = collect($contract['tables'])->keyBy('name');
            foreach ($declaredTables->where('implementation_status', 'implemented')->keys()->diff($inventory['createdTables']) as $name) {
                $findings[] = $analyzer->finding('BLOCKER', 'migration.missing_table_intent', "Implemented documentation contract has no creating migration: {$name}.");
            }
            foreach (collect($inventory['createdTables'])->filter(fn ($name) => $declaredTables->has($name) && in_array($declaredTables[$name]['implementation_status'], ['planned', 'not_implemented'], true)) as $name) {
                $findings[] = $analyzer->finding('HIGH', 'migration.deferred_table_intent', "Deferred documentation contract has a creating migration: {$name}.");
            }

            $target = ['mode' => 'docs-only'];
            if ($this->option('fresh')) {
                [$target, $databaseFindings, $ledger] = $this->inspectFresh($contract, $analyzer, $inspector);
                $findings = [...$findings, ...$databaseFindings];
                $inventory = $analyzer->migrationInventory(database_path('migrations'), $ledger);
            } elseif ($this->option('connection') !== null) {
                [$target, $databaseFindings, $ledger] = $this->inspectSelected((string) $this->option('connection'), $contract, $analyzer, $inspector);
                $findings = [...$findings, ...$databaseFindings];
                $inventory = $analyzer->migrationInventory(database_path('migrations'), $ledger);
            }
            foreach (($inventory['pending'] ?? []) as $name) {
                $findings[] = $analyzer->finding('BLOCKER', 'migration.pending', "Migration exists in source but is absent from ledger: {$name}.");
            }
            foreach (($inventory['missing_source'] ?? []) as $name) {
                $findings[] = $analyzer->finding('BLOCKER', 'migration.missing_source', "Migration exists in ledger but is absent from source: {$name}.");
            }

            $report = [
                'status' => collect($findings)->contains(fn ($finding) => in_array($finding['severity'], ['BLOCKER', 'HIGH', 'MEDIUM'], true)) ? 'failed' : 'passed',
                'target' => $target,
                'migration_files' => count($inventory['names']),
                'migration_ledger' => isset($inventory['pending']) ? ['pending' => $inventory['pending'], 'missing_source' => $inventory['missing_source']] : 'not_checked',
                'findings' => $findings,
            ];
            $this->render($report, $format);

            return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            return $this->failSafely($format, $this->redact($exception->getMessage()));
        }
    }

    private function inspectSelected(string $name, array $contract, SchemaDriftAnalyzer $analyzer, MySqlSchemaInspector $inspector): array
    {
        if ($name === '' || ! config()->has("database.connections.{$name}")) {
            throw new \RuntimeException('Selected connection is empty or undefined.');
        }
        if (app()->environment('production') && ! $this->option('allow-production-read')) {
            throw new \RuntimeException('Production metadata inspection requires --allow-production-read.');
        }
        $configuration = config("database.connections.{$name}");
        if (! in_array($configuration['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Schema drift comparison supports only MySQL/MariaDB; SQLite cannot establish compatibility.');
        }
        $connection = DB::connection($name);
        $database = (string) $connection->getDatabaseName();
        if ($database === '') {
            throw new \RuntimeException('Selected database name is empty.');
        }
        $target = $this->safeTarget($name, $configuration, $database, 'selected-read-only');
        $actual = $inspector->inspect($connection);
        $ledger = $connection->getSchemaBuilder()->hasTable(config('database.migrations.table', 'migrations'))
            ? $connection->table(config('database.migrations.table', 'migrations'))->pluck('migration')->all()
            : [];

        return [$target, $analyzer->compare($contract, $actual, 'selected database'), $ledger];
    }

    private function inspectFresh(array $contract, SchemaDriftAnalyzer $analyzer, MySqlSchemaInspector $inspector): array
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Fresh mode is prohibited in production.');
        }
        $baseName = (string) config('database.default');
        $configuration = config("database.connections.{$baseName}");
        if (! in_array($configuration['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Fresh mode requires the default connection to be MySQL/MariaDB; SQLite is intentionally rejected.');
        }
        $temporaryDatabase = 'lf_schema_drift_'.strtolower(Str::random(16));
        if (! preg_match('/^lf_schema_drift_[a-z0-9]{16}$/', $temporaryDatabase)) {
            throw new \RuntimeException('Refusing unsafe temporary database name.');
        }
        $adminName = 'schema_drift_admin';
        $freshName = 'schema_drift_fresh';
        $admin = $configuration;
        $admin['url'] = null;
        $admin['database'] = null;
        $fresh = $configuration;
        $fresh['url'] = null;
        $fresh['database'] = $temporaryDatabase;
        config(["database.connections.{$adminName}" => $admin, "database.connections.{$freshName}" => $fresh]);
        DB::purge($adminName);

        $created = false;
        try {
            DB::connection($adminName)->statement("CREATE DATABASE `{$temporaryDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;
            DB::purge($freshName);
            $exit = Artisan::call('migrate', ['--database' => $freshName, '--force' => true, '--no-interaction' => true]);
            if ($exit !== self::SUCCESS) {
                throw new \RuntimeException('Fresh migration run failed: '.trim(Artisan::output()));
            }
            $connection = DB::connection($freshName);
            $actual = $inspector->inspect($connection);
            $ledger = $connection->table(config('database.migrations.table', 'migrations'))->pluck('migration')->all();
            $target = $this->safeTarget($freshName, $fresh, $temporaryDatabase, 'fresh-ephemeral');

            return [$target, $analyzer->compare($contract, $actual, 'fresh database'), $ledger];
        } finally {
            DB::disconnect($freshName);
            if ($created) {
                DB::connection($adminName)->statement("DROP DATABASE `{$temporaryDatabase}`");
            }
            DB::disconnect($adminName);
        }
    }

    private function safeTarget(string $connection, array $configuration, string $database, string $mode): array
    {
        return [
            'mode' => $mode,
            'connection' => $connection,
            'driver' => $configuration['driver'] ?? 'unknown',
            'host' => $configuration['host'] ?? 'socket/local',
            'port' => $configuration['port'] ?? null,
            'database' => $database,
        ];
    }

    private function render(array $report, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $target = $report['target'];
        $this->line(sprintf('Target: mode=%s driver=%s host=%s database=%s', $target['mode'], $target['driver'] ?? 'n/a', $target['host'] ?? 'n/a', $target['database'] ?? 'n/a'));
        $this->line('Migration files: '.$report['migration_files']);
        foreach ($report['findings'] as $finding) {
            $this->line(sprintf('[%s] %s: %s', $finding['severity'], $finding['code'], $finding['message']));
        }
        $this->{$report['status'] === 'passed' ? 'info' : 'error'}('schema:drift '.$report['status'].'.');
    }

    private function failSafely(string $format, string $message): int
    {
        $report = ['status' => 'failed', 'findings' => [['severity' => 'BLOCKER', 'code' => 'command.error', 'message' => $message]]];
        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function redact(string $message): string
    {
        foreach (config('database.connections', []) as $connection) {
            foreach (['password', 'username', 'url'] as $field) {
                $secret = $connection[$field] ?? null;
                if (is_string($secret) && $secret !== '') {
                    $message = str_replace($secret, '[redacted]', $message);
                }
            }
        }

        return $message;
    }
}
