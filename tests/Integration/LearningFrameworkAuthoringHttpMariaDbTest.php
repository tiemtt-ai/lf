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
            ->assertSessionHasErrors('learning');

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
            ->post($this->host."/admin/learning-frameworks/{$framework->id}/definitions", [
                'framework_id' => (string) $framework->id,
                'code' => 'DEFINITION-CODE-XYZ',
                'node_type' => 'competency',
                'description' => 'Submitted without canonical_name.',
            ])
            ->assertSessionHasErrors('canonical_name');

        $html = $this->admin()
            ->get($this->host."/admin/learning-frameworks/{$framework->id}")
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('DEFINITION-CODE-XYZ', $html);
        $this->assertStringContainsString('value="bleed-guard"', $html);
    }

    /**
     * Nothing stops a Framework holding more than one draft, and each draft
     * renders its own add-Node form. Sharing one element id across them made
     * every `<label for>` address the first form on the page.
     */
    public function test_each_draft_version_renders_its_own_new_node_element_ids(): void
    {
        $framework = $this->createFramework('dom-ids');
        $first = $this->createVersion($framework->id, 'v1');
        $second = $this->createVersion($framework->id, 'v2');
        $this->createDefinition($framework->id, 'N-1');

        $html = $this->admin()
            ->get($this->host."/admin/learning-frameworks/{$framework->id}")
            ->assertOk()->getContent();

        $this->assertStringContainsString("node-new-{$first->id}-definition", $html);
        $this->assertStringContainsString("node-new-{$second->id}-definition", $html);
        $this->assertStringNotContainsString('id="node-new-definition"', $html);
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
