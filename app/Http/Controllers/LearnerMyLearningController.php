<?php

namespace App\Http\Controllers;

use App\Services\Assessment\AssessmentLearnerService;
use App\Services\CourseProgressRecorder;
use App\Services\LearnerMyLearningService;
use App\Services\LiveClassWhiteboardService;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Khóa học của tôi" on the tenant website (LF-Navigation § My Courses): self-paced
 * courses, classes and tests of the signed-in learner, the lesson page, Media of its
 * Activities and progress events (ADR-0030).
 */
class LearnerMyLearningController extends Controller
{
    public const TABS = ['courses', 'classes', 'exams'];

    private const VIEWS = [
        'courses' => ['current', 'completed', 'expired'],
        'classes' => ['current', 'completed'],
        'exams' => ['current', 'ended'],
    ];

    public function __construct(
        private readonly LearnerMyLearningService $learning,
        private readonly LiveClassWhiteboardService $whiteboards,
        private readonly AssessmentLearnerService $assessments,
        private readonly CourseProgressRecorder $progress,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $studentId = (int) $request->user()->id;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'courses';
        $views = self::VIEWS[$tab];
        $view = in_array($request->query('view'), $views, true) ? $request->query('view') : $views[0];

        $groups = match ($tab) {
            'courses' => $this->learning->courses($customerId, $studentId),
            'classes' => $this->learning->classes($customerId, $studentId),
            'exams' => $this->examGroups($customerId, $studentId),
        };

        // "Lọc": by name and category inside the chosen group; the group counts stay unfiltered.
        $categories = $tab === 'exams' ? [] : collect($groups)->flatten(1)->map(fn ($item) => $this->category($item))
            ->filter()->unique()->sort()->values()->all();
        $search = trim((string) $request->query('q', ''));
        $category = in_array($request->query('category'), $categories, true) ? $request->query('category') : '';
        $items = $groups[$view]->filter(fn ($item) => ($search === '' || mb_stripos($this->title($tab, $item), $search) !== false)
            && ($category === '' || $this->category($item) === $category))->values();

        return view('tenant.my-learning.index', [
            'tenant' => TenantContext::customer(),
            // A live Session of the learner's Cohort shows "Xem bảng" (ADR-0002 § LiveClass Whiteboard Amendment).
            'liveSessions' => $this->whiteboards->learnerLiveSessions($customerId, $studentId),
            'tab' => $tab,
            'view' => $view,
            'views' => $views,
            'groups' => $groups,
            'items' => $items,
            'filter' => ['q' => $search, 'category' => $category, 'categories' => $categories,
                'count' => ($search !== '' ? 1 : 0) + ($category !== '' ? 1 : 0)],
        ]);
    }

    public function course(Request $request, int $enrollment): View
    {
        $customerId = $this->customerId();
        $course = $this->learning->course($customerId, (int) $request->user()->id, $enrollment);
        abort_if($course === null, 404);

        return view('tenant.my-learning.course', ['tenant' => TenantContext::customer(), 'course' => $course]);
    }

    /** The learner's lesson page (ADR-0030 D1). A locked Lesson answers 403 with its reason. */
    public function lesson(Request $request, int $enrollment, int $lesson): View
    {
        $customerId = $this->customerId();
        $studentId = (int) $request->user()->id;
        $page = $this->learning->lesson($customerId, $studentId, $enrollment, $lesson);
        abort_if($page === null, 404);
        abort_unless($page->allowed, 403, __('lf.LF_my_learning_lesson_locked'));
        $this->progress->openLesson($customerId, $studentId, $enrollment, $lesson);

        return view('tenant.my-learning.lesson', [
            'tenant' => TenantContext::customer(),
            'lesson' => $page,
            'course' => $page->course,
            'liveSessions' => $page->course->cohort_id
                ? $this->whiteboards->learnerLiveSessions($customerId, $studentId)->where('cohort_id', $page->course->cohort_id)->values()
                : collect(),
        ]);
    }

    /** A short-lived URL of an Activity's Media (ADR-0030 D2). Reading it records nothing. */
    public function media(Request $request, MediaService $media, int $enrollment, int $activity): JsonResponse
    {
        $this->customerId();

        return response()->json(['url' => $media->generateLearnerActivitySignedUrl((int) $request->user()->id, $enrollment, $activity)]);
    }

    /** Records `opened`, `position` or `completed` for one Activity (ADR-0030 D3). */
    public function progress(Request $request, int $enrollment, int $activity): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', Rule::in(CourseProgressRecorder::EVENTS)],
            'position_seconds' => ['nullable', 'numeric', 'min:0', 'max:86400', 'required_if:event,position'],
        ]);
        $row = $this->progress->record($this->customerId(), (int) $request->user()->id, $enrollment, $activity,
            $validated['event'], isset($validated['position_seconds']) ? (float) $validated['position_seconds'] : null);
        abort_if($row === null, 403);

        return response()->json(['progress' => $this->progressPayload($row)]);
    }

    public function cohort(Request $request, int $cohort): View
    {
        $customerId = $this->customerId();
        $studentId = (int) $request->user()->id;
        $class = $this->learning->cohort($customerId, $studentId, $cohort);
        abort_if($class === null, 404);

        return view('tenant.my-learning.cohort', [
            'tenant' => TenantContext::customer(),
            'class' => $class,
            'liveSessions' => $this->whiteboards->learnerLiveSessions($customerId, $studentId)
                ->where('cohort_id', $class->cohort_id)->values(),
        ]);
    }

    /** "Bài thi của tôi": registrations still usable, then exhausted or cancelled ones. */
    /** The name "Lọc" searches: a course's title, a class's name and course, a test's title. */
    private function title(string $tab, object $item): string
    {
        return match ($tab) {
            'courses' => (string) ($item->product_title ?: $item->version_title),
            'classes' => $item->name.' '.($item->product_title ?: $item->version_title),
            'exams' => (string) $item->title,
        };
    }

    private function category(object $item): ?string
    {
        return LearnerMyLearningService::levelLabel($item->level ?? null) ?: ($item->category ?? null);
    }

    private function examGroups(int $customerId, int $studentId): array
    {
        $this->assessments->closeOverdue($customerId, $studentId);
        $registrations = $this->assessments->myRegistrations($customerId, $studentId);

        return [
            'current' => $registrations->where('status', 'active')->values(),
            'ended' => $registrations->where('status', '!=', 'active')->values(),
        ];
    }

    /** @return array{status: string, percentage: int, completed: bool} */
    private function progressPayload(object $row): array
    {
        return [
            'status' => (string) $row->status,
            'percentage' => (int) round((float) $row->progress_percentage),
            'completed' => $row->status === 'completed',
        ];
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return (int) $customerId;
    }
}
