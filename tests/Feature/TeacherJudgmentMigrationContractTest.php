<?php

namespace Tests\Feature;

use Tests\TestCase;

class TeacherJudgmentMigrationContractTest extends TestCase
{
    private string $source;

    /** @var array<string, mixed> */
    private array $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = file_get_contents(database_path(
            'migrations/2026_08_15_010000_create_liveclass_teacher_judgments.php'
        ));

        $contract = json_decode(
            file_get_contents(base_path('docs/database/LF-SCHEMA-CONTRACT.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->table = collect($contract['tables'])
            ->firstWhere('name', 'core_liveclass_teacher_judgments');
    }

    public function test_contract_inventory_matches_the_authorized_table(): void
    {
        $this->assertSame('implemented', $this->table['implementation_status']);
        $this->assertCount(20, $this->table['columns']);
        $this->assertCount(5, $this->table['indexes']);
        $this->assertCount(10, $this->table['foreign_keys']);
        $this->assertCount(5, $this->table['checks']);
        $this->assertCount(3, $this->table['triggers']);
        $this->assertTrue($this->table['trigger_identity_required']);
    }

    public function test_migration_declares_every_named_physical_object(): void
    {
        foreach (range(1, 5) as $number) {
            $this->assertStringContainsString(sprintf('idx_ltj_%03d', $number), $this->source);
            $this->assertStringContainsString(sprintf('chk_ltj_%03d', $number), $this->source);
        }

        foreach (range(1, 10) as $number) {
            $this->assertStringContainsString(sprintf('fk_ltj_%03d', $number), $this->source);
        }

        foreach ($this->table['triggers'] as $trigger) {
            $this->assertStringContainsString($trigger['name'], $this->source);
            $this->assertStringContainsString(
                $this->normalizeSql($trigger['statement']),
                $this->normalizeSql($this->source)
            );
        }
    }

    public function test_correction_and_immutability_codes_are_stable(): void
    {
        $this->assertStringContainsString(
            'LF_TEACHER_JUDGMENT_CORRECTION_INVALID',
            $this->source
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->source, 'LF_TEACHER_JUDGMENT_IMMUTABLE')
        );
        $this->assertStringContainsString(
            'prior.submitted_at <= NEW.submitted_at',
            $this->source
        );
    }

    public function test_interrupted_ddl_fails_closed_and_rollback_preserves_lineage(): void
    {
        $this->assertStringContainsString('interrupted-DDL recovery runbook', $this->source);
        $this->assertStringContainsString('assertInstalledTriggerIdentity', $this->source);
        $this->assertStringContainsString('information_schema.STATISTICS', $this->source);
        $this->assertStringContainsString('information_schema.TRIGGERS', $this->source);
        $this->assertStringContainsString('trigger inventory drifted', $this->source);
        $this->assertStringContainsString(
            'Refusing to drop non-empty Teacher Judgment source table',
            $this->source
        );
        $this->assertStringContainsString(
            "->where('source_type', 'teacher_judgment')",
            $this->source
        );
        $this->assertStringContainsString(
            'Refusing to orphan Teacher Judgment Learning Evidence',
            $this->source
        );
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $sql)));
    }
}
