<?php

namespace Tests\Integration;

use App\Services\LearningMasteryProfileProjector;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningRuntimeMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Learning runtime physical verification requires MySQL/MariaDB.');
        }
        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);

        parent::tearDown();
    }

    public function test_projector_runs_against_released_constraints_and_triggers(): void
    {
        $context = $this->context('primary');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $older = $this->calculation($context, 100, '2026-08-13 10:00:00.000001', 'novice');
        $newer = $this->calculation($context, 102, '2026-08-13 10:00:00.000002', 'mastered');
        $stale = $this->calculation($context, 101, '2026-08-13 10:00:00.000001', 'novice');
        $projector = app(LearningMasteryProfileProjector::class);

        $first = $projector->project($older);
        $this->assertSame((int) $first->id, (int) $projector->project($older)->id);
        $this->assertSame($newer, (int) $projector->project($newer)->current_calculation_id);
        $this->assertSame($newer, (int) $projector->project($stale)->current_calculation_id);
        $this->assertSame(1, DB::table('core_learning_mastery_profiles')->count());

        try {
            DB::table('core_learning_mastery_profiles')->where('id', $first->id)
                ->update(['mastery_level_key' => 'tampered']);
            $this->fail('The physical projection trigger must reject tampering.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0] ?? null);
            $this->assertStringContainsString('LF_PROFILE_MISMATCH', $exception->getMessage());
        }

        $otherContext = $this->context('other');
        $otherCalculation = $this->calculation(
            $otherContext,
            200,
            '2026-08-13 10:00:00.000003',
            'mastered',
        );
        TenantContext::set((object) ['id' => $otherContext['customer_id']]);
        try {
            $projector->project($newer);
            $this->fail('A tenant must not read another tenant Calculation.');
        } catch (RecordsNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(
            $otherCalculation,
            (int) $projector->project($otherCalculation)->current_calculation_id,
        );

        TenantContext::set(null);
        $this->expectException(AuthorizationException::class);
        $projector->project($newer);
    }

    private function context(string $suffix): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Learning Runtime Tenant {$suffix}",
            'slug' => "learning-runtime-tenant-{$suffix}",
            'subdomain' => "learning-runtime-tenant-{$suffix}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actorId = $this->user($customerId, "learning-actor-{$suffix}@example.test", 'customer_admin');
        $learnerId = $this->user($customerId, "learning-learner-{$suffix}@example.test", 'student');
        $scale = json_encode(['levels' => [
            ['key' => 'novice', 'threshold' => 0],
            ['key' => 'mastered', 'threshold' => 0.8],
        ]], JSON_THROW_ON_ERROR);
        $frameworkId = DB::table('core_learning_frameworks')->insertGetId([
            'customer_id' => $customerId,
            'code' => "runtime-framework-{$suffix}",
            'name' => "Runtime Framework {$suffix}",
            'default_mastery_scale_key' => 'runtime-scale',
            'default_mastery_scale_version' => '1',
            'default_mastery_scale' => $scale,
            'status' => 'active',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $versionId = DB::table('core_learning_framework_versions')->insertGetId([
            'customer_id' => $customerId,
            'framework_id' => $frameworkId,
            'version_number' => 1,
            'version_code' => "v1-{$suffix}",
            'title_snapshot' => "Runtime Framework v1 {$suffix}",
            'mastery_scale_key' => 'runtime-scale',
            'mastery_scale_version' => '1',
            'mastery_scale_snapshot' => $scale,
            'status' => 'draft_snapshot',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $definitionId = DB::table('core_learning_node_definitions')->insertGetId([
            'customer_id' => $customerId,
            'framework_id' => $frameworkId,
            'code' => "runtime-node-{$suffix}",
            'node_type' => 'competency',
            'canonical_name' => "Runtime Node {$suffix}",
            'status' => 'active',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'customer_id' => $customerId,
            'learner_id' => $learnerId,
            'framework_id' => $frameworkId,
            'version_id' => $versionId,
            'definition_id' => $definitionId,
            'scale' => $scale,
        ];
    }

    private function user(int $customerId, string $email, string $role): int
    {
        return DB::table('users')->insertGetId([
            'customer_id' => $customerId,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function calculation(
        array $context,
        int $id,
        string $calculatedAt,
        string $level,
    ): int {
        DB::table('core_learning_mastery_calculations')->insert([
            'id' => $id,
            'customer_id' => $context['customer_id'],
            'user_id' => $context['learner_id'],
            'framework_id' => $context['framework_id'],
            'node_definition_id' => $context['definition_id'],
            'basis_framework_version_id' => $context['version_id'],
            'calculation_source' => 'system',
            'calculation_idempotency_key' => "runtime-{$id}",
            'mastery_level_key' => $level,
            'mastery_score' => $level === 'mastered' ? '0.900000' : '0.200000',
            'calculation_rule_key' => 'runtime-rule',
            'calculation_rule_version' => '1',
            'calculation_rule_snapshot' => json_encode([
                'rule_key' => 'runtime-rule', 'rule_version' => '1',
            ], JSON_THROW_ON_ERROR),
            'mastery_scale_key' => 'runtime-scale',
            'mastery_scale_version' => '1',
            'mastery_scale_snapshot' => $context['scale'],
            'mastery_status_result' => 'established',
            'calculated_at' => $calculatedAt,
            'created_at' => now(),
        ]);

        return $id;
    }
}
