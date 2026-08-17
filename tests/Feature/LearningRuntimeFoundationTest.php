<?php

namespace Tests\Feature;

use App\Services\LearningEvidenceSourceGate;
use App\Services\LearningMasteryProfileProjector;
use App\Services\LearningRuntimeAccess;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningRuntimeFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(null);

        Schema::create('core_learning_mastery_calculations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('framework_id');
            $table->unsignedBigInteger('node_definition_id');
            $table->unsignedBigInteger('basis_framework_version_id');
            $table->string('mastery_level_key', 100);
            $table->decimal('mastery_score', 9, 6)->nullable();
            $table->string('mastery_status_result', 50);
            $table->dateTime('calculated_at');
            $table->timestamp('reassessment_due_at')->nullable();
        });
        Schema::create('core_learning_mastery_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('framework_id');
            $table->unsignedBigInteger('node_definition_id');
            $table->unsignedBigInteger('basis_framework_version_id');
            $table->unsignedBigInteger('current_calculation_id');
            $table->string('mastery_level_key', 100);
            $table->decimal('mastery_score', 9, 6)->nullable();
            $table->string('mastery_status', 50);
            $table->dateTime('calculated_at');
            $table->timestamp('reassessment_due_at')->nullable();
            $table->dateTime('projected_at');
            $table->timestamps();
            $table->unique([
                'customer_id', 'user_id', 'node_definition_id',
                'basis_framework_version_id',
            ], 'learning_profile_identity');
            $table->unique(['customer_id', 'current_calculation_id']);
        });
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        Schema::dropIfExists('core_learning_mastery_profiles');
        Schema::dropIfExists('core_learning_mastery_calculations');

        parent::tearDown();
    }

    public function test_runtime_requires_tenant_context_and_denies_all_external_access(): void
    {
        $access = new LearningRuntimeAccess;

        $this->expectException(AuthorizationException::class);
        $access->tenantId();
    }

    #[DataProvider('externalPrincipals')]
    public function test_external_principals_have_no_learning_read_or_write_path(string $principal): void
    {
        $access = new LearningRuntimeAccess;

        foreach ([
            fn () => $access->denyExternalRead($principal, 'mastery_profile'),
            fn () => $access->denyExternalWrite($principal, 'learning'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('External Learning access must fail closed.');
            } catch (AuthorizationException $exception) {
                $this->assertStringContainsString($principal, $exception->getMessage());
            }
        }
    }

    public static function externalPrincipals(): array
    {
        return [
            'admin' => ['customer_admin'],
            'teacher' => ['teacher'],
            'student' => ['student'],
            'AI' => ['ai'],
            'Track' => ['track'],
        ];
    }

    #[DataProvider('closedEvidenceSources')]
    public function test_initial_evidence_source_gate_fails_closed(string $source): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LF_EVIDENCE_SOURCE_CLOSED');

        (new LearningEvidenceSourceGate)->assertOpen($source);
    }

    public static function closedEvidenceSources(): array
    {
        return [
            'track table token' => ['track_events'],
            'behavioral signal' => ['behavioral_signal'],
            'unknown source' => ['other'],
        ];
    }

    public function test_teacher_judgment_is_the_only_initially_open_source(): void
    {
        (new LearningEvidenceSourceGate)->assertOpen('teacher_judgment');

        $this->addToAssertionCount(1);
    }

    public function test_projector_is_tenant_scoped_idempotent_and_uses_deterministic_ordering(): void
    {
        TenantContext::set((object) ['id' => 10]);
        $this->insertCalculation(1, 10, '2026-08-13 10:00:00.000001', 'developing');
        $this->insertCalculation(2, 10, '2026-08-13 10:00:00.000002', 'proficient');
        $this->insertCalculation(3, 10, '2026-08-13 10:00:00.000001', 'introduced');
        $projector = app(LearningMasteryProfileProjector::class);

        $first = $projector->project(1);
        $replay = $projector->project(1);
        $newest = $projector->project(2);
        $stale = $projector->project(3);

        $this->assertSame((int) $first->id, (int) $replay->id);
        $this->assertSame(2, (int) $newest->current_calculation_id);
        $this->assertSame(2, (int) $stale->current_calculation_id);
        $this->assertSame('proficient', $stale->mastery_level_key);
        $this->assertSame(1, DB::table('core_learning_mastery_profiles')->count());
    }

    /**
     * Ordering normalizes into the application timezone, not UTC: stored
     * calculated_at values are naive wall-clock like every other timestamp in
     * the database. Two values naming the same instant in different zones must
     * still compare equal, which is what this asserts.
     */
    public function test_ordering_normalizes_timezone_bearing_values_to_app_zone(): void
    {
        $projector = app(LearningMasteryProfileProjector::class);
        $method = new \ReflectionMethod($projector, 'orderingTime');

        $utc = $method->invoke($projector, CarbonImmutable::parse(
            '2026-08-13 10:00:00.000001 UTC',
        ));
        $vietnam = $method->invoke($projector, CarbonImmutable::parse(
            '2026-08-13 17:00:00.000001 Asia/Ho_Chi_Minh',
        ));

        $this->assertSame($utc, $vietnam);
        $this->assertSame('2026-08-13 17:00:00.000001', $vietnam);
    }

    public function test_projector_cannot_read_a_calculation_from_another_tenant(): void
    {
        TenantContext::set((object) ['id' => 10]);
        $this->insertCalculation(1, 20, '2026-08-13 10:00:00.000001', 'developing');

        $this->expectException(RecordsNotFoundException::class);
        app(LearningMasteryProfileProjector::class)->project(1);
    }

    public function test_projection_rejects_reusing_one_calculation_for_another_profile(): void
    {
        TenantContext::set((object) ['id' => 10]);
        $this->insertCalculation(1, 10, '2026-08-13 10:00:00.000001', 'developing');
        DB::table('core_learning_mastery_profiles')->insert([
            'customer_id' => 10,
            'user_id' => 999,
            'framework_id' => 200,
            'node_definition_id' => 999,
            'basis_framework_version_id' => 400,
            'current_calculation_id' => 1,
            'mastery_level_key' => 'developing',
            'mastery_score' => '0.750000',
            'mastery_status' => 'established',
            'calculated_at' => '2026-08-13 10:00:00.000001',
            'reassessment_due_at' => null,
            'projected_at' => '2026-08-13 10:00:01.000001',
            'created_at' => '2026-08-13 10:00:01.000001',
            'updated_at' => '2026-08-13 10:00:01.000001',
        ]);

        try {
            app(LearningMasteryProfileProjector::class)->project(1);
            $this->fail('Projection must reject reuse of one Calculation.');
        } catch (QueryException) {
            $this->assertFalse(DB::table('core_learning_mastery_profiles')->where([
                'customer_id' => 10,
                'user_id' => 100,
                'node_definition_id' => 300,
                'basis_framework_version_id' => 400,
            ])->exists());
        }
    }

    private function insertCalculation(
        int $id,
        int $customerId,
        string $calculatedAt,
        string $level,
    ): void {
        DB::table('core_learning_mastery_calculations')->insert([
            'id' => $id,
            'customer_id' => $customerId,
            'user_id' => 100,
            'framework_id' => 200,
            'node_definition_id' => 300,
            'basis_framework_version_id' => 400,
            'mastery_level_key' => $level,
            'mastery_score' => '0.750000',
            'mastery_status_result' => 'established',
            'calculated_at' => $calculatedAt,
            'reassessment_due_at' => null,
        ]);
    }
}
