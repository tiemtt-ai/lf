<?php

namespace Tests\Integration;

use App\Services\AiKnowledgeIngestionService;
use App\Services\MediaReadService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * K3 acceptance criteria (ai_knowledge_chunks.md § Media role vocabulary
 * alignment) on a physical MariaDB: the migrated CHECK, what it accepts and
 * refuses, snapshot fidelity, revision atomicity, preflight refusals, the
 * up/down/up round trip, a writer racing down(), and a lock timeout.
 *
 * MariaDB commits DDL. Tests that run DDL commit the RefreshDatabase
 * transaction first, remove their own rows in `finally`, restore the wide
 * CHECK, and reopen the transaction for the trait to roll back.
 */
class AiKnowledgeSourceRoleMigrationMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php';

    private const NARROW = ['paragraph', 'heading', 'list', 'table', 'figure', 'caption', 'header', 'footer', 'other'];

    private const NEW_ROLES = ['image', 'chart', 'diagram', 'geometry', 'formula', 'note'];

    /**
     * The migration's own order. Drift compares the stored expression as a
     * string, so a restore in any other order would leave the schema drifted.
     */
    private const WIDE = [
        'paragraph', 'heading', 'list', 'table', 'figure', 'image', 'chart', 'diagram',
        'geometry', 'formula', 'caption', 'note', 'header', 'footer', 'other',
    ];

    /** @var list<int> */
    private array $customers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK enforcement is only provable on MariaDB.');
        }
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_the_migrated_schema_carries_exactly_the_contract_check(): void
    {
        $this->assertSame($this->contractExpression(), $this->clause(), 'Physical CHECK must equal the approved contract expression.');
        $this->assertEqualsCanonicalizing([...self::NARROW, ...self::NEW_ROLES], $this->roleSet());
        $this->assertSame(1, (int) DB::selectOne('SELECT @@check_constraint_checks AS on_')->on_);
    }

    public function test_every_approved_role_and_null_is_written_and_anything_else_refused(): void
    {
        [$customerId, $sourceId] = $this->source('accept');
        $sequence = 10;
        foreach ([...self::NARROW, ...self::NEW_ROLES, null] as $role) {
            $id = $this->chunk($customerId, $sourceId, $sequence++, $role);
            $this->assertSame($role, DB::table('ai_knowledge_chunks')->where('id', $id)->value('source_role'));
        }
        $target = $this->chunk($customerId, $sourceId, $sequence++, 'paragraph');
        foreach ([...self::NEW_ROLES, null, 'figure'] as $role) {
            DB::table('ai_knowledge_chunks')->where('id', $target)->update(['source_role' => $role]);
            $this->assertSame($role, DB::table('ai_knowledge_chunks')->where('id', $target)->value('source_role'));
        }

        $this->assertCheckRefuses(fn () => $this->chunk($customerId, $sourceId, $sequence++, 'bogus'));
        $this->assertCheckRefuses(fn () => DB::table('ai_knowledge_chunks')->where('id', $target)->update(['source_role' => 'figures']));

        // Case semantics come from the column collation and are unchanged by K3:
        // an upper-case new role behaves exactly like an upper-case old role.
        $collation = DB::selectOne("SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_knowledge_chunks' AND COLUMN_NAME = 'source_role'")->c;
        $this->assertNotEmpty($collation);
        $this->assertSame($this->accepts($customerId, $sourceId, 900, 'PARAGRAPH'), $this->accepts($customerId, $sourceId, 901, 'IMAGE'));
    }

    public function test_a_new_role_revision_is_snapshotted_verbatim_and_a_retry_changes_nothing(): void
    {
        [$customerId, $actor, $mediaId] = $this->tenant('verbatim');
        $roles = [...self::NEW_ROLES, 'figure', 'paragraph'];
        $units = [];
        foreach ($roles as $i => $role) {
            $units[] = $this->unit($mediaId, '1#'.($i + 1), 'Vùng '.$role, $role, $i + 1);
        }
        $service = $this->serviceReturning($units, 2);

        $first = $service->ingestMedia($actor, 'course_activity', 7, 'document', 'region', 'vi', 'K3');
        $rows = fn () => DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->orderBy('sequence_no')
            ->get(['chunk_uuid', 'content_hash', 'content', 'source_role', 'locator_start', 'reading_order', 'status'])->toJson();
        $before = $rows();
        $again = $service->ingestMedia($actor, 'course_activity', 7, 'document', 'region', 'vi', 'K3');

        $this->assertSame($roles, DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->orderBy('sequence_no')->pluck('source_role')->all());
        $this->assertSame(count($roles), $first['chunk_count']);
        $this->assertSame($first['source_id'], $again['source_id']);
        $this->assertSame($before, $rows(), 'Retry must keep uuid, hash, content, role and status.');
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('id', $first['source_id'])->value('status'));
    }

    public function test_an_unknown_role_rolls_back_the_whole_new_revision_and_keeps_the_old_one(): void
    {
        [$customerId, $actor, $mediaId] = $this->tenant('atomic');
        $old = $this->serviceReturning([$this->unit($mediaId, '1#1', 'Cũ', 'paragraph', 1)])
            ->ingestMedia($actor, 'course_activity', 8, 'document', 'region', 'vi', 'K3');
        $oldChunks = DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $old['source_id'])->get()->toJson();

        $fingerprint = ['source_fingerprint' => str_repeat('b', 64)];
        $next = [
            $this->unit($mediaId, '1#1', 'Mới', 'paragraph', 1, $fingerprint),
            $this->unit($mediaId, '1#2', 'Hình', 'image', 2, $fingerprint),
            $this->unit($mediaId, '1#3', 'Lạ', 'bogus', 3, $fingerprint),
        ];
        try {
            $this->serviceReturning($next)->ingestMedia($actor, 'course_activity', 8, 'document', 'region', 'vi', 'K3');
            $this->fail('A role outside the vocabulary must refuse the revision.');
        } catch (QueryException $e) {
            $this->assertSame(['23000', 4025], array_slice($e->errorInfo, 0, 2));
        }

        $this->assertSame([$old['source_id']], DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('id', $old['source_id'])->value('status'));
        $this->assertSame($oldChunks, DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $old['source_id'])->get()->toJson());
    }

    /** DDL completed but the ledger never recorded it: up() stops, it does not re-run. */
    public function test_up_refuses_before_any_ddl_when_the_check_is_already_wide(): void
    {
        $before = $this->clause();

        $this->assertRefusedBeforeDdl(fn () => $this->migration()->up(), 'up()', '15 roles');
        $this->assertSame($before, $this->clause());
    }

    #[DataProvider('statuses')]
    public function test_down_refuses_while_any_tenant_holds_a_new_role_in_any_status(string $status): void
    {
        [$customerId, $sourceId] = $this->source('down-'.$status);
        $this->chunk($customerId, $sourceId, 10, 'paragraph');
        $this->chunk($customerId, $sourceId, 11, 'geometry', $status);
        $before = $this->clause();

        try {
            $this->migration()->down();
            $this->fail('down() must refuse while a row carries a new role.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused: 1 ai_knowledge_chunks row(s)', $e->getMessage());
        }
        $this->assertSame($before, $this->clause());
        $this->assertSame(3, DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $sourceId)->count(), 'Evidence is never removed.');
    }

    /** @return array<string,array{string}> */
    public static function statuses(): array
    {
        return array_combine(
            ['active', 'stale', 'archived', 'failed', 'deletion_pending', 'deleted'],
            array_map(fn ($s) => [$s], ['active', 'stale', 'archived', 'failed', 'deletion_pending', 'deleted']),
        );
    }

    /** Unknown constraint states stop up() before DDL; the state is left as found. */
    #[DataProvider('unexpectedStates')]
    public function test_up_refuses_an_unexpected_constraint_state(?string $check, string $reason): void
    {
        $this->withDdl(function () use ($check, $reason): void {
            DB::statement('ALTER TABLE ai_knowledge_chunks DROP CONSTRAINT chk_akc_source_role'
                .($check === null ? '' : ', ADD CONSTRAINT chk_akc_source_role CHECK ('.$check.')'));
            $before = $this->clauses();

            $this->assertRefusedBeforeDdl(fn () => $this->migration()->up(), 'up()', $reason);
            $this->assertSame($before, $this->clauses());
        });
    }

    /** @return array<string,array{?string,string}> */
    public static function unexpectedStates(): array
    {
        $narrow = "'paragraph','heading','list','table','figure','caption','header','footer','other'";

        return [
            'constraint missing' => [null, '0 matching constraints'],
            'extra OR branch' => ["source_role IS NULL OR source_role IN ($narrow) OR 1=1", 'unrecognized expression'],
            'NULL branch missing' => ["source_role IN ($narrow)", 'unrecognized expression'],
            'NOT NULL instead of NULL' => ["source_role IS NOT NULL OR source_role IN ($narrow)", 'unrecognized expression'],
            'one role short' => ["source_role IS NULL OR source_role IN ('paragraph','heading','list','table','figure','caption','header','footer')", '8 roles'],
            'role duplicated' => ["source_role IS NULL OR source_role IN ($narrow,'other')", 'unrecognized expression'],
            // K3-R8: a literal is never rewritten into a valid role.
            'backtick inside a literal' => ["source_role IS NULL OR source_role IN ('para`graph','heading','list','table','figure','caption','header','footer','other')", 'unrecognized expression'],
            'upper-case literal' => ["source_role IS NULL OR source_role IN ('PARAGRAPH','heading','list','table','figure','caption','header','footer','other')", 'unrecognized expression'],
        ];
    }

    /** down() reads the constraint with the same parser; a foreign wide CHECK is refused too. */
    #[DataProvider('unexpectedWideStates')]
    public function test_down_refuses_an_unexpected_constraint_state(string $check): void
    {
        $this->withDdl(function () use ($check): void {
            DB::statement('ALTER TABLE ai_knowledge_chunks DROP CONSTRAINT chk_akc_source_role, ADD CONSTRAINT chk_akc_source_role CHECK ('.$check.')');
            $before = $this->clauses();

            $this->assertRefusedBeforeDdl(fn () => $this->migration()->down(), 'down()', 'unrecognized expression');
            $this->assertSame($before, $this->clauses());
        });
    }

    /** @return array<string,array{string}> */
    public static function unexpectedWideStates(): array
    {
        $rest = "'heading','list','table','figure','image','chart','diagram','geometry','formula','caption','note','header','footer','other'";

        return [
            'backtick inside a literal' => ["source_role IS NULL OR source_role IN ('para`graph',$rest)"],
            'extra OR branch' => ["source_role IS NULL OR source_role IN ('paragraph',$rest) OR 1=1"],
        ];
    }

    public function test_up_refuses_while_check_enforcement_is_off(): void
    {
        $this->withDdl(function (): void {
            $this->swapTo(self::NARROW);
            DB::statement('SET SESSION check_constraint_checks = OFF');
            try {
                $this->assertRefusedBeforeDdl(fn () => $this->migration()->up(), 'up()', 'check_constraint_checks is off');
            } finally {
                DB::statement('SET SESSION check_constraint_checks = ON');
            }
            $this->assertEqualsCanonicalizing(self::NARROW, $this->roleSet());
        });
    }

    public function test_up_down_up_keeps_every_row_and_the_narrow_check_is_enforced_in_between(): void
    {
        $this->withDdl(function (): void {
            [$customerId, $sourceId] = $this->source('round-trip');
            $this->chunk($customerId, $sourceId, 10, 'paragraph');
            $this->chunk($customerId, $sourceId, 11, 'figure', 'archived');
            $this->chunk($customerId, $sourceId, 12, null);
            $rows = fn () => DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->orderBy('id')->get()->toJson();
            $before = $rows();

            $this->migration()->down();
            $this->assertEqualsCanonicalizing(self::NARROW, $this->roleSet());
            $this->assertCheckRefuses(fn () => $this->chunk($customerId, $sourceId, 13, 'image'));
            $this->assertSame($before, $rows());

            $this->migration()->up();
            $this->assertSame($this->contractExpression(), $this->clause(), 'up() must restore the contract expression exactly.');
            $this->assertSame($before, $rows());
            $this->chunk($customerId, $sourceId, 14, 'image');
        });
    }

    /** A writer that slips in after down()'s count makes the narrowing ALTER fail on validation. */
    public function test_a_row_written_after_the_count_fails_the_narrowing_and_the_wide_check_stays(): void
    {
        $this->withDdl(function (): void {
            [$customerId, $sourceId] = $this->source('race');
            $primary = DB::getDefaultConnection();
            config(['database.connections.race_second' => DB::connection()->getConfig()]);
            $armed = true;
            DB::connection()->beforeExecuting(function (string $query) use (&$armed, $customerId, $sourceId): void {
                if ($armed && str_starts_with($query, 'ALTER TABLE ai_knowledge_chunks DROP CONSTRAINT')) {
                    $armed = false;
                    $this->chunk($customerId, $sourceId, 10, 'note', connection: 'race_second');
                }
            });
            try {
                $this->migration()->down();
                $this->fail('The narrowing ALTER must fail on the row that raced in.');
            } catch (QueryException $e) {
                $this->assertSame(4025, $e->errorInfo[1] ?? null);
            } finally {
                $armed = false;
                DB::purge('race_second');
                DB::setDefaultConnection($primary);
            }
            $this->assertEqualsCanonicalizing([...self::NARROW, ...self::NEW_ROLES], $this->roleSet(), 'The table must keep the wide CHECK.');
            $this->assertSame(1, DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $sourceId)->where('source_role', 'note')->count());
        });
    }

    /** A metadata lock held elsewhere times the ALTER out; the CHECK is untouched. */
    public function test_a_lock_timeout_leaves_the_check_untouched(): void
    {
        $this->withDdl(function (): void {
            config(['database.connections.race_second' => DB::connection()->getConfig()]);
            $holder = DB::connection('race_second');
            $holder->beginTransaction();
            $holder->table('ai_knowledge_chunks')->count();
            DB::statement('SET SESSION lock_wait_timeout = 1');
            try {
                $this->migration()->down();
                $this->fail('The ALTER must time out on the held metadata lock.');
            } catch (QueryException $e) {
                $this->assertSame(1205, $e->errorInfo[1] ?? null);
            } finally {
                $holder->rollBack();
                DB::purge('race_second');
                DB::statement('SET SESSION lock_wait_timeout = DEFAULT');
            }
            $this->assertEqualsCanonicalizing([...self::NARROW, ...self::NEW_ROLES], $this->roleSet());
        });
    }

    // ------------------------------------------------------------------ helpers

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    private function contractExpression(): string
    {
        $contract = json_decode((string) file_get_contents(base_path('docs/database/LF-SCHEMA-CONTRACT.json')), true);

        return collect(collect($contract['tables'])->firstWhere('name', 'ai_knowledge_chunks')['checks'])
            ->pluck('expression')->first(fn (string $expression): bool => str_starts_with($expression, '`source_role`'));
    }

    /** Runs DDL outside the RefreshDatabase transaction and restores everything it touched. */
    private function withDdl(\Closure $body): void
    {
        DB::commit();
        try {
            $body();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->swapTo(self::WIDE);
            $this->assertSame($this->contractExpression(), $this->clause(), 'Restore must leave the contract expression byte for byte.');
            foreach ($this->customers as $customerId) {
                DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->delete();
                DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->delete();
                DB::table('media_files')->where('customer_id', $customerId)->delete();
                DB::table('users')->where('customer_id', $customerId)->delete();
                DB::table('saas_customers')->where('id', $customerId)->delete();
            }
            $this->customers = [];
            DB::beginTransaction();
        }
    }

    /** Test-only restore, idempotent: the migration's own swap is what is under test. */
    private function swapTo(array $roles): void
    {
        DB::statement('ALTER TABLE ai_knowledge_chunks DROP CONSTRAINT IF EXISTS chk_akc_source_role,'
            .' ADD CONSTRAINT chk_akc_source_role CHECK (source_role IS NULL OR source_role IN ('
            .implode(',', array_map(fn ($r) => "'$r'", $roles)).'))');
    }

    /** @return list<string> */
    private function clauses(): array
    {
        return array_map(fn ($row) => $row->CHECK_CLAUSE, DB::select(
            "SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc
             ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'ai_knowledge_chunks'
             AND tc.CONSTRAINT_TYPE = 'CHECK' AND tc.CONSTRAINT_NAME = 'chk_akc_source_role'"
        ));
    }

    private function clause(): string
    {
        $clauses = $this->clauses();
        $this->assertCount(1, $clauses);

        return $clauses[0];
    }

    /** @return list<string> */
    private function roleSet(): array
    {
        $this->assertSame(1, preg_match('/in \(([^()]*)\)$/', $this->clause(), $match));

        return array_map(fn ($literal) => trim($literal, "'"), explode(',', $match[1]));
    }

    private function assertRefusedBeforeDdl(\Closure $run, string $direction, string $reason): void
    {
        try {
            $run();
            $this->fail($direction.' must refuse this state.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(QueryException::class, $e);
            $this->assertStringContainsString('not in the state '.$direction.' replaces ('.$reason, $e->getMessage());
            $this->assertStringContainsString('No DDL was issued', $e->getMessage());
        }
    }

    private function assertCheckRefuses(\Closure $write): void
    {
        try {
            $write();
            $this->fail('The CHECK must refuse this value.');
        } catch (QueryException $e) {
            $this->assertSame(['23000', 4025], array_slice($e->errorInfo, 0, 2));
        }
    }

    private function accepts(int $customerId, int $sourceId, int $sequence, string $role): bool
    {
        try {
            DB::transaction(fn () => $this->chunk($customerId, $sourceId, $sequence, $role));

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /** @return array{int,int} */
    private function source(string $slug): array
    {
        [$customerId, $actor, $mediaId] = $this->tenant($slug);
        $source = $this->serviceReturning([$this->unit($mediaId, '1#1', 'Nguồn', 'paragraph', 1)])
            ->ingestMedia($actor, 'course_activity', 5, 'document', 'region', 'vi', 'K3');

        return [$customerId, $source['source_id']];
    }

    /** Hand-written rows start at sequence 10: source() already ingested sequence 1. */
    private function chunk(int $customerId, int $sourceId, int $sequence, ?string $role, string $status = 'active', ?string $connection = null): int
    {
        return DB::connection($connection)->table('ai_knowledge_chunks')->insertGetId([
            'customer_id' => $customerId,
            'knowledge_source_id' => $sourceId,
            'chunk_uuid' => sprintf('00000000-0000-4000-8000-%012d', $sourceId * 1000 + $sequence),
            'sequence_no' => $sequence,
            'content' => $status === 'deleted' ? null : 'K3 '.$sequence,
            'content_hash' => 'sha256:k3-'.$sequence,
            'part_index' => 1,
            'char_start' => 0,
            'char_end' => 4,
            'locator_type' => 'region',
            'locator_start' => '9#'.$sequence,
            'locator_end' => '9#'.$sequence,
            'source_role' => $role,
            'status' => $status,
            'deletion_requested_at' => in_array($status, ['deletion_pending', 'deleted'], true) ? now() : null,
            'deleted_at' => $status === 'deleted' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function serviceReturning(array $units, int $calls = 1): AiKnowledgeIngestionService
    {
        $reader = Mockery::mock(MediaReadService::class);
        $reader->shouldReceive('read')->times($calls)->andReturn($units);

        return new AiKnowledgeIngestionService($reader);
    }

    /** @return array<string,mixed> */
    private function unit(int $mediaId, string $locator, string $text, string $role, int $order, array $overrides = []): array
    {
        return array_replace_recursive([
            'media_file_id' => $mediaId,
            'source_fingerprint' => str_repeat('a', 64),
            'processing_version' => 'docling-v1',
            'content_type' => 'region',
            'locale' => 'vi',
            'language_profile' => ['vi'],
            'locator' => ['type' => 'region', 'value' => $locator],
            'text' => $text,
            'status' => 'ready',
            'structure' => [
                'role' => $role,
                'reading_order' => $order,
                'text_quality' => 'normal',
                'languages' => [],
                'bbox' => null,
            ],
        ], $overrides);
    }

    /** @return array{int,int,int} */
    private function tenant(string $slug): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "K3 {$slug}", 'slug' => "k3-{$slug}", 'subdomain' => "k3-{$slug}",
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customers[] = $customerId;
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => "K3 {$slug}", 'email' => "k3-{$slug}@example.test",
            'password' => bcrypt('password'), 'role' => 'customer_admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'uploaded_by' => $userId, 'file_type' => 'document',
            'mime_type' => 'application/pdf', 'original_name' => "{$slug}.pdf", 'display_name' => $slug,
            'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'test-media',
            'storage_key' => "k3/{$slug}.pdf", 'checksum' => 'sha256:k3-'.$slug, 'file_size_bytes' => 1,
            'visibility' => 'private', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $customerId]);

        return [$customerId, $userId, $mediaId];
    }
}
