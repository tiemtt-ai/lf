<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'uk_users_id_customer';

    private const INDEX_COLUMNS = ['id', 'customer_id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertPreflight();

        if ($this->hasEquivalentUniqueIndex()) {
            return;
        }

        if ($this->index(self::INDEX_NAME) !== null) {
            throw new RuntimeException(self::INDEX_NAME.' exists with an unexpected definition.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `users` ADD UNIQUE INDEX `'.self::INDEX_NAME.'` (`id`, `customer_id`), ALGORITHM=INPLACE, LOCK=NONE'
            );
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique(self::INDEX_COLUMNS, self::INDEX_NAME);
            });
        }

        if (! $this->hasEquivalentUniqueIndex()) {
            throw new RuntimeException('User tenant composite unique index was not created.');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $index = $this->index(self::INDEX_NAME);

        if ($index === null) {
            return;
        }

        if (! $this->isExpectedUniqueIndex($index)) {
            throw new RuntimeException(self::INDEX_NAME.' has an unexpected definition and cannot be dropped safely.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `users` DROP INDEX `'.self::INDEX_NAME.'`, ALGORITHM=INPLACE, LOCK=NONE'
            );
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX_NAME);
            });
        }
    }

    private function assertPreflight(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('saas_customers')) {
            throw new RuntimeException('Phase 4A requires users and saas_customers tables.');
        }

        if (! Schema::hasColumn('users', 'id') || ! Schema::hasColumn('users', 'customer_id')) {
            throw new RuntimeException('Phase 4A requires users.id and users.customer_id.');
        }

        $customerId = collect(Schema::getColumns('users'))
            ->first(fn (array $column): bool => strtolower($column['name']) === 'customer_id');

        if ($customerId === null || ($customerId['nullable'] ?? true)) {
            throw new RuntimeException('Phase 4A requires users.customer_id to be NOT NULL.');
        }

        if (DB::table('users')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Phase 4A found users with a null customer_id.');
        }

        if (DB::table('users as users')
            ->leftJoin('saas_customers as customers', 'customers.id', '=', 'users.customer_id')
            ->whereNull('customers.id')
            ->exists()) {
            throw new RuntimeException('Phase 4A found users without a valid tenant.');
        }
    }

    private function hasEquivalentUniqueIndex(): bool
    {
        return collect(Schema::getIndexes('users'))
            ->contains(fn (array $index): bool => $this->isExpectedUniqueIndex($index));
    }

    private function index(string $name): ?array
    {
        return collect(Schema::getIndexes('users'))
            ->first(fn (array $index): bool => strtolower($index['name']) === strtolower($name));
    }

    private function isExpectedUniqueIndex(array $index): bool
    {
        $columns = array_map('strtolower', $index['columns'] ?? []);

        return ($index['unique'] ?? false) === true && $columns === self::INDEX_COLUMNS;
    }
};
