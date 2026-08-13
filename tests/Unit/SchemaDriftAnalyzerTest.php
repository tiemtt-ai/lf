<?php

namespace Tests\Unit;

use App\Support\SchemaDrift\SchemaDriftAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SchemaDriftAnalyzerTest extends TestCase
{
    private SchemaDriftAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new SchemaDriftAnalyzer;
    }

    public function test_matching_schema_passes(): void
    {
        $this->assertSame([], $this->analyzer->compare($this->contract(), $this->actual()));
    }

    #[DataProvider('driftProvider')]
    public function test_semantic_drift_is_detected(callable $mutate, string $code): void
    {
        $actual = $this->actual();
        $mutate($actual);
        $this->assertContains($code, array_column($this->analyzer->compare($this->contract(), $actual), 'code'));
    }

    public static function driftProvider(): array
    {
        return [
            'missing table' => [fn (&$a) => $a['tables'] = [], 'table.missing'],
            'unexpected table' => [fn (&$a) => $a['tables'][] = ['name' => 'manual_table'], 'table.unexpected'],
            'missing column' => [fn (&$a) => array_shift($a['tables'][0]['columns']), 'column.missing'],
            'type mismatch' => [fn (&$a) => $a['tables'][0]['columns'][0]['type'] = 'varchar', 'column.type'],
            'nullable mismatch' => [fn (&$a) => $a['tables'][0]['columns'][0]['nullable'] = true, 'column.nullable'],
            'default mismatch' => [fn (&$a) => $a['tables'][0]['columns'][0]['default'] = '1', 'column.default'],
            'missing unique index' => [fn (&$a) => $a['tables'][0]['indexes'] = [], 'indexes.missing'],
            'foreign key action mismatch' => [fn (&$a) => $a['tables'][0]['foreign_keys'][0]['on_delete'] = 'cascade', 'foreign_keys.missing'],
            'missing trigger' => [fn (&$a) => $a['tables'][0]['triggers'] = [], 'triggers.missing'],
        ];
    }

    public function test_planned_table_absence_is_info_only(): void
    {
        $contract = $this->contract();
        $contract['tables'][0]['implementation_status'] = 'planned';
        $findings = $this->analyzer->compare($contract, ['tables' => []]);
        $this->assertSame('INFO', $findings[0]['severity']);
    }

    public function test_trigger_name_drift_is_detected_when_the_contract_requires_identity(): void
    {
        $contract = $this->contract();
        $contract['tables'][0]['trigger_identity_required'] = true;
        $actual = $this->actual();
        $actual['tables'][0]['triggers'][0]['name'] = 'wrong_trigger';

        $this->assertContains('triggers.missing', array_column($this->analyzer->compare($contract, $actual), 'code'));
    }

    public function test_trigger_identity_requirement_must_be_boolean(): void
    {
        [$root, $table] = $this->contractFixture();
        $table['trigger_identity_required'] = 'yes';
        $codes = array_column($this->analyzer->validateContract([
            'schema_version' => '1.0',
            'database_family' => 'mysql',
            'tables' => [$table],
        ], $root), 'code');
        $this->removeContractFixture($root);

        $this->assertContains('contract.trigger_identity_required', $codes);
    }

    public function test_migration_ledger_reports_pending_and_missing_source(): void
    {
        $directory = sys_get_temp_dir().'/lf-migrations-'.uniqid();
        mkdir($directory);
        touch($directory.'/2026_01_01_000000_one.php');
        $result = $this->analyzer->migrationInventory($directory, ['2026_01_02_000000_removed']);
        unlink($directory.'/2026_01_01_000000_one.php');
        rmdir($directory);
        $this->assertSame(['2026_01_01_000000_one'], $result['pending']);
        $this->assertSame(['2026_01_02_000000_removed'], $result['missing_source']);
    }

    public function test_migration_inventory_detects_raw_create_table_statements(): void
    {
        $directory = sys_get_temp_dir().'/lf-migrations-'.uniqid();
        mkdir($directory);
        $migration = $directory.'/2026_01_01_000000_raw.php';
        file_put_contents($migration, <<<'PHP'
<?php

DB::statement('CREATE TABLE IF NOT EXISTS `core_learning_nodes` (`id` BIGINT)');
PHP);

        $result = $this->analyzer->migrationInventory($directory);

        unlink($migration);
        rmdir($directory);
        $this->assertSame(['core_learning_nodes'], $result['createdTables']);
    }

    public function test_duplicate_contract_entry_fails_validation(): void
    {
        [$root, $table] = $this->contractFixture();
        $contract = ['schema_version' => '1.0', 'database_family' => 'mysql', 'tables' => [$table, $table]];
        $codes = array_column($this->analyzer->validateContract($contract, $root), 'code');
        $this->removeContractFixture($root);
        $this->assertContains('contract.duplicate_table', $codes);
    }

    public function test_invalid_documentation_path_fails_validation(): void
    {
        [$root, $table] = $this->contractFixture();
        $table['documentation'] = 'database/domain/missing.md';
        $codes = array_column($this->analyzer->validateContract(['schema_version' => '1.0', 'database_family' => 'mysql', 'tables' => [$table]], $root), 'code');
        $this->removeContractFixture($root);
        $this->assertContains('contract.documentation', $codes);
    }

    private function contract(): array
    {
        return ['allowlist' => [], 'tables' => [[
            'name' => 'courses', 'implementation_status' => 'implemented',
            'columns' => [['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'unsigned' => true, 'auto_increment' => true, 'generated' => null]],
            'primary_key' => ['id'],
            'indexes' => [['name' => 'ignored_name', 'columns' => ['id'], 'unique' => true, 'type' => 'btree']],
            'foreign_keys' => [['name' => 'ignored_fk', 'columns' => ['id'], 'foreign_table' => 'parents', 'foreign_columns' => ['id'], 'on_update' => 'no action', 'on_delete' => 'restrict']],
            'checks' => [['name' => 'ignored_check', 'expression' => 'id > 0']],
            'triggers' => [['name' => 'ignored_trigger', 'timing' => 'before', 'event' => 'update', 'statement' => 'set new.id = old.id']],
            'views' => [],
        ]]];
    }

    private function actual(): array
    {
        $table = $this->contract()['tables'][0];
        foreach (['indexes', 'foreign_keys', 'checks'] as $kind) {
            unset($table[$kind][0]['name']);
        }

        return ['tables' => [$table]];
    }

    private function contractFixture(): array
    {
        $root = sys_get_temp_dir().'/lf-contract-'.uniqid();
        mkdir($root.'/database/domain', 0777, true);
        touch($root.'/database/domain/courses.md');
        $table = $this->contract()['tables'][0];
        $table['documentation'] = 'database/domain/courses.md';

        return [$root, $table];
    }

    private function removeContractFixture(string $root): void
    {
        unlink($root.'/database/domain/courses.md');
        rmdir($root.'/database/domain');
        rmdir($root.'/database');
        rmdir($root);
    }
}
