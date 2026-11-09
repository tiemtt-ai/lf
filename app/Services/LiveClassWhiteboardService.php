<?php

namespace App\Services;

use App\Models\User;
use App\Support\LiveClassBoardContext;
use Carbon\CarbonImmutable;
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

    public function __construct(
        private readonly LiveClassSessionLifecycleService $lifecycle,
        private readonly CourseCohortContentOrder $contentOrder,
        private readonly MediaService $media,
        private readonly TrustedVideoUrlService $trustedVideos,
        private readonly CourseEnrollmentLifecycleService $enrollments,
        private readonly DocumentBoardPreview $previews,
    ) {}

    /**
     * Upcoming and live Sessions the teacher is assigned to, in active Cohorts.
     *
     * @return Collection<int, object>
     */
    public function teacherSessions(int $customerId, int $teacherId): Collection
    {
        $teacherActive = $this->teacherIsActive($customerId, $teacherId);

        return $this->sessionQuery($customerId)
            ->join('core_liveclass_session_teachers as assignments', function ($join) use ($customerId, $teacherId): void {
                $join->on('assignments.session_id', '=', 'sessions.id')
                    ->where('assignments.customer_id', $customerId)
                    ->where('assignments.teacher_id', $teacherId);
            })
            ->whereIn('sessions.status', ['scheduled', 'live'])
            ->where('cohorts.status', 'active')
            ->addSelect('assignments.assigned_from', 'assignments.assigned_to')
            ->orderBy('sessions.scheduled_start_at')->orderBy('sessions.id')
            ->get()
            ->filter(fn (object $session): bool => $session->status === 'live'
                || $this->localDate($session->scheduled_start_at, $session->timezone) >= $this->today($session->timezone))
            ->take(100)
            ->map(function (object $session) use ($teacherActive): object {
                $presenter = $teacherActive && $this->windowCovers($session);
                $session->is_today = $this->isPlannedToday($session);
                $session->can_start = $presenter && $session->status === 'scheduled' && $session->is_today;
                $session->can_open = $presenter && $session->status === 'live';
                $session->can_complete = $session->can_open;

                return $session;
            })
            ->values();
    }

    public function startForTeacher(int $customerId, int $teacherId, int $sessionId): void
    {
        $session = $this->teacherSession($customerId, $teacherId, $sessionId);

        if ($session->status !== 'scheduled' || ! $this->isPlannedToday($session)) {
            throw ValidationException::withMessages([
                'lifecycle' => __('lf.LF_liveclass_whiteboard_start_not_today'),
            ]);
        }

        $this->lifecycle->start($customerId, (int) $session->cohort_id, $sessionId);
    }

    public function completeForTeacher(int $customerId, int $teacherId, int $sessionId): void
    {
        $session = $this->teacherSession($customerId, $teacherId, $sessionId);
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
        abort_unless($this->learnerMayView($customerId, $membership), 403);

        return $this->context($session, $studentId, 'student', canPresent: false);
    }

    /**
     * Live Sessions whose board the learner may open now, for the learner course page.
     *
     * @return Collection<int, object>
     */
    public function learnerLiveSessions(int $customerId, int $studentId): Collection
    {
        return $this->sessionQuery($customerId)
            ->join('core_course_cohort_students as memberships', function ($join) use ($customerId, $studentId): void {
                $join->on('memberships.cohort_id', '=', 'sessions.cohort_id')
                    ->where('memberships.customer_id', $customerId)
                    ->where('memberships.student_id', $studentId)
                    ->where('memberships.status', 'active')
                    ->whereNull('memberships.left_at');
            })
            ->where('sessions.status', 'live')
            ->where('cohorts.status', 'active')
            ->addSelect('memberships.enrollment_id', 'memberships.student_id')
            ->orderBy('sessions.scheduled_start_at')->orderBy('sessions.id')
            ->limit(50)
            ->get()
            ->filter(fn (object $session): bool => $this->learnerMayView($customerId, $session))
            ->values();
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
     * Ready Media of the Session's Version Lesson that the Activity makes
     * available during the Session, in the Cohort's own order.
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
            ->where('activities.available_during_session', true)
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
        abort_unless($session->cohort_status === 'active' && $session->status === 'live', 403,
            __('lf.LF_liveclass_whiteboard_not_live'));

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
        return DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)
            ->where('student_id', $studentId)->where('status', 'active')->whereNull('left_at')
            ->first(['id', 'enrollment_id', 'student_id']);
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
                'sessions.meeting_url_snapshot', 'sessions.room_name_snapshot',
                'cohorts.name as cohort_name', 'cohorts.status as cohort_status', 'cohorts.default_meeting_url as cohort_meeting_url',
                'lessons.title_snapshot as lesson_title',
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
