<?php

namespace App\Services;

use App\Exceptions\LiveClassBoardRefused;
use App\Models\User;
use App\Support\LiveClassBoardContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Who may open a Session's Whiteboard, which Sessions a teacher sees, and the
 * board's material list (ADR-0002 § LiveClass Whiteboard Amendment).
 *
 * Every check runs on the server for every request; nothing is trusted from
 * the client. Session times are wall-clock values read in the Session's own
 * timezone (LF-Core-LiveClass § Session Time Convention).
 */
class LiveClassWhiteboardService
{
    /** Activity types the board lists; quiz and live_class carry no Media. */
    private const MATERIAL_TYPES = ['document', 'video', 'audio', 'embedded_video'];

    /** @var array<string, int> minutes the class's attendance policy opens early, by "customer:cohort" */
    private array $opensBeforeMinutes = [];

    public function __construct(
        private readonly LiveClassSessionLifecycleService $lifecycle,
        private readonly CourseCohortContentOrder $contentOrder,
        private readonly MediaService $media,
        private readonly TrustedVideoUrlService $trustedVideos,
        private readonly CourseEnrollmentLifecycleService $enrollments,
        private readonly DocumentBoardPreview $previews,
        private readonly LiveClassAttendancePolicies $attendancePolicies,
        private readonly LiveClassAttendanceRecorder $attendanceRecorder,
        private readonly LiveClassSessionOperations $sessionOperations,
        private readonly LiveClassAttendanceContent $attendanceContent,
    ) {}

    /**
     * Upcoming and live Sessions the teacher is assigned to, in active Cohorts.
     *
     * @return Collection<int, object>
     */
    public function teacherSessions(int $customerId, int $teacherId): Collection
    {
        $teacherActive = $this->teacherIsActive($customerId, $teacherId);
        $mayOperate = app(TeacherPermissions::class)->may(DB::table('users')->where('customer_id', $customerId)->where('id', $teacherId)->first(), 'class.operate');

        return $this->sessionQuery($customerId)
            ->join('core_liveclass_session_teachers as assignments', function ($join) use ($customerId, $teacherId): void {
                $join->on('assignments.session_id', '=', 'sessions.id')
                    ->where('assignments.customer_id', $customerId)
                    ->where('assignments.teacher_id', $teacherId);
            })
            ->whereIn('sessions.status', ['scheduled', 'live'])
            // A self-study session is learned at home, never started by a teacher.
            ->where('sessions.session_type', '!=', LiveClassSelfStudySession::TYPE)
            ->where('cohorts.status', 'active')
            ->addSelect('assignments.assigned_from', 'assignments.assigned_to')
            ->orderBy('sessions.scheduled_start_at')->orderBy('sessions.id')
            ->get()
            ->filter(fn (object $session): bool => $session->status === 'live'
                || $this->localDate($session->scheduled_start_at, $session->timezone) >= $this->today($session->timezone))
            ->take(100)
            ->map(function (object $session) use ($teacherActive, $mayOperate): object {
                $presenter = $teacherActive && $this->windowCovers($session);
                $session->is_today = $this->isPlannedToday($session);
                // The start opens with the class's attendance entry, the end with the planned start (Owner 2026-11-18).
                $session->start_opens_at = $this->startOpensAt($session);
                $startable = $presenter && $session->status === 'scheduled' && $session->is_today;
                $session->can_start = $startable && ! $session->start_opens_at->isFuture();
                $session->start_waits = $startable && ! $session->can_start;
                $live = $presenter && $session->status === 'live';
                // A practice session is started and ended like any other, but has no board (ADR-0022 Amendment 1.3, Đợt 4).
                $session->is_practice = LiveClassPracticeSession::isType($session->lesson_type ?? null);
                $session->can_open = $live && ! $session->is_practice;
                $session->can_complete = $live && ! $this->plannedStart($session)->isFuture();
                $session->complete_waits = $live && ! $session->can_complete;
                // What this teacher may change of the Session's operational part (ADR-0002 § Teacher Session Operations).
                // Without `class.operate` the teacher still teaches the Session, but changes nothing of it (ADR-0033 D3).
                $editable = $presenter && $mayOperate ? $this->sessionOperations->editable((int) $session->customer_id, $session) : ['place' => false, 'link' => false];
                $session->can_edit_place = $editable['place'];
                $session->can_edit_link = $editable['link'];

                return $session;
            })
            ->values();
    }

    /**
     * Sessions of an earlier day the teacher was assigned to and that never started: they stay `scheduled` until
     * someone cancels or moves them, so the teacher is shown what still waits for a decision (Owner 2026-11-18).
     *
     * @return Collection<int, object>
     */
    public function teacherOverdueSessions(int $customerId, int $teacherId): Collection
    {
        return $this->assignedSessionQuery($customerId, $teacherId)
            ->where('sessions.status', 'scheduled')
            ->where('cohorts.status', 'active')
            ->orderByDesc('sessions.scheduled_start_at')->orderByDesc('sessions.id')
            ->limit(200)
            ->get()
            ->filter(fn (object $session): bool => $this->localDate($session->scheduled_start_at, $session->timezone) < $this->today($session->timezone))
            ->take(100)
            ->values();
    }

    /**
     * The Sessions the teacher taught to the end, newest first, ten a page: the way back to their attendance.
     */
    public function teacherTaughtSessions(int $customerId, int $teacherId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->assignedSessionQuery($customerId, $teacherId)
            ->where('sessions.status', 'completed')
            ->orderByDesc('sessions.scheduled_start_at')->orderByDesc('sessions.id')
            ->paginate($perPage)->withQueryString();
    }

    private function assignedSessionQuery(int $customerId, int $teacherId)
    {
        return $this->sessionQuery($customerId)
            ->join('core_liveclass_session_teachers as assignments', function ($join) use ($customerId, $teacherId): void {
                $join->on('assignments.session_id', '=', 'sessions.id')
                    ->where('assignments.customer_id', $customerId)
                    ->where('assignments.teacher_id', $teacherId);
            })
            ->addSelect('assignments.assigned_from', 'assignments.assigned_to');
    }

    /** @return bool whether the Session has a board to open after the start (a practice session has none) */
    public function startForTeacher(int $customerId, int $teacherId, int $sessionId): bool
    {
        $session = $this->teacherSession($customerId, $teacherId, $sessionId);

        if ($session->status !== 'scheduled' || ! $this->isPlannedToday($session)) {
            throw ValidationException::withMessages([
                'lifecycle' => __('lf.LF_liveclass_whiteboard_start_not_today'),
            ]);
        }
        // Not before the class's attendance entry opens: a Session started earlier would be live while learners
        // still cannot be recorded entering it (Owner 2026-11-18).
        $opensAt = $this->startOpensAt($session);
        if ($opensAt->isFuture()) {
            throw ValidationException::withMessages([
                'lifecycle' => __('lf.LF_liveclass_whiteboard_start_too_early', ['time' => $opensAt->format('H:i')]),
            ]);
        }

        $this->lifecycle->start($customerId, (int) $session->cohort_id, $sessionId);

        return ! LiveClassPracticeSession::isType($session->lesson_type ?? null);
    }

    public function completeForTeacher(int $customerId, int $teacherId, int $sessionId): void
    {
        $session = $this->teacherSession($customerId, $teacherId, $sessionId);
        // A live Session is not ended before its planned start (Owner 2026-11-18); any other state is refused by the lifecycle.
        if ($session->status === 'live' && ($plannedStart = $this->plannedStart($session))->isFuture()) {
            throw ValidationException::withMessages([
                'lifecycle' => __('lf.LF_liveclass_whiteboard_complete_too_early', ['time' => $plannedStart->format('H:i')]),
            ]);
        }

        $this->lifecycle->complete($customerId, (int) $session->cohort_id, $sessionId);
    }

    public function teacherContext(int $customerId, int $teacherId, int $sessionId): LiveClassBoardContext
    {
        $session = $this->teacherSession($customerId, $teacherId, $sessionId);

        return $this->context($session, $teacherId, 'teacher');
    }

    public function adminContext(int $customerId, int $adminId, int $cohortId, int $sessionId): LiveClassBoardContext
    {
        $session = $this->sessionQuery($customerId)
            ->where('sessions.cohort_id', $cohortId)->where('sessions.id', $sessionId)->first();
        abort_if(! $session, 404);

        return $this->context($session, $adminId, 'customer_admin');
    }

    /**
     * A learner follows the board of a live Session of their own Cohort
     * (viewer conditions of the amendment). Someone with no active membership
     * in the Session's Cohort learns nothing about the Session.
     */
    public function learnerContext(int $customerId, int $studentId, int $sessionId): LiveClassBoardContext
    {
        $session = $this->sessionQuery($customerId)->where('sessions.id', $sessionId)->first();
        $membership = $session ? $this->learnerMembership($customerId, $studentId, (int) $session->cohort_id) : null;
        abort_if(! $membership, 404);
        if (! $this->learnerMayView($customerId, $membership)) {
            throw new LiveClassBoardRefused('inactive', (int) $session->cohort_id, __('lf.LF_liveclass_attendance_entry_inactive'));
        }

        return $this->context($session, $studentId, 'student', canPresent: false);
    }

    /**
     * Sessions the learner can act on now, for the learner pages: the live ones (the board is open), and — with
     * attendance on — the scheduled ones whose entry time has opened, so "Vào phòng họp" and the "Điểm danh" button
     * work before the teacher starts (ADR-0002 § Attendance Policy And Recording Amendment). Each row carries
     * `is_live`, `entry_meeting`, `entry_button`, `entry_warning` and the learner's `attendance` so far.
     *
     * @return Collection<int, object>
     */
    public function learnerLiveSessions(int $customerId, int $studentId): Collection
    {
        $attendanceOn = LiveClassAttendanceCheckIn::enabled();
        $now = CarbonImmutable::now()->utc();

        $sessions = $this->sessionQuery($customerId)
            ->join('core_course_cohort_students as memberships', function ($join) use ($customerId, $studentId): void {
                $join->on('memberships.cohort_id', '=', 'sessions.cohort_id')
                    ->where('memberships.customer_id', $customerId)
                    ->where('memberships.student_id', $studentId)
                    ->where('memberships.status', 'active')
                    ->whereNull('memberships.left_at');
            })
            ->whereIn('sessions.status', $attendanceOn ? ['live', 'scheduled'] : ['live'])
            ->where('cohorts.status', 'active')
            // A scheduled Session can only be open for entry near its own time; the exact check follows below.
            ->where(fn ($query) => $query->where('sessions.status', 'live')
                ->orWhereBetween('sessions.scheduled_start_at', [$now->subDays(2)->format('Y-m-d H:i:s'), $now->addDays(2)->format('Y-m-d H:i:s')]))
            ->addSelect('memberships.enrollment_id', 'memberships.student_id')
            ->orderBy('sessions.scheduled_start_at')->orderBy('sessions.id')
            ->limit(50)
            ->get()
            ->filter(fn (object $session): bool => $this->learnerMayView($customerId, $session));

        $results = $attendanceOn && $sessions->isNotEmpty()
            ? DB::table('core_liveclass_attendances')->where('customer_id', $customerId)
                ->whereIn('session_id', $sessions->pluck('id'))->whereIn('enrollment_id', $sessions->pluck('enrollment_id'))
                ->get(['session_id', 'enrollment_id', 'status', 'joined_at', 'attendance_mode', 'attendance_source'])
                ->keyBy(fn (object $row): string => $row->session_id.':'.$row->enrollment_id)
            : collect();

        return $sessions->map(function (object $session) use ($customerId, $attendanceOn, $now, $results): ?object {
            $session->is_live = $session->status === 'live';
            $session->entry_meeting = $session->entry_button = $session->entry_qr = $session->entry_warning = false;
            $session->attendance = $results->get($session->id.':'.$session->enrollment_id);
            if (LiveClassSelfStudySession::is($session)) {
                return $attendanceOn ? $this->selfStudyEntry($customerId, $session, $now) : null;
            }
            if (! $attendanceOn || ! LiveClassAttendanceCheckIn::sessionHasTeacher($customerId, (int) $session->id)) {
                return $session->is_live ? $session : null;
            }

            $policy = $this->attendancePolicies->forCohort($customerId, (int) $session->cohort_id);
            $open = fn (string $way): bool => $this->attendanceRecorder->offers($policy, $session, $way)
                && ! ($timing = LiveClassAttendanceTiming::of($policy, $session, fromPlannedStart: $way === 'button'))->isBeforeOpening($now)
                && ! $timing->isAfterClosing($now);
            $session->entry_meeting = self::meetingUrl($session) !== null && $open('meeting');
            $session->entry_button = $open('button');
            // The board shows a code to scan only when the system records the room by QR (not by hand) and it is open.
            $session->entry_qr = $open('qr');
            $hasResult = $session->attendance && ($session->attendance->joined_at !== null || $session->attendance->status !== 'registered');
            // Entering now would be recorded as absent: the page says so before the learner presses.
            $session->entry_warning = ! $hasResult && ($session->entry_meeting || $session->entry_button)
                && LiveClassAttendanceTiming::of($policy, $session, fromPlannedStart: $session->entry_button)->isPastAbsentThreshold($now);

            return $session->is_live || $session->entry_meeting || $session->entry_button ? $session : null;
        })->filter()->values();
    }

    /**
     * A self-study Session shows during its own hours (ADR-0022 Amendment 1.3, Đợt 4, Bước 3): with the "Điểm danh"
     * button when its lesson ticks no Activity, otherwise with a reminder that the ticked content decides.
     */
    private function selfStudyEntry(int $customerId, object $session, CarbonImmutable $now): ?object
    {
        if ($session->status !== 'scheduled'
            || $now->lt(LiveClassSessionTime::utc((string) $session->scheduled_start_at, $session->timezone))
            || $now->gt(LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone))) {
            return null;
        }
        $session->is_self_study = true;
        $session->self_study_ticked = $this->attendanceContent->tickedSelfStudy($customerId, (int) $session->version_lesson_id)->count();
        $session->entry_button = $session->self_study_ticked === 0;

        return $session;
    }

    /**
     * Opening the board of an online Session is an entry, like "Vào phòng họp"; of an in-room or hybrid Session it
     * is not (ADR-0002 § Attendance Policy And Recording Amendment, part B). The recorder decides; nothing is shown.
     */
    public function recordBoardEntry(LiveClassBoardContext $context): void
    {
        if (! LiveClassAttendanceCheckIn::enabled() || $context->role !== 'student') {
            return;
        }
        $session = $this->attendanceRecorder->session($context->customerId, $context->sessionId);
        $membership = $this->learnerMembership($context->customerId, $context->userId, $context->cohortId);
        if ($session && $membership) {
            $this->attendanceRecorder->enter($session, $membership, 'board', CarbonImmutable::now());
        }
    }

    /**
     * A learner's own entry to a Session that still takes entries: their seat, account and Enrollment, an active
     * Cohort and a Session that is scheduled or live. Unlike the board, the Session need not be live.
     * A learner without a seat gets 404; otherwise a refusal is returned, not thrown, so the page can say why:
     * `inactive` (account or Enrollment not active, or outside its learning window) or `closed` (the Session or
     * the class no longer takes entries).
     *
     * @return array{0: object, 1: object, 2: 'inactive'|'closed'|null} the Session row, the learner's seat, the refusal
     */
    public function learnerEntry(int $customerId, int $studentId, int $sessionId): array
    {
        $session = $this->sessionQuery($customerId)->where('sessions.id', $sessionId)->first();
        $membership = $session ? $this->learnerMembership($customerId, $studentId, (int) $session->cohort_id) : null;
        abort_if(! $membership, 404);
        $refusal = match (true) {
            ! $this->learnerMayView($customerId, $membership) => 'inactive',
            $session->cohort_status !== 'active' || ! in_array($session->status, ['scheduled', 'live'], true) => 'closed',
            default => null,
        };

        return [$session, $membership, $refusal];
    }

    /**
     * The same checks as the page and endpoints, without throwing: used by the
     * private channel authorization in routes/channels.php.
     */
    public function boardAccess(int $customerId, User $user, int $sessionId): ?LiveClassBoardContext
    {
        if ((int) $user->customer_id !== $customerId) {
            return null;
        }

        try {
            return match ($user->role) {
                'teacher' => $this->teacherContext($customerId, (int) $user->id, $sessionId),
                'customer_admin' => $this->adminSessionContext($customerId, $user, $sessionId),
                'student' => $this->learnerContext($customerId, (int) $user->id, $sessionId),
                default => null,
            };
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    private function adminSessionContext(int $customerId, User $admin, int $sessionId): LiveClassBoardContext
    {
        abort_unless(($admin->status ?? 'active') === 'active', 403);
        $session = $this->sessionQuery($customerId)->where('sessions.id', $sessionId)->first();
        abort_if(! $session, 404);

        return $this->context($session, (int) $admin->id, 'customer_admin');
    }

    /**
     * Ready Media of every material Activity of the Session's Version Lesson,
     * whatever its phase (ADR-0022 Amendment 1.3), in the Cohort's own order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function materials(LiveClassBoardContext $context): array
    {
        if (! $context->versionLessonId) {
            return [];
        }

        $activities = DB::table('core_course_template_version_activities as activities')
            ->leftJoin('media_file_usages as usages', function ($join) use ($context): void {
                $join->on('usages.owner_id', '=', 'activities.id')
                    ->on('usages.media_file_id', '=', 'activities.media_file_id')
                    ->whereColumn('usages.usage_type', 'activities.activity_type')
                    ->where('usages.customer_id', $context->customerId)
                    ->where('usages.owner_type', 'course_version_activity')
                    ->where('usages.status', 'active');
            })
            ->leftJoin('media_files as media', function ($join) use ($context): void {
                $join->on('media.id', '=', 'usages.media_file_id')
                    ->where('media.customer_id', $context->customerId)
                    ->where('media.status', 'ready');
            })
            ->where('activities.customer_id', $context->customerId)
            ->where('activities.template_version_id', $context->versionId)
            ->where('activities.version_lesson_id', $context->versionLessonId)
            ->whereIn('activities.activity_type', self::MATERIAL_TYPES)
            ->get([
                'activities.id', 'activities.sort_order', 'activities.title_snapshot', 'activities.activity_type',
                'activities.external_video_url_snapshot', 'activities.duration_seconds',
                'media.id as media_id', 'media.mime_type', 'media.page_count', 'media.duration_seconds as media_duration',
                'media.customer_id as media_customer_id', 'media.file_type as media_file_type', 'media.extension as media_extension',
                'media.storage_key as media_storage_key', 'media.checksum as media_checksum',
            ]);

        return $this->contentOrder->activities($context->customerId, $context->cohortId, $activities)
            ->map(fn (object $activity): ?array => $this->material($context->customerId, $activity))
            ->filter()
            ->values()
            ->all();
    }

    /** A fresh short-lived URL for one listed uploaded material. */
    public function materialUrl(LiveClassBoardContext $context, int $versionActivityId): string
    {
        $listed = collect($this->materials($context))
            ->first(fn (array $item): bool => $item['id'] === $versionActivityId && $item['kind'] !== 'embed');
        abort_if(! $listed, 404);

        return $this->media->generateLiveClassBoardSignedUrl($context, $versionActivityId, $listed['converted']);
    }

    private function material(int $customerId, object $activity): ?array
    {
        $base = [
            'id' => (int) $activity->id,
            'title' => $activity->title_snapshot,
            'page_count' => null,
            'duration_seconds' => null,
            'embed_url' => null,
            'converted' => false,
            'preview' => null,
        ];

        if ($activity->activity_type === 'embedded_video') {
            if (! $activity->external_video_url_snapshot) {
                return null;
            }
            try {
                $embed = $this->trustedVideos->embedUrl($activity->external_video_url_snapshot);
            } catch (Throwable) {
                return null;
            }

            return ['kind' => 'embed', 'embed_url' => $embed, 'duration_seconds' => (int) $activity->duration_seconds ?: null] + $base;
        }

        if (! $activity->media_id) {
            return null;
        }

        $kind = match (true) {
            $activity->activity_type === 'document' && $activity->mime_type === 'application/pdf' => 'pdf',
            $activity->activity_type === 'document' => 'document',
            $activity->activity_type === 'video' => 'video',
            default => 'audio',
        };

        if ($kind === 'document') {
            // An Office file with a ready PDF from Media opens like a PDF; until
            // then the drawer says why it opens in a new tab (LF-Core-LiveClass
            // § Tài liệu Office trên bảng).
            $state = $this->previews->state($customerId, (object) [
                'id' => (int) $activity->media_id, 'customer_id' => (int) $activity->media_customer_id,
                'file_type' => $activity->media_file_type, 'extension' => $activity->media_extension,
                'storage_key' => $activity->media_storage_key, 'checksum' => $activity->media_checksum,
            ]);
            if ($state !== null && $state['status'] === DocumentBoardPreview::READY) {
                return ['kind' => 'pdf', 'converted' => true, 'page_count' => $state['page_count']] + $base;
            }
            $base['preview'] = $state['status'] ?? null;
        }

        return [
            'kind' => $kind,
            'page_count' => $activity->page_count !== null ? (int) $activity->page_count : null,
            'duration_seconds' => $activity->media_duration !== null ? (int) $activity->media_duration : null,
        ] + $base;
    }

    /** The full Session row for a teacher assigned to it and inside their assignment window; 404/403 otherwise. */
    public function teacherSessionRow(int $customerId, int $teacherId, int $sessionId): object
    {
        $this->teacherSession($customerId, $teacherId, $sessionId);

        return DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $sessionId)->first()
            ?? abort(404);
    }

    private function teacherSession(int $customerId, int $teacherId, int $sessionId): object
    {
        $session = $this->sessionQuery($customerId)
            ->join('core_liveclass_session_teachers as assignments', function ($join) use ($customerId, $teacherId): void {
                $join->on('assignments.session_id', '=', 'sessions.id')
                    ->where('assignments.customer_id', $customerId)
                    ->where('assignments.teacher_id', $teacherId);
            })
            ->where('sessions.id', $sessionId)
            ->addSelect('assignments.assigned_from', 'assignments.assigned_to')
            ->first();

        // An unassigned teacher learns nothing about the Session.
        abort_if(! $session, 404);
        abort_unless($this->teacherIsActive($customerId, $teacherId) && $this->windowCovers($session), 403);

        return $session;
    }

    /**
     * The Session's meeting link for "Vào phòng họp" (LF-Core-LiveClass § Whiteboard,
     * Lối vào): only an online or hybrid Session, only an http(s) URL. A Session
     * without its own link uses the class's default (core_course_cohorts § Default Meeting Link).
     */
    public static function meetingUrl(object $session): ?string
    {
        $url = trim((string) ($session->meeting_url_snapshot ?? ''));
        if ($url === '') {
            $url = trim((string) ($session->cohort_meeting_url ?? ''));
        }
        if ($url === '' || ($session->delivery_mode ?? null) === 'offline') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) ? $url : null;
    }

    private function context(object $session, int $userId, string $role, bool $canPresent = true): LiveClassBoardContext
    {
        // A practice session has no board, for anyone (ADR-0022 Amendment 1.3, Đợt 4).
        abort_if(LiveClassPracticeSession::isType($session->lesson_type ?? null), 404);
        if ($session->cohort_status !== 'active' || $session->status !== 'live') {
            // Not started yet, or no longer running (ended, cancelled, class closed): the board pages say which.
            $notYet = $session->cohort_status === 'active' && $session->status === 'scheduled';
            throw new LiveClassBoardRefused('closed', (int) $session->cohort_id,
                __($notYet ? 'lf.LF_liveclass_whiteboard_not_live' : 'lf.LF_liveclass_whiteboard_ended'));
        }

        try {
            $plannedEnd = CarbonImmutable::parse((string) $session->scheduled_end_at, $session->timezone)->utc();
        } catch (Throwable) {
            $plannedEnd = null;
        }

        return new LiveClassBoardContext(
            customerId: (int) $session->customer_id,
            userId: $userId,
            role: $role,
            sessionId: (int) $session->id,
            cohortId: (int) $session->cohort_id,
            versionId: (int) $session->template_version_id,
            versionLessonId: $session->version_lesson_id !== null ? (int) $session->version_lesson_id : null,
            sessionTitle: (string) $session->title,
            cohortName: (string) $session->cohort_name,
            lessonTitle: $session->lesson_title,
            canPresent: $canPresent,
            plannedEndAt: $plannedEnd,
            deliveryMode: (string) ($session->delivery_mode ?? 'online'),
            meetingUrl: self::meetingUrl($session),
        );
    }

    private function learnerMembership(int $customerId, int $studentId, int $cohortId): ?object
    {
        return LearnerCohortSeat::current($customerId, $cohortId, $studentId);
    }

    /** An active learner User whose Enrollment for this membership is active and inside its access window now. */
    private function learnerMayView(int $customerId, object $membership): bool
    {
        $studentActive = DB::table('users')->where('id', $membership->student_id ?? 0)
            ->where('customer_id', $customerId)->where('role', 'student')->where('status', 'active')->exists();
        if (! $studentActive) {
            return false;
        }

        $enrollment = DB::table('core_course_enrollments')
            ->where('customer_id', $customerId)->where('id', $membership->enrollment_id)
            ->where('status', 'active')
            ->first(['id', 'access_starts_at', 'access_ends_at', 'review_starts_at', 'review_ends_at']);

        return $enrollment !== null && $this->enrollments->allowsLearningAccessAt($enrollment, Carbon::now());
    }

    private function sessionQuery(int $customerId)
    {
        return DB::table('core_liveclass_sessions as sessions')
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'sessions.cohort_id')->where('cohorts.customer_id', $customerId);
            })
            ->leftJoin('core_course_template_version_lessons as lessons', function ($join) use ($customerId): void {
                $join->on('lessons.id', '=', 'sessions.version_lesson_id')->where('lessons.customer_id', $customerId);
            })
            ->where('sessions.customer_id', $customerId)
            ->select([
                'sessions.id', 'sessions.customer_id', 'sessions.cohort_id', 'sessions.template_version_id',
                'sessions.version_lesson_id', 'sessions.title', 'sessions.session_type', 'sessions.delivery_mode',
                'sessions.status', 'sessions.scheduled_start_at', 'sessions.scheduled_end_at', 'sessions.timezone',
                'sessions.meeting_url_snapshot', 'sessions.room_name_snapshot', 'sessions.address_snapshot', 'sessions.attendance_tool',
                'sessions.actual_start_at', 'sessions.actual_end_at', 'sessions.version_activity_id',
                'cohorts.name as cohort_name', 'cohorts.status as cohort_status', 'cohorts.default_meeting_url as cohort_meeting_url',
                'lessons.title_snapshot as lesson_title', 'lessons.lesson_type',
            ]);
    }

    private function teacherIsActive(int $customerId, int $teacherId): bool
    {
        return DB::table('users')->where('id', $teacherId)->where('customer_id', $customerId)
            ->where('role', 'teacher')->where('status', 'active')->exists();
    }

    /** The optional assignment window, read as wall-clock time in the Session timezone. */
    private function windowCovers(object $session): bool
    {
        $now = Carbon::now($session->timezone)->format('Y-m-d H:i:s');

        return ($session->assigned_from === null || (string) $session->assigned_from <= $now)
            && ($session->assigned_to === null || (string) $session->assigned_to >= $now);
    }

    /** The planned start, in the Session's own timezone. */
    private function plannedStart(object $session): Carbon
    {
        return Carbon::parse((string) $session->scheduled_start_at, $session->timezone);
    }

    /** When the teacher may start: the planned start less the minutes the class's attendance policy opens early. */
    private function startOpensAt(object $session): Carbon
    {
        // One read per class, however many of its Sessions the list shows.
        $minutes = $this->opensBeforeMinutes[$session->customer_id.':'.$session->cohort_id]
            ??= max(0, (int) $this->attendancePolicies->forCohort((int) $session->customer_id, (int) $session->cohort_id)->opens_before_minutes);

        return $this->plannedStart($session)->subMinutes($minutes);
    }

    private function isPlannedToday(object $session): bool
    {
        return $this->localDate($session->scheduled_start_at, $session->timezone) === $this->today($session->timezone);
    }

    private function localDate(string $wallClock, string $timezone): string
    {
        return Carbon::parse($wallClock, $timezone)->toDateString();
    }

    private function today(string $timezone): string
    {
        return Carbon::now($timezone)->toDateString();
    }
}
