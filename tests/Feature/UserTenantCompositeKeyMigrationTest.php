<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UserTenantCompositeKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_NAME = 'uk_users_id_customer';

    public function test_fresh_schema_has_the_named_user_tenant_composite_unique_index(): void
    {
        $index = $this->index(self::INDEX_NAME);

        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
        $this->assertSame(['id', 'customer_id'], $index['columns']);
    }

    public function test_composite_foreign_key_rejects_a_cross_tenant_user_reference(): void
    {
        Schema::create('test_user_tenant_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_id');
            $table->foreign(['user_id', 'customer_id'], 'fk_test_user_tenant')
                ->references(['id', 'customer_id'])
                ->on('users')
                ->restrictOnDelete();
        });

        $tenantA = $this->createTenant('phase-4a-a');
        $tenantB = $this->createTenant('phase-4a-b');
        $user = DB::table('users')->insertGetId([
            'customer_id' => $tenantA,
            'name' => 'Phase 4A User',
            'email' => 'phase-4a@example.test',
            'password' => 'not-used',
            'role' => 'student',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('test_user_tenant_references')->insert([
            'user_id' => $user,
            'customer_id' => $tenantA,
        ]);

        $this->expectException(QueryException::class);
        DB::table('test_user_tenant_references')->insert([
            'user_id' => $user,
            'customer_id' => $tenantB,
        ]);
    }

    public function test_migration_is_idempotent_and_down_drops_only_its_index(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            $migration->up();
            $migration->up();

            $matching = collect(Schema::getIndexes('users'))
                ->filter(fn (array $index): bool => strtolower($index['name']) === self::INDEX_NAME);

            $this->assertCount(1, $matching);

            $migration->down();

            $this->assertNull($this->index(self::INDEX_NAME));
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertNotNull($this->index('users_email_unique'));
        });
    }

    public function test_migration_preflight_rejects_nullable_customer_ownership(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('customer_id')->nullable()->change();
            });

            try {
                $migration->up();
                $this->fail('Nullable users.customer_id should block Phase 4A.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Phase 4A requires users.customer_id to be NOT NULL.',
                    $exception->getMessage()
                );
            }

            $this->assertNull($this->index(self::INDEX_NAME));
        });
    }

    public function test_migration_preflight_rejects_orphan_tenant_ownership(): void
    {
        $this->withIsolatedSchema(function ($migration): void {
            Schema::disableForeignKeyConstraints();
            DB::table('users')->insert([
                'customer_id' => 999,
                'name' => 'Orphan User',
                'email' => 'orphan@example.test',
            ]);
            Schema::enableForeignKeyConstraints();

            try {
                $migration->up();
                $this->fail('Orphan tenant ownership should block Phase 4A.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Phase 4A found users without a valid tenant.',
                    $exception->getMessage()
                );
            }

            $this->assertNull($this->index(self::INDEX_NAME));
        });
    }

    private function withIsolatedSchema(callable $callback): void
    {
        $original = DB::getDefaultConnection();
        $connection = 'phase4a_'.strtolower(str_replace('.', '_', uniqid('', true)));
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
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->string('name');
                $table->string('email')->unique();
                $table->foreign('customer_id')->references('id')->on('saas_customers');
            });

            $migration = require database_path(
                'migrations/2026_08_13_000000_add_user_tenant_composite_unique.php'
            );
            $callback($migration);
        } finally {
            DB::disconnect($connection);
            DB::setDefaultConnection($original);
            config()->offsetUnset("database.connections.{$connection}");
        }
    }

    private function index(string $name): ?array
    {
        return collect(Schema::getIndexes('users'))
            ->first(fn (array $index): bool => strtolower($index['name']) === strtolower($name));
    }

    private function createTenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'subdomain' => $slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
