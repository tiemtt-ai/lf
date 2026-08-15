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
 * and the exact definition, so a pre-existing equivalent key created elsewhere
 * is never dropped by this rollback.
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
            if (! Schema::hasTable($table)) {
                continue;
            }

            $index = $this->index($table, $name);

            if ($index === null) {
                continue;
            }

            if (! $this->isExpectedUniqueIndex($index)) {
                throw new RuntimeException("{$name} has an unexpected definition and cannot be dropped safely.");
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

    private function assertPreflight(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Phase 4E prerequisite requires the {$table} table.");
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException("Phase 4E prerequisite requires {$table}.{$column}.");
            }
        }

        $customerId = collect(Schema::getColumns($table))
            ->first(fn (array $column): bool => strtolower($column['name']) === 'customer_id');

        if ($customerId === null || ($customerId['nullable'] ?? true)) {
            throw new RuntimeException("Phase 4E prerequisite requires {$table}.customer_id to be NOT NULL.");
        }

        if (DB::table($table)->whereNull('customer_id')->exists()) {
            throw new RuntimeException("Phase 4E prerequisite found {$table} rows with a null customer_id.");
        }

        if (Schema::hasTable('saas_customers')
            && DB::table($table.' as child')
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
