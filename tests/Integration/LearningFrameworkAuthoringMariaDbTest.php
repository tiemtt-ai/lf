<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\LearningFrameworkAuthoringService;
use App\Services\TeacherJudgmentService;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningFrameworkAuthoringMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Learning Framework authoring verification requires MariaDB.');
        }

        TenantContext::set(null);
        // The suite pins its own clock. The fixture Cohort runs 2026-08-01 to
        // 2026-08-31, and the Cohort submission boundary compares submitted_at
        // against that end date, so on real wall-clock every happy path in this
        // file would start failing on 2026-09-01 — and the future-occurrence
        // test a day earlier. A suite that goes red for reasons unrelated to any
        // change invites the cheapest possible repair, and the cheapest repair
        // here is loosening either the fixture or the rule that bounds writes
        // into an immutable table. Pinning removes the temptation.
        Carbon::setTestNow('2026-08-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::set(null);
        parent::tearDown();
    }

    /**
     * The point of this gate: a Node authored here must be judgeable. Asserting
     * that rows were inserted would prove the service writes, not that it
     * unblocks Teacher Judgment.
     */
    public function test_authored_framework_produces_a_node_a_teacher_can_judge(): void
    {
        $fixture = $this->courseFixture('e2e');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('e2e'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-e2e', 'title' => 'Framework e2e',
        ]);
        $definition = $authoring->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'node-e2e',
            'node_type' => 'competency', 'canonical_name' => 'Speaking accuracy',
        ]);
        $node = $authoring->createNode($fixture['admin_id'], [
            'framework_version_id' => $version->id, 'node_definition_id' => $definition->id,
        ]);

        $this->assertSame('draft_snapshot', $version->status);
        $this->assertSame(1, (int) $version->version_number);
        $this->assertSame('node-e2e', $node->code_snapshot);
        $this->assertSame(1, (int) $node->sequence);

        $published = $authoring->publishVersion($fixture['admin_id'], $version->id);
        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->published_at);

        $judgment = app(TeacherJudgmentService::class)->submit($fixture['teacher_id'], [
            'submission_uuid' => '22222222-2222-4222-8222-222222222222',
            'cohort_id' => $fixture['cohort_id'],
            'cohort_teacher_assignment_id' => $fixture['assignment_id'],
            'cohort_student_membership_id' => $fixture['membership_id'],
            'enrollment_id' => $fixture['enrollment_id'],
            'student_id' => $fixture['student_id'],
            'basis_framework_version_id' => $published->id,
            'learning_node_id' => $node->id,
            'mastery_level_key' => 'novice',
            'mastery_score' => 0.5,
            'reason' => 'Observed during the assigned teaching window.',
            'occurred_at' => '2026-08-15T09:00:00.000000+07:00',
        ]);

        $this->assertFalse($judgment->replayed);

        $stored = DB::table('core_liveclass_teacher_judgments')->find($judgment->judgment_id);
        $this->assertSame((int) $node->id, (int) $stored->learning_node_id);
        $this->assertSame((int) $published->id, (int) $stored->basis_framework_version_id);

        // The whole chain must exist against the authored Node, not just the
        // source row: that is what "a teacher can judge it" means.
        $this->assertSame((int) $node->id, (int) DB::table('core_learning_evidence')
            ->find($judgment->evidence_id)->learning_node_id);
        $this->assertSame((int) $definition->id, (int) DB::table('core_learning_mastery_calculations')
            ->find($judgment->calculation_id)->node_definition_id);
        $this->assertSame(1, DB::table('core_learning_calculation_evidence')
            ->where('mastery_calculation_id', $judgment->calculation_id)->count());
        $this->assertSame((int) $judgment->calculation_id, (int) DB::table('core_learning_mastery_profiles')
            ->find($judgment->profile_id)->current_calculation_id);
    }

    /**
     * core_learning_nodes has no before-insert trigger, so the engine accepts a
     * Node added to an already published version. Only the service stops it.
     */
    public function test_node_cannot_be_added_after_the_version_is_published(): void
    {
        $fixture = $this->courseFixture('frozen');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('frozen'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-frozen', 'title' => 'Framework frozen',
        ]);
        $late = $authoring->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'node-late',
            'node_type' => 'objective', 'canonical_name' => 'Added too late',
        ]);
        $authoring->publishVersion($fixture['admin_id'], $version->id);

        try {
            $authoring->createNode($fixture['admin_id'], [
                'framework_version_id' => $version->id, 'node_definition_id' => $late->id,
            ]);
            $this->fail('A published version must not accept a new Node.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_learning_nodes')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    public function test_publish_is_one_way_and_rejects_a_non_draft_version(): void
    {
        $fixture = $this->courseFixture('oneway');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('oneway'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-oneway', 'title' => 'Framework oneway',
        ]);
        $authoring->publishVersion($fixture['admin_id'], $version->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT');
        $authoring->publishVersion($fixture['admin_id'], $version->id);
    }

    public function test_invalid_scale_fails_as_a_domain_error_before_reaching_the_trigger(): void
    {
        $fixture = $this->courseFixture('scale');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        foreach ([
            'single level' => ['levels' => [['key' => 'only', 'threshold' => 0]]],
            'unordered thresholds' => ['levels' => [
                ['key' => 'high', 'threshold' => 0.8], ['key' => 'low', 'threshold' => 0.2],
            ]],
            'duplicate keys' => ['levels' => [
                ['key' => 'same', 'threshold' => 0], ['key' => 'same', 'threshold' => 0.5],
            ]],
        ] as $case => $scale) {
            try {
                $authoring->createFramework($fixture['admin_id'], [
                    'code' => "bad-{$fixture['customer_id']}", 'name' => 'Bad scale',
                    'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
                    'mastery_scale' => $scale,
                ]);
                $this->fail("{$case} must be rejected.");
            } catch (DomainException $exception) {
                $this->assertSame('LF_FRAMEWORK_AUTHORING_SCALE_INVALID', $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('core_learning_frameworks')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    public function test_authoring_is_tenant_scoped_and_requires_a_tenant_context(): void
    {
        $owner = $this->courseFixture('owner');
        $other = $this->courseFixture('other');

        TenantContext::set((object) ['id' => $owner['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);
        $framework = $authoring->createFramework($owner['admin_id'], $this->frameworkCommand('owner'));

        TenantContext::set((object) ['id' => $other['customer_id']]);
        try {
            $authoring->createDraftVersion($other['admin_id'], [
                'framework_id' => $framework->id, 'version_code' => 'v1-steal', 'title' => 'Cross tenant',
            ]);
            $this->fail('A tenant must not author into another tenant Framework.');
        } catch (RecordsNotFoundException) {
            $this->addToAssertionCount(1);
        }

        TenantContext::set((object) ['id' => $other['customer_id']]);
        try {
            $authoring->createFramework($owner['admin_id'], $this->frameworkCommand('actor'));
            $this->fail('An actor outside the tenant must be denied.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_ACTOR_DENIED', $exception->getMessage());
        }

        TenantContext::set(null);
        $this->expectException(AuthorizationException::class);
        $authoring->createFramework($owner['admin_id'], $this->frameworkCommand('no-tenant'));
    }

    /**
     * Owner decision N1. Publishing defines the measure the whole tenant is
     * judged against and is irreversible, so it is curriculum authority rather
     * than teaching. A teacher publishing would change the basis for another
     * teacher's judgments.
     */
    public function test_only_customer_admin_may_author_or_publish(): void
    {
        $fixture = $this->courseFixture('authority');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        foreach ([$fixture['teacher_id'], $fixture['student_id']] as $actor) {
            try {
                $authoring->createFramework($actor, $this->frameworkCommand('authority'));
                $this->fail('Only customer_admin may author a Framework.');
            } catch (DomainException $exception) {
                $this->assertSame('LF_FRAMEWORK_AUTHORING_ACTOR_DENIED', $exception->getMessage());
            }
        }

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('authority'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-authority', 'title' => 'Authority',
        ]);

        try {
            $authoring->publishVersion($fixture['teacher_id'], $version->id);
            $this->fail('Only customer_admin may publish a Framework Version.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_ACTOR_DENIED', $exception->getMessage());
        }

        $this->assertSame('draft_snapshot', DB::table('core_learning_framework_versions')
            ->where('id', $version->id)->value('status'));
    }

    /**
     * N2. The Framework guard reached createDraftVersion and createDefinition
     * but not createNode or publishVersion, and no trigger checks Framework
     * status on publish — trg_lrn_fw_versions_bu_immutable validates only the
     * version's own lifecycle. Archiving a Framework therefore did not stop its
     * draft version becoming a permanently valid judgment basis.
     */
    public function test_an_archived_framework_blocks_node_creation_and_publish(): void
    {
        $fixture = $this->courseFixture('archived');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('archived'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-archived', 'title' => 'Archived',
        ]);
        $definition = $authoring->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'node-archived',
            'node_type' => 'concept', 'canonical_name' => 'Archived node',
        ]);

        DB::table('core_learning_frameworks')->where('id', $framework->id)->update([
            'status' => 'archived', 'archived_at' => now(), 'archived_by' => $fixture['admin_id'],
        ]);

        foreach ([
            fn () => $authoring->createNode($fixture['admin_id'], [
                'framework_version_id' => $version->id, 'node_definition_id' => $definition->id,
            ]),
            fn () => $authoring->publishVersion($fixture['admin_id'], $version->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An archived Framework must accept neither Nodes nor a publish.');
            } catch (DomainException $exception) {
                $this->assertSame('LF_FRAMEWORK_AUTHORING_FRAMEWORK_ARCHIVED', $exception->getMessage());
            }
        }

        $this->assertSame('draft_snapshot', DB::table('core_learning_framework_versions')
            ->where('id', $version->id)->value('status'));
        $this->assertSame(0, DB::table('core_learning_nodes')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    /**
     * N3. Authoring and judging must share one value domain. A judgment score is
     * bounded to [0, 1] by the service and by chk_ltj_001, so a scale outside
     * that range was accepted at authoring and then failed every scored
     * judgment — a defect surfacing in the judgment while living in the
     * Framework. A lowest threshold above zero has the same shape: a score below
     * it selects no level at all.
     */
    public function test_mastery_scale_must_share_the_judgment_value_domain(): void
    {
        $fixture = $this->courseFixture('domain');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        foreach ([
            'percentage scale' => [['key' => 'low', 'threshold' => 0], ['key' => 'high', 'threshold' => 80]],
            'negative threshold' => [['key' => 'low', 'threshold' => -1], ['key' => 'high', 'threshold' => 0.8]],
            'lowest above zero' => [['key' => 'low', 'threshold' => 0.2], ['key' => 'high', 'threshold' => 0.8]],
        ] as $case => $levels) {
            try {
                $authoring->createFramework($fixture['admin_id'], [
                    'code' => 'domain-'.$fixture['customer_id'], 'name' => 'Domain',
                    'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
                    'mastery_scale' => ['levels' => $levels],
                ]);
                $this->fail("{$case} must be rejected.");
            } catch (DomainException $exception) {
                $this->assertSame('LF_FRAMEWORK_AUTHORING_SCALE_INVALID', $exception->getMessage(), $case);
            }
        }

        $this->assertSame(0, DB::table('core_learning_frameworks')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    /**
     * Shape rules reject before anything is written. Both codes were unreachable
     * by the suite: the same set-difference that closed the judgment side had
     * not been run against this service.
     */
    public function test_command_shape_is_rejected_before_any_write(): void
    {
        $fixture = $this->courseFixture('shape');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        try {
            $authoring->createDefinition($fixture['admin_id'], [
                'framework_id' => 1, 'code' => 'shape', 'node_type' => 'skill',
                'canonical_name' => 'Unsupported type',
            ]);
            $this->fail('An unsupported node_type must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_NODE_TYPE_INVALID', $exception->getMessage());
        }

        foreach ([
            'LF_FRAMEWORK_AUTHORING_FIELD_INVALID:code' => ['code' => '   '],
            'LF_FRAMEWORK_AUTHORING_FIELD_INVALID:name' => ['name' => str_repeat('n', 256)],
        ] as $code => $override) {
            try {
                $authoring->createFramework(
                    $fixture['admin_id'],
                    array_merge($this->frameworkCommand('shape'), $override)
                );
                $this->fail("{$code} must be raised.");
            } catch (DomainException $exception) {
                $this->assertSame($code, $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('core_learning_frameworks')
            ->where('customer_id', $fixture['customer_id'])->count());
        $this->assertSame(0, DB::table('core_learning_node_definitions')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    /**
     * Two cross-row rules in createNode, both tenant-safe on their own and both
     * invisible to any foreign key: a Definition belonging to another Framework,
     * and a Definition that is no longer active. Each is asserted by its exact
     * code so neither can pass on the other's rejection.
     */
    public function test_node_creation_rejects_a_mismatched_or_inactive_definition(): void
    {
        $fixture = $this->courseFixture('nodeguard');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $owning = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('nodeguard-owning'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $owning->id, 'version_code' => 'v1-nodeguard', 'title' => 'Node guard',
        ]);

        $foreign = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('nodeguard-foreign'));
        $foreignDefinition = $authoring->createDefinition($fixture['admin_id'], [
            'framework_id' => $foreign->id, 'code' => 'foreign-node',
            'node_type' => 'concept', 'canonical_name' => 'Foreign node',
        ]);

        try {
            $authoring->createNode($fixture['admin_id'], [
                'framework_version_id' => $version->id,
                'node_definition_id' => $foreignDefinition->id,
            ]);
            $this->fail('A Definition from another Framework must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_FRAMEWORK_MISMATCH', $exception->getMessage());
        }

        $ownDefinition = $authoring->createDefinition($fixture['admin_id'], [
            'framework_id' => $owning->id, 'code' => 'own-node',
            'node_type' => 'concept', 'canonical_name' => 'Own node',
        ]);
        DB::table('core_learning_node_definitions')->where('id', $ownDefinition->id)
            ->update(['status' => 'archived']);

        try {
            $authoring->createNode($fixture['admin_id'], [
                'framework_version_id' => $version->id,
                'node_definition_id' => $ownDefinition->id,
            ]);
            $this->fail('An archived Definition must not become a versioned Node.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_DEFINITION_INACTIVE', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_learning_nodes')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    /**
     * Every call site passes a class constant, so this branch cannot be reached
     * through the public API and is exercised directly. It exists because the
     * capability is a parameter: the day author and publish separate, a typo at
     * a call site must fail closed rather than resolve to whichever check is
     * cheaper. The valid-capability case is asserted too, so a guard that
     * rejected everything would not pass this test.
     */
    public function test_an_unknown_capability_is_rejected_by_the_actor_guard(): void
    {
        $fixture = $this->courseFixture('capability');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $service = app(LearningFrameworkAuthoringService::class);
        $method = new \ReflectionMethod($service, 'assertActor');

        try {
            $method->invoke($service, $fixture['customer_id'], $fixture['admin_id'], 'delete');
            $this->fail('An unknown capability must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_CAPABILITY_UNKNOWN', $exception->getMessage());
        }

        $method->invoke($service, $fixture['customer_id'], $fixture['admin_id'], 'author');
        $method->invoke($service, $fixture['customer_id'], $fixture['admin_id'], 'publish');
        $this->addToAssertionCount(2);
    }

    public function test_manual_update_methods_preserve_draft_snapshots_and_lock_published_identity(): void
    {
        $fixture = $this->courseFixture('updates');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $service = app(LearningFrameworkAuthoringService::class);
        $framework = $service->createFramework($fixture['admin_id'], $this->frameworkCommand('updates'));
        $version = $service->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'Draft',
        ]);
        $definition = $service->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'stable', 'node_type' => 'concept',
            'canonical_name' => 'Original',
        ]);
        $node = $service->createNode($fixture['admin_id'], [
            'framework_version_id' => $version->id, 'node_definition_id' => $definition->id,
        ]);

        $service->updateFramework($fixture['admin_id'], $framework->id, array_merge(
            $this->frameworkCommand('updates'), ['name' => 'Updated Framework']
        ));
        try {
            $service->updateDraftVersion($fixture['admin_id'], $version->id, [
                'framework_id' => $framework->id, 'version_code' => 'v1-updated', 'title' => 'Updated Draft',
            ]);
            $this->fail('A Framework Version code is immutable.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_VERSION_CODE_IMMUTABLE', $exception->getMessage());
        }
        $service->updateDraftVersion($fixture['admin_id'], $version->id, [
            'framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'Updated Draft',
        ]);
        $service->updateDefinition($fixture['admin_id'], $definition->id, [
            'framework_id' => $framework->id, 'code' => 'stable-2', 'node_type' => 'competency',
            'canonical_name' => 'Updated Definition', 'description' => 'Updated description',
        ]);
        $service->updateDraftNode($fixture['admin_id'], $node->id, [
            'framework_version_id' => $version->id, 'node_definition_id' => $definition->id,
            'sequence' => 3, 'criteria' => ['level' => 'applied'],
        ]);

        $this->assertSame('Updated Framework', DB::table('core_learning_frameworks')->find($framework->id)->name);
        $this->assertSame('Updated Draft', DB::table('core_learning_framework_versions')->find($version->id)->title_snapshot);
        $storedNode = DB::table('core_learning_nodes')->find($node->id);
        $this->assertSame('stable-2', $storedNode->code_snapshot);
        $this->assertSame('Updated Definition', $storedNode->name_snapshot);
        $this->assertSame(3, (int) $storedNode->sequence);

        $service->publishVersion($fixture['admin_id'], $version->id);
        try {
            $service->updateDefinition($fixture['admin_id'], $definition->id, [
                'framework_id' => $framework->id, 'code' => 'changed', 'node_type' => 'competency',
                'canonical_name' => 'Updated Definition', 'description' => 'Allowed alone',
            ]);
            $this->fail('Published Definition identity must be immutable.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_DEFINITION_IDENTITY_IMMUTABLE', $exception->getMessage());
        }

        $updated = $service->updateDefinition($fixture['admin_id'], $definition->id, [
            'framework_id' => $framework->id, 'code' => 'stable-2', 'node_type' => 'competency',
            'canonical_name' => 'Updated Definition', 'description' => 'Description remains editable',
        ]);
        $this->assertSame('Description remains editable', $updated->description);
    }

    public function test_node_definition_and_published_draft_mutations_fail_with_exact_codes(): void
    {
        $fixture = $this->courseFixture('immutable');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $service = app(LearningFrameworkAuthoringService::class);
        $framework = $service->createFramework($fixture['admin_id'], $this->frameworkCommand('immutable'));
        $version = $service->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'Draft',
        ]);
        $first = $service->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'first', 'node_type' => 'concept',
            'canonical_name' => 'First',
        ]);
        $second = $service->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'second', 'node_type' => 'concept',
            'canonical_name' => 'Second',
        ]);
        $node = $service->createNode($fixture['admin_id'], [
            'framework_version_id' => $version->id, 'node_definition_id' => $first->id,
        ]);

        try {
            $service->updateDraftNode($fixture['admin_id'], $node->id, [
                'framework_version_id' => $version->id, 'node_definition_id' => $second->id,
            ]);
            $this->fail('Node Definition identity must not change.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_NODE_DEFINITION_IMMUTABLE', $exception->getMessage());
        }

        $service->publishVersion($fixture['admin_id'], $version->id);
        foreach ([
            fn () => $service->updateDraftVersion($fixture['admin_id'], $version->id, [
                'framework_id' => $framework->id, 'version_code' => 'v2', 'title' => 'No',
            ]),
            fn () => $service->updateDraftNode($fixture['admin_id'], $node->id, [
                'framework_version_id' => $version->id, 'node_definition_id' => $first->id,
            ]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Published version mutation must fail.');
            } catch (DomainException $exception) {
                $this->assertSame('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT', $exception->getMessage());
            }
        }
    }

    public function test_http_surface_denies_non_admins_and_cross_tenant_reads(): void
    {
        $owner = $this->courseFixture('http-owner');
        $other = $this->courseFixture('http-other');
        TenantContext::set((object) ['id' => $owner['customer_id']]);
        $framework = app(LearningFrameworkAuthoringService::class)
            ->createFramework($owner['admin_id'], $this->frameworkCommand('http-owner'));

        $paths = [
            ['get', '/admin/learning-frameworks'],
            ['get', '/admin/learning-frameworks/create'],
            ['post', '/admin/learning-frameworks'],
            ['get', "/admin/learning-frameworks/{$framework->id}"],
            ['put', "/admin/learning-frameworks/{$framework->id}"],
            ['post', "/admin/learning-frameworks/{$framework->id}/versions"],
            ['put', "/admin/learning-frameworks/{$framework->id}/versions/1"],
            ['post', "/admin/learning-frameworks/{$framework->id}/versions/1/publish"],
            ['post', "/admin/learning-frameworks/{$framework->id}/definitions"],
            ['put', "/admin/learning-frameworks/{$framework->id}/definitions/1"],
            ['post', "/admin/learning-frameworks/{$framework->id}/versions/1/nodes"],
            ['put', "/admin/learning-frameworks/{$framework->id}/versions/1/nodes/1"],
        ];

        foreach ([$owner['teacher_id'], $owner['student_id']] as $userId) {
            $this->actingAs(User::findOrFail($userId));
            foreach ($paths as [$method, $path]) {
                $this->{$method}('https://authoring-http-owner.localhost'.$path)->assertForbidden();
            }
        }

        $this->actingAs(User::findOrFail($other['admin_id']))
            ->get("https://authoring-http-other.localhost/admin/learning-frameworks/{$framework->id}")
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkCommand(string $suffix): array
    {
        return [
            'code' => "framework-{$suffix}",
            'name' => "Framework {$suffix}",
            'mastery_scale_key' => 'direct',
            'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [
                ['key' => 'novice', 'threshold' => 0],
                ['key' => 'mastered', 'threshold' => 0.8],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseFixture(string $suffix): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Authoring {$suffix}", 'slug' => "authoring-{$suffix}",
            'subdomain' => "authoring-{$suffix}", 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $adminId = $this->user($customerId, "admin-{$suffix}@example.test", 'customer_admin');
        $teacherId = $this->user($customerId, "teacher-{$suffix}@example.test", 'teacher');
        $studentId = $this->user($customerId, "student-{$suffix}@example.test", 'student');

        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'title' => "Template {$suffix}",
            'lesson_count' => 0, 'sort_order' => 1, 'working_revision' => 1,
            'status' => 'published', 'created_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId,
            'version_number' => 1, 'version_code' => "v1-{$suffix}", 'is_current' => true,
            'title_snapshot' => "Template {$suffix}", 'lesson_count_snapshot' => 0,
            'source_working_revision' => 1, 'status' => 'published',
            'published_at' => $now, 'published_by' => $adminId,
            'source_template_updated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId, 'product_code' => "P-{$suffix}",
            'product_type' => 'course', 'title' => "Product {$suffix}", 'slug' => "product-{$suffix}",
            'thumbnail_type' => 'none', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'manual', 'enrollment_count' => 0,
            'is_certificate_enabled' => false, 'is_refundable' => false,
            'show_enrollment_count' => false, 'is_featured' => false, 'sort_order' => 0,
            'visibility' => 'private', 'status' => 'active', 'created_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $cohortId = DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId,
            'teacher_id' => $teacherId, 'name' => "Cohort {$suffix}", 'code' => "C-{$suffix}",
            'status' => 'active', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $assignmentId = DB::table('core_course_cohort_teachers')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacherId,
            'role' => 'teacher', 'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-31',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $enrollmentId = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId,
            'student_id' => $studentId, 'source' => 'admin', 'enrolled_by' => $adminId,
            'enrolled_at' => '2026-08-01 00:00:00', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $membershipId = DB::table('core_course_cohort_students')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'enrollment_id' => $enrollmentId, 'product_id' => $productId,
            'student_id' => $studentId, 'assigned_by' => $adminId,
            'joined_at' => '2026-08-01 00:00:00', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return compact('customerId', 'adminId', 'teacherId', 'studentId', 'productId')
            + ['customer_id' => $customerId, 'admin_id' => $adminId, 'teacher_id' => $teacherId,
                'student_id' => $studentId, 'cohort_id' => $cohortId, 'assignment_id' => $assignmentId,
                'enrollment_id' => $enrollmentId, 'membership_id' => $membershipId];
    }

    private function user(int $customerId, string $email, string $role): int
    {
        return DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'active',
            'email_verified_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
