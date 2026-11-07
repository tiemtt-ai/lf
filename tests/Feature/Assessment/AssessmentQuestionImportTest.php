<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Support\SimpleXlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nhập câu hỏi từ Excel / CSV: every row goes through the same save rules as the form, a file with any bad row saves
 * nothing, the importer owns the questions, and the template file reads back into the importer.
 */
class AssessmentQuestionImportTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments/questions';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments/questions';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private const HEADER = ['Dạng câu', 'Đề bài', 'A', 'B', 'C', 'D', 'E', 'F', 'Đáp án', 'Điểm', 'Kỹ năng', 'Độ khó', 'Giải thích', 'Đáp án mẫu'];

    private int $customerId;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
    }

    public function test_xlsx_rows_become_questions_of_every_type_owned_by_the_importer(): void
    {
        $category = $this->category('Sơ cấp 1');
        $file = $this->xlsx([
            self::HEADER,
            ['Trắc nghiệm một đáp án', '"학생" nghĩa là gì?', 'Giáo viên', 'Học sinh', 'Bác sĩ', '', '', '', 'B', '1', 'Đọc', 'Dễ', 'Học sinh = 학생', ''],
            ['multiple_choice', 'Động từ?', '가다', '예쁘다', '먹다', '', '', '', 'a, c', '2,5', 'reading', 'medium', '', ''],
            ['Đúng / Sai', 'Bảng chữ cái là 한글.', '', '', '', '', '', '', 'Sai', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['Điền đáp án ngắn', 'Cảm ơn?', '', '', '', '', '', '', '감사합니다 | 고맙습니다 | 감사합니다', '1', 'Viết', '', '', ''],
            ['Tự luận', 'Viết 3 câu.', '', '', '', '', '', '', '', '3', '', 'Khó', '', 'Đủ 3 câu.'],
            ['Nói (ghi âm)', 'Đọc to.', '', '', '', '', '', '', '', '2', 'Nói', '', '', ''],
        ]);

        $this->actingAs($this->teacher)
            ->post(self::TEACHER.'/import', ['file' => $file, 'category_id' => $category, 'status' => 'published'], self::AJAX)
            ->assertCreated()
            ->assertJson(['message' => 'Đã nhập 6 câu hỏi.']);

        $questions = DB::table('core_assessment_questions')->orderBy('id')->get();
        $this->assertSame(['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'essay', 'speaking'], $questions->pluck('question_type')->all());
        $this->assertSame([$this->teacher->id], $questions->pluck('created_by')->unique()->values()->all());
        $this->assertSame([$category], $questions->pluck('category_id')->unique()->values()->all());
        $this->assertSame(['published'], $questions->pluck('status')->unique()->values()->all());
        $this->assertEquals(2.5, (float) $questions[1]->default_points);
        $this->assertSame(['reading', 'easy'], [$questions[0]->skill_type, $questions[0]->difficulty_level]);

        $options = fn (int $index) => DB::table('core_assessment_question_options')->where('question_id', $questions[$index]->id)
            ->orderBy('sort_order')->get()->map(fn ($o) => [$o->option_text, (bool) $o->is_correct])->all();
        $this->assertSame([['Giáo viên', false], ['Học sinh', true], ['Bác sĩ', false]], $options(0));
        $this->assertSame([['가다', true], ['예쁘다', false], ['먹다', true]], $options(1));
        $this->assertSame([['Đúng', false], ['Sai', true]], $options(2));
        $this->assertSame([['감사합니다', true], ['고맙습니다', true]], $options(3));
        $this->assertSame('Đủ 3 câu.', DB::table('core_assessment_question_contents')->where('question_id', $questions[4]->id)->value('reference_answer'));
        $this->assertSame('Học sinh = 학생', DB::table('core_assessment_question_contents')->where('question_id', $questions[0]->id)->value('explanation'));
    }

    public function test_a_file_with_a_bad_row_saves_nothing_and_names_each_row(): void
    {
        $file = $this->xlsx([
            self::HEADER,
            ['single_choice', 'Đúng một câu', 'X', 'Y', '', '', '', '', 'A', '1', '', '', '', ''],
            ['single_choice', 'Đáp án lạ', 'X', 'Y', '', '', '', '', 'D', '1', '', '', '', ''],
            ['bài hát', 'Dạng lạ', '', '', '', '', '', '', '', '1', '', '', '', ''],
            ['single_choice', 'Thiếu đáp án', 'X', 'Y', '', '', '', '', '', '1', '', '', '', ''],
            ['essay', '', '', '', '', '', '', '', '', 'abc', '', '', '', ''],
        ]);

        $response = $this->actingAs($this->admin)
            ->post(self::ADMIN.'/import', ['file' => $file, 'status' => 'published'], self::AJAX)
            ->assertUnprocessable();

        $message = $response->json('errors.file.0');
        $this->assertStringContainsString('Dòng 3: đáp án "D"', $message);
        $this->assertStringContainsString('Dòng 4: dạng câu "bài hát"', $message);
        $this->assertStringContainsString('Dòng 5: câu "Trắc nghiệm một đáp án" chưa đủ', $message);
        $this->assertStringContainsString('Dòng 6: thiếu đề bài', $message);
        $this->assertStringNotContainsString('Dòng 2', $message);
        $this->assertSame(0, DB::table('core_assessment_questions')->count());
    }

    public function test_draft_import_accepts_incomplete_answers_and_csv_with_semicolons(): void
    {
        $csv = "\xEF\xBB\xBF".implode("\n", [
            implode(';', self::HEADER),
            'single_choice;Chưa có đáp án;X;Y;;;;;;1;;;;',
            'short_answer;"Có ; trong ô";;;;;;;"a | b";1;;;;',
        ]);
        $file = UploadedFile::fake()->createWithContent('cau-hoi.csv', $csv);

        $this->actingAs($this->admin)
            ->post(self::ADMIN.'/import', ['file' => $file, 'status' => 'draft'], self::AJAX)
            ->assertCreated();

        $this->assertSame(['draft'], DB::table('core_assessment_questions')->pluck('status')->unique()->values()->all());
        $this->assertSame('Có ; trong ô', DB::table('core_assessment_question_contents')->orderByDesc('id')->value('prompt'));
    }

    public function test_an_archived_or_foreign_category_is_refused(): void
    {
        $archived = $this->category('Cũ', 'archived');
        $file = fn () => $this->xlsx([self::HEADER, ['essay', 'Viết', '', '', '', '', '', '', '', '1', '', '', '', '']]);

        $this->actingAs($this->admin)
            ->post(self::ADMIN.'/import', ['file' => $file(), 'category_id' => $archived, 'status' => 'published'], self::AJAX)
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Dòng 2: '.__('lf.LF_assessment_question_error_category'));

        $other = $this->category('Khác', 'active', $this->createTenant('tenant-b'));
        $this->actingAs($this->admin)
            ->post(self::ADMIN.'/import', ['file' => $file(), 'category_id' => $other, 'status' => 'published'], self::AJAX)
            ->assertUnprocessable();
        $this->assertSame(0, DB::table('core_assessment_questions')->count());
    }

    public function test_unreadable_empty_and_wrong_files_are_refused(): void
    {
        $post = fn ($file) => $this->actingAs($this->admin)->post(self::ADMIN.'/import', ['file' => $file, 'status' => 'published'], self::AJAX);

        $post(UploadedFile::fake()->createWithContent('hong.xlsx', 'not a zip'))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', __('lf.LF_assessment_question_import_error_unreadable'));
        $post($this->xlsx([self::HEADER]))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', __('lf.LF_assessment_question_import_error_empty'));
        $post(UploadedFile::fake()->createWithContent('anh.png', 'x'))->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_template_downloads_and_imports_back(): void
    {
        $response = $this->actingAs($this->teacher)->get(self::TEACHER.'/import/template')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $rows = SimpleXlsx::read($path);
        $this->assertSame(__('lf.LF_assessment_question_import_col_type'), $rows[0][0]);

        $file = new UploadedFile($path, 'mau.xlsx', null, null, true);
        $this->actingAs($this->teacher)
            ->post(self::TEACHER.'/import', ['file' => $file, 'status' => 'published'], self::AJAX)
            ->assertCreated()
            ->assertJson(['message' => 'Đã nhập 6 câu hỏi.']);
    }

    public function test_export_follows_scope_and_filters_and_imports_back(): void
    {
        $this->importTemplateAs($this->teacher, self::TEACHER);
        $this->actingAs($this->admin)
            ->post(self::ADMIN.'/import', ['file' => $this->xlsx([self::HEADER, ['Tự luận', 'Câu của admin', '', '', '', '', '', '', '', '1', '', '', '', '']]), 'status' => 'draft'], self::AJAX)
            ->assertCreated();

        $teacherRows = $this->exportAs($this->teacher, self::TEACHER.'/export');
        $this->assertSame(__('lf.LF_assessment_question_import_col_type'), $teacherRows[0][0]);
        $this->assertCount(7, $teacherRows);
        $this->assertNotContains('Câu của admin', array_column($teacherRows, 1));

        $this->assertCount(8, $this->exportAs($this->admin, self::ADMIN.'/export'));
        $essays = $this->exportAs($this->admin, self::ADMIN.'/export?question_type=essay&status=draft');
        // An essay carries its "Cho nộp tệp" (ADR-0027 D5); a file without the column imported it as no.
        $this->assertSame([$teacherRows[0], ['Tự luận', 'Câu của admin', '', '', '', '', '', '', '', '1', '', '', '', '', 'Không']], $essays);
        $this->assertSame('Có', collect($teacherRows)->firstWhere(0, 'Tự luận')[14]);

        // What comes out goes back in unchanged.
        $before = DB::table('core_assessment_questions')->count();
        $this->actingAs($this->admin)
            ->post(self::ADMIN.'/import', ['file' => $this->xlsx($teacherRows), 'status' => 'published'], self::AJAX)
            ->assertCreated()
            ->assertJson(['message' => 'Đã nhập 6 câu hỏi.']);
        $this->assertSame($before + 6, DB::table('core_assessment_questions')->count());
        // Admin now sees the teacher's six and its own six copies: the same rows twice.
        $expected = [...array_slice($teacherRows, 1), ...array_slice($teacherRows, 1)];
        $actual = array_slice($this->exportAs($this->admin, self::ADMIN.'/export?status=published'), 1);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);

        $student = $this->createUser($this->customerId, 'student');
        $this->actingAs($student)->get(self::TEACHER.'/export')->assertForbidden();
    }

    public function test_dialog_and_button_are_on_the_page_and_students_are_kept_out(): void
    {
        $this->actingAs($this->teacher)->get(self::TEACHER)->assertOk()
            ->assertSee('data-url="'.self::TEACHER.'/import"', false)
            ->assertSee('href="'.self::TEACHER.'/export"', false);
        $this->actingAs($this->teacher)->get(self::TEACHER.'/import', self::AJAX)->assertOk()
            ->assertSee('name="file"', false)
            ->assertSee(self::TEACHER.'/import/template', false);

        $student = $this->createUser($this->customerId, 'student');
        $this->actingAs($student)->post(self::TEACHER.'/import', ['status' => 'published'], self::AJAX)->assertForbidden();
    }

    private function importTemplateAs(User $user, string $base): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $this->actingAs($user)->get($base.'/import/template')->assertOk()->getContent());
        $this->actingAs($user)
            ->post($base.'/import', ['file' => new UploadedFile($path, 'mau.xlsx', null, null, true), 'status' => 'published'], self::AJAX)
            ->assertCreated();
    }

    /** @return list<list<string>> */
    private function exportAs(User $user, string $url): array
    {
        $response = $this->actingAs($user)->get($url)->assertOk();
        $this->assertStringContainsString('attachment; filename="cau-hoi-', $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($path, $response->getContent());

        return SimpleXlsx::read($path);
    }

    /** @param  list<list<string>>  $rows */
    private function xlsx(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cau-hoi.xlsx', SimpleXlsx::write($rows));
    }

    private function category(string $name, string $status = 'active', ?int $customerId = null): int
    {
        return DB::table('core_assessment_categories')->insertGetId([
            'customer_id' => $customerId ?? $this->customerId, 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createTenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
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
