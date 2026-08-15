<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CourseTenantCompositeKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TARGETS = [
        'core_course_cohorts' => 'uk_core_course_cohorts_id_customer',
        'core_course_cohort_teachers' => 'uk_core_course_cohort_teachers_id_customer',
        'core_course_cohort_students' => 'uk_core_course_cohort_students_id_customer',
        'core_course_enrollments' => 'uk_core_course_enrollments_id_customer',
    ];

    public function test_fresh_schema_has_all_four_named_composite_unique_indexes(): void
    {
        foreach (self::TARGETS as $table => $name) {
            $index = $this->index($table, $name);
            $this->assertNotNull($index);
            $this->assertTrue($index['unique']);
            $this->assertSame(['id', 'customer_id'], $index['columns']);
        }
    }

    public function test_migration_is_idempotent_and_down_removes_canonical_exact_indexes(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            $migration->up();

            // Laravel builds a new instance per run, so replay must be proven
            // across instances rather than on the one that created the keys.
            $this->freshMigration()->up();

            foreach (self::TARGETS as $table => $name) {
                $this->assertNotNull($this->index($table, $name));
            }

            $migration->down();

            foreach (self::TARGETS as $table => $name) {
                $this->assertNull($this->index($table, $name));
                $this->assertNotNull($this->index($table, "{$table}_customer_id_index"));
            }
        });
    }

    public function test_exact_alternate_index_is_adopted_without_duplicate_or_rollback_deletion(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            Schema::table('core_course_cohorts', function (Blueprint $table): void {
                $table->unique(['id', 'customer_id'], 'existing_exact_parent_key');
            });

            $migration->up();

            $this->assertNotNull($this->index('core_course_cohorts', 'existing_exact_parent_key'));
            $this->assertNull($this->index(
                'core_course_cohorts',
                self::TARGETS['core_course_cohorts']
            ));

            $migration->down();

            $this->assertNotNull($this->index('core_course_cohorts', 'existing_exact_parent_key'));
        });
    }

    public function test_conflicting_canonical_index_fails_closed(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            Schema::table('core_course_cohorts', function (Blueprint $table): void {
                $table->index(
                    ['id', 'customer_id'],
                    self::TARGETS['core_course_cohorts']
                );
            });

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already has a non-unique (id, customer_id) index');
            $migration->up();
        });
    }

    public function test_interrupted_forward_run_can_be_retried_and_rolled_back(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            // A run killed between two ALTER TABLE statements leaves keys behind
            // with no ledger row. Reproduce that by creating the first key only.
            Schema::table('core_course_cohorts', function (Blueprint $table): void {
                $table->unique(
                    ['id', 'customer_id'],
                    self::TARGETS['core_course_cohorts']
                );
            });

            $this->freshMigration()->up();

            foreach (self::TARGETS as $table => $name) {
                $this->assertNotNull(
                    $this->index($table, $name),
                    "Retry must finish {$table} instead of failing closed."
                );
            }

            $this->freshMigration()->down();

            foreach (self::TARGETS as $table => $name) {
                $this->assertNull($this->index($table, $name));
            }
        });
    }

    public function test_wrong_order_unique_key_fails_closed_before_any_index_is_created(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            Schema::table('core_course_enrollments', function (Blueprint $table): void {
                $table->unique(['customer_id', 'id'], 'wrong_order_parent_key');
            });

            try {
                $migration->up();
                $this->fail('Wrong-order parent key should block the prerequisite.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('wrong-order unique key', $exception->getMessage());
            }

            foreach (self::TARGETS as $table => $name) {
                $this->assertNull($this->index($table, $name));
            }
        });
    }

    public function test_rollback_conflict_on_later_table_fails_before_any_drop(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            $migration->up();
            Schema::table('core_course_cohorts', function (Blueprint $table): void {
                $table->unique(['id', 'customer_id'], 'alternate_exact_parent_key');
            });

            try {
                $this->freshMigration()->down();
                $this->fail('Ambiguous rollback should fail before mutation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('another exact parent key exists', $exception->getMessage());
            }

            foreach (self::TARGETS as $table => $name) {
                $this->assertNotNull(
                    $this->index($table, $name),
                    "Rollback preflight must preserve {$table}."
                );
            }
        });
    }

    public function test_child_composite_foreign_key_blocks_rollback_before_any_drop(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            $migration->up();
            Schema::create('test_parent_key_dependency', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('enrollment_id');
                $table->unsignedBigInteger('customer_id');
                $table->foreign(['enrollment_id', 'customer_id'])
                    ->references(['id', 'customer_id'])
                    ->on('core_course_enrollments')
                    ->restrictOnDelete();
            });

            try {
                $this->freshMigration()->down();
                $this->fail('Child composite FK should block rollback.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('child composite foreign key', $exception->getMessage());
            }

            foreach (self::TARGETS as $table => $name) {
                $this->assertNotNull($this->index($table, $name));
            }
        });
    }

    public function test_missing_tenant_table_fails_closed(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            Schema::disableForeignKeyConstraints();
            Schema::drop('saas_customers');
            Schema::enableForeignKeyConstraints();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('requires the saas_customers table');
            $migration->up();
        });
    }

    public function test_orphan_tenant_fails_before_any_index_is_created(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            Schema::disableForeignKeyConstraints();
            DB::table('core_course_enrollments')->insert([
                'customer_id' => 999,
            ]);
            Schema::enableForeignKeyConstraints();

            try {
                $migration->up();
                $this->fail('Orphan tenant ownership should block the prerequisite.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Phase 4E prerequisite found core_course_enrollments rows without a valid tenant.',
                    $exception->getMessage()
                );
            }

            foreach (self::TARGETS as $table => $name) {
                $this->assertNull($this->index($table, $name));
            }
        });
    }

    public function test_composite_foreign_key_rejects_cross_tenant_parent_reference(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            $migration->up();

            Schema::create('test_teacher_judgments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('cohort_id');
                $table->unsignedBigInteger('customer_id');
                $table->foreign(['cohort_id', 'customer_id'])
                    ->references(['id', 'customer_id'])
                    ->on('core_course_cohorts')
                    ->restrictOnDelete();
            });

            $tenantA = DB::table('saas_customers')->insertGetId(['name' => 'A']);
            $tenantB = DB::table('saas_customers')->insertGetId(['name' => 'B']);
            $cohort = DB::table('core_course_cohorts')->insertGetId([
                'customer_id' => $tenantA,
            ]);

            DB::table('test_teacher_judgments')->insert([
                'cohort_id' => $cohort,
                'customer_id' => $tenantA,
            ]);

            $this->expectException(QueryException::class);
            DB::table('test_teacher_judgments')->insert([
                'cohort_id' => $cohort,
                'customer_id' => $tenantB,
            ]);
        });
    }

    private function withIsolatedSchema(callable $callback): void
    {
        $original = DB::getDefaultConnection();
        $connection = 'phase4e_parent_'.strtolower(str_replace('.', '_', uniqid('', true)));
        config(["database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection($connection);

        try {
            Schema::create('saas_customers', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });

            foreach (array_keys(self::TARGETS) as $tableName) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('customer_id');
                    $table->foreign('customer_id')->references('id')->on('saas_customers');
                    $table->index('customer_id');
                });
            }

            $callback($this->freshMigration());
        } finally {
            DB::disconnect($connection);
            DB::setDefaultConnection($original);
            config()->offsetUnset("database.connections.{$connection}");
        }
    }

    private function freshMigration(): object
    {
        return require database_path(
            'migrations/2026_08_15_000000_add_course_tenant_composite_uniques.php'
        );
    }

    private function index(string $table, string $name): ?array
    {
        return collect(Schema::getIndexes($table))
            ->first(fn (array $index): bool => strtolower($index['name']) === strtolower($name));
    }
}
