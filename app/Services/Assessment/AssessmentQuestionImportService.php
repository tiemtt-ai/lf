<?php

namespace App\Services\Assessment;

use App\Models\User;
use App\Support\SimpleXlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Nhập câu hỏi từ Excel (.xlsx) hoặc CSV: one question per row, fixed columns (see COLUMNS / the template). The whole
 * file is checked first and saved in one transaction, so a file with any bad row saves nothing. Every row goes
 * through AssessmentQuestionService::save, so the same answer and publish rules as the form apply.
 *
 * Plain text only (Owner 2026-11-06); passages and media are not imported.
 */
final class AssessmentQuestionImportService
{
    public const MAX_ROWS = 500;

    /** Column keys in file order. */
    public const COLUMNS = [
        'type', 'prompt', 'option_a', 'option_b', 'option_c', 'option_d', 'option_e', 'option_f',
        'answer', 'points', 'skill', 'level', 'explanation', 'reference_answer',
    ];

    private const OPTION_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

    public function __construct(private readonly AssessmentQuestionService $questions) {}

    /** Header + three sample rows for the template file, in the current locale. */
    public function templateRows(): array
    {
        $header = array_map(fn (string $key) => __('lf.LF_assessment_question_import_col_'.$key), self::COLUMNS);
        $label = fn (string $group, string $value) => __('lf.LF_assessment_question_'.$group.'_'.$value);

        return [
            $header,
            [$label('type', 'single_choice'), '"학생" nghĩa là gì?', 'Giáo viên', 'Học sinh', 'Bác sĩ', 'Nhân viên', '', '', 'B', '1', $label('skill', 'reading'), $label('level', 'easy'), '', ''],
            [$label('type', 'multiple_choice'), 'Những từ nào là động từ?', '가다', '예쁘다', '먹다', '크다', '', '', 'A,C', '2', $label('skill', 'reading'), $label('level', 'medium'), '', ''],
            [$label('type', 'true_false'), 'Bảng chữ cái tiếng Hàn được gọi là 한글.', '', '', '', '', '', '', __('lf.LF_assessment_question_true'), '1', '', '', '', ''],
            [$label('type', 'short_answer'), 'Viết bằng tiếng Hàn: "Cảm ơn".', '', '', '', '', '', '', '감사합니다 | 고맙습니다', '1', $label('skill', 'writing'), '', '', ''],
            [$label('type', 'essay'), 'Viết 3 câu về một ngày của bạn.', '', '', '', '', '', '', '', '3', $label('skill', 'writing'), '', '', 'Dùng đúng đuôi -아요/어요, đủ 3 câu.'],
            [$label('type', 'speaking'), 'Đọc to: 저는 베트남 사람이에요.', '', '', '', '', '', '', '', '2', $label('skill', 'speaking'), '', '', ''],
        ];
    }

    /**
     * Xuất Excel: header + one row per question, in the import columns, so an exported file can be edited and imported
     * again. Only questions the user may see; filters as on the Câu hỏi list.
     *
     * @param  array{keyword: string, category_id: int|null, question_type: string|null, status: string|null}  $filters
     * @return list<list<string>>
     */
    public function exportRows(int $customerId, User $user, array $filters): array
    {
        $locale = $this->questions->locale();
        $questions = $this->questions->scoped($customerId, $user, 'q')
            ->leftJoin('core_assessment_question_contents as c', function ($join) use ($locale): void {
                $join->on('c.question_id', '=', 'q.id')->on('c.customer_id', '=', 'q.customer_id')->where('c.locale', '=', $locale);
            })
            ->when($filters['keyword'] !== '', fn ($query) => $query->where('c.prompt', 'like', '%'.$filters['keyword'].'%'))
            ->when($filters['category_id'], fn ($query, $id) => $query->where('q.category_id', $id))
            ->when($filters['question_type'], fn ($query, $type) => $query->where('q.question_type', $type))
            ->when($filters['status'], fn ($query, $status) => $query->where('q.status', $status))
            ->orderBy('q.id')
            ->limit(5000)
            ->get(['q.id', 'q.question_type', 'q.skill_type', 'q.difficulty_level', 'q.default_points', 'c.prompt', 'c.explanation', 'c.reference_answer']);

        $options = DB::table('core_assessment_question_options')->where('customer_id', $customerId)
            ->whereIn('question_id', $questions->pluck('id'))->orderBy('sort_order')->get()->groupBy('question_id');
        $label = fn (string $group, ?string $value) => $value === null || $value === '' ? '' : __('lf.LF_assessment_question_'.$group.'_'.$value);
        $points = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        $rows = [$this->templateRows()[0]];
        foreach ($questions as $question) {
            $own = $options->get($question->id, collect())->values();
            $choices = array_fill(0, count(self::OPTION_LETTERS), '');
            $answer = '';

            if (in_array($question->question_type, ['single_choice', 'multiple_choice'], true)) {
                foreach ($own->take(count(self::OPTION_LETTERS)) as $i => $option) {
                    $choices[$i] = (string) $option->option_text;
                }
                $answer = $own->take(count(self::OPTION_LETTERS))->filter(fn ($o) => (bool) $o->is_correct)
                    ->keys()->map(fn ($i) => self::OPTION_LETTERS[$i])->implode(',');
            } elseif ($question->question_type === 'true_false') {
                $correct = $own->search(fn ($o) => (bool) $o->is_correct);
                $answer = $correct === false ? '' : __($correct === 0 ? 'lf.LF_assessment_question_true' : 'lf.LF_assessment_question_false');
            } elseif ($question->question_type === 'short_answer') {
                $answer = $own->pluck('option_text')->implode(' | ');
            }

            $rows[] = [
                $label('type', $question->question_type), (string) $question->prompt, ...$choices, $answer,
                $points($question->default_points), $label('skill', $question->skill_type), $label('level', $question->difficulty_level),
                (string) $question->explanation, (string) $question->reference_answer,
            ];
        }

        return $rows;
    }

    /**
     * Reads the file into rows (header row included). Throws a validation error on the `file` field when the file
     * cannot be read.
     *
     * @return list<list<string>>
     */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $rows = $extension === 'csv' ? $this->readCsv((string) $file->getRealPath()) : SimpleXlsx::read((string) $file->getRealPath(), self::MAX_ROWS + 50);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('lf.LF_assessment_question_import_error_unreadable')]);
        }

        return $rows;
    }

    /**
     * Validates every row and saves all of them, or none. Returns the number of questions created.
     *
     * @param  list<list<string>>  $rows  including the header row
     */
    public function import(int $customerId, User $user, array $rows, ?int $categoryId, string $status): int
    {
        // Row 1 is the header; blank rows are skipped. Row numbers in messages are the spreadsheet's own.
        $items = [];
        foreach (array_slice($rows, 1, null, true) as $index => $row) {
            $cells = array_map(fn ($value) => trim((string) $value), $row);
            if (implode('', $cells) === '') {
                continue;
            }
            $items[$index + 1] = array_combine(self::COLUMNS, array_pad(array_slice($cells, 0, count(self::COLUMNS)), count(self::COLUMNS), ''));
        }

        if ($items === []) {
            throw ValidationException::withMessages(['file' => __('lf.LF_assessment_question_import_error_empty')]);
        }
        if (count($items) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('lf.LF_assessment_question_import_error_too_many', ['max' => self::MAX_ROWS])]);
        }

        $errors = [];
        $data = [];
        foreach ($items as $line => $item) {
            try {
                $data[$line] = $this->rowData($item, $categoryId, $status);
            } catch (ValidationException $exception) {
                $errors[$line] = collect($exception->errors())->flatten()->first();
            }
        }

        if ($errors === []) {
            // Saving checks what only the database knows (category, publish rules); a failure there names its row too.
            try {
                DB::transaction(function () use ($customerId, $user, $data, &$errors): void {
                    foreach ($data as $line => $values) {
                        try {
                            $this->questions->save($customerId, $user, null, $values);
                        } catch (ValidationException $exception) {
                            $errors[$line] = collect($exception->errors())->flatten()->first();
                            throw $exception;
                        }
                    }
                });
            } catch (ValidationException $exception) {
                if ($errors === []) {
                    throw $exception;
                }
            }
        }

        if ($errors !== []) {
            $lines = collect($errors)->take(10)
                ->map(fn ($message, $line) => __('lf.LF_assessment_question_import_error_row', ['row' => $line, 'message' => $message]))
                ->values();
            if (count($errors) > 10) {
                $lines->push(__('lf.LF_assessment_question_import_error_more', ['count' => count($errors) - 10]));
            }

            throw ValidationException::withMessages(['file' => $lines->implode("\n")]);
        }

        return count($data);
    }

    /** @return array<string, mixed> the shape AssessmentQuestionService::save takes */
    private function rowData(array $item, ?int $categoryId, string $status): array
    {
        $fail = fn (string $key, array $replace = []) => throw ValidationException::withMessages(['row' => __('lf.LF_assessment_question_import_error_'.$key, $replace)]);

        $type = $this->match($item['type'], AssessmentQuestionService::TYPES, 'type');
        if ($type === null) {
            $fail('type', ['value' => $item['type']]);
        }
        if ($item['prompt'] === '') {
            $fail('prompt');
        }
        if (mb_strlen($item['prompt']) > 20000 || mb_strlen($item['explanation']) > 20000 || mb_strlen($item['reference_answer']) > 20000) {
            $fail('too_long');
        }

        $points = $item['points'] === '' ? '1' : str_replace(',', '.', $item['points']);
        if (! is_numeric($points) || (float) $points < 0 || (float) $points > 999999.99 || preg_match('/\.\d{3,}$/', $points)) {
            $fail('points', ['value' => $item['points']]);
        }

        $skill = $item['skill'] === '' ? null : $this->match($item['skill'], AssessmentQuestionService::SKILLS, 'skill');
        if ($item['skill'] !== '' && $skill === null) {
            $fail('skill', ['value' => $item['skill']]);
        }
        $level = $item['level'] === '' ? null : $this->match($item['level'], AssessmentQuestionService::LEVELS, 'level');
        if ($item['level'] !== '' && $level === null) {
            $fail('level', ['value' => $item['level']]);
        }

        $options = [];
        $accepted = [];
        $trueFalse = null;
        $answer = $item['answer'];

        if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $choices = [];
            foreach (self::OPTION_LETTERS as $i => $letter) {
                $text = $item['option_'.strtolower($letter)];
                if ($text !== '') {
                    if (mb_strlen($text) > 2000) {
                        $fail('too_long');
                    }
                    $choices[$letter] = $text;
                }
            }
            $letters = array_values(array_filter(array_map(fn ($part) => strtoupper(trim($part)), preg_split('/[,;\s]+/', $answer) ?: [])));
            foreach ($letters as $letter) {
                if (! isset($choices[$letter])) {
                    $fail('answer_letter', ['value' => $letter]);
                }
            }
            if ($type === 'single_choice' && count(array_unique($letters)) > 1) {
                $fail('answer_single');
            }
            foreach ($choices as $letter => $text) {
                $options[] = ['text' => $text, 'correct' => in_array($letter, $letters, true)];
            }
        } elseif ($type === 'true_false') {
            $normalized = mb_strtolower($answer);
            $trueWords = array_map('mb_strtolower', ['true', 'đúng', 'dung', 'đ', __('lf.LF_assessment_question_true', [], 'vi'), __('lf.LF_assessment_question_true', [], 'en')]);
            $falseWords = array_map('mb_strtolower', ['false', 'sai', 's', __('lf.LF_assessment_question_false', [], 'vi'), __('lf.LF_assessment_question_false', [], 'en')]);
            $trueFalse = in_array($normalized, $trueWords, true) ? 'true' : (in_array($normalized, $falseWords, true) ? 'false' : null);
            if ($answer !== '' && $trueFalse === null) {
                $fail('answer_true_false', ['value' => $answer]);
            }
        } elseif ($type === 'short_answer') {
            $accepted = collect(explode('|', $answer))->map(fn ($text) => trim($text))->filter(fn ($text) => $text !== '')
                ->unique(fn ($text) => mb_strtolower($text))->values()->all();
            if (collect($accepted)->contains(fn ($text) => mb_strlen($text) > 500)) {
                $fail('too_long');
            }
        }

        // Publishing needs a complete answer; say so per row before anything is saved.
        if ($status === 'published') {
            $problem = AssessmentQuestionService::answerProblem($type, match ($type) {
                'single_choice', 'multiple_choice' => $options,
                'true_false' => [['text' => 'T', 'correct' => $trueFalse === 'true'], ['text' => 'F', 'correct' => $trueFalse === 'false']],
                'short_answer' => array_map(fn ($text) => ['text' => $text, 'correct' => true], $accepted),
                default => [],
            });
            if ($problem !== null) {
                $fail('answer_missing', ['type' => __('lf.LF_assessment_question_type_'.$type)]);
            }
        }

        $nullable = fn (string $value) => $value === '' ? null : $value;

        return [
            'question_type' => $type,
            'category_id' => $categoryId,
            'skill_type' => $skill,
            'difficulty_level' => $level,
            'default_points' => number_format((float) $points, 2, '.', ''),
            'status' => $status,
            'prompt' => $item['prompt'],
            'explanation' => $nullable($item['explanation']),
            'reference_answer' => $nullable($item['reference_answer']),
            'options' => $options,
            'accepted_answers' => $accepted,
            'true_false_answer' => $trueFalse,
            'passage_id' => null,
        ];
    }

    /**
     * A value given as its code (single_choice) or its label in Vietnamese or English, any case.
     *
     * @param  list<string>  $values
     */
    private function match(string $given, array $values, string $group): ?string
    {
        $needle = mb_strtolower(trim($given));
        if ($needle === '') {
            return null;
        }
        foreach ($values as $value) {
            $names = [$value, __('lf.LF_assessment_question_'.$group.'_'.$value, [], 'vi'), __('lf.LF_assessment_question_'.$group.'_'.$value, [], 'en')];
            foreach ($names as $name) {
                if (mb_strtolower($name) === $needle) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('lf.LF_assessment_question_import_error_encoding')]);
        }
        // Excel in Vietnamese locales saves CSV with ";".
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($value) => (string) $value, $row);
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }
}
