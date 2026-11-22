<?php

namespace Tests\Feature;

use App\Events\LiveClassWhiteboardBroadcast;
use App\Models\User;
use App\Services\CourseCohortLifecycleService;
use App\Services\LiveClassSessionLifecycleService;
use App\Services\LiveClassWhiteboardLiveState;
use App\Services\MediaOutputProfile;
use App\Services\MediaProcessingOrchestrator;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-0002 § LiveClass Whiteboard Amendment. Phase 1: the teacher's Session
 * list, teacher start/complete, the board for teacher and administrator, and
 * the board's material list and signed URLs. Phase 2: the learner viewer,
 * channel authorization, the active-presenter lock, publishing, the cached
 * state and every way a board closes.
 */
class LiveClassWhiteboardTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);

        // 16:00 on 2026-07-04 in Asia/Ho_Chi_Minh: inside the 15:00–17:00 Sessions these tests start and end.
        Carbon::setTestNow(Carbon::parse('2026-07-04 16:00:00', 'Asia/Ho_Chi_Minh'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_routes_use_each_roles_own_middleware(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['teacher.live-sessions.index', 'teacher.live-sessions.start', 'teacher.live-sessions.complete',
            'teacher.live-sessions.board', 'teacher.live-sessions.board.materials', 'teacher.live-sessions.board.materials.url'] as $name) {
            $middleware = $routes->getByName($name)->gatherMiddleware();
            $this->assertContains('role:teacher', $middleware, $name);
            $this->assertContains('tenant', $middleware, $name);
            $this->assertContains('tenant.user', $middleware, $name);
        }
        foreach (['admin.course-cohorts.sessions.board', 'admin.course-cohorts.sessions.board.materials', 'admin.course-cohorts.sessions.board.materials.url'] as $name) {
            $middleware = $routes->getByName($name)->gatherMiddleware();
            $this->assertContains('role:customer_admin', $middleware, $name);
            $this->assertNotContains('role:teacher', $middleware, $name);
        }
        $this->assertNull($routes->getByName('teacher.course-cohorts.sessions.board'));
    }

    public function test_teacher_sees_only_their_upcoming_and_live_sessions(): void
    {
        $setup = $this->setupClass();
        $mine = $this->liveSession($setup, 'Mine today', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['teacher']);
        $this->liveSession($setup, 'Mine tomorrow', '2026-07-05 15:00:00', '2026-07-05 17:00:00', teacher: $setup['teacher']);
        $this->liveSession($setup, 'Mine yesterday', '2026-07-03 15:00:00', '2026-07-03 17:00:00', teacher: $setup['teacher']);
        $this->liveSession($setup, 'Mine live earlier', '2026-07-01 15:00:00', '2026-07-01 17:00:00', status: 'live', teacher: $setup['teacher']);
        $this->liveSession($setup, 'Mine completed', '2026-07-04 08:00:00', '2026-07-04 09:00:00', status: 'completed', teacher: $setup['teacher']);
        $this->liveSession($setup, 'Someone else', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['otherTeacher']);

        $response = $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions')->assertOk();

        $response->assertSee('Mine today')->assertSee('Mine tomorrow')->assertSee('Mine live earlier')
            ->assertDontSee('Mine yesterday')->assertDontSee('Mine completed')->assertDontSee('Someone else')
            ->assertSee(route('teacher.live-sessions.start', $mine), false);
        $this->assertSame(1, substr_count($response->getContent(), '/start'));

        // A missed Session and a taught one each have their own tab (Owner 2026-11-18); another teacher's stay out.
        $response->assertSee('class="course-template-tabs"', false);
        $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions?tab=overdue')->assertOk()
            ->assertSee('Mine yesterday')->assertDontSee('Mine today')->assertDontSee('Mine completed')->assertDontSee('Someone else')
            ->assertDontSee('/start', false);
        $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions?tab=taught')->assertOk()
            ->assertSee('Mine completed')->assertDontSee('Mine yesterday')->assertDontSee('Mine today')
            ->assertSee('mk-table-foot', false)
            ->assertDontSee('/start', false)->assertDontSee('/complete', false);
        $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions?tab[]=taught')->assertOk()->assertSee('Mine today');
    }

    public function test_teacher_starts_their_session_on_its_day_and_lands_on_the_board(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'Today', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['teacher']);

        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$session}/start")
            ->assertRedirect(route('teacher.live-sessions.board', $session));

        $row = DB::table('core_liveclass_sessions')->find($session);
        $this->assertSame('live', $row->status);
        $this->assertNotNull($row->actual_start_at);

        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$session}/board")
            ->assertOk()->assertSee('lf-wb-config', false)->assertSee($setup['cohortName']);
    }

    public function test_a_practice_session_starts_without_a_board(): void
    {
        // ADR-0022 Amendment 1.3, Đợt 4, Bước 1.
        $setup = $this->setupClass();
        DB::table('core_course_template_version_lessons')->where('id', $setup['lesson'])->update(['lesson_type' => 'practice']);
        $session = $this->liveSession($setup, 'Field trip', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['teacher']);
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['attendance_tool' => 'button', 'room_name_snapshot' => 'Sân trường']);
        $student = $this->learner($setup);

        // While a Session is still ahead the teacher may rename the place; the attendance tool is not a choice.
        $later = $this->liveSession($setup, 'Workshop', '2026-07-05 15:00:00', '2026-07-05 17:00:00', teacher: $setup['teacher']);
        DB::table('core_liveclass_sessions')->where('id', $later)->update(['attendance_tool' => 'button', 'room_name_snapshot' => 'Sân trường']);
        $this->actingAs($setup['teacher'])->put(self::HOST."/teacher/live-sessions/{$later}", [
            'operations_session_id' => $later, 'room_name' => 'Bảo tàng', 'address' => '', 'attendance_tool' => 'board',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Bảo tàng', 'button'], array_values((array) DB::table('core_liveclass_sessions')->where('id', $later)
            ->first(['room_name_snapshot', 'attendance_tool'])));
        $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions')->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_session_place'))
            ->assertDontSee('id="session-operations-'.$later.'-tool"', false);

        // The start confirmation posts into this page, not into a board window.
        $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions')->assertOk()
            ->assertSee("ask('start', \$el.dataset.url, {$session}, true)", false);
        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$session}/start")
            ->assertRedirect(route('teacher.live-sessions.index'))->assertSessionHas('success', __('lf.LF_liveclass_whiteboard_started'));
        $this->assertSame('live', DB::table('core_liveclass_sessions')->where('id', $session)->value('status'));

        // Live: it can be ended, but nobody has a board to open.
        $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions')->assertOk()
            ->assertSee(route('teacher.live-sessions.complete', $session), false)
            ->assertDontSee(route('teacher.live-sessions.board', $session), false);
        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$session}/board")->assertNotFound();
        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/board")->assertNotFound();
        $this->actingAs($student)->get(self::HOST."/my-courses/live-sessions/{$session}/board")->assertNotFound();
        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=sessions")->assertOk()
            ->assertDontSee("sessions/{$session}/board", false);
    }

    public function test_start_is_refused_outside_the_planned_local_day(): void
    {
        $setup = $this->setupClass();
        // 2026-07-05 00:30 in Ho Chi Minh is still 2026-07-04 in UTC: the Session timezone decides.
        $tomorrow = $this->liveSession($setup, 'Tomorrow', '2026-07-05 00:30:00', '2026-07-05 01:30:00', teacher: $setup['teacher']);

        $this->actingAs($setup['teacher'])->from(self::HOST.'/teacher/live-sessions')
            ->post(self::HOST."/teacher/live-sessions/{$tomorrow}/start")
            ->assertSessionHasErrors('lifecycle');

        $this->assertSame('scheduled', DB::table('core_liveclass_sessions')->where('id', $tomorrow)->value('status'));
    }

    public function test_start_opens_with_the_attendance_entry_and_the_end_with_the_planned_start(): void
    {
        $setup = $this->setupClass();
        // Now is 16:00. The default policy opens 15 minutes early: 18:00 opens at 17:45, 16:10 opened at 15:55.
        $later = $this->liveSession($setup, 'Later today', '2026-07-04 18:00:00', '2026-07-04 19:00:00', teacher: $setup['teacher']);
        $soon = $this->liveSession($setup, 'Soon', '2026-07-04 16:10:00', '2026-07-04 17:10:00', teacher: $setup['teacher']);
        $list = self::HOST.'/teacher/live-sessions';

        $page = $this->actingAs($setup['teacher'])->get($list)->assertOk()
            ->assertSee(route('teacher.live-sessions.start', $soon), false)
            ->assertDontSee(route('teacher.live-sessions.start', $later), false)
            ->assertSeeText(__('lf.LF_liveclass_whiteboard_start_from', ['time' => '17:45']));

        $this->actingAs($setup['teacher'])->from($list)->post("{$list}/{$later}/start")
            ->assertSessionHasErrors(['lifecycle' => __('lf.LF_liveclass_whiteboard_start_too_early', ['time' => '17:45'])]);
        $this->assertSame('scheduled', DB::table('core_liveclass_sessions')->where('id', $later)->value('status'));

        // Started in the early window, the Session cannot be ended before 16:10; from then on it can.
        $this->actingAs($setup['teacher'])->post("{$list}/{$soon}/start")->assertRedirect(route('teacher.live-sessions.board', $soon));
        $this->actingAs($setup['teacher'])->get($list)->assertDontSee(route('teacher.live-sessions.complete', $soon), false);
        $this->actingAs($setup['teacher'])->from($list)->post("{$list}/{$soon}/complete")
            ->assertSessionHasErrors(['lifecycle' => __('lf.LF_liveclass_whiteboard_complete_too_early', ['time' => '16:10'])]);
        $this->assertSame('live', DB::table('core_liveclass_sessions')->where('id', $soon)->value('status'));

        Carbon::setTestNow(Carbon::parse('2026-07-04 16:10:00', 'Asia/Ho_Chi_Minh'));
        $this->actingAs($setup['teacher'])->post("{$list}/{$soon}/complete")->assertRedirect(route('teacher.live-sessions.index'));
        $this->assertSame('completed', DB::table('core_liveclass_sessions')->where('id', $soon)->value('status'));
    }

    public function test_only_an_active_assigned_teacher_within_the_window_may_act(): void
    {
        $setup = $this->setupClass();
        $notMine = $this->liveSession($setup, 'Not mine', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['otherTeacher']);
        $windowed = $this->liveSession($setup, 'Windowed', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['teacher'],
            assignedFrom: '2026-07-04 17:30:00');

        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$notMine}/start")->assertNotFound();
        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$windowed}/start")->assertForbidden();

        $own = $this->liveSession($setup, 'Own', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['teacher']);
        DB::table('users')->where('id', $setup['teacher']->id)->update(['status' => 'inactive']);
        $this->actingAs($setup['teacher']->fresh())->post(self::HOST."/teacher/live-sessions/{$own}/start")->assertForbidden();

        $this->assertSame(['scheduled'], DB::table('core_liveclass_sessions')->whereIn('id', [$notMine, $windowed, $own])
            ->pluck('status')->unique()->values()->all());
    }

    public function test_a_session_of_another_tenant_is_not_found(): void
    {
        $setup = $this->setupClass();
        $other = $this->setupClass('tenant-b');
        $foreign = $this->liveSession($other, 'Foreign', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $other['teacher']);

        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$foreign}/board")->assertNotFound();
        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$foreign}/complete")->assertNotFound();
        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$other['cohort']}/sessions/{$foreign}/board")->assertNotFound();
        $this->assertSame('live', DB::table('core_liveclass_sessions')->where('id', $foreign)->value('status'));
    }

    public function test_board_opens_only_while_the_session_is_live_in_an_active_cohort(): void
    {
        $setup = $this->setupClass();
        $scheduled = $this->liveSession($setup, 'Scheduled', '2026-07-04 15:00:00', '2026-07-04 17:00:00', teacher: $setup['teacher']);
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);

        // A board that is not open sends the page back to the list with the reason, not a bare 403.
        $list = self::HOST.'/teacher/live-sessions';
        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$scheduled}/board")
            ->assertRedirect($list)->assertSessionHasErrors(['lifecycle' => __('lf.LF_liveclass_whiteboard_not_live')]);
        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$live}/board")->assertOk();

        DB::table('core_course_cohorts')->where('id', $setup['cohort'])->update(['status' => 'completed']);
        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$live}/board")
            ->assertRedirect($list)->assertSessionHasErrors(['lifecycle' => __('lf.LF_liveclass_whiteboard_ended')]);
        // The board's own endpoints and channel stay 403.
        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$live}/board/materials")->assertForbidden();
    }

    public function test_teacher_completes_a_live_session_and_the_board_closes(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);

        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$live}/complete")
            ->assertRedirect(route('teacher.live-sessions.index'));

        $row = DB::table('core_liveclass_sessions')->find($live);
        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->actual_end_at);
        // A stale "Mở bảng" after the end says so on the list.
        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$live}/board")
            ->assertRedirect(route('teacher.live-sessions.index'))->assertSessionHasErrors(['lifecycle' => __('lf.LF_liveclass_whiteboard_ended')]);
        $this->actingAs($setup['teacher'])->followingRedirects()->get(self::HOST."/teacher/live-sessions/{$live}/board")
            ->assertOk()->assertSeeText(__('lf.LF_liveclass_whiteboard_ended'));
    }

    public function test_material_list_is_the_lessons_ready_in_class_media_in_cohort_order(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $m = $setup['materials'];

        $response = $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$live}/board/materials")->assertOk();
        $items = collect($response->json('materials'));

        // Cohort order puts the video before the PDF; unready, other-lesson and quiz Activities are left out. A material of
        // any part of the session is listed, the homework after it included (ADR-0022 Amendment 1.3).
        $this->assertSame([$m['video'], $m['pdf'], $m['doc'], $m['embed'], $m['homework']], $items->pluck('id')->all());
        $this->assertSame(['video', 'pdf', 'document', 'embed', 'pdf'], $items->pluck('kind')->all());
        $this->assertSame(12, $items->firstWhere('id', $m['pdf'])['page_count']);
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/', $items->firstWhere('id', $m['embed'])['embed_url']);
        $this->assertNull($items->firstWhere('id', $m['embed'])['url_endpoint']);
        $this->assertSame(route('teacher.live-sessions.board.materials.url', [$live, $m['pdf']]), $items->firstWhere('id', $m['pdf'])['url_endpoint']);
    }

    public function test_material_url_is_a_same_origin_signed_url_for_listed_media_only(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $m = $setup['materials'];
        $base = self::HOST."/teacher/live-sessions/{$live}/board/materials";

        $url = $this->actingAs($setup['teacher'])->getJson("{$base}/{$m['pdf']}/url")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('url');
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$m['pdfMedia'].'/signed?', $url);

        $this->actingAs($setup['teacher'])->getJson("{$base}/{$m['homework']}/url")->assertOk();
        foreach (['unready', 'otherLesson', 'embed'] as $key) {
            $this->actingAs($setup['teacher'])->getJson("{$base}/{$m[$key]}/url")->assertNotFound();
        }
    }

    public function test_an_office_document_shows_its_pdf_state_and_opens_its_ready_pdf_through_a_signed_route(): void
    {
        Storage::fake('media_local');
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $m = $setup['materials'];
        $base = self::HOST."/teacher/live-sessions/{$live}/board/materials";
        $doc = fn (): array => collect($this->actingAs($setup['teacher'])->getJson($base)->assertOk()->json('materials'))->firstWhere('id', $m['doc']);

        $this->assertSame(['document', false, 'absent'], [$doc()['kind'], $doc()['converted'], $doc()['preview']]);
        $job = $this->boardPdfJob($setup, $m['docMedia'], 'pending');
        $this->assertSame(['document', 'processing'], [$doc()['kind'], $doc()['preview']]);
        DB::table('media_processing_jobs')->where('id', $job)->update(['status' => 'failed', 'error_code' => 'corrupt_source', 'completed_at' => now()]);
        $this->assertSame(['document', 'failed'], [$doc()['kind'], $doc()['preview']]);
        // Not converted: the URL is the original file's, for a new tab.
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$m['docMedia'].'/signed?',
            $this->actingAs($setup['teacher'])->getJson("{$base}/{$m['doc']}/url")->assertOk()->json('url'));

        $variant = $this->boardPdf($setup, $m['docMedia'], $this->boardPdfJob($setup, $m['docMedia'], 'ready', 2));
        $this->assertSame(['pdf', true, 3], [$doc()['kind'], $doc()['converted'], $doc()['page_count']]);
        $url = $this->actingAs($setup['teacher'])->getJson("{$base}/{$m['doc']}/url")->assertOk()->json('url');
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$m['docMedia'].'/variants/'.$variant.'/signed?', $url);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('sheet.pdf', (string) $response->headers->get('Content-Disposition'));

        // A learner of the class gets the same PDF.
        $learner = $this->learner($setup);
        $learnerDoc = collect($this->actingAs($learner)->getJson(self::HOST."/my-courses/live-sessions/{$live}/board/materials")->assertOk()->json('materials'))
            ->firstWhere('id', $m['doc']);
        $this->assertSame(['pdf', true], [$learnerDoc['kind'], $learnerDoc['converted']]);

        // The signature binds the variant, and only a preview of a ready file is served.
        $this->get(str_replace("/variants/{$variant}/", '/variants/'.($variant + 1).'/', $url))->assertForbidden();
        URL::forceRootUrl(self::HOST);
        $this->get(URL::temporarySignedRoute('media.variants.signed', now()->addMinutes(5), ['mediaFile' => $m['pdfMedia'], 'variant' => $variant]))
            ->assertNotFound();
        URL::forceRootUrl(null);
        DB::table('media_variants')->where('id', $variant)->update(['variant_type' => 'thumbnail']);
        $this->get($url)->assertNotFound();
        DB::table('media_variants')->where('id', $variant)->update(['variant_type' => 'preview']);
        DB::table('media_files')->where('id', $m['docMedia'])->update(['status' => 'processing']);
        $this->get($url)->assertNotFound();
    }

    public function test_a_video_plays_its_ready_faststart_copy_and_the_original_until_then(): void
    {
        Storage::fake('media_local');
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $m = $setup['materials'];
        $base = self::HOST."/teacher/live-sessions/{$live}/board/materials";
        $url = fn (): string => $this->actingAs($setup['teacher'])->getJson("{$base}/{$m['video']}/url")->assertOk()->json('url');

        // No copy yet: the original file, as before.
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$m['videoMedia'].'/signed?', $url());

        $file = DB::table('media_files')->where('id', $m['videoMedia'])->first();
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $job = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $setup['customer'], 'media_file_id' => $m['videoMedia'], 'job_type' => 'transcode', 'status' => 'processing',
            'attempt' => 1, 'idempotency_key' => 'board-video-'.$m['videoMedia'], 'correlation_id' => (string) Str::uuid(),
            'source_fingerprint' => $orchestrator->sourceFingerprint($file), 'processing_version' => 'ffmpeg-faststart-v1',
            'output_profile' => 'preset=faststart', 'output_profile_hash' => app(MediaOutputProfile::class)->hash('preset=faststart'),
            'provider' => 'local_video', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $key = "tenants/{$setup['customer']}/media/{$m['videoMedia']}/previews/ffmpeg-faststart-v1/{$job}.mp4";
        Storage::disk('media_local')->put($key, str_repeat('v', 100));
        $variant = DB::table('media_variants')->insertGetId([
            'customer_id' => $setup['customer'], 'media_file_id' => $m['videoMedia'], 'variant_type' => 'preview',
            'storage_key' => $key, 'mime_type' => 'video/mp4', 'file_size_bytes' => 100, 'processing_job_id' => $job,
            'processing_version' => 'ffmpeg-faststart-v1', 'source_fingerprint' => $orchestrator->sourceFingerprint($file),
            'status' => 'ready', 'metadata' => json_encode(['faststart' => true, 'duration_ms' => 2000]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // chk_mpj_ready: the job turns ready together with its output (SQLite skips the CHECK, MariaDB does not).
        DB::table('media_processing_jobs')->where('id', $job)->update(['status' => 'ready', 'completed_at' => now(), 'output_type' => 'variant', 'output_id' => $variant]);

        // The list does not change: the copy is the same video, not a converted kind.
        $item = collect($this->actingAs($setup['teacher'])->getJson($base)->assertOk()->json('materials'))->firstWhere('id', $m['video']);
        $this->assertSame(['video', false], [$item['kind'], $item['converted']]);

        $signed = $url();
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$m['videoMedia'].'/variants/'.$variant.'/signed?', $signed);
        $response = $this->get($signed)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->assertStringContainsString('warmup.mp4', (string) $response->headers->get('Content-Disposition'));
        $this->get($signed, ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/100');

        // A learner of the class gets the same copy.
        $learner = $this->learner($setup);
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$m['videoMedia'].'/variants/'.$variant.'/signed?',
            $this->actingAs($learner)->getJson(self::HOST."/my-courses/live-sessions/{$live}/board/materials/{$m['video']}/url")->assertOk()->json('url'));

        // A copy of an older source is never served: back to the original.
        DB::table('media_variants')->where('id', $variant)->update(['source_fingerprint' => str_repeat('0', 64)]);
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$m['videoMedia'].'/signed?', $url());
    }

    public function test_operational_session_board_has_no_materials(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Orientation', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher'], operational: true);

        $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$live}/board/materials")
            ->assertOk()->assertExactJson(['materials' => []]);
        $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$live}/board/materials/{$setup['materials']['pdf']}/url")
            ->assertNotFound();
    }

    public function test_administrator_opens_the_board_from_the_sessions_tab(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $scheduled = $this->liveSession($setup, 'Later', '2026-07-05 15:00:00', '2026-07-05 17:00:00', teacher: $setup['teacher']);
        $page = $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=sessions")->assertOk()->getContent();
        // Only the live Session offers the board.
        $this->assertSame(1, preg_match_all('#data-board-url="[^"]*/admin/course-cohorts/'.$setup['cohort'].'/sessions/(\d+)/board"#', $page, $found));
        $this->assertSame([(string) $live], $found[1]);

        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$live}/board")->assertOk();
        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$scheduled}/board")
            ->assertRedirect(route('admin.course-cohorts.show', ['id' => $setup['cohort'], 'tab' => 'sessions']))
            ->assertSessionHasErrors(['lifecycle' => __('lf.LF_liveclass_whiteboard_not_live')]);
        $this->actingAs($setup['admin'])->getJson(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$live}/board/materials/{$setup['materials']['pdf']}/url")
            ->assertOk()->assertJsonStructure(['url']);
        // The Session must belong to the Cohort in the path.
        $otherCohort = $this->cohort($setup['customer'], 'Other cohort', $setup['product'], $setup['version']);
        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$otherCohort}/sessions/{$live}/board")->assertNotFound();
    }

    public function test_other_roles_cannot_reach_the_board(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $student = $this->user($setup['customer'], 'student');

        $this->actingAs($setup['teacher'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$live}/board")->assertForbidden();
        $this->actingAs($setup['admin'])->get(self::HOST."/teacher/live-sessions/{$live}/board")->assertForbidden();
        $this->actingAs($student)->get(self::HOST."/teacher/live-sessions/{$live}/board")->assertForbidden();
        $this->actingAs($student)->post(self::HOST."/teacher/live-sessions/{$live}/complete")->assertForbidden();
        $this->assertSame('live', DB::table('core_liveclass_sessions')->where('id', $live)->value('status'));
    }

    // ------------------------------------------------------------------ phase 2: learners and sync

    public function test_learner_routes_use_the_student_middleware_and_offer_no_publishing(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['student.live-sessions.board', 'student.live-sessions.board.materials', 'student.live-sessions.board.materials.url',
            'student.live-sessions.board.state', 'student.live-sessions.board.status'] as $name) {
            $middleware = $routes->getByName($name)->gatherMiddleware();
            $this->assertContains('role:student', $middleware, $name);
            $this->assertContains('tenant', $middleware, $name);
            $this->assertContains('tenant.user', $middleware, $name);
        }
        foreach (['presence', 'events', 'snapshot', 'release'] as $part) {
            $this->assertNull($routes->getByName('student.live-sessions.board.'.$part), $part);
            $this->assertContains('role:teacher', $routes->getByName('teacher.live-sessions.board.'.$part)->gatherMiddleware());
            $this->assertContains('role:customer_admin', $routes->getByName('admin.course-cohorts.sessions.board.'.$part)->gatherMiddleware());
        }
    }

    public function test_learner_course_page_offers_the_board_of_live_sessions_only(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live now', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $this->liveSession($setup, 'Tomorrow', '2026-07-05 15:00:00', '2026-07-05 17:00:00', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $outsider = $this->user($setup['customer'], 'student');
        // In class there is no board; with attendance off the board shows no code either, so there is no QR hint
        // (LiveClassAttendanceCheckInTest covers the hint while a code is shown). The board is for online and remote learners.
        $this->assertStringNotContainsString('data-live-qr-hint', $this->actingAs($student)->get(self::HOST.'/my-courses')->assertOk()->getContent());
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['delivery_mode' => 'online']);

        $page = $this->actingAs($student)->get(self::HOST.'/my-courses')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('#href="[^"]*/my-courses/live-sessions/(\d+)/board"#', $page, $found));
        $this->assertSame([(string) $live], $found[1]);
        $this->assertStringContainsString('Live now', $page);
        $this->assertStringNotContainsString('Tomorrow', $page);

        $other = $this->actingAs($outsider)->get(self::HOST.'/my-courses')->assertOk()->getContent();
        $this->assertStringNotContainsString('/board"', $other);
    }

    public function test_learner_views_the_board_only_with_an_active_membership_and_enrollment(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $scheduled = $this->liveSession($setup, 'Later', '2026-07-05 15:00:00', '2026-07-05 17:00:00', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $board = self::HOST."/my-courses/live-sessions/{$live}/board";

        $page = $this->actingAs($student)->get($board)->assertOk()->getContent();
        $config = json_decode($this->configJson($page), true);
        $this->assertSame('viewer', $config['role']);
        $this->assertSame(['stateUrl', 'statusUrl', 'channel', 'authUrl', 'lockSeconds', 'maxEventBytes', 'maxBatch'], array_keys($config['sync']));
        $this->assertSame("tenant.{$setup['customer']}.liveclass-board.{$live}", $config['sync']['channel']);
        $this->actingAs($student)->getJson("{$board}/materials")->assertOk()->assertJsonCount(5, 'materials');
        $this->actingAs($student)->getJson("{$board}/materials/{$setup['materials']['pdf']}/url")->assertOk()->assertJsonStructure(['url']);
        $this->actingAs($student)->getJson("{$board}/materials/{$setup['materials']['unready']}/url")->assertNotFound();
        $this->actingAs($student)->getJson("{$board}/state")->assertOk()->assertJsonPath('seq', 0);

        // Not live, not a member, no longer a member, Enrollment not active or outside its window.
        // A board that is not open goes back to the class page, an inactive Enrollment to the classes tab; both say why.
        $this->actingAs($student)->get(self::HOST."/my-courses/live-sessions/{$scheduled}/board")
            ->assertRedirect(route('student.courses.classes.show', $setup['cohort']))
            ->assertSessionHas('lf_learn_notice', __('lf.LF_liveclass_whiteboard_not_live'));
        $this->actingAs($this->user($setup['customer'], 'student'))->get($board)->assertNotFound();
        $membership = DB::table('core_course_cohort_students')->where('student_id', $student->id)->first();
        DB::table('core_course_enrollments')->where('id', $membership->enrollment_id)->update(['status' => 'suspended']);
        $inactive = fn () => $this->actingAs($student)->get($board)
            ->assertRedirect(route('student.courses.index', ['tab' => 'classes']))
            ->assertSessionHas('lf_learn_notice', __('lf.LF_liveclass_attendance_entry_inactive'));
        $inactive();
        $this->actingAs($student)->getJson("{$board}/status")->assertForbidden();
        DB::table('core_course_enrollments')->where('id', $membership->enrollment_id)->update([
            'status' => 'active', 'access_starts_at' => '2026-06-01 00:00:00', 'access_ends_at' => '2026-07-01 00:00:00',
        ]);
        $inactive();
        DB::table('core_course_enrollments')->where('id', $membership->enrollment_id)->update(['access_starts_at' => null, 'access_ends_at' => null]);
        $this->actingAs($student)->get($board)->assertOk();
        DB::table('core_course_cohort_students')->where('id', $membership->id)->update(['left_at' => now()]);
        $this->actingAs($student)->get($board)->assertNotFound();

        // Another tenant's learner never reaches this Session.
        $other = $this->setupClass('tenant-b');
        $this->actingAs($this->learner($other))->get('https://tenant-b.localhost'."/my-courses/live-sessions/{$live}/board")->assertNotFound();
    }

    public function test_private_channel_admits_presenters_and_members_only(): void
    {
        $this->useReverbBroadcaster();
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $scheduled = $this->liveSession($setup, 'Later', '2026-07-05 15:00:00', '2026-07-05 17:00:00', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $channel = fn (int $session, ?int $customer = null): array => [
            'socket_id' => '1234.5678', 'channel_name' => 'private-tenant.'.($customer ?? $setup['customer']).'.liveclass-board.'.$session,
        ];

        foreach ([$setup['teacher'], $setup['admin'], $student] as $user) {
            $this->actingAs($user)->postJson(self::HOST.'/broadcasting/auth', $channel($live))->assertOk()->assertJsonStructure(['auth']);
        }
        $this->actingAs($setup['otherTeacher'])->postJson(self::HOST.'/broadcasting/auth', $channel($live))->assertForbidden();
        $this->actingAs($this->user($setup['customer'], 'student'))->postJson(self::HOST.'/broadcasting/auth', $channel($live))->assertForbidden();
        $this->actingAs($student)->postJson(self::HOST.'/broadcasting/auth', $channel($scheduled))->assertForbidden();
        // The tenant in the channel name must be the request's tenant.
        $this->actingAs($student)->postJson(self::HOST.'/broadcasting/auth', $channel($live, $setup['customer'] + 1))->assertForbidden();
    }

    public function test_one_active_presenter_publishes_numbered_events_into_the_cached_state(): void
    {
        Event::fake([LiveClassWhiteboardBroadcast::class]);
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $teacherBoard = self::HOST."/teacher/live-sessions/{$live}/board";
        $adminBoard = self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$live}/board";
        $stroke = ['type' => 'stroke.add', 'page' => 1, 'stroke' => ['id' => 's1', 'tool' => 'pen', 'color' => '#2755ea', 'width' => 3, 'points' => [[1, 2], [3, 4]]]];

        $this->actingAs($setup['teacher'])->postJson("{$teacherBoard}/presence", ['client_id' => 'teacher-tab-1'])
            ->assertOk()->assertJsonPath('presenting', true);
        $this->actingAs($setup['admin'])->postJson("{$adminBoard}/presence", ['client_id' => 'admin-tab-1'])
            ->assertOk()->assertJsonPath('presenting', false)->assertJsonPath('presenter.name', $setup['teacher']->name);
        $this->actingAs($setup['admin'])->postJson("{$adminBoard}/events", ['client_id' => 'admin-tab-1', 'events' => [$stroke]])->assertStatus(409);
        // Another tab of the same teacher is not the holder either.
        $this->actingAs($setup['teacher'])->postJson("{$teacherBoard}/events", ['client_id' => 'teacher-tab-2', 'events' => [$stroke]])->assertStatus(409);

        $this->actingAs($setup['teacher'])->postJson("{$teacherBoard}/events", ['client_id' => 'teacher-tab-1', 'events' => [
            $stroke + ['seq' => 99, 'client_id' => 'spoofed', 'user_id' => 1],
            ['type' => 'pointer', 'page' => 1, 'x' => 5, 'y' => 6],
            ['type' => 'page.change', 'page' => 2],
        ]])->assertOk()->assertJsonPath('seq', 2)->assertJsonPath('broadcast', true);

        $state = $this->actingAs($student)->getJson(self::HOST."/my-courses/live-sessions/{$live}/board/state")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame(2, $state['seq']);
        $this->assertSame([1, 2], array_column($state['events'], 'seq'));
        $this->assertSame(['stroke.add', 'page.change'], array_column($state['events'], 'type'));
        $this->assertSame('teacher-tab-1', $state['events'][0]['client_id']);
        $this->assertArrayNotHasKey('user_id', $state['events'][0]);
        $this->assertSame($setup['teacher']->name, $state['presenter']['name']);
        Event::assertDispatched(LiveClassWhiteboardBroadcast::class, fn ($event): bool => $event->name === 'board.events'
            && $event->sessionId === $live && array_column($event->payload['events'], 'seq') === [1, null, 2]
            && $event->broadcastOn()->name === "private-tenant.{$setup['customer']}.liveclass-board.{$live}");

        // A snapshot restarts the log at the current sequence.
        $this->actingAs($setup['teacher'])->postJson("{$teacherBoard}/snapshot", ['client_id' => 'teacher-tab-1', 'state' => [
            'v' => 1, 'material' => ['key' => 'blank', 'kind' => 'blank'], 'page' => 1, 'pages' => ['1' => ['paths' => [$stroke['stroke']], 'notes' => []]],
            'secret' => 'dropped',
        ]])->assertOk()->assertJsonPath('seq', 2);
        $state = $this->actingAs($setup['teacher'])->getJson("{$teacherBoard}/state")->assertOk()->json();
        $this->assertSame(2, $state['snapshot_seq']);
        $this->assertSame([], $state['events']);
        $this->assertArrayNotHasKey('secret', $state['snapshot']);

        // An explicit takeover moves the lock; the previous holder is refused and told.
        $this->actingAs($setup['admin'])->postJson("{$adminBoard}/presence", ['client_id' => 'admin-tab-1', 'takeover' => true])
            ->assertOk()->assertJsonPath('presenting', true);
        $this->actingAs($setup['teacher'])->postJson("{$teacherBoard}/events", ['client_id' => 'teacher-tab-1', 'events' => [$stroke]])->assertStatus(409);
        $this->actingAs($setup['teacher'])->postJson("{$teacherBoard}/presence", ['client_id' => 'teacher-tab-1'])
            ->assertOk()->assertJsonPath('presenting', false);
        Event::assertDispatched(LiveClassWhiteboardBroadcast::class, fn ($event): bool => $event->name === 'board.presenter'
            && $event->payload['client_id'] === 'admin-tab-1');

        // Releasing by the holder frees the lock for the next presenter.
        $this->actingAs($setup['admin'])->postJson("{$adminBoard}/release", ['client_id' => 'admin-tab-1'])->assertNoContent();
        $this->actingAs($setup['teacher'])->getJson("{$teacherBoard}/status")->assertOk()->assertJsonPath('presenter.name', null);
    }

    public function test_the_snapshot_has_its_own_cache_key_and_a_late_joiner_sees_a_consistent_board(): void
    {
        Event::fake([LiveClassWhiteboardBroadcast::class]);
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $board = self::HOST."/teacher/live-sessions/{$live}/board";
        $logKey = "lf-wb:{$setup['customer']}:{$live}:state";
        $snapshotKey = "lf-wb:{$setup['customer']}:{$live}:snapshot";
        $pages = fn (string $id): array => ['1' => ['paths' => [['id' => $id, 'tool' => 'pen', 'points' => [[1, 2]]]], 'notes' => []]];
        $send = fn (array $events) => $this->actingAs($setup['teacher'])->postJson("{$board}/events", ['client_id' => 'teacher-tab-1', 'events' => $events]);
        $stroke = ['type' => 'stroke.add', 'page' => 1, 'stroke' => ['id' => 's1', 'tool' => 'pen', 'points' => [[1, 2]]]];
        $this->actingAs($setup['teacher'])->postJson("{$board}/presence", ['client_id' => 'teacher-tab-1'])->assertOk();

        // Publishing while drawing touches only the log.
        $send([$stroke])->assertOk();
        $this->actingAs($setup['teacher'])->postJson("{$board}/snapshot", ['client_id' => 'teacher-tab-1', 'state' => ['v' => 1, 'page' => 1, 'pages' => $pages('s1')]])
            ->assertOk()->assertJsonPath('seq', 1);
        $send([['type' => 'stroke.append', 'page' => 1, 'id' => 's1', 'from' => 1, 'points' => [[3, 4]]]])->assertOk()->assertJsonPath('snapshot_requested', false);
        $this->assertArrayNotHasKey('snapshot', Cache::get($logKey));
        $this->assertSame(1, Cache::get($snapshotKey)['seq']);
        $state = $this->actingAs($setup['teacher'])->getJson("{$board}/state")->json();
        $this->assertSame([1, 1, [2]], [$state['snapshot_seq'], count($state['snapshot']['pages']['1']['paths']), array_column($state['events'], 'seq')]);
        $this->assertArrayNotHasKey('snapshotted', $state);

        // A reader that got the log just before a newer snapshot: the snapshot covers the log.
        Cache::put($snapshotKey, ['seq' => 2, 'snapshot' => ['v' => 1, 'page' => 1, 'pages' => $pages('s2')]], 600);
        $state = $this->actingAs($setup['teacher'])->getJson("{$board}/state")->json();
        $this->assertSame([2, 2, 's2', []], [$state['seq'], $state['snapshot_seq'], $state['snapshot']['pages']['1']['paths'][0]['id'], $state['events']]);

        // A snapshot older than the log's start does not count.
        Cache::put($logKey, ['seq' => 5, 'snapshot_seq' => 5, 'events' => [], 'incomplete' => true], 600);
        $this->assertNull($this->actingAs($setup['teacher'])->getJson("{$board}/state")->json('snapshot'));

        // A snapshot that expired before the log asks the presenter for a new one.
        Cache::put($logKey, ['seq' => 2, 'snapshot_seq' => 2, 'events' => [], 'incomplete' => false, 'snapshotted' => true], 600);
        Cache::forget($snapshotKey);
        $send([$stroke])->assertOk()->assertJsonPath('snapshot_requested', true);

        // A log cached before the split keeps its snapshot, which moves to its own key on the next publish.
        Cache::put($logKey, ['seq' => 3, 'snapshot' => ['v' => 1, 'page' => 1, 'pages' => $pages('old')], 'snapshot_seq' => 3, 'events' => []], 600);
        $this->assertSame('old', $this->actingAs($setup['teacher'])->getJson("{$board}/state")->json('snapshot.pages.1.paths.0.id'));
        $send([$stroke])->assertOk()->assertJsonPath('snapshot_requested', false);
        $this->assertArrayNotHasKey('snapshot', Cache::get($logKey));
        $this->assertSame([3, 'old'], [Cache::get($snapshotKey)['seq'], Cache::get($snapshotKey)['snapshot']['pages']['1']['paths'][0]['id']]);
        $this->assertSame([3, [4]], [
            $this->actingAs($setup['teacher'])->getJson("{$board}/state")->json('snapshot_seq'),
            array_column($this->actingAs($setup['teacher'])->getJson("{$board}/state")->json('events'), 'seq'),
        ]);

        // Ending the board clears the snapshot too.
        app(LiveClassWhiteboardLiveState::class)->end($setup['customer'], $live);
        $this->assertFalse(Cache::has($snapshotKey));
    }

    public function test_drawing_does_not_use_up_the_presence_or_snapshot_rate_limits(): void
    {
        Event::fake([LiveClassWhiteboardBroadcast::class]);
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $board = self::HOST."/teacher/live-sessions/{$live}/board";
        $this->actingAs($setup['teacher'])->postJson("{$board}/presence", ['client_id' => 'teacher-tab-1'])->assertOk();

        // About 13 seconds of drawing at ten requests a second.
        for ($i = 0; $i < 130; $i++) {
            $this->actingAs($setup['teacher'])->postJson("{$board}/events", ['client_id' => 'teacher-tab-1', 'events' => [['type' => 'board.clear']]])->assertOk();
        }

        // Each board route counts on its own, so the heartbeat and the snapshot still go through.
        $this->actingAs($setup['teacher'])->postJson("{$board}/presence", ['client_id' => 'teacher-tab-1'])->assertOk();
        $this->actingAs($setup['teacher'])->postJson("{$board}/snapshot", ['client_id' => 'teacher-tab-1', 'state' => ['v' => 1, 'page' => 1, 'pages' => []]])->assertOk();
    }

    public function test_published_events_are_validated(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $board = self::HOST."/teacher/live-sessions/{$live}/board";
        $this->actingAs($setup['teacher'])->postJson("{$board}/presence", ['client_id' => 'teacher-tab-1'])->assertOk();
        $send = fn (array $events) => $this->actingAs($setup['teacher'])->postJson("{$board}/events", ['client_id' => 'teacher-tab-1', 'events' => $events]);

        $send([['type' => 'client-whisper']])->assertUnprocessable();
        $send([['type' => 'page.change', 'page' => 0]])->assertUnprocessable();
        $send([['type' => 'note.update', 'id' => 'n1', 'text' => str_repeat('a', 8200)]])->assertUnprocessable();
        $send(array_fill(0, 41, ['type' => 'board.clear']))->assertUnprocessable();
        $send([])->assertUnprocessable();
        $this->actingAs($setup['teacher'])->postJson("{$board}/presence", ['client_id' => 'bad id!'])->assertUnprocessable();
        $this->actingAs($setup['teacher'])->postJson("{$board}/snapshot", ['client_id' => 'teacher-tab-1', 'state' => [
            'pages' => ['1' => ['notes' => [['id' => 'n1', 'text' => str_repeat('a', 1_100_000)]]]],
        ]])->assertUnprocessable();
        $this->assertSame(0, $this->actingAs($setup['teacher'])->getJson("{$board}/state")->json('seq'));

        $send(array_fill(0, 40, ['type' => 'board.clear']))->assertOk()->assertJsonPath('seq', 40);
    }

    /** LF-Core-LiveClass § Âm thanh, video và chế độ nghe: media events carry server time; the heartbeat is not cached. */
    public function test_media_events_carry_server_time_and_the_position_heartbeat_is_not_cached(): void
    {
        Event::fake([LiveClassWhiteboardBroadcast::class]);
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $board = self::HOST."/teacher/live-sessions/{$live}/board";
        $studentState = self::HOST."/my-courses/live-sessions/{$live}/board/state";
        $now = Carbon::now()->getTimestampMs();
        $this->actingAs($setup['teacher'])->postJson("{$board}/presence", ['client_id' => 'teacher-tab-1'])->assertOk();

        $this->actingAs($setup['teacher'])->postJson("{$board}/events", ['client_id' => 'teacher-tab-1', 'events' => [
            ['type' => 'video.play', 'id' => $setup['materials']['video'], 'kind' => 'audio', 'time' => 3.2, 'playing' => true, 'at' => 1],
            ['type' => 'video.sync', 'id' => $setup['materials']['video'], 'kind' => 'audio', 'time' => 8.2, 'playing' => true, 'at' => 1],
            ['type' => 'page.change', 'page' => 2, 'at' => 123],
        ]])->assertOk()->assertJsonPath('seq', 2);

        $state = $this->actingAs($student)->getJson($studentState)->assertOk()->json();
        $this->assertSame(['video.play', 'page.change'], array_column($state['events'], 'type'), 'the heartbeat is not cached');
        $this->assertSame($now, $state['events'][0]['at'], 'a media event carries server time, never the browser\'s');
        $this->assertSame(123, $state['events'][1]['at'], 'other events are left as sent');
        $this->assertSame($now, $state['now']);
        Event::assertDispatched(LiveClassWhiteboardBroadcast::class, fn ($event): bool => $event->name === 'board.events'
            && array_column($event->payload['events'], 'seq') === [1, null, 2]
            && $event->payload['events'][1]['type'] === 'video.sync' && $event->payload['events'][1]['at'] === $now);

        // A snapshot's media is stamped when it arrives; the clock in the state moves with server time.
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $this->actingAs($setup['teacher'])->postJson("{$board}/snapshot", ['client_id' => 'teacher-tab-1', 'state' => [
            'v' => 1, 'material' => ['key' => 'blank', 'kind' => 'blank'], 'page' => 1, 'pages' => [],
            'video' => ['id' => $setup['materials']['video'], 'kind' => 'audio', 'time' => 8.2, 'playing' => true, 'at' => 1],
        ]])->assertOk();
        $state = $this->actingAs($student)->getJson($studentState)->assertOk()->json();
        $this->assertSame($now + 5000, $state['snapshot']['video']['at']);
        $this->assertSame($now + 5000, $state['now']);

        $this->actingAs($setup['teacher'])->postJson("{$board}/snapshot", ['client_id' => 'teacher-tab-1', 'state' => [
            'v' => 1, 'material' => ['key' => 'blank', 'kind' => 'blank'], 'page' => 1, 'pages' => [], 'video' => null,
        ]])->assertOk();
        $this->assertNull($this->actingAs($student)->getJson($studentState)->json('snapshot.video'));
    }

    public function test_board_config_carries_the_sessions_delivery_mode(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $config = fn (User $user, string $url): array => json_decode($this->configJson($this->actingAs($user)->get($url)->assertOk()->getContent()), true);

        $this->assertSame('offline', $config($setup['teacher'], self::HOST."/teacher/live-sessions/{$live}/board")['deliveryMode']);
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['delivery_mode' => 'hybrid']);
        $this->assertSame('hybrid', $config($student, self::HOST."/my-courses/live-sessions/{$live}/board")['deliveryMode']);
    }

    public function test_join_meeting_shows_an_online_sessions_http_link_to_people_who_may_open_the_board(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $link = 'https://meet.example.test/room-1?pwd=a&b=c';
        $pages = [
            [$student, self::HOST.'/my-courses', 'data-live-meeting'],
            [$student, self::HOST."/my-courses/live-sessions/{$live}/board", 'data-wb-meeting'],
            [$setup['teacher'], self::HOST."/teacher/live-sessions/{$live}/board", 'data-wb-meeting'],
            [$setup['teacher'], self::HOST.'/teacher/live-sessions', 'Vào phòng họp'],
        ];
        $shows = function (bool $expected) use ($pages, $link): void {
            foreach ($pages as [$user, $url, $marker]) {
                $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
                $this->assertSame($expected, str_contains($html, e($link)) && str_contains($html, $marker), $url);
            }
        };

        // Offline: no button even with a link.
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['meeting_url_snapshot' => $link]);
        $shows(false);

        DB::table('core_liveclass_sessions')->where('id', $live)->update(['delivery_mode' => 'online']);
        $shows(true);

        // Only http(s) links become a button.
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['meeting_url_snapshot' => 'javascript:alert(1)']);
        foreach ($pages as [$user, $url]) {
            $this->assertStringNotContainsString('javascript:alert', $this->actingAs($user)->get($url)->assertOk()->getContent());
        }

        // A learner of another cohort never sees it.
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['meeting_url_snapshot' => $link]);
        $outsider = $this->user($setup['customer'], 'student');
        $this->assertStringNotContainsString(e($link), $this->actingAs($outsider)->get(self::HOST.'/my-courses')->assertOk()->getContent());
        $this->actingAs($outsider)->get(self::HOST."/my-courses/live-sessions/{$live}/board")->assertNotFound();
    }

    public function test_the_learner_card_follows_the_delivery_mode(): void
    {
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['meeting_url_snapshot' => 'https://meet.example.test/room']);
        $page = fn (): string => $this->actingAs($student)->get(self::HOST.'/my-courses')->assertOk()->getContent();
        $board = "/my-courses/live-sessions/{$live}/board";

        // In class: no board and no meeting (and, with attendance off, no code to scan).
        $html = $page();
        $this->assertStringNotContainsString('data-live-qr-hint', $html);
        $this->assertStringNotContainsString($board, $html);
        $this->assertStringNotContainsString('data-live-where', $html);

        // In class + remote: the learner picks where they are; remote has the board and the meeting.
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['delivery_mode' => 'hybrid']);
        $html = $page();
        $this->assertStringContainsString('data-live-where="room"', $html);
        $this->assertStringContainsString('data-live-where="remote"', $html);
        $this->assertStringContainsString($board, $html);
        $this->assertStringContainsString('data-live-meeting', $html);

        // Online: the board and the meeting, no choice and no QR.
        DB::table('core_liveclass_sessions')->where('id', $live)->update(['delivery_mode' => 'online']);
        $html = $page();
        $this->assertStringNotContainsString('data-live-where', $html);
        $this->assertStringContainsString($board, $html);
        $this->assertStringContainsString('data-live-meeting', $html);
    }

    public function test_a_live_session_past_its_scheduled_end_is_completed_by_the_sweep(): void
    {
        Event::fake([LiveClassWhiteboardBroadcast::class]);
        $setup = $this->setupClass();
        // 16:00 on the Sessions' own clock. One Session ended at 15:30, one ends at 17:00, one is still only scheduled.
        Carbon::setTestNow(Carbon::parse('2026-07-04 16:00:00', 'Asia/Ho_Chi_Minh'));
        $over = $this->liveSession($setup, 'Over', '2026-07-04 14:00:00', '2026-07-04 15:30:00', status: 'live', teacher: $setup['teacher']);
        $running = $this->liveSession($setup, 'Running', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $planned = $this->liveSession($setup, 'Planned', '2026-07-04 13:00:00', '2026-07-04 14:00:00', teacher: $setup['teacher']);
        $this->actingAs($setup['teacher'])->postJson(self::HOST."/teacher/live-sessions/{$over}/board/presence", ['client_id' => 'teacher-tab-1'])->assertOk();

        $this->artisan('liveclass:complete-overdue')->expectsOutput('Completed 1 overdue session(s).')->assertSuccessful();

        $row = DB::table('core_liveclass_sessions')->find($over);
        $this->assertSame('completed', $row->status);
        $this->assertSame('2026-07-04 15:30:00', (string) $row->actual_end_at);
        $this->assertSame('live', DB::table('core_liveclass_sessions')->where('id', $running)->value('status'));
        $this->assertSame('scheduled', DB::table('core_liveclass_sessions')->where('id', $planned)->value('status'));
        $this->assertTrue(Event::dispatched(LiveClassWhiteboardBroadcast::class,
            fn ($event): bool => $event->name === 'board.ended' && $event->sessionId === $over)->isNotEmpty());
        $this->assertFalse(Cache::has("lf-wb:{$setup['customer']}:{$over}:lock"));

        // Another tenant's filter touches nothing; a second run finds nothing left.
        Carbon::setTestNow(Carbon::parse('2026-07-04 17:30:00', 'Asia/Ho_Chi_Minh'));
        $this->artisan('liveclass:complete-overdue', ['--customer' => $setup['customer'] + 999])->expectsOutput('Completed 0 overdue session(s).');
        $this->artisan('liveclass:complete-overdue')->expectsOutput('Completed 1 overdue session(s).');
        $this->assertSame('completed', DB::table('core_liveclass_sessions')->where('id', $running)->value('status'));
    }

    public function test_every_way_out_of_live_ends_the_board_and_clears_its_state(): void
    {
        Event::fake([LiveClassWhiteboardBroadcast::class]);
        $setup = $this->setupClass();
        $completed = $this->liveSession($setup, 'Completed', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $cancelled = $this->liveSession($setup, 'Cancelled', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $cohortLive = $this->liveSession($setup, 'Cohort ends', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        foreach ([$completed, $cancelled, $cohortLive] as $session) {
            $this->actingAs($setup['teacher'])->postJson(self::HOST."/teacher/live-sessions/{$session}/board/presence", ['client_id' => 'teacher-tab-1'])->assertOk();
            $this->actingAs($setup['teacher'])->postJson(self::HOST."/teacher/live-sessions/{$session}/board/events", [
                'client_id' => 'teacher-tab-1', 'events' => [['type' => 'board.clear']],
            ])->assertOk();
        }
        $ended = fn (int $session): bool => Event::dispatched(LiveClassWhiteboardBroadcast::class,
            fn ($event): bool => $event->name === 'board.ended' && $event->sessionId === $session)->isNotEmpty();
        $cached = fn (int $session): bool => Cache::has("lf-wb:{$setup['customer']}:{$session}:state") || Cache::has("lf-wb:{$setup['customer']}:{$session}:lock");

        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$completed}/complete")->assertRedirect();
        $this->assertTrue($ended($completed));
        $this->assertFalse($cached($completed));
        $this->assertTrue($cached($cancelled));

        app(LiveClassSessionLifecycleService::class)->cancel($setup['customer'], $setup['cohort'], $cancelled, 'Rain');
        $this->assertTrue($ended($cancelled));
        $this->assertFalse($cached($cancelled));
        $this->assertFalse($ended($cohortLive));

        app(CourseCohortLifecycleService::class)->complete($setup['customer'], $setup['cohort']);
        $this->assertTrue($ended($cohortLive));
        $this->assertFalse($cached($cohortLive));
    }

    public function test_a_learner_removed_from_the_cohort_is_disconnected(): void
    {
        Event::fake([LiveClassWhiteboardBroadcast::class]);
        $setup = $this->setupClass();
        $live = $this->liveSession($setup, 'Live', '2026-07-04 15:00:00', '2026-07-04 17:00:00', status: 'live', teacher: $setup['teacher']);
        $student = $this->learner($setup);
        $staying = $this->learner($setup);
        $state = self::HOST."/my-courses/live-sessions/{$live}/board/state";
        $this->actingAs($student)->getJson($state)->assertOk();

        $membership = DB::table('core_course_cohort_students')->where('student_id', $student->id)->value('id');
        $this->actingAs($setup['admin'])->post(self::HOST."/admin/course-cohort-students/{$membership}/archive")->assertRedirect();

        Event::assertDispatched(LiveClassWhiteboardBroadcast::class, fn ($event): bool => $event->name === 'board.revoked'
            && $event->sessionId === $live && $event->payload['user_ids'] === [$student->id]);
        $this->actingAs($student)->getJson($state)->assertNotFound();
        $this->actingAs($staying)->getJson($state)->assertOk();
    }

    // ------------------------------------------------------------------ fixtures

    /** A learner with an active Enrollment and an active membership of the setup's Cohort. */
    private function boardPdfJob(array $setup, int $media, string $status, int $attempt = 1): int
    {
        $file = DB::table('media_files')->where('id', $media)->first();
        $orchestrator = app(MediaProcessingOrchestrator::class);

        return DB::table('media_processing_jobs')->insertGetId([
            // chk_mpj_ready: a ready job names its output, so a "ready" job stays processing until boardPdf() adds the PDF.
            'customer_id' => $setup['customer'], 'media_file_id' => $media, 'job_type' => 'transcode', 'status' => $status === 'ready' ? 'processing' : $status,
            'attempt' => $attempt, 'idempotency_key' => 'board-pdf-'.$media.'-'.$attempt, 'correlation_id' => (string) Str::uuid(),
            'source_fingerprint' => $orchestrator->sourceFingerprint($file), 'processing_version' => $orchestrator->versionFor('transcode', $file),
            'output_profile' => 'preset=pdf', 'output_profile_hash' => app(MediaOutputProfile::class)->hash('preset=pdf'),
            'provider' => 'local_document', 'started_at' => $status === 'pending' ? null : now(),
            'completed_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A ready board PDF of an Office file, as the conversion job leaves it. */
    private function boardPdf(array $setup, int $media, int $job): int
    {
        $file = DB::table('media_files')->where('id', $media)->first();
        $key = "tenants/{$setup['customer']}/media/{$media}/previews/v1/{$job}.pdf";
        Storage::disk('media_local')->put($key, "%PDF-1.4\n% board\n");
        $variant = DB::table('media_variants')->insertGetId([
            'customer_id' => $setup['customer'], 'media_file_id' => $media, 'variant_type' => 'preview',
            'storage_key' => $key, 'mime_type' => 'application/pdf', 'file_size_bytes' => 18, 'processing_job_id' => $job,
            'processing_version' => 'libreoffice-pdf-v1', 'source_fingerprint' => app(MediaProcessingOrchestrator::class)->sourceFingerprint($file),
            'status' => 'ready', 'metadata' => json_encode(['page_count' => 3]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_processing_jobs')->where('id', $job)->update(['status' => 'ready', 'completed_at' => now(), 'output_type' => 'variant', 'output_id' => $variant]);

        return $variant;
    }

    private function learner(array $setup): User
    {
        $student = $this->user($setup['customer'], 'student');
        $enrollment = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $setup['customer'], 'product_id' => $setup['product'], 'version_id' => $setup['version'],
            'student_id' => $student->id, 'source' => 'admin', 'enrolled_by' => $setup['admin']->id,
            'enrolled_at' => '2026-07-01 00:00:00', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_cohort_students')->insert([
            'customer_id' => $setup['customer'], 'cohort_id' => $setup['cohort'], 'enrollment_id' => $enrollment,
            'product_id' => $setup['product'], 'student_id' => $student->id, 'assigned_by' => $setup['admin']->id,
            'joined_at' => '2026-07-01 00:00:00', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $student;
    }

    private function configJson(string $page): string
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="lf-wb-config">(.*?)</script>#s', $page, $match));

        return $match[1];
    }

    /** Channel callbacks register on the broadcaster that exists when routes/channels.php loads. */
    private function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->purge('reverb');
        require base_path('routes/channels.php');
    }

    private function setupClass(string $slug = 'tenant-a'): array
    {
        $customer = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->user($customer, 'customer_admin');
        $teacher = $this->user($customer, 'teacher');
        $otherTeacher = $this->user($customer, 'teacher');
        $product = $this->product($customer, $slug);
        $template = DB::table('core_course_templates')->insertGetId([
            'course_type' => 'blended', 'customer_id' => $customer, 'title' => 'Board Template',
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => 'blended', 'customer_id' => $customer, 'template_id' => $template,
            'version_number' => 1, 'version_code' => 'V-'.$template, 'is_current' => true,
            'title_snapshot' => 'Board Version', 'estimated_minutes_per_lesson_snapshot' => 0, 'lesson_count_snapshot' => 2,
            'source_working_revision' => 1, 'status' => 'published', 'published_at' => now(), 'published_by' => $admin->id,
            'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cohortName = 'Board class '.$slug;
        $cohort = $this->cohort($customer, $cohortName, $product, $version);
        $lesson = $this->lesson($customer, $version, 'Lesson one', 1);
        $otherLesson = $this->lesson($customer, $version, 'Lesson two', 2);
        $liveActivity = $this->activity($customer, $version, $lesson, 'live_class', 'Live class', 1);

        $pdfMedia = $this->media($customer, $admin->id, 'document', 'application/pdf', 'slides.pdf', 12);
        $pdf = $this->activity($customer, $version, $lesson, 'document', 'Slides', 2, $pdfMedia);
        $videoMedia = $this->media($customer, $admin->id, 'video', 'video/mp4', 'warmup.mp4');
        $video = $this->activity($customer, $version, $lesson, 'video', 'Warm-up video', 3, $videoMedia);
        $docMedia = $this->media($customer, $admin->id, 'document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sheet.docx');
        $doc = $this->activity($customer, $version, $lesson, 'document', 'Worksheet', 4, $docMedia);
        $embed = $this->activity($customer, $version, $lesson, 'embedded_video', 'YouTube clip', 5, null,
            ['external_video_url_snapshot' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $homework = $this->activity($customer, $version, $lesson, 'document', 'Homework', 6,
            $this->media($customer, $admin->id, 'document', 'application/pdf', 'homework.pdf'),
            ['available_during_session' => false, 'available_after_session' => true]);
        $unready = $this->activity($customer, $version, $lesson, 'document', 'Processing', 7,
            $this->media($customer, $admin->id, 'document', 'application/pdf', 'processing.pdf', status: 'processing'));
        $this->activity($customer, $version, $lesson, 'quiz', 'Quiz', 8);
        $otherLessonActivity = $this->activity($customer, $version, $otherLesson, 'document', 'Next lesson', 1,
            $this->media($customer, $admin->id, 'document', 'application/pdf', 'next.pdf'));

        // The class keeps its own order: the video first.
        foreach ([$video => 1, $liveActivity => 2, $pdf => 3, $doc => 4, $embed => 5, $homework => 6, $unready => 7] as $activity => $order) {
            DB::table('core_course_cohort_activity_orders')->insert([
                'customer_id' => $customer, 'cohort_id' => $cohort,
                'version_activity_id' => $activity, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [
            'customer' => $customer, 'admin' => $admin, 'teacher' => $teacher, 'otherTeacher' => $otherTeacher,
            'product' => $product, 'version' => $version, 'cohort' => $cohort, 'cohortName' => $cohortName,
            'lesson' => $lesson, 'liveActivity' => $liveActivity,
            'materials' => [
                'pdf' => $pdf, 'pdfMedia' => $pdfMedia, 'video' => $video, 'videoMedia' => $videoMedia, 'doc' => $doc, 'docMedia' => $docMedia, 'embed' => $embed,
                'homework' => $homework, 'unready' => $unready, 'otherLesson' => $otherLessonActivity,
            ],
        ];
    }

    private function liveSession(array $setup, string $title, string $start, string $end, string $status = 'scheduled',
        ?User $teacher = null, ?string $assignedFrom = null, bool $operational = false): int
    {
        static $number = 0;
        $session = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $setup['customer'], 'cohort_id' => $setup['cohort'], 'template_version_id' => $setup['version'],
            'session_type' => $operational ? 'operational' : 'curriculum',
            'version_lesson_id' => $operational ? null : $setup['lesson'],
            'version_activity_id' => $operational ? null : $setup['liveActivity'],
            'title' => $title, 'session_no' => ++$number, 'delivery_mode' => 'offline',
            'scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => $status, 'actual_start_at' => $status === 'live' ? $start : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($teacher) {
            DB::table('core_liveclass_session_teachers')->insert([
                'customer_id' => $setup['customer'], 'session_id' => $session, 'teacher_id' => $teacher->id, 'role' => 'teacher',
                'assigned_from' => $assignedFrom, 'assigned_to' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $session;
    }

    private function user(int $customer, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customer, 'name' => ucfirst($role).' '.uniqid(), 'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function product(int $customer, string $slug): int
    {
        return DB::table('core_course_products')->insertGetId([
            'customer_id' => $customer, 'product_code' => strtoupper($slug), 'product_type' => 'single_course',
            'title' => 'Board product', 'slug' => 'board-'.$slug, 'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'paid', 'enrollment_count' => 0, 'is_certificate_enabled' => false, 'is_refundable' => false,
            'show_enrollment_count' => true, 'is_featured' => false, 'sort_order' => 0, 'visibility' => 'public',
            'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cohort(int $customer, string $name, int $product, int $version): int
    {
        return DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customer, 'product_id' => $product, 'version_id' => $version, 'name' => $name,
            'code' => 'COH-'.uniqid(), 'status' => 'active', 'start_date' => '2026-07-01', 'end_date' => '2026-08-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lesson(int $customer, int $version, string $title, int $order): int
    {
        static $source = 70000;

        return DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customer, 'template_version_id' => $version, 'source_template_lesson_id' => ++$source,
            'title_snapshot' => $title, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A published quiz version for a Quiz Activity: chk_cctva_quiz_ref needs the pair (SQLite skips the CHECK, MariaDB does not). */
    private function quizSnapshot(int $customer): array
    {
        $author = (int) DB::table('users')->where('customer_id', $customer)->value('id');
        $quiz = DB::table('core_assessment_quizzes')->insertGetId([
            'customer_id' => $customer, 'created_by' => $author, 'title' => 'Quiz', 'assessment_type' => 'exam', 'max_attempts' => 1,
            'show_result_mode' => 'immediately', 'status' => 'draft', 'registration_open' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_assessment_quiz_versions')->insertGetId([
            'customer_id' => $customer, 'quiz_id' => $quiz, 'version_no' => 1, 'title' => 'Quiz', 'assessment_type' => 'exam',
            'max_attempts' => 1, 'show_result_mode' => 'immediately', 'total_points' => 1, 'question_count' => 1, 'sections_snapshot' => '[]',
            'published_by' => $author, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_assessment_quizzes')->where('id', $quiz)->update(['status' => 'published', 'current_version_id' => $version]);

        return ['assessment_quiz_id_snapshot' => $quiz, 'assessment_quiz_version_id_snapshot' => $version];
    }

    private function activity(int $customer, int $version, int $lesson, string $type, string $title, int $order,
        ?int $media = null, array $extra = []): int
    {
        static $source = 80000;
        if ($type === 'quiz' && ! isset($extra['assessment_quiz_version_id_snapshot'])) {
            $extra += $this->quizSnapshot($customer);
        }
        $activity = DB::table('core_course_template_version_activities')->insertGetId($extra + [
            'customer_id' => $customer, 'template_version_id' => $version, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => ++$source, 'title_snapshot' => $title, 'activity_type' => $type,
            'media_file_id' => $media, 'sort_order' => $order, 'available_during_session' => true,
            'completion_rule' => $type === 'live_class' ? 'manual' : 'view', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($media) {
            DB::table('media_file_usages')->insert([
                'customer_id' => $customer, 'media_file_id' => $media, 'owner_type' => 'course_version_activity',
                'owner_id' => $activity, 'usage_type' => $type, 'status' => 'active', 'metadata' => null,
                'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $activity;
    }

    private function media(int $customer, int $uploadedBy, string $type, string $mime, string $name, ?int $pages = null,
        string $status = 'ready'): int
    {
        return DB::table('media_files')->insertGetId([
            'customer_id' => $customer, 'uploaded_by' => $uploadedBy, 'file_type' => $type, 'mime_type' => $mime,
            'original_name' => $name, 'display_name' => $name, 'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'storage_disk' => 'media_local', 'storage_bucket' => 'lf-test-media', 'storage_region' => 'ap-southeast-1',
            'storage_key' => "tenants/{$customer}/board/{$name}", 'checksum' => 'sha256:'.hash('sha256', $customer.$name.uniqid()),
            'file_size_bytes' => 32, 'page_count' => $pages, 'visibility' => 'private', 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
