<?php

namespace Tests\Integration;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gate 2 coverage for the customer-admin Framework authoring surface, driven
 * over HTTP with browser-shaped payloads.
 *
 * The sibling LearningFrameworkAuthoringMariaDbTest calls the owner service
 * directly and passes native PHP scalars. That is the right shape for testing
 * domain rules, and it is structurally blind to an entire class of defect,
 * because an HTML form does not send PHP scalars. It sends:
 *
 *   - a string for every field, `<input type="number">` included, so a
 *     threshold arrives as "0.5" and not 0.5;
 *   - an empty string for any box the user left blank, which the global
 *     ConvertEmptyStringsToNull middleware then turns into null — which is not
 *     the same thing as the field being absent, and `sometimes` does not skip
 *     it.
 *
 * Two blocking defects reached the shipped surface through exactly that blind
 * spot: string thresholds were persisted verbatim and refused by
 * trg_lrn_frameworks_bi_scale, so no Framework could be created from the form
 * at all; and a blank sequence box was refused by request validation, so no
 * Node could be created from the form at all. Both suites stayed green
 * throughout. Every payload below is therefore written the way the browser
 * transmits it, and any future test added here must keep that property or this
 * file stops being worth running.
 *
 * Denial coverage — non-admin roles and cross-tenant reads — lives in the
 * sibling suite and is not repeated here.
 *
 * Vite is stubbed rather than built: the assertions that matter read form
 * markup, and requiring a manifest would force an npm build into the MariaDB
 * CI job that no other file in it needs.
 */
class LearningFrameworkAuthoringHttpMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private int $adminId;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Learning Framework authoring over HTTP requires MariaDB.');
        }

        $this->withoutVite();
        TenantContext::set(null);

        $now = now();
        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Authoring HTTP', 'slug' => 'authoring-http',
            'subdomain' => 'authoring-http', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'customer_id' => $this->customerId, 'name' => 'admin',
            'email' => 'admin@authoring-http.test', 'password' => bcrypt('password'),
            'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->host = 'https://authoring-http.localhost';
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Framework

    public function test_create_framework_accepts_the_create_form_payload(): void
    {
        $this->admin()
            ->post($this->host.'/admin/learning-frameworks', $this->frameworkPayload('fw-create'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('core_learning_frameworks', [
            'customer_id' => $this->customerId, 'code' => 'fw-create', 'status' => 'active',
        ]);
    }

    /**
     * trg_lrn_frameworks_bi_scale reads JSON_TYPE of each threshold and refuses
     * STRING. The form cannot send anything but a string, so the boundary is
     * responsible for the conversion and this asserts the stored type, not the
     * stored text.
     */
    public function test_form_thresholds_are_persisted_as_json_numbers(): void
    {
        $framework = $this->createFramework('fw-scale');

        $types = DB::selectOne(
            "SELECT JSON_TYPE(JSON_EXTRACT(default_mastery_scale, '$.levels[0].threshold')) AS low,
                    JSON_TYPE(JSON_EXTRACT(default_mastery_scale, '$.levels[1].threshold')) AS mid,
                    JSON_TYPE(JSON_EXTRACT(default_mastery_scale, '$.levels[2].threshold')) AS high
             FROM core_learning_frameworks WHERE id = ? AND customer_id = ?",
            [$framework->id, $this->customerId]
        );

        foreach (['low', 'mid', 'high'] as $level) {
            $this->assertContains($types->{$level}, ['INTEGER', 'DOUBLE'],
                "Threshold {$level} was stored as {$types->$level}, which the scale trigger refuses.");
        }
    }

    public function test_update_framework_accepts_the_detail_form_payload(): void
    {
        $framework = $this->createFramework('fw-update');

        $payload = $this->frameworkPayload('fw-update');
        $payload['name'] = 'Renamed framework';
        $payload['mastery_scale']['levels'][1]['threshold'] = '0.6';

        $this->admin()
            ->put($this->host."/admin/learning-frameworks/{$framework->id}", $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $row = DB::table('core_learning_frameworks')->where('id', $framework->id)->first();
        $this->assertSame('Renamed framework', $row->name);
        $this->assertStringContainsString('"threshold":0.6', $row->default_mastery_scale);
    }

    /**
     * The service bounds thresholds to the [0,1] domain judging uses. Over HTTP
     * that arrives as a DomainException, and the boundary must turn it into a
     * validation error rather than a 500.
     */
    public function test_scale_outside_the_judgment_domain_is_refused_at_the_boundary(): void
    {
        $payload = $this->frameworkPayload('fw-bad-scale');
        $payload['mastery_scale']['levels'] = [
            ['key' => 'novice', 'threshold' => '0.2'],
            ['key' => 'mastered', 'threshold' => '0.9'],
        ];

        $this->admin()
            ->post($this->host.'/admin/learning-frameworks', $payload)
            ->assertSessionHasErrors('mastery_scale');

        $this->assertDatabaseMissing('core_learning_frameworks', ['code' => 'fw-bad-scale']);
    }

    // --------------------------------------------------------------- Definition

    public function test_create_definition_accepts_the_detail_form_payload(): void
    {
        $framework = $this->createFramework('def-create');

        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$framework->id}/definitions",
                $this->definitionPayload($framework->id, 'D-1'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('core_learning_node_definitions', [
            'customer_id' => $this->customerId, 'framework_id' => $framework->id, 'code' => 'D-1',
        ]);
    }

    /**
     * Once a non-draft version references a Definition, the form disables its
     * identity inputs and resubmits the frozen values as hidden fields. This is
     * the payload that produces, and it must be accepted — a surface that
     * refuses its own rendered form is the G2-B2 defect in reverse.
     */
    public function test_locked_definition_identity_travels_as_hidden_fields(): void
    {
        ['framework' => $framework, 'definition' => $definition] = $this->publishedFixture('def-lock');

        $payload = $this->definitionPayload($framework->id, $definition->code);
        $payload['description'] = 'Description stays editable after publication.';

        $this->admin()
            ->put($this->host."/admin/learning-frameworks/{$framework->id}/definitions/{$definition->id}", $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame('Description stays editable after publication.',
            DB::table('core_learning_node_definitions')->where('id', $definition->id)->value('description'));
    }

    public function test_changing_a_locked_definition_identity_is_refused(): void
    {
        ['framework' => $framework, 'definition' => $definition] = $this->publishedFixture('def-frozen');

        $payload = $this->definitionPayload($framework->id, 'RENAMED-CODE');

        $this->admin()
            ->put($this->host."/admin/learning-frameworks/{$framework->id}/definitions/{$definition->id}", $payload)
            ->assertSessionHasErrors('learning');

        $this->assertSame($definition->code,
            DB::table('core_learning_node_definitions')->where('id', $definition->id)->value('code'));
    }

    // ------------------------------------------------------------------ Version

    public function test_create_draft_version_accepts_the_detail_form_payload(): void
    {
        $framework = $this->createFramework('ver-create');

        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$framework->id}/versions", [
                'framework_id' => (string) $framework->id,
                'version_code' => 'v1', 'title' => 'Version one', 'description' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('core_learning_framework_versions', [
            'framework_id' => $framework->id, 'version_code' => 'v1', 'status' => 'draft_snapshot',
        ]);
    }

    /**
     * The version code input is rendered disabled, so the browser omits it and
     * a hidden field carries the unchanged value. The service compares that
     * value and refuses any change, unconditionally.
     */
    public function test_update_draft_version_carries_the_disabled_version_code(): void
    {
        $framework = $this->createFramework('ver-update');
        $version = $this->createVersion($framework->id, 'v1');

        $this->admin()
            ->put($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}", [
                'framework_id' => (string) $framework->id,
                'version_code' => 'v1', 'title' => 'Retitled', 'description' => 'Now described.',
            ])
            ->assertSessionHasNoErrors();

        $row = DB::table('core_learning_framework_versions')->where('id', $version->id)->first();
        $this->assertSame('Retitled', $row->title_snapshot);
        $this->assertSame('Now described.', $row->description_snapshot);
    }

    public function test_publish_is_refused_while_the_version_has_no_node(): void
    {
        $framework = $this->createFramework('ver-empty');
        $version = $this->createVersion($framework->id, 'v1');

        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}/publish")
            ->assertSessionHasErrors('learning');

        $this->assertSame('draft_snapshot',
            DB::table('core_learning_framework_versions')->where('id', $version->id)->value('status'));
    }

    public function test_publish_promotes_a_draft_carrying_an_active_node(): void
    {
        ['version' => $version] = $this->publishedFixture('ver-publish');

        $this->assertSame('published',
            DB::table('core_learning_framework_versions')->where('id', $version->id)->value('status'));
    }

    // --------------------------------------------------------------------- Node

    /**
     * The sequence box is rendered empty so the service assigns the next
     * position itself. An empty box is transmitted as "" and reaches validation
     * as null, so this asserts the whole path: the boundary must accept it, and
     * the service must append rather than write zero.
     */
    public function test_blank_sequence_box_lets_the_service_append(): void
    {
        $framework = $this->createFramework('node-append');
        $version = $this->createVersion($framework->id, 'v1');

        foreach (['N-1', 'N-2', 'N-3'] as $code) {
            $definition = $this->createDefinition($framework->id, $code);

            $this->admin()
                ->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}/nodes", [
                    'framework_version_id' => (string) $version->id,
                    'node_definition_id' => (string) $definition->id,
                    'sequence' => '',
                    'criteria_json' => '',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame([1, 2, 3], DB::table('core_learning_nodes')
            ->where('framework_version_id', $version->id)
            ->orderBy('id')->pluck('sequence')->map(fn ($s) => (int) $s)->all());
    }

    public function test_node_accepts_an_explicit_sequence_and_criteria(): void
    {
        $framework = $this->createFramework('node-explicit');
        $version = $this->createVersion($framework->id, 'v1');
        $definition = $this->createDefinition($framework->id, 'N-1');

        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}/nodes", [
                'framework_version_id' => (string) $version->id,
                'node_definition_id' => (string) $definition->id,
                'sequence' => '4',
                'criteria_json' => '{"evidence":"observation"}',
            ])
            ->assertSessionHasNoErrors();

        $node = DB::table('core_learning_nodes')->where('framework_version_id', $version->id)->first();
        $this->assertSame(4, (int) $node->sequence);
        $this->assertSame(['evidence' => 'observation'], json_decode($node->criteria_snapshot, true));
    }

    public function test_update_node_refreshes_its_snapshot_from_the_definition(): void
    {
        $framework = $this->createFramework('node-update');
        $version = $this->createVersion($framework->id, 'v1');
        $definition = $this->createDefinition($framework->id, 'N-1');
        $node = $this->createNode($framework->id, $version->id, $definition->id);

        $renamed = $this->definitionPayload($framework->id, $definition->code);
        $renamed['canonical_name'] = 'Renamed while draft';
        $this->admin()->put(
            $this->host."/admin/learning-frameworks/{$framework->id}/definitions/{$definition->id}", $renamed
        )->assertSessionHasNoErrors();

        $this->admin()
            ->put($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}/nodes/{$node->id}", [
                'framework_version_id' => (string) $version->id,
                'node_definition_id' => (string) $definition->id,
                'sequence' => '2',
                'criteria_json' => '',
            ])
            ->assertSessionHasNoErrors();

        $row = DB::table('core_learning_nodes')->where('id', $node->id)->first();
        $this->assertSame('Renamed while draft', $row->name_snapshot);
        $this->assertSame(2, (int) $row->sequence);
        $this->assertNull($row->criteria_snapshot);
    }

    // ------------------------------------------------------------- Rendered page

    /**
     * The detail page renders one form per Framework, Definition, Version and
     * Node, all sharing a single old-input bag, and Framework and Definition
     * share the field names `code`, `name` and `description`. Repopulating from
     * old() therefore let a failed Definition submit prefill the Framework
     * identity field, and a subsequent save rewrote the Framework code with no
     * warning. This is the regression guard for that.
     */
    public function test_a_failed_definition_submit_does_not_repopulate_the_framework_form(): void
    {
        $framework = $this->createFramework('bleed-guard');

        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$framework->id}/competencies", [
                'code' => 'DEFINITION-CODE-XYZ',
                'node_type' => 'competency',
                'description' => 'Submitted without canonical_name.',
            ])
            ->assertSessionHasErrors('canonical_name');

        $html = $this->admin()
            ->get($this->host."/admin/learning-frameworks/{$framework->id}")
            ->assertOk()->getContent();

        // The Framework edit form never reads old input: its code stays the Framework's own.
        $this->assertStringContainsString('<input type="hidden" name="code" value="bleed-guard">', $html);
        $this->assertSame(1, substr_count($html, 'value="DEFINITION-CODE-XYZ"'), 'Only the add-competency form is repopulated.');
        $this->assertStringContainsString('id="competency-code" name="code" class="lf-form-control" maxlength="120" autocomplete="off" value="DEFINITION-CODE-XYZ"', $html);
    }

    // ----------------------------------------------- Simplified page (2026-10-11)
    public function test_the_simplified_create_form_derives_the_code_and_keeps_the_default_scale(): void
    {
        // Exactly what the create page sends when only the name and description are filled in.
        $payload = [
            'name' => 'Chuẩn đầu ra tiếng Hàn sơ cấp', 'description' => '', 'code' => '',
            'mastery_scale_key' => 'lf-default', 'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [
                ['key' => 'not_started', 'threshold' => '0'],
                ['key' => 'developing', 'threshold' => '0.5'],
                ['key' => 'proficient', 'threshold' => '0.75'],
                ['key' => 'mastered', 'threshold' => '0.9'],
            ]],
        ];
        $this->admin()->post($this->host.'/admin/learning-frameworks', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->admin()->post($this->host.'/admin/learning-frameworks', $payload)->assertSessionHasNoErrors();

        $codes = DB::table('core_learning_frameworks')->where('customer_id', $this->customerId)->orderBy('id')->pluck('code')->all();
        $this->assertSame(['chuan-dau-ra-tieng-han-so-cap', 'chuan-dau-ra-tieng-han-so-cap-2'], $codes);
        $scale = json_decode((string) DB::table('core_learning_frameworks')->where('code', $codes[0])->value('default_mastery_scale'), true);
        $this->assertSame(['not_started', 'developing', 'proficient', 'mastered'], array_column($scale['levels'], 'key'));

        $html = $this->admin()->get($this->host.'/admin/learning-frameworks/create')->assertOk()->getContent();
        $this->assertStringContainsString('<details class="admin-form-standard-section lf-framework-create__advanced"', $html, 'Code and scale sit in the closed Advanced drawer.');
        $this->assertStringContainsString('value="proficient"', $html);
    }

    public function test_adding_a_competency_seeds_one_draft_from_the_published_version(): void
    {
        $framework = $this->createFramework('seed-draft');
        $definition = $this->createDefinition($framework->id, 'N-1');
        $v1 = $this->createVersion($framework->id, 'v1');
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$v1->id}/nodes", [
            'framework_version_id' => (string) $v1->id, 'node_definition_id' => (string) $definition->id,
            'sequence' => '7', 'criteria_json' => '{"stage":"one"}',
        ])->assertSessionHasNoErrors();
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$v1->id}/publish")->assertSessionHasNoErrors();

        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/competencies", [
            'canonical_name' => 'Giao tiếp cơ bản', 'node_type' => 'competency', 'code' => '', 'description' => '',
        ])->assertSessionHasNoErrors();
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/competencies", [
            'canonical_name' => 'Giao tiếp cơ bản', 'node_type' => 'objective', 'code' => '', 'description' => '',
        ])->assertSessionHasNoErrors();

        $drafts = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->where('status', 'draft_snapshot')->get();
        $this->assertCount(1, $drafts, 'Both additions land in the same working draft.');
        $this->assertSame(2, (int) $drafts[0]->version_number);
        $nodes = DB::table('core_learning_nodes')->where('framework_version_id', $drafts[0]->id)->orderBy('sequence')->get();
        $this->assertSame(['N-1', 'GIAO-TIEP-CO-BAN', 'GIAO-TIEP-CO-BAN-2'], $nodes->pluck('code_snapshot')->all());
        $this->assertSame(7, (int) $nodes[0]->sequence, 'The seeded Node keeps the published order.');
        $this->assertSame(['stage' => 'one'], json_decode($nodes[0]->criteria_snapshot, true), 'The seeded Node keeps its criteria.');
        $this->assertSame(1, DB::table('core_learning_nodes')->where('framework_version_id', $v1->id)->count(), 'The published Version is untouched.');

        $html = $this->admin()->get($this->host."/admin/learning-frameworks/{$framework->id}")->assertOk()->getContent();
        $this->assertStringContainsString('data-lf-framework-draft="'.$drafts[0]->id.'"', $html);
        $this->assertStringContainsString("/admin/learning-frameworks/{$framework->id}/versions/{$drafts[0]->id}/publish", $html);
        $this->assertStringContainsString('Xuất bản Phiên bản 2', $html);
        $this->assertStringContainsString('Mới nhất · Phiên bản 1', $html);
        $this->assertStringNotContainsString('stage', $html, 'Criteria JSON is not shown.');

        // The list shows the version in use, its competency count and the open draft.
        $row = $this->admin()->get($this->host.'/admin/learning-frameworks')->assertOk()->getContent();
        preg_match('#<tr data-lf-framework-row="'.$framework->id.'">.*?</tr>#s', $row, $match);
        $this->assertNotEmpty($match);
        $this->assertStringContainsString('Phiên bản 1', $match[0]);
        $this->assertStringContainsString('1 năng lực', $match[0]);
        $this->assertStringContainsString('Có bản nháp', $match[0]);
    }

    public function test_an_unplaced_definition_joins_the_draft_once(): void
    {
        $framework = $this->createFramework('unplaced');
        $definition = $this->createDefinition($framework->id, 'LOOSE-1');

        $html = $this->admin()->get($this->host."/admin/learning-frameworks/{$framework->id}")->assertOk()->getContent();
        $this->assertStringContainsString('data-lf-framework-unplaced="'.$definition->id.'"', $html);

        $url = $this->host."/admin/learning-frameworks/{$framework->id}/competencies";
        $this->admin()->post($url, ['node_definition_id' => (string) $definition->id])->assertSessionHasNoErrors();
        $this->admin()->post($url, ['node_definition_id' => (string) $definition->id])->assertSessionHasErrors('learning');

        $draft = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->firstOrFail();
        $this->assertSame(1, DB::table('core_learning_nodes')->where('framework_version_id', $draft->id)->count());
        $html = $this->admin()->get($this->host."/admin/learning-frameworks/{$framework->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('data-lf-framework-unplaced', $html);
        $this->assertStringContainsString('data-lf-framework-item="'.$definition->id.'"', $html);
    }

    public function test_a_definition_from_another_framework_cannot_join_the_draft(): void
    {
        $target = $this->createFramework('join-target');
        $other = $this->createFramework('join-other');
        $foreign = $this->createDefinition($other->id, 'FOREIGN-1');

        $this->admin()->post($this->host."/admin/learning-frameworks/{$target->id}/competencies", [
            'node_definition_id' => (string) $foreign->id,
        ])->assertSessionHasErrors('learning');

        $this->assertSame(0, DB::table('core_learning_nodes')->where('framework_id', $target->id)->count());
        $this->assertSame(0, DB::table('core_learning_framework_versions')->where('framework_id', $target->id)->count(), 'A refused addition leaves no draft behind.');
    }

    public function test_discarding_the_draft_removes_only_what_it_brought(): void
    {
        $framework = $this->createFramework('discard');
        $kept = $this->createDefinition($framework->id, 'KEPT-1');
        $url = $this->host."/admin/learning-frameworks/{$framework->id}/competencies";
        $this->admin()->post($url, ['node_definition_id' => (string) $kept->id])->assertSessionHasNoErrors();
        $v1 = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->firstOrFail();
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$v1->id}/publish")->assertSessionHasNoErrors();

        $loose = $this->createDefinition($framework->id, 'LOOSE-1');
        $this->admin()->post($url, ['canonical_name' => 'Brand new', 'node_type' => 'competency'])->assertSessionHasNoErrors();
        $this->admin()->post($url, ['node_definition_id' => (string) $loose->id])->assertSessionHasNoErrors();
        $draft = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->where('status', 'draft_snapshot')->firstOrFail();
        $this->assertStringContainsString('data-lf-framework-discard', $this->admin()->get($this->host."/admin/learning-frameworks/{$framework->id}")->getContent());

        $this->admin()->delete($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$draft->id}")->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('core_learning_framework_versions', ['id' => $draft->id]);
        $this->assertSame(0, DB::table('core_learning_nodes')->where('framework_version_id', $draft->id)->count());
        $this->assertDatabaseMissing('core_learning_node_definitions', ['framework_id' => $framework->id, 'canonical_name' => 'Brand new']);
        $this->assertDatabaseHas('core_learning_node_definitions', ['id' => $kept->id]);
        // A Definition older than the draft is kept.
        $this->assertDatabaseHas('core_learning_node_definitions', ['id' => $loose->id]);
        $this->assertSame(1, DB::table('core_learning_nodes')->where('framework_version_id', $v1->id)->count(), 'The published Version is untouched.');
        $html = $this->admin()->get($this->host."/admin/learning-frameworks/{$framework->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('data-lf-framework-draft', $html);
        $this->assertStringContainsString('data-lf-framework-unplaced="'.$loose->id.'"', $html);
    }

    public function test_a_published_version_cannot_be_discarded_and_a_teacher_cannot_discard(): void
    {
        $framework = $this->createFramework('discard-guard');
        $definition = $this->createDefinition($framework->id, 'G-1');
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/competencies", ['node_definition_id' => (string) $definition->id])->assertSessionHasNoErrors();
        $version = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->firstOrFail();
        $teacher = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'teacher', 'email' => 'teacher-discard@authoring-http.test',
            'password' => bcrypt('password'), 'role' => 'teacher', 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->actingAs($teacher)->delete($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}")->assertForbidden();
        $this->assertDatabaseHas('core_learning_framework_versions', ['id' => $version->id]);

        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}/publish")->assertSessionHasNoErrors();
        $this->admin()->delete($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}")->assertSessionHasErrors('learning');
        $this->assertDatabaseHas('core_learning_framework_versions', ['id' => $version->id, 'status' => 'published']);
    }

    public function test_editing_the_scale_applies_to_later_versions_only(): void
    {
        $framework = $this->createFramework('scale-edit');
        $definition = $this->createDefinition($framework->id, 'S-1');
        $url = $this->host."/admin/learning-frameworks/{$framework->id}/competencies";
        $this->admin()->post($url, ['node_definition_id' => (string) $definition->id])->assertSessionHasNoErrors();
        $v1 = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->firstOrFail();
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$v1->id}/publish")->assertSessionHasNoErrors();

        $html = $this->admin()->get($this->host."/admin/learning-frameworks/{$framework->id}?tab=scale")->assertOk()->getContent();
        $this->assertStringContainsString('data-lf-framework-scale-form', $html);
        $this->assertStringContainsString('name="mastery_scale_version" value="2"', $html, 'Saving the scale form moves to the next scale version.');

        // Exactly what the scale form sends after adding a level.
        $this->admin()->put($this->host."/admin/learning-frameworks/{$framework->id}", [
            'code' => 'scale-edit', 'name' => 'Framework scale-edit', 'description' => '',
            'mastery_scale_key' => 'lf-default', 'mastery_scale_version' => '2',
            'mastery_scale' => ['levels' => [
                ['key' => 'not_started', 'threshold' => '0'],
                ['key' => 'developing', 'threshold' => '0.4'],
                ['key' => 'proficient', 'threshold' => '0.7'],
                ['key' => 'mastered', 'threshold' => '0.85'],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, count(json_decode((string) DB::table('core_learning_framework_versions')->where('id', $v1->id)->value('mastery_scale_snapshot'), true)['levels']), 'The published Version keeps its scale.');
        $this->admin()->post($url, ['canonical_name' => 'After scale', 'node_type' => 'competency'])->assertSessionHasNoErrors();
        $v2 = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->where('status', 'draft_snapshot')->firstOrFail();
        $this->assertSame('2', (string) $v2->mastery_scale_version);
        $this->assertSame(4, count(json_decode((string) $v2->mastery_scale_snapshot, true)['levels']), 'A new draft takes the new scale.');

        // A broken scale is refused with a readable message on the scale field.
        $this->admin()->put($this->host."/admin/learning-frameworks/{$framework->id}", [
            'code' => 'scale-edit', 'name' => 'Framework scale-edit', 'description' => '',
            'mastery_scale_key' => 'lf-default', 'mastery_scale_version' => '3',
            'mastery_scale' => ['levels' => [
                ['key' => 'a', 'threshold' => '0'],
                ['key' => 'b', 'threshold' => '0.9'],
                ['key' => 'c', 'threshold' => '0.5'],
            ]],
        ])->assertSessionHasErrors('mastery_scale');
        $this->assertSame('2', (string) DB::table('core_learning_frameworks')->where('id', $framework->id)->value('default_mastery_scale_version'));
    }

    public function test_version_history_shows_each_version_with_its_own_competencies_and_scale(): void
    {
        $framework = $this->createFramework('history-view');
        $definition = $this->createDefinition($framework->id, 'H-1');
        $url = $this->host."/admin/learning-frameworks/{$framework->id}";
        $this->admin()->post($url.'/competencies', ['node_definition_id' => (string) $definition->id])->assertSessionHasNoErrors();
        $v1 = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->firstOrFail();
        $this->admin()->post($url."/versions/{$v1->id}/publish")->assertSessionHasNoErrors();
        $this->admin()->put($url, [
            'code' => 'history-view', 'name' => 'Framework history-view', 'description' => '',
            'mastery_scale_key' => 'lf-default', 'mastery_scale_version' => '2',
            'mastery_scale' => ['levels' => [
                ['key' => 'not_started', 'threshold' => '0'],
                ['key' => 'developing', 'threshold' => '0.4'],
                ['key' => 'proficient', 'threshold' => '0.7'],
                ['key' => 'mastered', 'threshold' => '0.85'],
            ]],
        ])->assertSessionHasNoErrors();
        $this->admin()->post($url.'/competencies', ['canonical_name' => 'Added later', 'node_type' => 'competency'])->assertSessionHasNoErrors();
        $v2 = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->where('status', 'draft_snapshot')->firstOrFail();
        $this->admin()->post($url."/versions/{$v2->id}/publish")->assertSessionHasNoErrors();

        $content = $this->admin()->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#aria-current="page"\s+data-lf-framework-tab="content"#', $content, 'Content is the default tab.');
        $this->assertStringNotContainsString('data-lf-framework-scale-form', $content, 'The scale lives in its own tab.');
        $this->assertStringNotContainsString('data-lf-framework-version-view', $content);

        $history = $this->admin()->get($url.'?tab=history')->assertOk()->getContent();
        $this->assertStringContainsString('data-lf-framework-version="'.$v1->id.'"', $history);
        $this->assertStringContainsString('x-data="{ expanded: null }"', $history, 'History lists Versions; none opens by itself.');

        $oldPage = $this->admin()->get($url.'?version='.$v1->id)->assertOk()->getContent();
        $this->assertStringContainsString('x-data="{ expanded: '.$v1->id.' }"', $oldPage, '?version= opens that Version.');
        $old = $this->versionView($oldPage, $v1->id);
        $this->assertStringContainsString('data-lf-framework-version-item="'.$definition->id.'"', $old);
        $this->assertStringNotContainsString('Added later', $old, 'An old Version shows only its own competencies.');
        $this->assertStringContainsString('≥ 80%', $old, 'An old Version shows the scale frozen with it.');
        $this->assertStringNotContainsString('≥ 85%', $old);
        $this->assertDoesNotMatchRegularExpression('#<form[^>]*method="POST"#i', $old, 'A published Version is read only; only the client-side search form is there.');

        $new = $this->versionView($this->admin()->get($url.'?version='.$v2->id)->assertOk()->getContent(), $v2->id);
        $this->assertStringContainsString('Added later', $new);
        $this->assertStringContainsString('≥ 85%', $new);

        $other = $this->createFramework('history-other');
        $otherDefinition = $this->createDefinition($other->id, 'O-1');
        $this->admin()->post($this->host."/admin/learning-frameworks/{$other->id}/competencies", ['node_definition_id' => (string) $otherDefinition->id])->assertSessionHasNoErrors();
        $foreign = DB::table('core_learning_framework_versions')->where('framework_id', $other->id)->firstOrFail();
        $this->assertStringNotContainsString('data-lf-framework-version-view', $this->admin()->get($url.'?version='.$foreign->id)->assertOk()->getContent(),
            'A Version of another framework is never shown here.');
    }

    public function test_a_teacher_cannot_add_a_competency(): void
    {
        $framework = $this->createFramework('teacher-denied');
        $teacher = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'teacher', 'email' => 'teacher@authoring-http.test',
            'password' => bcrypt('password'), 'role' => 'teacher', 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->actingAs($teacher)->post($this->host."/admin/learning-frameworks/{$framework->id}/competencies", [
            'canonical_name' => 'Nope', 'node_type' => 'competency',
        ])->assertForbidden();

        $this->assertSame(0, DB::table('core_learning_node_definitions')->where('framework_id', $framework->id)->count());
    }

    public function test_a_published_definition_offers_only_its_description_for_editing(): void
    {
        $framework = $this->createFramework('locked-view');
        $definition = $this->createDefinition($framework->id, 'LOCK-1');
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/competencies", [
            'node_definition_id' => (string) $definition->id,
        ])->assertSessionHasNoErrors();
        $draft = DB::table('core_learning_framework_versions')->where('framework_id', $framework->id)->firstOrFail();
        $this->admin()->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$draft->id}/publish")->assertSessionHasNoErrors();

        $html = $this->admin()->get($this->host."/admin/learning-frameworks/{$framework->id}")->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="canonical_name" value="Definition LOCK-1">', $html);
        $this->assertStringNotContainsString('id="competency-'.$definition->id.'-name"', $html);
        $this->assertStringContainsString('id="competency-'.$definition->id.'-description"', $html);
    }

    // ------------------------------------------------------------------ Boundary

    /**
     * @return array<string, array<string, string>>
     */
    public static function prohibitedFieldProvider(): array
    {
        return [
            'tenant ownership' => ['customer_id', '99'],
            'lifecycle status' => ['status', 'archived'],
            'audit column' => ['created_by', '99'],
        ];
    }

    #[DataProvider('prohibitedFieldProvider')]
    public function test_prohibited_fields_are_refused_before_any_write(string $field, string $value): void
    {
        $payload = $this->frameworkPayload('prohibited') + [$field => $value];

        $this->admin()
            ->post($this->host.'/admin/learning-frameworks', $payload)
            ->assertSessionHasErrors($field);

        $this->assertDatabaseMissing('core_learning_frameworks', ['code' => 'prohibited']);
    }

    /**
     * The route names the Framework and the body names it again. They are
     * compared rather than trusted, so a body pointing at another Framework
     * cannot smuggle a write past the route the actor was authorised for.
     */
    public function test_a_body_naming_another_framework_is_refused(): void
    {
        $target = $this->createFramework('mismatch-target');
        $other = $this->createFramework('mismatch-other');

        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$target->id}/versions", [
                'framework_id' => (string) $other->id,
                'version_code' => 'v1', 'title' => 'Smuggled', 'description' => '',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('core_learning_framework_versions', ['version_code' => 'v1']);
    }

    // ------------------------------------------------------------------ Fixtures

    private function admin(): self
    {
        $this->actingAs(User::findOrFail($this->adminId));

        return $this;
    }

    /**
     * A form-shaped Framework payload: every value a string, because that is
     * all a form can send.
     *
     * Three levels rather than two. The create form currently renders a fixed
     * pair and offers no control to add a third, so this is the shape the edit
     * form produces for an existing three-level Framework — deliberately, since
     * the level count is the part of the scale most likely to move and the
     * least covered elsewhere.
     *
     * @return array<string, mixed>
     */
    private function versionView(string $html, int $versionId): string
    {
        // The panel's x-data holds arrow functions, so cut from its marker instead of matching the opening tag.
        $start = strpos($html, 'data-lf-framework-version-view="'.$versionId.'"');
        $this->assertNotFalse($start, 'The Version panel is rendered.');
        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function frameworkPayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Framework '.$code,
            'description' => '',
            'mastery_scale_key' => 'lf-default',
            'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [
                ['key' => 'not_started', 'threshold' => '0'],
                ['key' => 'developing', 'threshold' => '0.5'],
                ['key' => 'mastered', 'threshold' => '0.8'],
            ]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function definitionPayload(int $frameworkId, string $code): array
    {
        return [
            'framework_id' => (string) $frameworkId,
            'code' => $code,
            'node_type' => 'competency',
            'canonical_name' => 'Definition '.$code,
            'description' => '',
        ];
    }

    private function createFramework(string $code): object
    {
        $this->admin()
            ->post($this->host.'/admin/learning-frameworks', $this->frameworkPayload($code))
            ->assertSessionHasNoErrors();

        return DB::table('core_learning_frameworks')
            ->where('customer_id', $this->customerId)->where('code', $code)->firstOrFail();
    }

    private function createDefinition(int $frameworkId, string $code): object
    {
        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$frameworkId}/definitions",
                $this->definitionPayload($frameworkId, $code))
            ->assertSessionHasNoErrors();

        return DB::table('core_learning_node_definitions')
            ->where('customer_id', $this->customerId)->where('framework_id', $frameworkId)
            ->where('code', $code)->firstOrFail();
    }

    private function createVersion(int $frameworkId, string $versionCode): object
    {
        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$frameworkId}/versions", [
                'framework_id' => (string) $frameworkId,
                'version_code' => $versionCode,
                'title' => 'Version '.$versionCode,
                'description' => '',
            ])
            ->assertSessionHasNoErrors();

        return DB::table('core_learning_framework_versions')
            ->where('customer_id', $this->customerId)->where('framework_id', $frameworkId)
            ->where('version_code', $versionCode)->firstOrFail();
    }

    /**
     * Explicit sequence rather than the blank box: this helper builds fixtures
     * for other assertions, and it must not fail for a reason under test
     * somewhere else in this file.
     */
    private function createNode(int $frameworkId, int $versionId, int $definitionId): object
    {
        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$frameworkId}/versions/{$versionId}/nodes", [
                'framework_version_id' => (string) $versionId,
                'node_definition_id' => (string) $definitionId,
                'sequence' => '1',
                'criteria_json' => '',
            ])
            ->assertSessionHasNoErrors();

        return DB::table('core_learning_nodes')
            ->where('customer_id', $this->customerId)->where('framework_version_id', $versionId)
            ->where('node_definition_id', $definitionId)->firstOrFail();
    }

    /**
     * A Framework with one published Version carrying one Node, built entirely
     * over HTTP.
     *
     * @return array{framework: object, definition: object, version: object, node: object}
     */
    private function publishedFixture(string $code): array
    {
        $framework = $this->createFramework($code);
        $definition = $this->createDefinition($framework->id, 'D-'.$code);
        $version = $this->createVersion($framework->id, 'v1');
        $node = $this->createNode($framework->id, $version->id, $definition->id);

        $this->admin()
            ->post($this->host."/admin/learning-frameworks/{$framework->id}/versions/{$version->id}/publish")
            ->assertSessionHasNoErrors();

        return compact('framework', 'definition', 'version', 'node');
    }
}
