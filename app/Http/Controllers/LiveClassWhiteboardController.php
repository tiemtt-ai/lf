<?php

namespace App\Http\Controllers;

use App\Exceptions\LiveClassBoardRefused;
use App\Services\LiveClassAttendanceCheckIn;
use App\Services\LiveClassWhiteboardLiveState;
use App\Services\LiveClassWhiteboardService;
use App\Services\TeacherPermissions;
use App\Support\LiveClassBoardContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Whiteboard surfaces (ADR-0002 § LiveClass Whiteboard Amendment): the
 * teacher's Session list with start and complete, and the board itself for a
 * teacher under /teacher, an administrator under /admin and a learner under
 * /my-courses. Tenant, Session, presenter and viewer always come from the
 * server, never from the request body.
 */
final class LiveClassWhiteboardController extends Controller
{
    public function __construct(private readonly LiveClassWhiteboardService $whiteboards) {}

    public function teacherIndex(Request $request): View
    {
        $customerId = $this->customerId();
        $teacher = $request->user();
        $tab = in_array($request->query('tab'), ['overdue', 'taught'], true) ? $request->query('tab') : 'upcoming';
        $upcoming = $this->whiteboards->teacherSessions($customerId, (int) $teacher->id);
        $overdue = $this->whiteboards->teacherOverdueSessions($customerId, (int) $teacher->id);
        $permissions = app(TeacherPermissions::class);

        return view('liveclass-whiteboard.teacher-sessions', [
            'tab' => $tab,
            'sessions' => match ($tab) {
                'overdue' => $overdue,
                'taught' => $this->whiteboards->teacherTaughtSessions($customerId, (int) $teacher->id),
                default => $upcoming,
            },
            'tabCounts' => ['upcoming' => $upcoming->count(), 'overdue' => $overdue->count()],
            // A missed Session is cancelled or moved in "Quản lý lớp", which only the class's main teacher with class.manage opens.
            'managedCohortIds' => $permissions->may($teacher, 'class.manage') ? $permissions->primaryCohortIds($teacher) : [],
        ]);
    }

    public function teacherStart(Request $request, int $session): RedirectResponse
    {
        $hasBoard = $this->whiteboards->startForTeacher($this->customerId(), (int) $request->user()->id, $session);

        return $hasBoard
            ? redirect()->route('teacher.live-sessions.board', $session)
            : redirect()->route('teacher.live-sessions.index')->with('success', __('lf.LF_liveclass_whiteboard_started'));
    }

    public function teacherComplete(Request $request, int $session): RedirectResponse
    {
        $this->whiteboards->completeForTeacher($this->customerId(), (int) $request->user()->id, $session);

        return redirect()->route('teacher.live-sessions.index')
            ->with('success', __('lf.LF_liveclass_whiteboard_completed'));
    }

    public function teacherBoard(Request $request, int $session): View|RedirectResponse
    {
        try {
            $context = $this->whiteboards->teacherContext($this->customerId(), (int) $request->user()->id, $session);
        } catch (LiveClassBoardRefused $refused) {
            // A stale "Mở bảng" (the Session ended or has not started): back to the list with the reason, not a bare 403.
            return redirect()->route('teacher.live-sessions.index')->withErrors(['lifecycle' => $refused->getMessage()]);
        }

        return $this->board($request, $context,
            route('teacher.live-sessions.index'),
            fn (int $activity): string => route('teacher.live-sessions.board.materials.url', [$session, $activity]),
            route('teacher.live-sessions.board.materials', $session),
            $this->syncUrls('teacher.live-sessions.board.', [$session], presenter: true));
    }

    public function teacherMaterials(Request $request, int $session): JsonResponse
    {
        $context = $this->whiteboards->teacherContext($this->customerId(), (int) $request->user()->id, $session);

        return response()->json(['materials' => $this->presentMaterials($context,
            fn (int $activity): string => route('teacher.live-sessions.board.materials.url', [$session, $activity]))]);
    }

    public function teacherMaterialUrl(Request $request, int $session, int $activity): JsonResponse
    {
        $context = $this->whiteboards->teacherContext($this->customerId(), (int) $request->user()->id, $session);

        return $this->materialUrl($context, $activity);
    }

    public function adminBoard(Request $request, int $cohort, int $session): View|RedirectResponse
    {
        try {
            $context = $this->whiteboards->adminContext($this->customerId(), (int) $request->user()->id, $cohort, $session);
        } catch (LiveClassBoardRefused $refused) {
            return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'sessions'])
                ->withErrors(['lifecycle' => $refused->getMessage()]);
        }

        return $this->board($request, $context,
            route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'sessions']),
            fn (int $activity): string => route('admin.course-cohorts.sessions.board.materials.url', [$cohort, $session, $activity]),
            route('admin.course-cohorts.sessions.board.materials', [$cohort, $session]),
            $this->syncUrls('admin.course-cohorts.sessions.board.', [$cohort, $session], presenter: true));
    }

    public function adminMaterials(Request $request, int $cohort, int $session): JsonResponse
    {
        $context = $this->whiteboards->adminContext($this->customerId(), (int) $request->user()->id, $cohort, $session);

        return response()->json(['materials' => $this->presentMaterials($context,
            fn (int $activity): string => route('admin.course-cohorts.sessions.board.materials.url', [$cohort, $session, $activity]))]);
    }

    public function adminMaterialUrl(Request $request, int $cohort, int $session, int $activity): JsonResponse
    {
        $context = $this->whiteboards->adminContext($this->customerId(), (int) $request->user()->id, $cohort, $session);

        return $this->materialUrl($context, $activity);
    }

    public function studentBoard(Request $request, int $session): View|RedirectResponse
    {
        try {
            $context = $this->whiteboards->learnerContext($this->customerId(), (int) $request->user()->id, $session);
        } catch (LiveClassBoardRefused $refused) {
            // Same as a refused entry (LiveClassAttendanceEntryController): an inactive Enrollment goes to the classes
            // tab, a board that is not open goes back to the class page; both say why.
            return ($refused->reason === 'inactive'
                ? redirect()->route('student.courses.index', ['tab' => 'classes'])
                : redirect()->route('student.courses.classes.show', $refused->cohortId))
                ->with('lf_learn_notice', $refused->getMessage());
        }
        $this->whiteboards->recordBoardEntry($context);

        return $this->board($request, $context,
            route('student.courses.index'),
            fn (int $activity): string => route('student.live-sessions.board.materials.url', [$session, $activity]),
            route('student.live-sessions.board.materials', $session),
            $this->syncUrls('student.live-sessions.board.', [$session], presenter: false));
    }

    /** A learner's board reloads the list when the presenter opens a material it does not know yet. */
    public function studentMaterials(Request $request, int $session): JsonResponse
    {
        $context = $this->whiteboards->learnerContext($this->customerId(), (int) $request->user()->id, $session);

        return response()->json(['materials' => $this->presentMaterials($context,
            fn (int $activity): string => route('student.live-sessions.board.materials.url', [$session, $activity]))]);
    }

    public function studentMaterialUrl(Request $request, int $session, int $activity): JsonResponse
    {
        $context = $this->whiteboards->learnerContext($this->customerId(), (int) $request->user()->id, $session);

        return $this->materialUrl($context, $activity);
    }

    /** @param array<int, int> $parameters */
    private function syncUrls(string $prefix, array $parameters, bool $presenter): array
    {
        $names = $presenter ? ['state', 'status', 'presence', 'events', 'snapshot', 'release'] : ['state', 'status'];

        return collect($names)->mapWithKeys(fn (string $name): array => [$name.'Url' => route($prefix.$name, $parameters)])->all();
    }

    /** @param array<string, string> $syncUrls */
    private function board(Request $request, LiveClassBoardContext $context, string $backUrl, callable $urlFor, ?string $materialsUrl, array $syncUrls): View
    {
        $user = $request->user();

        return view('liveclass-whiteboard.board', [
            'context' => $context,
            'config' => [
                'storageNamespace' => 'lf-wb:'.$context->customerId.':'.$context->userId.':'.$context->sessionId,
                'settingsKey' => 'lf-wb:settings:'.$context->customerId.':'.$context->userId,
                'role' => $context->canPresent ? 'presenter' : 'viewer',
                'userId' => $context->userId,
                'deliveryMode' => $context->deliveryMode,
                'className' => $context->cohortName,
                'sessionTitle' => $context->sessionTitle,
                'lessonTitle' => $context->lessonTitle,
                'userName' => $user->name,
                'userCode' => (string) $user->id,
                'backUrl' => $backUrl,
                'materialsUrl' => $materialsUrl,
                'materials' => $this->presentMaterials($context, $urlFor),
                // Attendance QR code (ADR-0002 § Attendance Check-In Amendment): presenters of in-room Sessions only.
                'attendanceCodeUrl' => LiveClassAttendanceCheckIn::offeredOn($context)
                    ? ($context->role === 'teacher'
                        ? route('teacher.live-sessions.board.attendance-code', $context->sessionId)
                        : route('admin.course-cohorts.sessions.board.attendance-code', [$context->cohortId, $context->sessionId]))
                    : null,
                'sync' => $syncUrls + [
                    'channel' => LiveClassWhiteboardLiveState::channelName($context->customerId, $context->sessionId),
                    'authUrl' => url('/broadcasting/auth'),
                    'lockSeconds' => LiveClassWhiteboardLiveState::LOCK_SECONDS,
                    'maxEventBytes' => LiveClassWhiteboardLiveState::MAX_EVENT_BYTES,
                    'maxBatch' => LiveClassWhiteboardLiveState::MAX_BATCH,
                ],
            ],
        ]);
    }

    private function presentMaterials(LiveClassBoardContext $context, callable $urlFor): array
    {
        return array_map(fn (array $item): array => $item + [
            'url_endpoint' => $item['kind'] === 'embed' ? null : $urlFor($item['id']),
        ], $this->whiteboards->materials($context));
    }

    private function materialUrl(LiveClassBoardContext $context, int $activity): JsonResponse
    {
        return response()
            ->json(['url' => $this->whiteboards->materialUrl($context, $activity)])
            ->header('Cache-Control', 'no-store');
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return (int) $customerId;
    }
}
