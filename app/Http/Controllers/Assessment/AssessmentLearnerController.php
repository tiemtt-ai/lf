<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentAttemptService as Attempts;
use App\Services\Assessment\AssessmentLearnerService as Learner;
use App\Services\Assessment\AssessmentQuestionService;
use App\Services\Assessment\AssessmentQuizPublishService;
use App\Services\Assessment\AssessmentQuizService as Quizzes;
use App\Services\MediaService;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Kiểm tra on the tenant website: the public list of open quizzes, self-registration, "Đề thi của tôi", taking an
 * attempt (answers saved per question, timer from the server deadline, speaking recordings) and the result page.
 * The student reaches only their own registrations and attempts; nothing graded is sent before the attempt ends.
 */
final class AssessmentLearnerController extends Controller
{
    public function __construct(
        private readonly Learner $learner,
        private readonly Attempts $attempts,
        private readonly MediaService $media,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $filters = [
            'keyword' => trim($validated['keyword'] ?? ''),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
        ];
        $student = $request->user()?->role === 'student' ? $request->user() : null;

        return view('tenant.assessments.index', [
            'tenant' => TenantContext::customer(),
            'quizzes' => $this->learner->catalog($customerId, $filters['keyword'], $filters['category_id'])->paginate(12)->withQueryString(),
            'categories' => $this->learner->catalogCategories($customerId),
            'filters' => $filters,
            'registered' => $student ? $this->learner->activeRegistrations($customerId, (int) $student->id) : collect(),
        ]);
    }

    public function mine(Request $request): View
    {
        $customerId = $this->customerId();
        $studentId = (int) $request->user()->id;
        $this->learner->closeOverdue($customerId, $studentId);

        return view('tenant.assessments.mine', [
            'tenant' => TenantContext::customer(),
            'registrations' => $this->learner->myRegistrations($customerId, $studentId),
        ]);
    }

    public function register(Request $request, int $id): RedirectResponse
    {
        $this->learner->register($this->customerId(), $request->user(), $id);

        return redirect()->route('student.assessments.mine')->with('success', __('lf.LF_assessment_learner_registered'));
    }

    public function start(Request $request, int $registrationId): RedirectResponse
    {
        $customerId = $this->customerId();
        $studentId = (int) $request->user()->id;
        $this->learner->registration($customerId, $studentId, $registrationId);
        $this->learner->closeOverdue($customerId, $studentId);
        $attempt = $this->attempts->start($customerId, $registrationId);

        return redirect()->route('student.assessments.attempts.show', $attempt->id);
    }

    public function show(Request $request, int $id): View|RedirectResponse
    {
        $customerId = $this->customerId();
        $attempt = $this->learner->attempt($customerId, (int) $request->user()->id, $id);
        if ($attempt->status !== 'in_progress') {
            return redirect()->route('student.assessments.attempts.result', $id);
        }

        $version = $this->version($customerId, $attempt);
        $answers = DB::table(Attempts::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $id)
            ->get()->keyBy('quiz_version_question_id');

        return view('tenant.assessments.take', [
            'tenant' => TenantContext::customer(),
            'attempt' => $attempt,
            'version' => $version,
            ...$this->paper($customerId, $version, $answers, false),
            'answers' => $answers,
            'remainingSeconds' => $attempt->deadline_at ? max(0, strtotime((string) $attempt->deadline_at) - now()->getTimestamp()) : null,
            'uploadLimitBytes' => UploadLimit::effectiveKilobytes() * 1024,
        ]);
    }

    /** Autosave of one question (JSON). */
    public function save(Request $request, int $id): JsonResponse
    {
        $customerId = $this->customerId();
        $validated = $request->validate([
            'question' => ['required', 'integer', 'min:1'],
            'option_keys' => ['nullable', 'array', 'max:50'],
            'option_keys.*' => ['integer'],
            'text' => ['nullable', 'string', 'max:20000'],
        ]);
        $attempt = $this->learner->attempt($customerId, (int) $request->user()->id, $id);
        if ($attempt->status !== 'in_progress') {
            return $this->closed($id);
        }

        try {
            $this->attempts->saveAnswer($customerId, $id, (int) $validated['question'], [
                'option_keys' => $validated['option_keys'] ?? [],
                'text' => $validated['text'] ?? null,
            ]);
        } catch (ValidationException) {
            return $this->closed($id);
        }

        return response()->json(['saved_at' => now()->format('H:i:s')]);
    }

    /** Uploads (or replaces) the recording of a speaking question (multipart). */
    public function record(Request $request, int $id, int $questionId): JsonResponse
    {
        $customerId = $this->customerId();
        $request->validate([
            'recording' => ['required', 'file', 'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,video/mp4,audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/x-m4a',
                'max:'.UploadLimit::effectiveKilobytes()],
        ]);
        $user = $request->user();
        $attempt = $this->learner->attempt($customerId, (int) $user->id, $id);
        if ($attempt->status !== 'in_progress') {
            return $this->closed($id);
        }

        try {
            $this->attempts->assertOpen($customerId, $id);
        } catch (ValidationException) {
            return $this->closed($id);
        }
        $question = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)->where('customer_id', $customerId)
            ->where('quiz_version_id', $attempt->quiz_version_id)->where('id', $questionId)->where('question_type', 'speaking')->first();
        abort_if(! $question, 404);

        $file = $this->media->upload($request->file('recording'), [
            'file_type' => 'audio',
            'module' => 'assessment',
            'entity_type' => 'attempts',
            'entity_id' => $id,
            'purpose' => 'speaking_recording',
            'display_name' => __('lf.LF_assessment_learner_recording_name', ['number' => $question->sort_order, 'name' => $user->name]),
        ], (int) $user->id);

        try {
            $replaced = $this->attempts->saveRecording($customerId, $id, $questionId, (int) $file->id, $this->media);
        } catch (ValidationException) {
            $this->media->deleteMediaIfUnused((int) $file->id);

            return $this->closed($id);
        }
        if ($replaced) {
            try {
                $this->media->deleteMediaIfUnused($replaced);
            } catch (Throwable $exception) {
                Log::warning('Replaced assessment recording was not deleted.', ['media_file_id' => $replaced, 'exception' => $exception::class]);
            }
        }

        return response()->json(['saved_at' => now()->format('H:i:s')]);
    }

    /** Submit: saves what the form carries (works without JavaScript), then ends the attempt. */
    public function submit(Request $request, int $id): RedirectResponse
    {
        $customerId = $this->customerId();
        $validated = $request->validate([
            'answers' => ['nullable', 'array', 'max:500'],
            'answers.*.option_keys' => ['nullable', 'array', 'max:50'],
            'answers.*.option_keys.*' => ['integer'],
            'answers.*.text' => ['nullable', 'string', 'max:20000'],
            'answers.*.present' => ['nullable'],
        ]);
        $attempt = $this->learner->attempt($customerId, (int) $request->user()->id, $id);

        if ($attempt->status === 'in_progress') {
            try {
                foreach ($validated['answers'] ?? [] as $questionId => $answer) {
                    // `present` marks a question the form shows, so clearing every checkbox clears the saved choice.
                    if (isset($answer['present'])) {
                        $this->attempts->saveAnswer($customerId, $id, (int) $questionId, [
                            'option_keys' => $answer['option_keys'] ?? [],
                            'text' => $answer['text'] ?? null,
                        ]);
                    }
                }
            } catch (ValidationException) {
                // Past the grace period: keep what was saved before.
            }

            $late = $attempt->deadline_at !== null && now()->getTimestamp() >= strtotime((string) $attempt->deadline_at);
            try {
                $this->attempts->submit($customerId, $id, $late ? 'timeout' : 'student');
            } catch (ValidationException) {
                // Closed meanwhile (another tab or the timeout): show the result.
            }
        }

        return redirect()->route('student.assessments.attempts.result', $id)->with('success', __('lf.LF_assessment_learner_submitted'));
    }

    public function result(Request $request, int $id): View|RedirectResponse
    {
        $customerId = $this->customerId();
        $attempt = $this->learner->attempt($customerId, (int) $request->user()->id, $id);
        if ($attempt->status === 'in_progress') {
            return redirect()->route('student.assessments.attempts.show', $id);
        }

        $version = $this->version($customerId, $attempt);
        $visible = $this->learner->visibility($attempt, $version);
        $answers = DB::table(Attempts::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $id)
            ->get()->keyBy('quiz_version_question_id');
        $files = DB::table(Attempts::FILES_TABLE.' as f')->join('media_files as m', 'm.id', '=', 'f.media_file_id')
            ->where('f.customer_id', $customerId)->whereIn('f.answer_id', $answers->pluck('id'))->where('m.status', 'ready')
            ->get(['f.answer_id', 'f.media_file_id'])->keyBy('answer_id');
        $registration = DB::table('core_assessment_quiz_registrations')->where('id', $attempt->quiz_registration_id)->first();

        return view('tenant.assessments.result', [
            'tenant' => TenantContext::customer(),
            'attempt' => $attempt,
            'version' => $version,
            'visible' => $visible,
            ...$this->paper($customerId, $version, $answers, $visible['answers']),
            'answers' => $answers,
            'fileUrls' => $files->map(fn (object $file) => $this->media->generateSignedUrl((int) $file->media_file_id)),
            'canRetry' => $registration && $registration->status === 'active',
        ]);
    }

    /**
     * The questions as the student sees them: prompt, media and options; the correct answers and explanations only when
     * `$reveal` (never reference answers). With sections, passages and signed media URLs.
     *
     * @return array<string, mixed>
     */
    private function paper(int $customerId, object $version, $answers, bool $reveal): array
    {
        $questions = $this->attempts->versionQuestions($customerId, (int) $version->id)->map(function (object $question) use ($answers, $reveal): object {
            $snapshot = json_decode((string) $question->question_snapshot, true) ?: [];

            return (object) [
                'id' => (int) $question->id,
                'number' => (int) $question->sort_order,
                'type' => $question->question_type,
                'section_key' => (int) $question->section_key,
                'passage_id' => $question->passage_id,
                'points' => $question->points,
                'manual' => (bool) $question->requires_manual_grading,
                'prompt' => (string) ($snapshot['prompt'] ?? ''),
                'media' => $snapshot['media'] ?? [],
                'explanation' => $reveal ? ($snapshot['explanation'] ?? null) : null,
                'options' => collect(json_decode((string) $question->options_snapshot, true) ?: [])
                    ->map(fn (array $option) => ['key' => (int) $option['key'], 'label' => $option['label'], 'text' => $option['text']])->all(),
                'correct' => $reveal ? (json_decode((string) $question->correct_answer_snapshot, true) ?: []) : [],
                'answered' => $this->answered($question, $answers->get($question->id)),
            ];
        });
        $passages = collect(json_decode((string) $version->passages_snapshot, true) ?: []);
        $ids = $questions->flatMap(fn (object $question) => collect($question->media)->pluck('media_file_id'))
            ->merge($passages->flatMap(fn (array $passage) => collect($passage['media'] ?? [])->pluck('media_file_id')))->unique();

        return [
            'questions' => $questions,
            'sections' => collect(json_decode((string) $version->sections_snapshot, true) ?: [])->keyBy('key'),
            'passages' => $passages,
            'mediaUrls' => DB::table('media_files')->where('customer_id', $customerId)->whereIn('id', $ids)->where('status', 'ready')->pluck('id')
                ->mapWithKeys(fn ($mediaId) => [(int) $mediaId => $this->media->generateSignedUrl((int) $mediaId)])->all(),
        ];
    }

    private function answered(object $question, ?object $answer): bool
    {
        if (! $answer) {
            return false;
        }
        if (in_array($question->question_type, AssessmentQuestionService::CHOICE_TYPES, true)) {
            return (json_decode((string) $answer->selected_option_keys, true) ?: []) !== [];
        }
        if ($question->question_type === 'speaking') {
            return DB::table(Attempts::FILES_TABLE)->where('answer_id', $answer->id)->exists();
        }

        return filled($answer->answer_text);
    }

    private function version(int $customerId, object $attempt): object
    {
        return DB::table(Quizzes::VERSIONS_TABLE)->where('customer_id', $customerId)->where('id', $attempt->quiz_version_id)->first();
    }

    private function closed(int $id): JsonResponse
    {
        return response()->json([
            'message' => __('lf.LF_assessment_attempt_error_closed'),
            'redirect' => route('student.assessments.attempts.result', $id),
        ], 409);
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return $customerId;
    }
}
