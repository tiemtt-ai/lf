<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4E Course parent-key prerequisite.
 *
 * Adds the exact `UNIQUE (id, customer_id)` candidate key to the four released
 * Course parents so a future LiveClass-owned Teacher Judgment source can declare
 * tenant-safe composite foreign keys of the shape `(parent_id, customer_id)`.
 * The primary key on `id` proves row identity but is not the composite candidate
 * key MySQL/MariaDB requires for those child references.
 *
 * This migration adds keys only. It changes no data, column, primary key,
 * foreign key or lifecycle, and it creates no child table.
 *
 * Conflict policy, narrowing the prerequisite review's "partial overlap" wording
 * to an operational rule. Every target already carries ordinary secondary
 * indexes led by `customer_id` (for example `idx_cce_customer`); those are not
 * conflicts and must not block. An index blocks only when it is, ignoring
 * PRIMARY:
 *
 *   - exactly `(customer_id, id)` — wrong order for the intended child shape;
 *   - exactly `(id, customer_id)` but not UNIQUE;
 *   - UNIQUE and led by `id` — it would make the new key ambiguous;
 *   - occupying the canonical name with any other definition.
 *
 * An exact ordered UNIQUE `(id, customer_id)` under any name satisfies the
 * contract: that table is skipped as an idempotent no-op. `down()` is the
 * asymmetric half — it removes only a key that matches both the canonical name
 * and the exact definition. A pre-existing non-canonical equivalent is never
 * dropped; an exact canonical key is deliberately adopted as described below.
 *
 * Both directions must stay re-runnable. Laravel builds a fresh migration
 * instance per run and writes the ledger row only after `up()` returns, so a run
 * interrupted between two `ALTER TABLE` statements leaves keys in place with no
 * ledger row. Ownership must therefore be derived from the key definition, not
 * from per-instance state: a canonical-named exact key is byte-identical to the
 * one this migration creates, so adopting it lets a retry finish the remaining
 * tables and lets `down()` unwind. `assertNoConflictingIndex()` already rejects
 * the canonical name carrying any other definition, which is the case that
 * genuinely needs a human.
 */
return new class extends Migration
{
    private const TARGETS = [
        'core_course_cohorts' => 'uk_core_course_cohorts_id_customer',
        'core_course_cohort_teachers' => 'uk_core_course_cohort_teachers_id_customer',
        'core_course_cohort_students' => 'uk_core_course_cohort_students_id_customer',
        'core_course_enrollments' => 'uk_core_course_enrollments_id_customer',
    ];

    private const COLUMNS = ['id', 'customer_id'];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $name) {
            $this->assertPreflight($table, $name);
        }

        foreach (self::TARGETS as $table => $name) {
            if ($this->exactUniqueIndex($table) !== null) {
                continue;
            }

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement(
                    'ALTER TABLE `'.$table.'` ADD UNIQUE INDEX `'.$name.'` (`id`, `customer_id`), ALGORITHM=INPLACE, LOCK=NONE'
                );
            } else {
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->unique(self::COLUMNS, $name);
                });
            }

            if ($this->exactUniqueIndex($table) === null) {
                throw new RuntimeException("Course tenant composite unique index was not created on {$table}.");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TARGETS, true) as $table => $name) {
            $this->assertRollbackSafe($table, $name);
        }

        foreach (array_reverse(self::TARGETS, true) as $table => $name) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $index = $this->index($table, $name);

            if ($index === null) {
                continue;
            }

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement(
                    'ALTER TABLE `'.$table.'` DROP INDEX `'.$name.'`, ALGORITHM=INPLACE, LOCK=NONE'
                );
            } else {
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropUnique($name);
                });
            }
        }
    }

    private function assertRollbackSafe(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $index = $this->index($table, $name);
        if ($index === null) {
            return;
        }

        if (! $this->isExpectedUniqueIndex($index)) {
            throw new RuntimeException("{$name} has an unexpected definition and cannot be dropped safely.");
        }

        $otherExact = collect(Schema::getIndexes($table))->contains(
            fn (array $candidate): bool => $this->isExpectedUniqueIndex($candidate)
                && strtolower((string) ($candidate['name'] ?? '')) !== strtolower($name)
        );
        if ($otherExact) {
            throw new RuntimeException("{$name} cannot be dropped while another exact parent key exists.");
        }

        if ($this->hasReferencingCompositeForeignKey($table)) {
            throw new RuntimeException("{$name} cannot be dropped while a child composite foreign key depends on it.");
        }
    }

    /**
     * On MySQL/MariaDB this must not walk `Schema::getTables()`. That helper is
     * not schema-filtered, so it returns every table the connection can see on
     * the whole server — 890 of them on the development host — and introspecting
     * each one costs roughly 0.18s, putting a single rollback at about ten
     * minutes and scanning databases this migration has no business reading.
     * One schema-filtered information_schema query per parent answers the same
     * question. Other drivers keep the loop, scoped to the current connection.
     */
    private function hasReferencingCompositeForeignKey(string $table): bool
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $referencedColumns = implode(',', self::COLUMNS);

            foreach (DB::select(
                'SELECT GROUP_CONCAT(LOWER(REFERENCED_COLUMN_NAME) ORDER BY ORDINAL_POSITION) AS referenced_columns
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?
                  GROUP BY CONSTRAINT_SCHEMA, CONSTRAINT_NAME',
                [$table]
            ) as $constraint) {
                if ($constraint->referenced_columns === $referencedColumns) {
                    return true;
                }
            }

            return false;
        }

        foreach (Schema::getTables($this->currentSchema()) as $candidate) {
            $candidateName = (string) ($candidate['name'] ?? '');
            if ($candidateName === '') {
                continue;
            }

            foreach (Schema::getForeignKeys($candidateName) as $foreignKey) {
                $foreignTable = strtolower((string) ($foreignKey['foreign_table'] ?? ''));
                $foreignColumns = array_map('strtolower', $foreignKey['foreign_columns'] ?? []);

                if ($foreignTable === strtolower($table) && $foreignColumns === self::COLUMNS) {
                    return true;
                }
            }
        }

        return false;
    }

    private function currentSchema(): ?string
    {
        return DB::getDriverName() === 'sqlite' ? null : DB::connection()->getDatabaseName();
    }

    private function assertPreflight(string $table, string $name): void
    {
        if (! Schema::hasTable('saas_customers')) {
            throw new RuntimeException('Phase 4E prerequisite requires the saas_customers table.');
        }

        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Phase 4E prerequisite requires the {$table} table.");
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException("Phase 4E prerequisite requires {$table}.{$column}.");
            }
        }

        $columns = collect(Schema::getColumns($table));
        foreach (self::COLUMNS as $columnName) {
            $column = $columns->first(
                fn (array $candidate): bool => strtolower($candidate['name']) === $columnName
            );

            if ($column === null || ($column['nullable'] ?? true)) {
                throw new RuntimeException("Phase 4E prerequisite requires {$table}.{$columnName} to be NOT NULL.");
            }

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $type = strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));
                if (! str_contains($type, 'bigint') || ! str_contains($type, 'unsigned')) {
                    throw new RuntimeException("Phase 4E prerequisite requires {$table}.{$columnName} to be BIGINT UNSIGNED.");
                }
            }
        }

        if (DB::table($table)->whereNull('customer_id')->exists()) {
            throw new RuntimeException("Phase 4E prerequisite found {$table} rows with a null customer_id.");
        }

        if (DB::table($table.' as child')
            ->leftJoin('saas_customers as tenant', 'tenant.id', '=', 'child.customer_id')
            ->whereNull('tenant.id')
            ->exists()) {
            throw new RuntimeException("Phase 4E prerequisite found {$table} rows without a valid tenant.");
        }

        $this->assertNoConflictingIndex($table, $name);
    }

    private function assertNoConflictingIndex(string $table, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary'] ?? false) {
                continue;
            }

            $columns = array_map('strtolower', $index['columns'] ?? []);
            $unique = ($index['unique'] ?? false) === true;
            $indexName = (string) ($index['name'] ?? '');

            if ($columns === array_reverse(self::COLUMNS) && $unique) {
                throw new RuntimeException("{$table} already has a wrong-order unique key: {$indexName}.");
            }

            if ($columns === self::COLUMNS && ! $unique) {
                throw new RuntimeException("{$table} already has a non-unique (id, customer_id) index: {$indexName}.");
            }

            if ($unique && $columns !== self::COLUMNS && ($columns[0] ?? null) === 'id') {
                throw new RuntimeException("{$table} already has an ambiguous unique key led by id: {$indexName}.");
            }

            if (strtolower($indexName) === strtolower($name) && ! $this->isExpectedUniqueIndex($index)) {
                throw new RuntimeException("{$name} exists on {$table} with an unexpected definition.");
            }
        }
    }

    private function exactUniqueIndex(string $table): ?array
    {
        return collect(Schema::getIndexes($table))
            ->first(fn (array $index): bool => $this->isExpectedUniqueIndex($index));
    }

    private function index(string $table, string $name): ?array
    {
        return collect(Schema::getIndexes($table))
            ->first(fn (array $index): bool => strtolower((string) ($index['name'] ?? '')) === strtolower($name));
    }

    private function isExpectedUniqueIndex(array $index): bool
    {
        if ($index['primary'] ?? false) {
            return false;
        }

        return ($index['unique'] ?? false) === true
            && array_map('strtolower', $index['columns'] ?? []) === self::COLUMNS;
    }
};
