<?php

namespace Tests\Feature;

use App\Services\CourseTemplateLearningMappingIntentService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CourseFrameworkDetachTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['users', 'core_course_templates', 'core_course_template_learning_mapping_intents'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->integer('customer_id');
                if ($table === 'users') {
                    $blueprint->string('role');
                    $blueprint->string('status');
                } elseif ($table === 'core_course_templates') {
                    $blueprint->string('status');
                    $blueprint->integer('working_revision');
                    $blueprint->integer('selected_learning_framework_id')->nullable();
                    $blueprint->integer('selected_learning_framework_version_id')->nullable();
                    $blueprint->timestamp('updated_at')->nullable();
                } else {
                    $blueprint->integer('template_id');
                    $blueprint->string('origin');
                }
            });
        }
        TenantContext::set((object) ['id' => 1]);
        DB::table('users')->insert(['id' => 1, 'customer_id' => 1, 'role' => 'customer_admin', 'status' => 'active']);
        foreach ([1, 2] as $id) {
            DB::table('core_course_templates')->insert(['id' => $id, 'customer_id' => $id, 'status' => 'draft', 'working_revision' => 7,
                'selected_learning_framework_id' => 5, 'selected_learning_framework_version_id' => 10]);
            DB::table('core_course_template_learning_mapping_intents')->insert(['customer_id' => $id, 'template_id' => $id, 'origin' => 'manual']);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        foreach (['core_learning_nodes', 'core_learning_framework_versions', 'core_learning_frameworks', 'core_course_template_learning_mapping_intents', 'core_course_templates', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_detach_removes_only_own_draft_links_and_clears_both_selection_fields(): void
    {
        DB::table('core_course_template_learning_mapping_intents')->insert(['customer_id' => 1, 'template_id' => 1, 'origin' => 'ai_proposal']);
        app(CourseTemplateLearningMappingIntentService::class)->detachFramework(1, 1, 1, 7);
        $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'working_revision' => 8,
            'selected_learning_framework_id' => null, 'selected_learning_framework_version_id' => null]);
        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
        $this->assertDatabaseHas('core_course_template_learning_mapping_intents', ['customer_id' => 2, 'template_id' => 2]);
    }

    public function test_stale_revision_and_non_draft_leave_selection_and_links_intact(): void
    {
        foreach ([['draft', 6], ['active', 7]] as [$status, $revision]) {
            DB::table('core_course_templates')->where('id', 1)->update(['status' => $status]);
            try {
                app(CourseTemplateLearningMappingIntentService::class)->detachFramework(1, 1, 1, $revision);
                $this->fail('Invalid detach succeeded.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'working_revision' => 7, 'selected_learning_framework_id' => 5]);
                $this->assertDatabaseHas('core_course_template_learning_mapping_intents', ['customer_id' => 1, 'template_id' => 1]);
            }
        }
    }

    public function test_teacher_is_forbidden(): void
    {
        DB::table('users')->where('id', 1)->update(['role' => 'teacher']);
        $this->expectException(HttpException::class);
        app(CourseTemplateLearningMappingIntentService::class)->detachFramework(1, 1, 1, 7);
    }

    public function test_failure_clearing_selection_rolls_back_link_deletion(): void
    {
        DB::unprepared("CREATE TRIGGER detach_test_fail BEFORE UPDATE ON core_course_templates BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            app(CourseTemplateLearningMappingIntentService::class)->detachFramework(1, 1, 1, 7);
            $this->fail('Failure did not occur.');
        } catch (QueryException) {
            $this->assertDatabaseHas('core_course_template_learning_mapping_intents', ['customer_id' => 1, 'template_id' => 1]);
            $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'working_revision' => 7, 'selected_learning_framework_id' => 5]);
        }
    }

    public function test_detach_does_not_change_published_versions_or_canonical_mappings(): void
    {
        foreach (['core_course_template_versions', 'core_learning_node_mappings'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('payload');
            });
            DB::table($table)->insert(['payload' => 'immutable published data']);
        }
        try {
            app(CourseTemplateLearningMappingIntentService::class)->detachFramework(1, 1, 1, 7);
            foreach (['core_course_template_versions', 'core_learning_node_mappings'] as $table) {
                $this->assertDatabaseCount($table, 1);
                $this->assertDatabaseHas($table, ['payload' => 'immutable published data']);
            }
        } finally {
            Schema::dropIfExists('core_learning_node_mappings');
            Schema::dropIfExists('core_course_template_versions');
        }
    }

    public function test_foreign_tenant_template_is_not_found(): void
    {
        $this->expectException(HttpException::class);
        app(CourseTemplateLearningMappingIntentService::class)->detachFramework(1, 1, 2, 7);
    }

    public function test_draft_button_has_confirmation_and_active_template_has_no_detach_button(): void
    {
        $version = (object) ['framework_id' => 5, 'framework_version_id' => 10, 'framework_name' => 'Framework',
            'title_snapshot' => 'Version 1', 'version_code' => 'v1'];
        $template = DB::table('core_course_templates')->where('id', 1)->first();
        $data = ['template' => $template, 'routePrefix' => 'admin.course-templates',
            'learningMappingState' => ['versions' => collect([$version]), 'nodes' => collect(), 'intents' => collect()],
            'activitiesByLesson' => [], 'directLessons' => [], 'lessonsBySection' => [], 'sections' => [],
            'directActivityRoutePrefix' => '', 'activityRoutePrefix' => ''];
        $html = view('course-templates.partials.learning-mappings', $data)->render();
        $this->assertStringContainsString('data-lf-confirm=', $html);
        $this->assertStringContainsString('id="learning-framework-version"', $html);
        $this->assertStringNotContainsString('data-course-learning-upgrade-picker', $html);
        $this->assertStringNotContainsString('course-learning__upgrade-plan', $html);
        $this->assertStringContainsString('/learning-framework', $html);
        $this->assertStringContainsString('value="DELETE"', $html);
        $template->status = 'active';
        $html = view('course-templates.partials.learning-mappings', $data)->render();
        $this->assertStringNotContainsString('value="DELETE"', $html);
    }

    private function upgradeFixture(): void
    {
        Schema::table('core_course_template_learning_mapping_intents', fn (Blueprint $t) => $t->integer('learning_node_id')->default(20));
        Schema::create('core_learning_frameworks', function (Blueprint $t): void {
            $t->id();
            $t->integer('customer_id');
            $t->string('status');
            $t->string('name');
        });
        Schema::create('core_learning_framework_versions', function (Blueprint $t): void {
            $t->id();
            $t->integer('customer_id');
            $t->integer('framework_id');
            $t->integer('version_number');
            $t->string('status');
            $t->string('version_code');
            $t->string('title_snapshot');
            $t->text('mastery_scale_snapshot')->nullable();
        });
        Schema::create('core_learning_nodes', function (Blueprint $t): void {
            $t->id();
            $t->integer('customer_id');
            $t->integer('node_definition_id');
            $t->string('code_snapshot');
            $t->string('name_snapshot');
        });
        DB::table('core_learning_frameworks')->insert(['id' => 5, 'customer_id' => 1, 'status' => 'active', 'name' => 'Framework']);
        DB::table('core_learning_framework_versions')->insert(['id' => 11, 'customer_id' => 1, 'framework_id' => 5,
            'version_number' => 2, 'status' => 'published', 'version_code' => 'v2', 'title_snapshot' => 'Version 2']);
        DB::table('core_learning_nodes')->insert(['id' => 20, 'customer_id' => 1, 'node_definition_id' => 30, 'code_snapshot' => 'A', 'name_snapshot' => 'Competency']);
        DB::table('core_course_template_learning_mapping_intents')->insert(['customer_id' => 1, 'template_id' => 1, 'origin' => 'ai_proposal']);
    }

    public function test_version_switch_removes_manual_and_ai_links_without_touching_other_tenant(): void
    {
        $this->upgradeFixture();
        $service = app(CourseTemplateLearningMappingIntentService::class);
        $plan = $service->upgradePlan(1, 1, 1, 11);
        $this->assertCount(0, $plan['kept']);
        $this->assertCount(2, $plan['removed']);
        $this->assertFalse($plan['ai_blocked']);
        $service->upgrade(1, 1, 1, 11, 7);
        $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'working_revision' => 8, 'selected_learning_framework_version_id' => 11]);
        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
        $this->assertDatabaseHas('core_course_template_learning_mapping_intents', ['customer_id' => 2]);
        DB::table('core_learning_framework_versions')->insert(['id' => 10, 'customer_id' => 1, 'framework_id' => 5,
            'version_number' => 1, 'status' => 'published', 'version_code' => 'v1', 'title_snapshot' => 'Version 1']);
        $service->upgrade(1, 1, 1, 10, 8);
        $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'selected_learning_framework_version_id' => 10]);
        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
    }

    public function test_version_switch_stale_revision_does_not_delete_links(): void
    {
        $this->upgradeFixture();
        try {
            app(CourseTemplateLearningMappingIntentService::class)->upgrade(1, 1, 1, 11, 6);
            $this->fail('Stale request must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'selected_learning_framework_version_id' => 10]);
            $this->assertSame(2, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
        }
    }

    public function test_version_switch_failure_rolls_back_and_published_snapshots_are_untouched(): void
    {
        $this->upgradeFixture();
        foreach (['core_course_template_versions', 'core_learning_node_mappings'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('payload');
            });
            DB::table($table)->insert(['payload' => 'published snapshot']);
        }
        try {
            DB::statement("CREATE TRIGGER reject_version_update BEFORE UPDATE ON core_course_templates BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
            try {
                app(CourseTemplateLearningMappingIntentService::class)->upgrade(1, 1, 1, 11, 7);
                $this->fail('Update must fail.');
            } catch (QueryException) {
                $this->assertSame(2, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
                $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'working_revision' => 7, 'selected_learning_framework_version_id' => 10]);
            }
            DB::statement('DROP TRIGGER reject_version_update');
            app(CourseTemplateLearningMappingIntentService::class)->upgrade(1, 1, 1, 11, 7);
            foreach (['core_course_template_versions', 'core_learning_node_mappings'] as $table) {
                $this->assertDatabaseHas($table, ['payload' => 'published snapshot']);
                $this->assertDatabaseCount($table, 1);
            }
        } finally {
            Schema::dropIfExists('core_learning_node_mappings');
            Schema::dropIfExists('core_course_template_versions');
        }
    }

    public function test_version_switch_rejects_current_version_and_other_framework(): void
    {
        $this->upgradeFixture();
        DB::table('core_learning_framework_versions')->where('id', 11)->update(['framework_id' => 999]);
        foreach ([10, 11] as $target) {
            try {
                app(CourseTemplateLearningMappingIntentService::class)->upgrade(1, 1, 1, $target, 7);
                $this->fail('Invalid target must be rejected.');
            } catch (ValidationException) {
                $this->assertSame(2, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
            }
        }
    }

    public function test_direct_framework_change_resets_links_and_applies_target_atomically(): void
    {
        $this->upgradeFixture();
        DB::table('core_learning_frameworks')->insert(['id' => 6, 'customer_id' => 1, 'status' => 'active', 'name' => 'Other framework']);
        DB::table('core_learning_framework_versions')->where('id', 11)->update(['framework_id' => 6]);
        $service = app(CourseTemplateLearningMappingIntentService::class);
        try {
            $service->select(1, 1, 1, 6, 11, 6);
            $this->fail('Stale confirmation must fail.');
        } catch (ValidationException) {
            $this->assertSame(2, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
        }
        DB::statement("CREATE TRIGGER reject_direct_update BEFORE UPDATE ON core_course_templates BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
        try {
            $service->select(1, 1, 1, 6, 11, 7);
            $this->fail('Forced failure must rollback.');
        } catch (QueryException) {
            $this->assertSame(2, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
        }
        DB::statement('DROP TRIGGER reject_direct_update');
        $service->select(1, 1, 1, 6, 11, 7);
        $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'selected_learning_framework_id' => 6,
            'selected_learning_framework_version_id' => 11, 'working_revision' => 8]);
        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->where('customer_id', 1)->count());
        $this->assertDatabaseHas('core_course_template_learning_mapping_intents', ['customer_id' => 2]);
        $service->select(1, 1, 1, 6, 11, 8);
        $this->assertDatabaseHas('core_course_templates', ['id' => 1, 'working_revision' => 8]);
    }
}
