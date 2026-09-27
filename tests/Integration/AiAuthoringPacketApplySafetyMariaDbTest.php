<?php

namespace Tests\Integration;

use App\Support\Database\TriggerCreationPreflight;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Apply-safety hardening of the Step 7 packet migration after the independent
 * pre-apply review (H2, R1): a failure must be detected before the first DDL,
 * and the Course origin CHECK must never be absent between two statements.
 *
 * These tests really run the packet's down()/up(). MariaDB commits DDL, so no
 * test data is created before it, and every test rebuilds the packet as the
 * test connection's own account in `finally`.
 */
class AiAuthoringPacketApplySafetyMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_15_000100_create_ai_authoring_proposal_packet.php';

    private const PACKET = ['generation_requests', 'proposals', 'proposal_revisions', 'proposal_sources', 'proposal_reviews', 'proposal_applications'];

    private const ACCOUNT = 'lf_preflight_probe';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The packet migration only runs on MariaDB.');
        }
    }

    public function test_a_partial_previous_attempt_is_refused_before_any_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        try {
            // What a failure after the first CREATE TABLE leaves behind.
            DB::statement('CREATE TABLE ai_authoring_generation_requests (id BIGINT UNSIGNED PRIMARY KEY)');
            $before = $this->schemaShape();

            try {
                $migration->up();
                $this->fail('A partial packet must be refused.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('LF_AUTHORING_PACKET_PARTIAL_STATE', $e->getMessage());
                $this->assertStringContainsString('ai_authoring_generation_requests', $e->getMessage());
            }

            $this->assertSame($before, $this->schemaShape(), 'The refusal must not have issued any DDL.');
        } finally {
            Schema::dropIfExists('ai_authoring_generation_requests');
            $migration->up();
        }
        $this->assertPacketComplete();
    }

    public function test_a_missing_trigger_privilege_stops_before_any_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        $before = $this->schemaShape();
        try {
            $this->createAccount(withTrigger: false);
            $this->useAccount();

            try {
                $migration->up();
                $this->fail('A missing TRIGGER privilege must be refused.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('LF_MIGRATION_PREFLIGHT_TRIGGER_PRIVILEGE', $e->getMessage());
            }
        } finally {
            $this->useDefaultAccount();
            $this->dropAccount();
        }

        $this->assertSame($before, $this->schemaShape(), 'No table, column or trigger may be left behind.');
        $migration->up();
        $this->assertPacketComplete();
    }

    /**
     * Fail-closed limits of the metadata check: a direct schema grant passes, a
     * grant held only through a role is not visible and is refused.
     */
    public function test_only_a_direct_grant_satisfies_the_preflight(): void
    {
        try {
            $this->createAccount(withTrigger: true);
            TriggerCreationPreflight::assertCanCreateTriggers(DB::connection($this->useAccount()));
            $this->useDefaultAccount();

            DB::statement('REVOKE TRIGGER ON `'.DB::getDatabaseName().'`.* FROM '.$this->grantees()[0]);
            DB::statement('REVOKE TRIGGER ON `'.DB::getDatabaseName().'`.* FROM '.$this->grantees()[1]);
            DB::statement('CREATE ROLE IF NOT EXISTS lf_preflight_role');
            DB::statement('GRANT TRIGGER ON `'.DB::getDatabaseName().'`.* TO lf_preflight_role');
            foreach ($this->grantees() as $grantee) {
                DB::statement("GRANT lf_preflight_role TO $grantee");
                DB::statement("SET DEFAULT ROLE lf_preflight_role FOR $grantee");
            }
            try {
                TriggerCreationPreflight::assertCanCreateTriggers(DB::connection($this->useAccount()));
                $this->fail('A role-only grant must not count as proven.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('LF_MIGRATION_PREFLIGHT_TRIGGER_PRIVILEGE', $e->getMessage());
            }
        } finally {
            $this->useDefaultAccount();
            $this->dropAccount();
            DB::statement('DROP ROLE IF EXISTS lf_preflight_role');
        }
    }

    public function test_the_origin_check_is_swapped_in_one_statement_in_both_directions(): void
    {
        $migration = $this->migration();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            if (str_contains($query->sql, 'core_course_template_learning_mapping_intents')) {
                $statements[] = $query->sql;
            }
        });

        $migration->down();
        $this->assertSame(["`origin` = 'manual'"], $this->originChecks());
        $migration->up();

        $drops = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'DROP CONSTRAINT chk_cct_lmi_origin')));
        $this->assertCount(2, $drops, 'One swap on the way down, one on the way up.');
        foreach ($drops as $sql) {
            $this->assertStringContainsString('ADD CONSTRAINT chk_cct_lmi_origin', $sql, 'Origin must never be unconstrained between statements.');
        }
        $this->assertSame(["`origin` in ('manual','ai_proposal')"], $this->originChecks());
        $this->assertPacketComplete();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /** @return array<string,mixed> */
    private function schemaShape(): array
    {
        $db = DB::connection('mysql')->getDatabaseName();

        return [
            'tables' => array_column(DB::connection('mysql')->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'ai\\_authoring\\_%' ORDER BY 1", [$db]), 'TABLE_NAME'),
            'columns' => array_column(DB::connection('mysql')->select("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'core_course_template_learning_mapping_intents' ORDER BY 1", [$db]), 'COLUMN_NAME'),
            'checks' => array_column(DB::connection('mysql')->select("SELECT CONCAT(CONSTRAINT_NAME, '=', CHECK_CLAUSE) AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'core_course_template_learning_mapping_intents' ORDER BY 1", [$db]), 'c'),
            'triggers' => array_column(DB::connection('mysql')->select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY 1', [$db]), 'TRIGGER_NAME'),
        ];
    }

    /** @return array<int,string> */
    private function originChecks(): array
    {
        return array_column(DB::select("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = 'chk_cct_lmi_origin'", [DB::getDatabaseName()]), 'CHECK_CLAUSE');
    }

    private function assertPacketComplete(): void
    {
        foreach (self::PACKET as $suffix) {
            $this->assertTrue(Schema::hasTable('ai_authoring_'.$suffix));
        }
        $this->assertTrue(Schema::hasColumn('core_course_template_learning_mapping_intents', 'ai_context_review_id'));
        $this->assertSame(17, (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE LIKE 'ai\\_authoring\\_%'", [DB::getDatabaseName()])->n);
    }

    /** @return array{0:string,1:string} Both hosts: a local socket login is 'localhost', CI over TCP is '%'. */
    private function grantees(): array
    {
        return ["'".self::ACCOUNT."'@'localhost'", "'".self::ACCOUNT."'@'%'"];
    }

    private function createAccount(bool $withTrigger): void
    {
        $privileges = 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP'.($withTrigger ? ', TRIGGER' : '');
        foreach ($this->grantees() as $grantee) {
            DB::statement("CREATE USER IF NOT EXISTS $grantee IDENTIFIED BY 'lf-preflight-probe'");
            DB::statement("GRANT $privileges ON `".DB::getDatabaseName()."`.* TO $grantee");
        }
    }

    private function dropAccount(): void
    {
        foreach ($this->grantees() as $grantee) {
            DB::statement("DROP USER IF EXISTS $grantee");
        }
    }

    private function useAccount(): string
    {
        config(['database.connections.lf_preflight' => array_replace(config('database.connections.mysql'), [
            'username' => self::ACCOUNT, 'password' => 'lf-preflight-probe',
        ])]);
        DB::purge('lf_preflight');
        config(['database.default' => 'lf_preflight']);

        return 'lf_preflight';
    }

    private function useDefaultAccount(): void
    {
        DB::purge('lf_preflight');
        config(['database.default' => 'mysql']);
    }
}
