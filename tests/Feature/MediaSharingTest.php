<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaService;
use App\Services\MediaShareService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MediaSharingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://tenant-a.localhost/admin/media';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
            'media.disk' => 'media_local',
            'media.bucket' => 'lf-test-media',
            'media.region' => 'ap-southeast-1',
        ]);

        Carbon::setTestNow('2026-07-08 10:00:00');
        Storage::fake('media_local');
        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::set(null);

        parent::tearDown();
    }

    public function test_admin_sets_the_scope_and_each_change_is_kept_as_history(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $media = $this->upload($admin, 'Doc');

        $this->actingAs($admin)->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[0]->id, $teachers[1]->id]])
            ->assertOk()->assertJsonPath('state.scope', 'teachers')->assertJsonCount(2, 'state.user_ids');
        $this->assertSame(__('lf.LF_media_share_saved'), session('success'), 'flashed, so the reloaded list shows it as a toast');
        $this->assertSame(2, DB::table('media_file_shares')->where('media_file_id', $media)->whereNull('revoked_at')->count());

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[1]->id]])->assertOk();
        $this->assertSame(1, DB::table('media_file_shares')->where('media_file_id', $media)->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::table('media_file_shares')->where('media_file_id', $media)->whereNotNull('revoked_at')->count(), 'the revoked row stays');

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'all_teachers'])->assertOk()->assertJsonPath('state.scope', 'all_teachers');
        $this->assertDatabaseHas('media_file_shares', ['media_file_id' => $media, 'grantee_type' => 'role', 'grantee_role' => 'teacher', 'revoked_at' => null]);

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'private'])->assertOk()->assertJsonPath('state.scope', 'private');
        $this->assertSame(0, DB::table('media_file_shares')->where('media_file_id', $media)->whereNull('revoked_at')->count());

        // Sharing again after a revoke starts a new row; the history rows are never rewritten.
        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[1]->id]])->assertOk();
        $this->assertSame(1, DB::table('media_file_shares')->where('media_file_id', $media)->whereNull('revoked_at')->count());
        $this->assertGreaterThanOrEqual(4, DB::table('media_file_shares')->where('media_file_id', $media)->count());
    }

    public function test_the_owners_note_travels_with_the_sharing_and_disappears_when_it_ends(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $media = $this->upload($admin, 'Noted doc');
        $url = self::URL."/{$media}/shares";
        $this->actingAs($admin);

        $this->putJson($url, ['scope' => 'all_teachers', 'note' => '  Audio bài nghe cấp 1 <b>tuần 3</b>  '])
            ->assertOk()->assertJsonPath('state.note', 'Audio bài nghe cấp 1 <b>tuần 3</b>');
        $this->assertSame('Audio bài nghe cấp 1 <b>tuần 3</b>', DB::table('media_files')->where('id', $media)->value('share_note'));

        // The teacher reads it in the library's usage popup (the data is JSON, so the markup is escaped) and in the picker.
        $this->actingAs($teachers[0])->get('https://tenant-a.localhost/teacher/media')->assertOk()
            ->assertSee('\\u003Cb\\u003E', false)->assertDontSee('<b>tu', false);
        $this->actingAs($teachers[0])->getJson('https://tenant-a.localhost/teacher/media/picker?type=document')
            ->assertJsonPath('items.0.note', 'Audio bài nghe cấp 1 <b>tuần 3</b>');

        // Changing only the note is not a revocation: it is allowed even while a teacher uses the Media.
        $this->actingAs($teachers[1]);
        app(MediaService::class)->attachUsage($media, 'course_activity', 901, 'document');
        $this->actingAs($admin)->putJson($url, ['scope' => 'all_teachers', 'note' => 'Dùng cho tuần 4'])->assertOk()->assertJsonPath('state.note', 'Dùng cho tuần 4');

        // Too long is refused; blank means no note; going private removes it.
        $this->putJson($url, ['scope' => 'all_teachers', 'note' => str_repeat('a', 301)])->assertStatus(422);
        $this->putJson($url, ['scope' => 'all_teachers', 'note' => "   \n "])->assertOk()->assertJsonPath('state.note', null);
        $this->putJson($url, ['scope' => 'all_teachers', 'note' => 'Again'])->assertOk();
        $this->actingAs($teachers[1]);
        app(MediaService::class)->detachUsage($media, 'course_activity', 901, 'document');
        $this->actingAs($admin)->putJson($url, ['scope' => 'private', 'note' => 'Should not stay'])->assertOk()->assertJsonPath('state.note', null);
        $this->assertNull(DB::table('media_files')->where('id', $media)->value('share_note'));
    }

    public function test_the_scope_and_the_recipients_are_validated_and_stay_inside_the_tenant(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $foreignTenant = $this->createTenant('tenant-b');
        $foreignTeacher = $this->createUser($foreignTenant, 'teacher');
        $otherAdmin = $this->createUser($customerId, 'customer_admin');
        $media = $this->upload($admin, 'Doc');
        $this->actingAs($admin);

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'everyone'])->assertStatus(422);
        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => []])->assertStatus(422);
        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$foreignTeacher->id]])->assertStatus(422);
        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$otherAdmin->id]])->assertStatus(422);
        $this->putJson(self::URL.'/999999/shares', ['scope' => 'private'])->assertNotFound();
        $this->assertSame(0, DB::table('media_file_shares')->count());

        // A Media of another tenant is not found, whoever asks.
        TenantContext::set((object) ['id' => $foreignTenant]);
        $foreignOwner = $this->createUser($foreignTenant, 'customer_admin');
        $foreignMedia = $this->upload($foreignOwner, 'Foreign');
        TenantContext::set((object) ['id' => $customerId]);
        $this->actingAs($admin)->putJson(self::URL."/{$foreignMedia}/shares", ['scope' => 'all_teachers'])->assertNotFound();
        $this->assertSame(0, DB::table('media_file_shares')->count());
    }

    public function test_only_the_owner_or_an_admin_may_change_the_sharing(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $service = app(MediaShareService::class);
        $ownMedia = $this->upload($teachers[0], 'Teacher own');
        $othersMedia = $this->upload($admin, 'Admin own');

        $state = $service->apply($teachers[0], $customerId, $ownMedia, 'teachers', [$teachers[1]->id]);
        $this->assertSame('teachers', $state['scope'], 'the owner shares their own Media');

        $this->expectException(HttpException::class);
        $service->apply($teachers[0], $customerId, $othersMedia, 'all_teachers');
    }

    public function test_revoking_is_refused_while_a_teacher_still_uses_the_media_and_names_who(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $media = $this->upload($admin, 'Doc');
        $this->actingAs($admin)->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[0]->id, $teachers[1]->id]])->assertOk();

        $this->actingAs($teachers[0]);
        app(MediaService::class)->attachUsage($media, 'course_activity', 501, 'document');
        app(MediaService::class)->attachUsage($media, 'course_activity', 502, 'document');
        $this->actingAs($admin);

        // The teacher who uses it cannot be removed; the other one can.
        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[1]->id]])
            ->assertStatus(409)
            ->assertJsonPath('blocked.0.id', $teachers[0]->id)
            ->assertJsonPath('blocked.0.places', 2)
            ->assertJsonPath('message', fn ($message) => str_contains($message, $teachers[0]->name));
        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'private'])->assertStatus(409);
        $this->assertSame(2, DB::table('media_file_shares')->where('media_file_id', $media)->whereNull('revoked_at')->count(), 'nothing changed');

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[0]->id]])->assertOk();

        // Once the teacher stops using it, it can be revoked.
        $this->actingAs($teachers[0]);
        app(MediaService::class)->detachUsage($media, 'course_activity', 501, 'document');
        app(MediaService::class)->detachUsage($media, 'course_activity', 502, 'document');
        $this->actingAs($admin)->putJson(self::URL."/{$media}/shares", ['scope' => 'private'])->assertOk();
    }

    public function test_leaving_the_whole_group_is_refused_for_any_teacher_who_uses_it_but_a_chosen_one_may_stay(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $media = $this->upload($admin, 'Doc');
        $this->actingAs($admin)->putJson(self::URL."/{$media}/shares", ['scope' => 'all_teachers'])->assertOk();

        $this->actingAs($teachers[2]);
        app(MediaService::class)->attachUsage($media, 'course_activity', 601, 'document');
        $this->actingAs($admin);

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[0]->id]])
            ->assertStatus(409)->assertJsonPath('blocked.0.id', $teachers[2]->id);
        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[0]->id, $teachers[2]->id]])->assertOk();
        $this->assertDatabaseHas('media_file_shares', ['media_file_id' => $media, 'grantee_role' => 'teacher', 'revoked_at' => Carbon::now()]);
    }

    public function test_a_teacher_sees_their_own_media_and_what_was_shared_with_them_only(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $service = app(MediaShareService::class);
        $own = $this->upload($teachers[0], 'Own');
        $private = $this->upload($admin, 'Private');
        $direct = $this->upload($admin, 'Direct');
        $group = $this->upload($admin, 'Group');
        $revoked = $this->upload($admin, 'Revoked');
        $others = $this->upload($teachers[1], 'Other teacher private');
        $deleted = $this->upload($teachers[0], 'Deleted');
        DB::table('media_files')->where('id', $deleted)->update(['status' => 'deleted']);

        $service->apply($admin, $customerId, $direct, 'teachers', [$teachers[0]->id]);
        $service->apply($admin, $customerId, $group, 'all_teachers');
        $service->apply($admin, $customerId, $revoked, 'teachers', [$teachers[0]->id]);
        $service->apply($admin, $customerId, $revoked, 'private');
        $service->apply($admin, $customerId, $others, 'teachers', [$teachers[2]->id]);

        $visible = fn (User $teacher) => $service->visibleToTeacher($customerId, $teacher->id)->pluck('media_files.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$own, $direct, $group], $visible($teachers[0]));
        $this->assertSame([$group, $others], $visible($teachers[1]));
        $this->assertSame([$group, $others], $visible($teachers[2]));

        // A teacher added later sees the group share too.
        $later = $this->createUser($customerId, 'teacher');
        $this->assertSame([$group], $visible($later));

        // Another tenant never sees anything of this one.
        $foreignTenant = $this->createTenant('tenant-b');
        $foreignTeacher = $this->createUser($foreignTenant, 'teacher');
        $this->assertSame([], $service->visibleToTeacher($foreignTenant, $foreignTeacher->id)->pluck('media_files.id')->all());
    }

    public function test_the_database_allows_one_active_share_per_recipient_only(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $media = $this->upload($admin, 'Doc');
        $row = fn () => [
            'customer_id' => $customerId, 'media_file_id' => $media, 'grantee_type' => 'user', 'grantee_user_id' => $teachers[0]->id,
            'grantee_role' => null, 'grantee_key' => 'u:'.$teachers[0]->id, 'shared_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('media_file_shares')->insert($row());

        $this->expectException(QueryException::class);
        DB::table('media_file_shares')->insert($row());
    }

    public function test_revoked_rows_do_not_block_a_new_share_and_the_list_shows_the_sharing_state(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $media = $this->upload($admin, 'Listed doc');
        $this->actingAs($admin);

        // Sharing is told by one sign on the share button (a dot), never by more text in the row or a long hover text.
        $dots = fn () => $this->xpath($this->get(self::URL)->assertOk()->getContent())->query('//button[@data-shared]')->length;
        $this->assertSame(0, $dots());

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[0]->id, $teachers[1]->id]])->assertOk();
        $this->assertSame(1, $dots());
        $state = html_entity_decode($this->get(self::URL)->getContent(), ENT_QUOTES);
        $this->assertStringContainsString('\\u0022scope\\u0022:\\u0022teachers\\u0022', $state, 'the share state travels with the row into the popup');

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'all_teachers'])->assertOk();
        $this->assertSame(1, $dots());
        $this->assertStringContainsString('\\u0022scope\\u0022:\\u0022all_teachers\\u0022', html_entity_decode($this->get(self::URL)->getContent(), ENT_QUOTES));

        $this->putJson(self::URL."/{$media}/shares", ['scope' => 'private'])->assertOk();
        $this->assertSame(0, $dots());
    }

    public function test_the_teacher_library_lists_own_and_shared_media_only_and_labels_where_each_comes_from(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $service = app(MediaShareService::class);
        $own = $this->upload($teachers[0], 'Mine only');
        $direct = $this->upload($teachers[1], 'From B direct');
        $group = $this->upload($admin, 'From admin group');
        $this->upload($teachers[1], 'B private');
        $this->upload($admin, 'Admin private');
        $service->apply($teachers[1], $customerId, $direct, 'teachers', [$teachers[0]->id]);
        $service->apply($admin, $customerId, $group, 'all_teachers');
        $url = 'https://tenant-a.localhost/teacher/media';

        $html = $this->actingAs($teachers[0])->get($url)->assertOk()
            ->assertSeeText('Mine only')->assertSeeText('From B direct')->assertSeeText('From admin group')
            ->assertDontSeeText('B private')->assertDontSeeText('Admin private')
            ->assertSeeText(__('lf.LF_media_source_mine'))
            ->assertSeeText($teachers[1]->name)
            ->assertSee(__('lf.LF_media_source_shared_tip', ['name' => $teachers[1]->name, 'email' => $teachers[1]->email]), false)
            ->getContent();

        $xpath = $this->xpath($html);
        $this->assertSame(0, $xpath->query('//button[@data-ui-cleanup-open]')->length, 'the clean-up is an admin tool');
        $this->assertSame(0, $xpath->query('//*[@id="owner_type"]')->length, 'no tenant-wide "where used" filters for teachers');
        $this->assertSame(1, $xpath->query('//select[@id="source"]')->length);
        $this->assertSame(1, $xpath->query('//button[starts-with(@data-tooltip, "'.__('lf.LF_media_file_action_share').'")]')->length, 'only the own Media can be shared');
        $this->assertSame(1, $xpath->query('//input[@data-ui-bulk-item and @value="'.$own.'"]')->length, 'own unused Media can be selected for deletion');
        $this->assertSame(2, $xpath->query('//span[@class="ui-bulk-lock"]/input[@disabled]')->length, 'someone else\'s Media is locked');

        // Source filter and the tab counts follow the same visibility.
        $this->actingAs($teachers[0])->get($url.'?source=mine')->assertSeeText('Mine only')->assertDontSeeText('From B direct');
        $this->actingAs($teachers[0])->get($url.'?source=shared')->assertDontSeeText('Mine only')->assertSeeText('From B direct')->assertSeeText('From admin group');
        $this->assertSame('3', trim($this->xpath($this->actingAs($teachers[0])->get($url)->getContent())->query('//nav[contains(@class,"mk-tabs")]//a[1]/span')->item(0)->textContent));

        // Admins still see everything, and the other teacher sees theirs.
        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/media')->assertSeeText('B private')->assertSeeText('Admin private');
        $this->actingAs($teachers[2])->get($url)->assertDontSeeText('Mine only')->assertSeeText('From admin group');
    }

    public function test_media_can_be_filtered_by_how_it_is_shared_and_the_share_state_shows_on_the_action_buttons(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $service = app(MediaShareService::class);
        $private = $this->upload($admin, 'Only me');
        $chosen = $this->upload($admin, 'To chosen');
        $everyone = $this->upload($admin, 'To everyone');
        $mixed = $this->upload($admin, 'Chosen then group');
        $service->apply($admin, $customerId, $chosen, 'teachers', [$teachers[0]->id], 'Week 3 listening');
        $service->apply($admin, $customerId, $everyone, 'all_teachers');
        $service->apply($admin, $customerId, $mixed, 'teachers', [$teachers[1]->id]);
        $service->apply($admin, $customerId, $mixed, 'all_teachers');

        $shown = fn (string $query) => collect($this->actingAs($admin)->get(self::URL.$query)->assertOk()->getContent())
            ->map(fn (string $html) => collect(['Only me', 'To chosen', 'To everyone', 'Chosen then group'])->filter(fn (string $name) => str_contains($html, '>'.$name.'<'))->values()->all())->first();

        $this->assertSame(['Only me'], $shown('?share=private'));
        $this->assertSame(['To chosen'], $shown('?share=teachers'));
        $this->assertSame(['To everyone', 'Chosen then group'], $shown('?share=all_teachers'));
        $this->assertCount(4, $shown(''));
        $this->assertCount(4, $shown('?share=nonsense'), 'an unknown value filters nothing');

        // The share button of a shared Media carries a dot; the note is read in the popups, not on the buttons.
        $html = $this->actingAs($admin)->get(self::URL.'?share=teachers')->getContent();
        $this->assertSame(1, $this->xpath($html)->query('//button[@data-shared and starts-with(@data-tooltip, "'.__('lf.LF_media_file_action_share').'")]')->length);
        $this->assertSame(0, $this->xpath($html)->query('//button[contains(@data-tooltip, "Week 3")]')->length);
        $this->assertStringContainsString('Week 3 listening', html_entity_decode($html, ENT_QUOTES));

        // A teacher filters by origin and by what of theirs is shared.
        $own = $this->upload($teachers[0], 'Teacher private');
        $ownShared = $this->upload($teachers[0], 'Teacher shared');
        $service->apply($teachers[0], $customerId, $ownShared, 'teachers', [$teachers[1]->id]);
        $base = 'https://tenant-a.localhost/teacher/media';
        $names = fn (string $source) => collect(['Teacher private', 'Teacher shared', 'To chosen', 'To everyone', 'Chosen then group'])
            ->filter(fn (string $name) => str_contains($this->actingAs($teachers[0])->get($base.'?source='.$source)->getContent(), '>'.$name.'<'))->values()->all();
        $this->assertSame(['Teacher private'], $names('mine_private'));
        $this->assertSame(['Teacher shared'], $names('mine_shared'));
        $this->assertSame(['Teacher private', 'Teacher shared'], $names('mine'));
        $this->assertSame(['To chosen', 'To everyone', 'Chosen then group'], $names('shared'));
        $this->assertNotEmpty($own);
        $this->assertNotEmpty($private);
    }

    public function test_the_alpine_state_of_the_library_is_not_cut_short_by_stray_quotes(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $this->upload($teachers[0], 'Any doc');

        foreach ([[$admin, self::URL], [$teachers[0], 'https://tenant-a.localhost/teacher/media']] as [$user, $url]) {
            $xpath = $this->xpath($this->actingAs($user)->get($url)->assertOk()->getContent());
            $state = (string) $xpath->query('//*[@id="media-list"]/@x-data')->item(0)?->nodeValue;
            // A raw double quote inside the attribute would end it early and leave Alpine with a broken object.
            $this->assertStringContainsString('closeUsage()', $state, $url);
            $this->assertStringEndsWith('}', trim($state), $url);
            $this->assertSame(substr_count($state, '{'), substr_count($state, '}'), 'balanced braces: '.$url);
        }
    }

    public function test_a_teacher_is_told_where_they_use_a_media_but_only_how_many_places_others_use_it(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $media = $this->upload($teachers[0], 'Shared by A');
        app(MediaShareService::class)->apply($teachers[0], $customerId, $media, 'all_teachers');
        $this->actingAs($teachers[1]);
        app(MediaService::class)->attachUsage($media, 'course_activity', 701, 'document');
        app(MediaService::class)->attachUsage($media, 'course_activity', 702, 'document');
        $this->actingAs($teachers[0]);
        app(MediaService::class)->attachUsage($media, 'course_activity', 703, 'document');

        $owner = $this->actingAs($teachers[0])->get('https://tenant-a.localhost/teacher/media')->assertOk()
            ->assertSeeText(__('lf.LF_media_file_status_used_by_you', ['count' => 1]))
            ->assertSeeText(__('lf.LF_media_file_status_used_by_others', ['count' => 2]));
        $this->assertSame(1, $this->usageItemCount($owner->getContent()), 'the owner sees their own place only');

        $borrower = $this->actingAs($teachers[1])->get('https://tenant-a.localhost/teacher/media')->assertOk()
            ->assertSeeText(__('lf.LF_media_file_status_used_by_you', ['count' => 2]))
            ->assertSeeText(__('lf.LF_media_file_status_used_by_others', ['count' => 1]));
        $this->assertSame(2, $this->usageItemCount($borrower->getContent()));
        $this->actingAs($teachers[2])->get('https://tenant-a.localhost/teacher/media')->assertSeeText(__('lf.LF_media_file_status_used_by_others', ['count' => 3]));
    }

    public function test_a_teacher_deletes_and_shares_only_their_own_media_through_the_teacher_routes(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $own = $this->upload($teachers[0], 'Own unused');
        $used = $this->upload($teachers[0], 'Own used');
        $others = $this->upload($admin, 'Admin media');
        $this->actingAs($teachers[0]);
        app(MediaService::class)->attachUsage($used, 'course_activity', 801, 'document');
        $base = 'https://tenant-a.localhost/teacher/media';

        $this->putJson("{$base}/{$others}/shares", ['scope' => 'all_teachers'])->assertForbidden();
        $this->putJson("{$base}/{$own}/shares", ['scope' => 'teachers', 'user_ids' => [$teachers[1]->id]])->assertOk()->assertJsonPath('state.scope', 'teachers');

        $this->deleteJson("{$base}/bulk", ['media_ids' => [$others]])->assertForbidden();
        $this->assertSame('ready', DB::table('media_files')->where('id', $others)->value('status'));
        $this->deleteJson("{$base}/bulk", ['media_ids' => [$used]])->assertStatus(409);
        $this->deleteJson("{$base}/bulk", ['media_ids' => [$own]])->assertOk();
        $this->assertSame('deleted', DB::table('media_files')->where('id', $own)->value('status'));

        // The admin-only routes are not there for teachers.
        $this->getJson('https://tenant-a.localhost/teacher/media/cleanup/scan')->assertNotFound();
        $this->getJson('https://tenant-a.localhost/admin/media')->assertForbidden();
    }

    private function usageItemCount(string $html): int
    {
        preg_match("/openUsage\\('[^']*', JSON\\.parse\\('(.*?)'\\), /s", html_entity_decode($html, ENT_QUOTES), $match);

        return count(json_decode(json_decode('"'.$match[1].'"'), true));
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($document);
    }

    /**
     * @return array{0: int, 1: User, 2: array<int, User>}
     */
    private function world(): array
    {
        $customerId = $this->createTenant();
        TenantContext::set((object) ['id' => $customerId]);
        $admin = $this->createUser($customerId, 'customer_admin');
        $teachers = [$this->createUser($customerId, 'teacher'), $this->createUser($customerId, 'teacher'), $this->createUser($customerId, 'teacher')];
        $this->actingAs($admin);

        return [$customerId, $admin, $teachers];
    }

    private int $uploads = 0;

    private function upload(User $user, string $name): int
    {
        // Unattached uploads with the same entity replace each other, so every upload gets its own entity id.
        $this->uploads++;

        return (int) app(MediaService::class)->uploadMedia(
            UploadedFile::fake()->createWithContent(str($name)->slug().'.pdf', '%PDF-1.4
'.$name.'
%%EOF'),
            ['file_type' => 'document', 'module' => 'media', 'entity_type' => 'library', 'entity_id' => $this->uploads, 'purpose' => 'upload', 'display_name' => $name],
            $user->id
        )->id;
    }

    private function createTenant(string $slug = 'tenant-a'): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
