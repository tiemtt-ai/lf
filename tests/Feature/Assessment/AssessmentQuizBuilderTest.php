<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The quiz page (ADR-0025 D6–D7): sections and chosen questions of the draft (step 2), then publishing an immutable
 * version with snapshots, archiving and opening registration (step 3).
 */
class AssessmentQuizBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
            'media.disk' => 'media_local',
        ]);
        Storage::fake('media_local');

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
    }

    public function test_a_new_quiz_opens_its_page_with_one_hidden_section(): void
    {
        $response = $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes', $this->quizPayload(), self::AJAX)->assertCreated();
        $quiz = (int) DB::table('core_assessment_quizzes')->value('id');
        $response->assertJson(['redirect' => self::ADMIN.'/quizzes/'.$quiz]);

        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz)->assertOk()
            ->assertSee('data-assessment-section="quizzes"', false)
            ->assertSeeText(__('lf.LF_assessment_quiz_never_published'))
            ->assertSeeText(__('lf.LF_assessment_quiz_questions_empty'))
            // One section: no section header row.
            ->assertDontSee('ui-quiz-section-row', false)
            // Tabs as on the Course Template page; Thông tin opens first, the others are rendered but hidden.
            // Người chấm only shows once the quiz has an essay / speaking question; this one is empty.
            ->assertSeeInOrder([__('lf.LF_assessment_quiz_tab_info'), __('lf.LF_assessment_quiz_tab_questions'),
                __('lf.LF_assessment_quiz_tab_publish')])
            ->assertDontSeeText(__('lf.LF_assessment_quiz_tab_graders'))
            ->assertSee('<input type="hidden" name="tab" value="info">', false);

        // ?tab= opens that tab and keeps it for live refreshes; an unknown tab falls back to Thông tin.
        $page = $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz.'?tab=publish')->assertOk()
            ->assertSee('<input type="hidden" name="tab" value="publish">', false);
        $this->assertMatchesRegularExpression('/\?tab=publish"\s+class="course-template-tab is-active"\s+aria-current="page"/', $page->getContent());
        $this->assertMatchesRegularExpression('/id="assessment-quiz-tab-info"[^>]*\shidden\s*>/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/id="assessment-quiz-tab-publish"[^>]*\shidden\s*>/', $page->getContent());
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz.'?tab=nope')->assertOk()
            ->assertSee('<input type="hidden" name="tab" value="info">', false);
    }

    public function test_questions_and_passages_are_added_in_order_with_their_default_points(): void
    {
        $quiz = $this->quiz($this->admin);
        $single = $this->question($this->admin, 'Câu lẻ', '2.5');
        $draft = $this->question($this->admin, 'Câu nháp', '1', 'draft');
        $passage = $this->passage($this->admin, 'Bài đọc');
        $childA = $this->question($this->admin, 'Con A', '1', 'published', $passage);
        $childDraft = $this->question($this->admin, 'Con nháp', '1', 'draft', $passage);
        $childB = $this->question($this->admin, 'Con B', '3', 'published', $passage);
        $section = $this->sectionIds($quiz)[0];

        // The picker lists only ready single questions and passages with a ready child.
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz.'/questions/pick', self::AJAX)->assertOk()
            ->assertSeeText('Câu lẻ')->assertDontSeeText('Câu nháp')->assertDontSeeText('Con A')->assertSeeText('Bài đọc')
            // The passage source explains itself and sketches how a passage shows in the test.
            ->assertSee(e(__('lf.LF_assessment_quiz_picker_passages_tip')), false)->assertDontSee('ui-passage-demo', false);

        $this->add($quiz, $section, [$single], [$passage])->assertCreated()
            ->assertJson(['message' => trans_choice('lf.LF_assessment_quiz_questions_added', 3, ['count' => 3])]);
        $this->assertSame([[$single, 1, '2.50'], [$childA, 2, '1.00'], [$childB, 3, '3.00']], $this->items($quiz));

        // Draft questions are refused; adding the same again adds nothing.
        $this->add($quiz, $section, [$draft], [])->assertUnprocessable()->assertJsonValidationErrors(['question_ids']);
        $this->add($quiz, $section, [$single], [$passage])->assertUnprocessable()->assertJsonValidationErrors(['question_ids']);
        $this->add($quiz, $section, [], [])->assertUnprocessable()->assertJsonValidationErrors(['question_ids']);
        $this->assertNotContains($childDraft, array_column($this->items($quiz), 0));

        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz)->assertOk()
            ->assertSeeTextInOrder(['Câu lẻ', 'Con A', 'Con B'])
            ->assertSeeText(trans_choice('lf.LF_assessment_quiz_totals', 3, ['count' => 3, 'points' => '6.5']));
        $this->assertNotNull(DB::table('core_assessment_quizzes')->value('content_updated_at'));
    }

    public function test_points_section_order_and_removal_of_chosen_questions(): void
    {
        $quiz = $this->quiz($this->admin);
        [$a, $b, $c] = [$this->question($this->admin, 'A'), $this->question($this->admin, 'B'), $this->question($this->admin, 'C')];
        $first = $this->sectionIds($quiz)[0];
        $this->add($quiz, $first, [$a, $b, $c], [])->assertCreated();
        $item = fn (int $question) => (int) DB::table('core_assessment_quiz_questions')->where('question_id', $question)->value('id');
        $base = self::ADMIN.'/quizzes/'.$quiz;

        $this->actingAs($this->admin)->from($base)->post($base.'/questions/'.$item($c).'/move', ['direction' => 'up'])->assertRedirect($base);
        $this->assertSame([$a, $c, $b], array_column($this->items($quiz), 0));

        // A second section; moving C there puts it at that section's end and closes the gap.
        $this->actingAs($this->admin)->postJson($base.'/sections', ['title' => 'Nghe', 'skill_type' => 'listening'], self::AJAX)->assertCreated();
        $second = $this->sectionIds($quiz)[1];
        $this->actingAs($this->admin)->putJson($base.'/questions/'.$item($c), ['points' => '4.25', 'quiz_section_id' => $second], self::AJAX)->assertOk();
        $this->assertSame([[$a, 1, '1.00'], [$b, 2, '1.00'], [$c, 1, '4.25']], $this->items($quiz));
        $this->assertSame($second, (int) DB::table('core_assessment_quiz_questions')->where('question_id', $c)->value('quiz_section_id'));

        $this->actingAs($this->admin)->putJson($base.'/questions/'.$item($a), ['points' => '-1', 'quiz_section_id' => $first], self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['points']);
        $other = $this->quiz($this->admin);
        $this->actingAs($this->admin)->putJson($base.'/questions/'.$item($a), ['points' => '1', 'quiz_section_id' => $this->sectionIds($other)[0]], self::AJAX)
            ->assertNotFound();

        $this->actingAs($this->admin)->get($base)->assertOk()->assertSee('ui-quiz-section-row', false)->assertSeeText('Nghe');

        $this->actingAs($this->admin)->deleteJson($base.'/questions', ['ids' => [$item($a)]], self::AJAX)->assertOk();
        $this->assertSame([[$b, 1, '1.00'], [$c, 1, '4.25']], $this->items($quiz));
        $this->assertTrue(DB::table('core_assessment_questions')->where('id', $a)->exists());
    }

    public function test_sections_are_added_ordered_and_deleted_only_when_empty(): void
    {
        $quiz = $this->quiz($this->admin);
        $base = self::ADMIN.'/quizzes/'.$quiz;
        $this->actingAs($this->admin)->postJson($base.'/sections', ['title' => 'Đọc'], self::AJAX)->assertCreated();
        $this->actingAs($this->admin)->postJson($base.'/sections', ['title' => '', 'skill_type' => 'cooking'], self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'skill_type']);
        [$first, $second] = $this->sectionIds($quiz);

        $this->actingAs($this->admin)->post($base.'/sections/'.$second.'/move', ['direction' => 'up']);
        $this->assertSame([$second, $first], $this->sectionIds($quiz));
        $this->actingAs($this->admin)->putJson($base.'/sections/'.$second, ['title' => 'Đọc hiểu'], self::AJAX)->assertOk();
        $this->assertSame('Đọc hiểu', DB::table('core_assessment_quiz_sections')->where('id', $second)->value('title'));

        $this->add($quiz, $second, [$this->question($this->admin, 'Câu')], [])->assertCreated();
        $this->actingAs($this->admin)->deleteJson($base.'/sections/'.$second, [], self::AJAX)->assertStatus(409)
            ->assertJson(['message' => __('lf.LF_assessment_quiz_section_error_not_empty')]);
        $this->actingAs($this->admin)->deleteJson($base.'/sections/'.$first, [], self::AJAX)->assertOk();
        $this->assertSame([[$second, 1]], DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->get()
            ->map(fn ($s) => [(int) $s->id, (int) $s->sort_order])->all());
        $this->actingAs($this->admin)->deleteJson($base.'/sections/'.$second, [], self::AJAX)->assertStatus(409);
    }

    public function test_publishing_freezes_settings_questions_answers_passages_and_media(): void
    {
        $quiz = $this->quiz($this->admin, ['title' => 'TOPIK 01', 'duration_minutes' => '50', 'max_attempts' => '2', 'pass_score_percentage' => '60']);
        $choice = $this->question($this->admin, 'Chọn đúng', '2', 'published', null, [
            'options' => [['text' => 'Sai', 'correct' => 0], ['text' => 'Đúng', 'correct' => 1]],
            'explanation' => 'Vì vậy', 'prompt_image_file' => UploadedFile::fake()->image('a.png', 30, 20),
        ]);
        $short = $this->question($this->admin, 'Thủ đô', '1', 'published', null, ['question_type' => 'short_answer', 'accepted_answers' => ['Seoul', '서울']]);
        $passage = $this->passage($this->admin, 'Bài nghe', ['prompt_audio_file' => UploadedFile::fake()->create('n.mp3', 20, 'audio/mpeg')]);
        $essay = $this->question($this->admin, 'Viết', '5', 'published', $passage, ['question_type' => 'essay']);
        $section = $this->sectionIds($quiz)[0];
        $this->add($quiz, $section, [$choice, $short], [$passage])->assertCreated();
        $base = self::ADMIN.'/quizzes/'.$quiz;

        $this->actingAs($this->admin)->from($base)->post($base.'/publish')->assertRedirect($base)
            ->assertSessionHas('success', __('lf.LF_assessment_quiz_published', ['number' => 1]));

        $version = DB::table('core_assessment_quiz_versions')->first();
        $this->assertSame([1, 'TOPIK 01', 50, 2, '8.00', 3, $this->admin->id], [
            (int) $version->version_no, $version->title, (int) $version->duration_minutes, (int) $version->max_attempts,
            number_format((float) $version->total_points, 2, '.', ''), (int) $version->question_count, (int) $version->published_by,
        ]);
        $this->assertSame([['key' => 1, 'title' => __('lf.LF_assessment_quiz_default_section'), 'description' => null, 'skill_type' => null]],
            json_decode($version->sections_snapshot, true));
        $passages = json_decode($version->passages_snapshot, true);
        $this->assertSame('Bài nghe', $passages[(string) $passage]['title']);
        $this->assertSame('prompt_audio', $passages[(string) $passage]['media'][0]['media_role']);

        $quizRow = DB::table('core_assessment_quizzes')->find($quiz);
        $this->assertSame(['published', (int) $version->id], [$quizRow->status, (int) $quizRow->current_version_id]);

        $rows = DB::table('core_assessment_quiz_version_questions')->where('quiz_version_id', $version->id)->orderBy('sort_order')->get();
        $this->assertSame([$choice, $short, $essay], $rows->pluck('question_id')->map(fn ($id) => (int) $id)->all());
        [$c, $s, $e] = $rows->all();
        $snapshot = json_decode($c->question_snapshot, true);
        $this->assertSame(['Chọn đúng', 'Vì vậy', 'prompt_image'], [$snapshot['prompt'], $snapshot['explanation'], $snapshot['media'][0]['media_role']]);
        $this->assertSame([['key' => 1, 'label' => 'A', 'text' => 'Sai'], ['key' => 2, 'label' => 'B', 'text' => 'Đúng']], json_decode($c->options_snapshot, true));
        $this->assertSame(['option_keys' => [2]], json_decode($c->correct_answer_snapshot, true));
        $this->assertNull($s->options_snapshot);
        $this->assertSame(['accepted' => ['Seoul', '서울']], json_decode($s->correct_answer_snapshot, true));
        $this->assertSame(['essay', 1, null, $passage, '5.00'], [$e->question_type, (int) $e->requires_manual_grading, $e->correct_answer_snapshot,
            (int) $e->passage_id, number_format((float) $e->points, 2, '.', '')]);

        // The version holds its own media usages.
        $this->assertSame(2, DB::table('media_file_usages')->where('owner_type', 'assessment_quiz_version')
            ->where('owner_id', $version->id)->where('status', 'active')->count());

        // Editing the bank question later never changes the version.
        DB::table('core_assessment_question_contents')->where('question_id', $choice)->update(['prompt' => 'Đã sửa']);
        $this->assertSame('Chọn đúng', json_decode(DB::table('core_assessment_quiz_version_questions')->where('id', $c->id)->value('question_snapshot'), true)['prompt']);
        // A question in a version can no longer be deleted.
        $this->actingAs($this->admin)->deleteJson(self::ADMIN.'/questions', ['ids' => [$short]], self::AJAX)->assertStatus(409);
    }

    public function test_publishing_again_makes_a_new_version_and_reports_draft_changes(): void
    {
        $quiz = $this->quiz($this->admin);
        $section = $this->sectionIds($quiz)[0];
        $this->add($quiz, $section, [$this->question($this->admin, 'Một')], [])->assertCreated();
        $base = self::ADMIN.'/quizzes/'.$quiz;
        $this->actingAs($this->admin)->post($base.'/publish')->assertSessionHasNoErrors();

        $this->travel(5)->minutes();
        $this->actingAs($this->admin)->get($base)->assertOk()->assertDontSeeText(__('lf.LF_assessment_quiz_unpublished_changes'));
        $this->add($quiz, $section, [$this->question($this->admin, 'Hai')], [])->assertCreated();
        $this->actingAs($this->admin)->get($base)->assertOk()->assertSeeText(__('lf.LF_assessment_quiz_unpublished_changes'))
            ->assertSeeText(__('lf.LF_assessment_quiz_publish_again'));

        $this->actingAs($this->admin)->post($base.'/publish')->assertSessionHasNoErrors();
        $versions = DB::table('core_assessment_quiz_versions')->where('quiz_id', $quiz)->orderBy('version_no')->get();
        $this->assertSame([[1, 1], [2, 2]], $versions->map(fn ($v) => [(int) $v->version_no, (int) $v->question_count])->all());
        $this->assertSame((int) $versions->last()->id, (int) DB::table('core_assessment_quizzes')->value('current_version_id'));
        $this->actingAs($this->admin)->get($base)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_quiz_version_number', ['number' => 2]))
            ->assertSeeText(__('lf.LF_assessment_quiz_version_current'));
    }

    public function test_publish_refuses_an_invalid_draft(): void
    {
        $quiz = $this->quiz($this->admin);
        $base = self::ADMIN.'/quizzes/'.$quiz;
        $this->actingAs($this->admin)->from($base)->post($base.'/publish')->assertRedirect($base)
            ->assertSessionHasErrors(['publish' => __('lf.LF_assessment_quiz_publish_error_empty')]);

        // A passage split across two sections, and a question that went back to draft.
        $passage = $this->passage($this->admin, 'Bài đọc');
        $childA = $this->question($this->admin, 'Con A', '1', 'published', $passage);
        $this->question($this->admin, 'Con B', '1', 'published', $passage);
        $single = $this->question($this->admin, 'Lẻ');
        $first = $this->sectionIds($quiz)[0];
        $this->add($quiz, $first, [$single], [$passage])->assertCreated();
        $this->actingAs($this->admin)->postJson($base.'/sections', ['title' => 'Phần 2'], self::AJAX)->assertCreated();
        $itemA = (int) DB::table('core_assessment_quiz_questions')->where('question_id', $childA)->value('id');
        $this->actingAs($this->admin)->putJson($base.'/questions/'.$itemA, ['points' => '1', 'quiz_section_id' => $this->sectionIds($quiz)[1]], self::AJAX)->assertOk();
        DB::table('core_assessment_questions')->where('id', $single)->update(['status' => 'draft']);

        $this->actingAs($this->admin)->from($base)->post($base.'/publish')->assertRedirect($base)->assertSessionHasErrors('publish');
        $errors = session('errors')->get('publish');
        $this->assertContains(__('lf.LF_assessment_quiz_publish_error_passage_split', ['passage' => 'Bài đọc']), $errors);
        $this->assertContains(__('lf.LF_assessment_quiz_publish_error_question_not_ready', ['question' => __('lf.LF_assessment_quiz_question_number', ['number' => 1])]), $errors);
        $this->assertSame(0, DB::table('core_assessment_quiz_versions')->count());
        $this->assertSame('draft', DB::table('core_assessment_quizzes')->value('status'));

        $this->actingAs($this->admin)->get($base)->assertOk()->assertSeeText(__('lf.LF_assessment_quiz_item_not_ready'));
    }

    public function test_archive_restore_and_registration_follow_the_lifecycle(): void
    {
        $quiz = $this->quiz($this->admin);
        $base = self::ADMIN.'/quizzes/'.$quiz;
        $state = fn () => [DB::table('core_assessment_quizzes')->value('status'), (int) DB::table('core_assessment_quizzes')->value('registration_open')];

        // A draft can be neither opened nor archived.
        $this->actingAs($this->admin)->post($base.'/registration', ['open' => 1])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->post($base.'/archive')->assertSessionHasErrors('status');
        $this->assertSame(['draft', 0], $state());

        $this->add($quiz, $this->sectionIds($quiz)[0], [$this->question($this->admin, 'Một')], [])->assertCreated();
        $this->actingAs($this->admin)->post($base.'/publish')->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($base.'/registration', ['open' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['published', 1], $state());
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes')->assertOk()->assertSeeText(__('lf.LF_assessment_quiz_registration_open'));

        // Archiving closes registration; an archived quiz cannot be published or opened.
        $this->actingAs($this->admin)->post($base.'/archive')->assertSessionHasNoErrors();
        $this->assertSame(['archived', 0], $state());
        $this->actingAs($this->admin)->post($base.'/publish')->assertSessionHasErrors('publish');
        $this->actingAs($this->admin)->post($base.'/registration', ['open' => 1])->assertSessionHasErrors('status');
        $this->assertSame(1, DB::table('core_assessment_quiz_versions')->count());

        $this->actingAs($this->admin)->post($base.'/restore')->assertSessionHasNoErrors();
        $this->assertSame(['published', 0], $state());
        $this->actingAs($this->admin)->post($base.'/registration', ['open' => 0])->assertSessionHasNoErrors();
        $this->assertSame(['published', 0], $state());
    }

    public function test_teachers_build_only_their_own_quizzes_from_questions_they_can_see(): void
    {
        $other = $this->createUser($this->customerId, 'teacher');
        $mine = $this->quiz($this->teacher);
        $theirs = $this->quiz($other);
        $myQuestion = $this->question($this->teacher, 'Câu của tôi');
        $theirQuestion = $this->question($other, 'Câu người khác');
        $theirPassage = $this->passage($other, 'Ngữ liệu người khác');
        $this->question($other, 'Con', '1', 'published', $theirPassage);
        $section = $this->sectionIds($mine)[0];

        $this->actingAs($this->teacher)->get(self::TEACHER.'/quizzes/'.$mine.'/questions/pick', self::AJAX)->assertOk()
            ->assertSeeText('Câu của tôi')->assertDontSeeText('Câu người khác')->assertDontSeeText('Ngữ liệu người khác');
        $this->add($mine, $section, [$theirQuestion], [], $this->teacher)->assertNotFound();
        $this->add($mine, $section, [], [$theirPassage], $this->teacher)->assertNotFound();
        $this->add($mine, $section, [$myQuestion], [], $this->teacher)->assertCreated();

        $base = self::TEACHER.'/quizzes/'.$theirs;
        $this->actingAs($this->teacher)->get($base)->assertNotFound();
        $this->actingAs($this->teacher)->post($base.'/publish')->assertNotFound();
        $this->actingAs($this->teacher)->postJson($base.'/sections', ['title' => 'X'], self::AJAX)->assertNotFound();
        $this->add($theirs, $this->sectionIds($theirs)[0], [$myQuestion], [], $this->teacher)->assertNotFound();

        $this->actingAs($this->teacher)->post(self::TEACHER.'/quizzes/'.$mine.'/publish')->assertSessionHasNoErrors();
        $this->assertSame('published', DB::table('core_assessment_quizzes')->where('id', $mine)->value('status'));
    }

    public function test_another_tenant_and_students_cannot_reach_the_quiz_page(): void
    {
        $quiz = $this->quiz($this->admin);
        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');
        $base = 'https://tenant-b.localhost/admin/assessments/quizzes/'.$quiz;

        $this->actingAs($otherAdmin)->get($base)->assertNotFound();
        $this->actingAs($otherAdmin)->post($base.'/publish')->assertNotFound();
        $this->actingAs($otherAdmin)->deleteJson($base.'/questions', ['ids' => [1]], self::AJAX)->assertNotFound();

        $student = $this->createUser($this->customerId, 'student');
        $this->actingAs($student)->get(self::ADMIN.'/quizzes/'.$quiz)->assertForbidden();
        $this->actingAs($student)->post(self::TEACHER.'/quizzes/'.$quiz.'/publish')->assertForbidden();
    }

    // ---- fixtures ----

    /** @param  array<string, mixed>  $overrides */
    private function quizPayload(array $overrides = []): array
    {
        return $overrides + ['title' => 'Đề mẫu', 'assessment_type' => 'exam', 'max_attempts' => '1', 'show_result_mode' => 'after_grading'];
    }

    /** @param  array<string, mixed>  $overrides */
    private function quiz(User $user, array $overrides = []): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/quizzes', $this->quizPayload($overrides), self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_quizzes')->max('id');
    }

    /** @param  array<string, mixed>  $extra */
    private function question(User $user, string $prompt, string $points = '1', string $status = 'published', ?int $passageId = null, array $extra = []): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $payload = $extra + [
            'question_type' => 'single_choice',
            'default_points' => $points,
            'status' => $status,
            'prompt' => $prompt,
            'options' => [['text' => 'Một', 'correct' => 1], ['text' => 'Hai', 'correct' => 0]],
        ];
        if ($passageId !== null) {
            $payload['passage_id'] = $passageId;
        }
        $this->actingAs($user)->post($base.'/questions', $payload, self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_questions')->max('id');
    }

    /** @param  array<string, mixed>  $files */
    private function passage(User $user, string $title, array $files = []): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->post($base.'/passages', $files + ['title' => $title, 'status' => 'published'], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_passages')->max('id');
    }

    /**
     * @param  list<int>  $questionIds
     * @param  list<int>  $passageIds
     */
    private function add(int $quizId, int $sectionId, array $questionIds, array $passageIds, ?User $user = null): TestResponse
    {
        $user ??= $this->admin;
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;

        return $this->actingAs($user)->postJson($base.'/quizzes/'.$quizId.'/questions', [
            'quiz_section_id' => $sectionId, 'question_ids' => $questionIds, 'passage_ids' => $passageIds,
        ], self::AJAX);
    }

    /** @return list<int> */
    private function sectionIds(int $quizId): array
    {
        return DB::table('core_assessment_quiz_sections')->where('quiz_id', $quizId)->orderBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<array{int, int, string}> question id, sort order, points — in section then question order */
    private function items(int $quizId): array
    {
        return DB::table('core_assessment_quiz_questions as qq')
            ->join('core_assessment_quiz_sections as s', 's.id', '=', 'qq.quiz_section_id')
            ->where('qq.quiz_id', $quizId)->orderBy('s.sort_order')->orderBy('qq.sort_order')
            ->get(['qq.question_id', 'qq.sort_order', 'qq.points'])
            ->map(fn ($row) => [(int) $row->question_id, (int) $row->sort_order, number_format((float) $row->points, 2, '.', '')])->all();
    }

    private function createTenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
