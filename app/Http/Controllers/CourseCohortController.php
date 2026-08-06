<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\CourseCohortLifecycleService;
use App\Services\CourseCohortVersionResolver;
use App\Services\LiveClassSchedulePreviewService;
use App\Services\LiveClassSessionPolicy;
use App\Support\SequentialCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourseCohortController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    private const STATUSES = [
        'draft',
        'active',
        'completed',
        'archived',
    ];

    private string $cohortRoutePrefix = 'admin.course-cohorts';

    public function __construct(
        private readonly CourseCohortVersionResolver $versionResolver,
        private readonly CourseCohortLifecycleService $lifecycleService,
        private readonly LiveClassSessionPolicy $sessionPolicy,
        private readonly LiveClassSchedulePreviewService $schedulePreviewService
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');

        if (! in_array($status, self::STATUSES, true)) {
            $status = null;
        }

        $cohorts = DB::table('core_course_cohorts as cohorts')
            ->leftJoin('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'cohorts.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->leftJoin('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'cohorts.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('cohorts.customer_id', $customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('cohorts.name', 'like', '%'.$keyword.'%')
                        ->orWhere('cohorts.code', 'like', '%'.$keyword.'%')
                        ->orWhere('products.title', 'like', '%'.$keyword.'%')
                        ->orWhere('products.product_code', 'like', '%'.$keyword.'%')
                        ->orWhere('versions.version_code', 'like', '%'.$keyword.'%');
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('cohorts.status', $status);
            })
            ->orderByDesc('cohorts.created_at')
            ->orderByDesc('cohorts.id')
            ->select(
                'cohorts.*',
                'products.title as product_title',
                'products.product_code',
                'products.registration_starts_at as product_registration_starts_at',
                'products.registration_ends_at as product_registration_ends_at',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code'
            )
            ->paginate(10)
            ->withQueryString();

        return view('course-cohorts.index', [
            'cohorts' => $cohorts,
            'keyword' => $keyword,
            'status' => $status,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();

        return view('course-cohorts.create', [
            'products' => $this->versionResolver->eligibleProducts($customerId),
            'cohortTabs' => $this->cohortTabs(null, $this->routePrefix($request), 'overview'),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedCreateData($request, $customerId);

        $cohortId = DB::transaction(function () use ($customerId, $validated): int {
            DB::table('saas_customers')->where('id', $customerId)->lockForUpdate()->first();
            $version = $this->versionResolver->resolve($customerId, (int) $validated['product_id'], true);
            abort_if(! $version, 422, __('lf.LF_course_cohort_validation_active_item'));
            $now = now();

            return DB::table('core_course_cohorts')->insertGetId(
                $this->cohortValues(array_merge($validated, [
                    'description' => null,
                    'status' => 'draft',
                ]), [
                    'customer_id' => $customerId,
                    'version_id' => $version->version_id,
                    'teacher_id' => null,
                    'code' => SequentialCodeGenerator::next($customerId, 'core_course_cohorts', 'code', 'COH'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }, 3);

        return redirect()
            ->route('admin.course-cohorts.show', $cohortId)
            ->with('success', __('lf.LF_course_cohort_common_created'));
    }

    public function show(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);
        $customerId = $this->customerId();
        $cohort = $this->findCohort($customerId, $id);
        $requestedTab = in_array($request->query('tab'), [
            'students', 'teachers', 'schedules', 'sessions', 'attendance', 'recordings',
        ], true) ? $request->query('tab') : 'overview';

        $activeMembershipCount = (int) DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)->where('cohort_id', $id)
            ->where('status', 'active')->count();
        $cohortSessions = $this->cohortSessionsQuery($customerId, $id);
        $eligibleAttendanceSessions = $cohortSessions
            ->filter(fn (object $session): bool => $this->sessionPolicy->canRecordAttendance($session, now()))
            ->values();
        $recordingCount = (int) DB::table('core_liveclass_recordings as recordings')
            ->join('core_liveclass_sessions as sessions', 'sessions.id', '=', 'recordings.session_id')
            ->where('recordings.customer_id', $customerId)->where('sessions.cohort_id', $id)->count();
        $scheduleCount = (int) DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)->where('cohort_id', $id)->count();

        $tabs = $this->cohortTabs($cohort, $routePrefix = $this->routePrefix($request), $requestedTab, [
            'students' => $activeMembershipCount,
            'schedules' => $scheduleCount,
            'sessions' => $eligibleAttendanceSessions->count(),
            'recordings' => $recordingCount,
        ]);
        $activeTab = collect($tabs)->firstWhere('key', $requestedTab)['accessible'] ? $requestedTab : 'overview';

        $tabData = match ($activeTab) {
            'students' => $this->studentTabData($request, $customerId, $id),
            'teachers' => $this->teacherTabData($customerId, $id),
            'schedules' => $this->scheduleTabData($request, $customerId, $id),
            'sessions' => $this->sessionTabData($customerId, $cohort),
            'attendance' => $this->attendanceTabData($request, $customerId, $id, $eligibleAttendanceSessions),
            'recordings' => $this->recordingTabData($customerId, $id),
            default => [],
        };

        return view('course-cohorts.show', array_merge([
            'studentKeyword' => '',
            'students' => collect(),
            'selectedEnrollments' => collect(),
            'teachers' => collect(),
            'availableTeachers' => collect(),
            'schedules' => collect(),
            'versionLessons' => collect(),
            'versionActivities' => collect(),
            'sessions' => collect(),
            'selectedSessionId' => null,
            'attendance' => collect(),
            'recordings' => collect(),
        ], $tabData, [
            'cohort' => $cohort,
            'activeMembershipCount' => $activeMembershipCount,
            'activeTab' => $activeTab,
            'activationIssues' => $cohort->status === 'draft'
                ? $this->lifecycleService->activationIssues($customerId, $id)
                : [],
            'cohortTabs' => $tabs,
            'routePrefix' => $routePrefix,
        ]));
    }

    private function availableTeachersQuery(int $customerId)
    {
        return DB::table('users')->where('customer_id', $customerId)
            ->where('role', 'teacher')->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function cohortSessionsQuery(int $customerId, int $cohortId)
    {
        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->leftJoin('core_liveclass_session_schedule_origins as origins', function ($join) use ($customerId): void {
                $join->on('origins.session_id', '=', 'sessions.id')
                    ->where('origins.customer_id', $customerId);
            })
            ->leftJoin('core_course_template_version_lessons as lessons', 'lessons.id', '=', 'sessions.version_lesson_id')
            ->leftJoin('core_course_template_version_activities as activities', 'activities.id', '=', 'sessions.version_activity_id')
            ->leftJoin('users as teachers', 'teachers.id', '=', 'sessions.primary_teacher_id')
            ->where('sessions.customer_id', $customerId)->where('sessions.cohort_id', $cohortId)
            ->orderBy('sessions.scheduled_start_at')->orderBy('sessions.id')
            ->select(
                'sessions.*',
                'lessons.title_snapshot as lesson_title',
                'activities.title_snapshot as activity_title',
                'activities.live_class_url_snapshot as activity_meeting_url',
                'teachers.name as primary_teacher_name', 'origins.id as schedule_origin_id', 'origins.schedule_id as source_schedule_id',
                'origins.source_start_at', 'origins.source_end_at', 'origins.source_timezone'
            )
            ->get();

        if ($sessions->isEmpty()) {
            return $sessions;
        }

        $teamBySession = DB::table('core_liveclass_session_teachers as assignments')
            ->join('users as teachers', function ($join) use ($customerId): void {
                $join->on('teachers.id', '=', 'assignments.teacher_id')
                    ->where('teachers.customer_id', $customerId);
            })
            ->where('assignments.customer_id', $customerId)
            ->whereIn('assignments.session_id', $sessions->pluck('id'))
            ->orderByRaw("CASE assignments.role WHEN 'primary_teacher' THEN 0 WHEN 'teacher' THEN 1 ELSE 2 END")
            ->orderBy('teachers.name')
            ->get(['assignments.session_id', 'assignments.teacher_id', 'assignments.role', 'teachers.name'])
            ->groupBy('session_id');

        $sessions->each(function (object $session) use ($teamBySession): void {
            $team = $teamBySession->get($session->id, collect())->values();
            $session->teacher_team = $team;
            $teacherIds = $team->pluck('teacher_id')->map(fn ($id): string => (string) $id);
            if ($session->primary_teacher_id && ! $teacherIds->contains((string) $session->primary_teacher_id)) {
                $teacherIds->prepend((string) $session->primary_teacher_id);
            }
            $session->teacher_ids = $teacherIds->unique()->values();
            if ($session->schedule_origin_id) {
                $currentStart = Carbon::parse($session->scheduled_start_at, $session->timezone)->utc();
                $currentEnd = Carbon::parse($session->scheduled_end_at, $session->timezone)->utc();
                $session->schedule_relation = $currentStart->equalTo(Carbon::parse($session->source_start_at, 'UTC'))
                    && $currentEnd->equalTo(Carbon::parse($session->source_end_at, 'UTC'))
                        ? 'on_schedule' : 'rescheduled';
            } else {
                $session->schedule_relation = Carbon::parse($session->created_at)
                    ->lt(Carbon::parse(config('liveclass.schedule_origin_rollout_at')))
                        ? 'source_unknown' : 'off_schedule';
            }
        });

        return $sessions;
    }

    private function studentTabData(Request $request, int $customerId, int $id): array
    {
        $studentKeyword = trim((string) $request->query('student_keyword', ''));
        $selectedEnrollments = DB::table('core_course_cohort_students as memberships')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'memberships.student_id')
                    ->where('students.customer_id', $customerId);
            })
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')
                    ->where('enrollments.customer_id', $customerId);
            })
            ->where('memberships.customer_id', $customerId)
            ->where('memberships.cohort_id', $id)
            ->where('memberships.status', 'active')
            ->orderBy('students.name')
            ->get([
                'enrollments.id', 'enrollments.status',
                'students.name as student_name', 'students.email as student_email',
                'students.status as student_status',
            ]);
        $students = DB::table('core_course_cohort_students as memberships')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'memberships.student_id')
                    ->where('students.customer_id', $customerId);
            })
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')
                    ->where('enrollments.customer_id', $customerId);
            })
            ->where('memberships.customer_id', $customerId)
            ->where('memberships.cohort_id', $id)
            ->where('memberships.status', 'active')
            ->when($studentKeyword !== '', function ($query) use ($studentKeyword): void {
                $query->where(function ($query) use ($studentKeyword): void {
                    $query->where('students.name', 'like', '%'.$studentKeyword.'%')
                        ->orWhere('students.email', 'like', '%'.$studentKeyword.'%')
                        ->orWhere('enrollments.id', preg_replace('/\D+/', '', $studentKeyword) ?: 0);
                });
            })
            ->orderBy('students.name')
            ->orderBy('memberships.id')
            ->select(
                'memberships.id as membership_id',
                'memberships.joined_at',
                'enrollments.id as enrollment_id',
                'enrollments.status as enrollment_status',
                'enrollments.source as enrollment_source',
                'enrollments.enrolled_at',
                'enrollments.access_starts_at',
                'enrollments.access_ends_at',
                'enrollments.review_starts_at',
                'enrollments.review_ends_at',
                'students.name as student_name',
                'students.email as student_email'
            )
            ->paginate(10, ['*'], 'student_page')
            ->withQueryString();

        return [
            'students' => $students,
            'studentKeyword' => $studentKeyword,
            'selectedEnrollments' => $selectedEnrollments,
        ];
    }

    private function teacherTabData(int $customerId, int $id): array
    {
        $teachers = DB::table('core_course_cohort_teachers as assignments')
            ->join('users as teachers', 'teachers.id', '=', 'assignments.teacher_id')
            ->where('assignments.customer_id', $customerId)
            ->where('assignments.cohort_id', $id)
            ->where('assignments.status', 'active')
            ->select('assignments.*', 'teachers.name as teacher_name', 'teachers.email as teacher_email')
            ->orderByRaw("CASE assignments.role WHEN 'primary_teacher' THEN 0 WHEN 'teacher' THEN 1 ELSE 2 END")
            ->orderBy('teachers.name')->get();

        return [
            'teachers' => $teachers,
            'availableTeachers' => $this->availableTeachersQuery($customerId)
                ->reject(fn (object $teacher): bool => $teachers->contains('teacher_id', $teacher->id))
                ->values(),
        ];
    }

    private function scheduleTabData(Request $request, int $customerId, int $cohortId): array
    {
        $schedules = DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->paginate(10, ['*'], 'schedule_page')
            ->withQueryString();
        $scheduleIds = $schedules->getCollection()->pluck('id');
        $slots = $scheduleIds->isEmpty() ? collect() : DB::table('core_liveclass_schedule_slots')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('schedule_id');
        $exclusions = $scheduleIds->isEmpty() ? collect() : DB::table('core_liveclass_schedule_exclusions')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->orderBy('excluded_on')->orderBy('id')->get()->groupBy('schedule_id');

        $schedules->getCollection()->each(function (object $schedule) use ($slots, $exclusions): void {
            $schedule->slots = $slots->get($schedule->id, collect())->values();
            $schedule->exclusions = $exclusions->get($schedule->id, collect())->values();
            $schedule->preview_count = $this->schedulePreviewService->calculate(
                $schedule->starts_on,
                $schedule->ends_on,
                $schedule->timezone,
                $schedule->slots,
                $schedule->exclusions
            )->count();
            $schedule->derived_status = $this->schedulePreviewService->derivedStatus(
                $schedule->starts_on,
                $schedule->ends_on,
                $schedule->timezone
            );
        });

        $formMode = in_array($request->query('schedule_form'), ['create', 'view', 'edit'], true)
            ? $request->query('schedule_form')
            : null;
        $formSchedule = null;
        $formSlots = collect();
        $formExclusions = collect();
        $formPreview = collect();
        $formDerivedStatus = null;

        if (in_array($formMode, ['view', 'edit'], true)) {
            $formSchedule = DB::table('core_liveclass_schedules')
                ->where('customer_id', $customerId)
                ->where('cohort_id', $cohortId)
                ->where('id', $request->integer('schedule_id'))
                ->firstOrFail();
            $formSlots = DB::table('core_liveclass_schedule_slots')
                ->where('customer_id', $customerId)
                ->where('schedule_id', $formSchedule->id)
                ->orderBy('sort_order')->orderBy('id')->get();
            $formExclusions = DB::table('core_liveclass_schedule_exclusions')
                ->where('customer_id', $customerId)
                ->where('schedule_id', $formSchedule->id)
                ->orderBy('excluded_on')->orderBy('id')->get();
            if ($formMode === 'view') {
                $formPreview = $this->schedulePreviewService->calculate(
                    $formSchedule->starts_on,
                    $formSchedule->ends_on,
                    $formSchedule->timezone,
                    $formSlots,
                    $formExclusions
                );
                $formDerivedStatus = $this->schedulePreviewService->derivedStatus(
                    $formSchedule->starts_on,
                    $formSchedule->ends_on,
                    $formSchedule->timezone
                );
            }
        }

        return [
            'schedules' => $schedules,
            'scheduleFormMode' => $formMode,
            'scheduleFormSchedule' => $formSchedule,
            'scheduleFormSlots' => $formSlots,
            'scheduleFormExclusions' => $formExclusions,
            'scheduleFormPreview' => $formPreview,
            'scheduleFormDerivedStatus' => $formDerivedStatus,
            'scheduleTimezones' => timezone_identifiers_list(),
        ];
    }

    private function sessionTabData(int $customerId, object $cohort): array
    {
        $versionLessons = collect();
        $versionActivities = collect();
        if ($cohort->version_id) {
            $versionLessons = DB::table('core_course_template_version_lessons')
                ->where('customer_id', $customerId)->where('template_version_id', $cohort->version_id)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'title_snapshot']);
            $versionActivities = DB::table('core_course_template_version_activities')
                ->where('customer_id', $customerId)->where('template_version_id', $cohort->version_id)
                ->where('activity_type', 'live_class')
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'version_lesson_id', 'title_snapshot', 'live_class_url_snapshot']);
        }

        $availableTeachers = DB::table('core_course_cohort_teachers as assignments')
            ->join('users as teachers', function ($join) use ($customerId): void {
                $join->on('teachers.id', '=', 'assignments.teacher_id')
                    ->where('teachers.customer_id', $customerId)
                    ->where('teachers.role', 'teacher')
                    ->where('teachers.status', 'active');
            })
            ->where('assignments.customer_id', $customerId)
            ->where('assignments.cohort_id', $cohort->id)
            ->where('assignments.status', 'active')
            ->orderByRaw("CASE assignments.role WHEN 'primary_teacher' THEN 0 WHEN 'teacher' THEN 1 ELSE 2 END")
            ->orderBy('teachers.name')
            ->get([
                'teachers.id', 'teachers.name', 'teachers.email',
                'assignments.role', 'assignments.assigned_from', 'assignments.assigned_to',
            ]);
        $sessions = $this->cohortSessionsQuery($customerId, $cohort->id);
        $sessionIds = $sessions->pluck('id');
        $sessionsWithEvidence = $sessionIds->isEmpty() ? collect() : DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id')
            ->merge(DB::table('core_liveclass_recordings')
                ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id'))
            ->map(fn ($id): int => (int) $id)->unique();
        $sessions->each(function (object $session) use ($sessionsWithEvidence): void {
            $session->can_edit = $this->sessionPolicy->canEdit(
                $session,
                $sessionsWithEvidence->contains((int) $session->id),
                now()
            );
        });

        $plannedOccurrences = collect();
        $schedules = DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort->id)
            ->whereDate('ends_on', '>=', now()->toDateString())->orderBy('starts_on')->get();
        $consumed = DB::table('core_liveclass_session_schedule_origins')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $schedules->pluck('id'))
            ->get()->mapWithKeys(fn ($origin) => [implode('|', [$origin->schedule_id, $origin->schedule_slot_id, $origin->source_local_date]) => true]);
        foreach ($schedules as $schedule) {
            $slots = DB::table('core_liveclass_schedule_slots')->where('customer_id', $customerId)
                ->where('schedule_id', $schedule->id)->orderBy('sort_order')->get();
            $exclusions = DB::table('core_liveclass_schedule_exclusions')->where('customer_id', $customerId)
                ->where('schedule_id', $schedule->id)->get();
            $preview = $this->schedulePreviewService->calculate(
                max($schedule->starts_on, now($schedule->timezone)->toDateString()),
                $schedule->ends_on, $schedule->timezone, $slots, $exclusions
            );
            foreach ($preview as $occurrence) {
                $key = implode('|', [$schedule->id, $occurrence['schedule_slot_id'], $occurrence['date']]);
                if (! $consumed->has($key)) {
                    $plannedOccurrences->push(array_merge($occurrence, [
                        'schedule_id' => (int) $schedule->id,
                        'schedule_name' => $schedule->name,
                    ]));
                }
            }
        }

        return [
            'versionLessons' => $versionLessons,
            'versionActivities' => $versionActivities,
            'availableTeachers' => $availableTeachers,
            'sessions' => $sessions,
            'plannedOccurrences' => $plannedOccurrences->take(100)->values(),
        ];
    }

    private function attendanceTabData(Request $request, int $customerId, int $id, $sessions): array
    {
        $selectedSessionId = $request->integer('session_id') ?: ($sessions->first()?->id);
        if ($selectedSessionId && ! $sessions->contains('id', $selectedSessionId)) {
            $selectedSessionId = $sessions->first()?->id;
        }
        $attendance = collect();
        if ($selectedSessionId) {
            $attendance = DB::table('core_course_cohort_students as memberships')
                ->join('core_course_enrollments as enrollments', 'enrollments.id', '=', 'memberships.enrollment_id')
                ->join('users as students', 'students.id', '=', 'memberships.student_id')
                ->leftJoin('core_liveclass_attendances as attendance', function ($join) use ($selectedSessionId): void {
                    $join->on('attendance.enrollment_id', '=', 'memberships.enrollment_id')
                        ->where('attendance.session_id', $selectedSessionId);
                })
                ->where('memberships.customer_id', $customerId)->where('memberships.cohort_id', $id)
                ->where('memberships.status', 'active')
                ->orderBy('students.name')
                ->get([
                    'enrollments.id as enrollment_id', 'students.name as student_name',
                    'students.email as student_email', 'attendance.status as attendance_status',
                    'attendance.attendance_mode', 'attendance.notes',
                ]);
        }

        return ['sessions' => $sessions, 'selectedSessionId' => $selectedSessionId, 'attendance' => $attendance];
    }

    private function recordingTabData(int $customerId, int $id): array
    {
        $recordings = DB::table('core_liveclass_recordings as recordings')
            ->join('core_liveclass_sessions as sessions', 'sessions.id', '=', 'recordings.session_id')
            ->where('recordings.customer_id', $customerId)->where('sessions.cohort_id', $id)
            ->orderByDesc('recordings.id')
            ->select('recordings.*', 'sessions.title as session_title', 'sessions.session_no')
            ->get();

        $sessions = $this->cohortSessionsQuery($customerId, $id)
            ->filter(fn (object $session): bool => $this->sessionPolicy->canCreateRecording($session, now()))
            ->values();

        return ['sessions' => $sessions, 'recordings' => $recordings];
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $cohort = $this->findCohort($customerId, $id);

        if ($request->query('tab') === 'students') {
            return redirect()->route('admin.course-cohorts.show', [
                'id' => $cohort->id,
                'tab' => 'students',
                'manage' => 1,
            ]);
        }

        $activeMembershipCount = (int) DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $id)
            ->where('status', 'active')
            ->count();

        return view('course-cohorts.edit', [
            'cohort' => $cohort,
            'cohortTabs' => $this->cohortTabs($cohort, $this->routePrefix($request),
                'overview', [
                    'students' => $activeMembershipCount,
                    'schedules' => (int) DB::table('core_liveclass_schedules')
                        ->where('customer_id', $customerId)->where('cohort_id', $id)->count(),
                    'sessions' => (int) DB::table('core_liveclass_sessions')->where('customer_id', $customerId)
                        ->where('cohort_id', $id)->count(),
                    'recordings' => (int) DB::table('core_liveclass_recordings as recordings')
                        ->join('core_liveclass_sessions as sessions', 'sessions.id', '=', 'recordings.session_id')
                        ->where('recordings.customer_id', $customerId)->where('sessions.cohort_id', $id)->count(),
                ]),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $cohort = $this->findCohort($customerId, $id);
        abort_if($cohort->status === 'archived' || $cohort->status === 'completed', 422);
        $validated = $this->validatedUpdateData($request, $customerId, $cohort);

        DB::transaction(function () use ($customerId, $id, $validated): void {
            $locked = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $locked, 404);
            $activeCount = DB::table('core_course_cohort_students')->where('customer_id', $customerId)
                ->where('cohort_id', $id)->where('status', 'active')->count();
            abort_if($validated['capacity'] !== null && (int) $validated['capacity'] < $activeCount, 422,
                __('lf.LF_course_cohort_validation_capacity_below_membership'));

            $scheduleWindowErrors = $this->scheduleWindowErrors(
                $customerId,
                $id,
                $validated['start_date'] ?? null,
                $validated['end_date'] ?? null
            );
            if ($scheduleWindowErrors !== []) {
                throw ValidationException::withMessages(['start_date' => $scheduleWindowErrors]);
            }

            $values = [
                'name' => $validated['name'],
                'capacity' => $validated['capacity'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'updated_at' => now(),
            ];

            if ($locked->product_id && ! $locked->version_id) {
                $version = $this->versionResolver->resolve($customerId, (int) $locked->product_id, true);
                abort_if(! $version, 422, __('lf.LF_course_cohort_validation_active_item'));
                $values['version_id'] = $version->version_id;
            }

            DB::table('core_course_cohorts')->where('customer_id', $customerId)->where('id', $id)->update($values);

            DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)
                ->where('cohort_id', $id)
                ->where('role', 'primary_teacher')
                ->where('status', 'active')
                ->update([
                    'assigned_from' => $values['start_date'],
                    'assigned_to' => $values['end_date'],
                    'updated_at' => now(),
                ]);
        }, 3);

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_common_updated'));
    }

    public function activate(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $this->lifecycleService->activate($this->customerId(), $id);

        return redirect()->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_lifecycle_activated'));
    }

    public function complete(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $this->lifecycleService->complete($this->customerId(), $id);

        return redirect()->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_lifecycle_completed'));
    }

    public function archive(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $this->lifecycleService->archive($this->customerId(), $id);

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_lifecycle_archived'));
    }

    private function validatedCreateData(Request $request, int $customerId): array
    {
        $input = array_intersect_key($request->request->all(), array_flip([
            'product_id', 'name', 'capacity', 'start_date', 'end_date', 'notes',
        ]));

        $validator = Validator::make($input, [
            'product_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request, $customerId): void {
            foreach ([
                'customer_id', 'version_id', 'teacher_id', 'student_id',
                'cohort_document_file', 'cohort_attachment_file',
            ] as $managed) {
                if ($request->has($managed) || $request->hasFile($managed)) {
                    $validator->errors()->add($managed, __('lf.LF_course_cohort_validation_managed'));
                }
            }

            $productId = (int) $request->input('product_id');
            if ($productId > 0 && ! $this->versionResolver->resolve($customerId, $productId)) {
                $validator->errors()->add('product_id', __('lf.LF_course_cohort_validation_active_item'));
            }
        });

        return $validator->validate();
    }

    private function validatedUpdateData(Request $request, int $customerId, object $cohort): array
    {
        $input = array_intersect_key($request->request->all(), array_flip([
            'name', 'capacity', 'start_date', 'end_date', 'notes',
        ]));

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'required_with:end_date', 'date'],
            'end_date' => ['nullable', 'required_with:start_date', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request, $customerId, $cohort, $input): void {
            foreach (['customer_id', 'version_id', 'teacher_id'] as $managed) {
                if ($request->has($managed)) {
                    $validator->errors()->add($managed, __('lf.LF_course_cohort_validation_managed'));
                }
            }

            foreach (['cohort_document_file', 'cohort_attachment_file'] as $managedFile) {
                if ($request->hasFile($managedFile)) {
                    $validator->errors()->add($managedFile, __('lf.LF_course_cohort_validation_managed'));
                }
            }

            if ($request->has('product_id')
                && (int) $request->input('product_id') !== (int) $cohort->product_id) {
                $validator->errors()->add('product_id', __('lf.LF_course_cohort_validation_binding_locked'));
            }

            if ((int) $cohort->customer_id !== $customerId) {
                $validator->errors()->add('customer_id', __('lf.LF_course_cohort_validation_managed'));
            }

            $hasPrimaryTeacher = DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)
                ->where('cohort_id', $cohort->id)
                ->where('role', 'primary_teacher')
                ->where('status', 'active')
                ->exists();
            if ($hasPrimaryTeacher && (empty($input['start_date']) || empty($input['end_date']))) {
                $validator->errors()->add('start_date', __('lf.LF_course_cohort_teacher_validation_primary_period_required'));
            }

            if (! $validator->errors()->hasAny(['start_date', 'end_date'])) {
                foreach ($this->scheduleWindowErrors(
                    $customerId,
                    (int) $cohort->id,
                    $input['start_date'] ?? null,
                    $input['end_date'] ?? null
                ) as $message) {
                    $validator->errors()->add('start_date', $message);
                }
            }
        });

        return $validator->validate();
    }

    private function scheduleWindowErrors(
        int $customerId,
        int $cohortId,
        ?string $startsOn,
        ?string $endsOn
    ): array {
        $schedules = DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->when($startsOn && $endsOn, function ($query) use ($startsOn, $endsOn): void {
                $query->where(function ($query) use ($startsOn, $endsOn): void {
                    $query->where('starts_on', '<', $startsOn)
                        ->orWhere('ends_on', '>', $endsOn);
                });
            })
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get(['name', 'starts_on', 'ends_on']);

        if ($startsOn && $endsOn) {
            return $schedules->map(fn (object $schedule): string => __(
                'lf.LF_course_cohort_validation_schedule_outside_operation',
                [
                    'name' => $schedule->name,
                    'from' => Carbon::parse($schedule->starts_on)->format('d/m/Y'),
                    'to' => Carbon::parse($schedule->ends_on)->format('d/m/Y'),
                ]
            ))->all();
        }

        return $schedules->isEmpty()
            ? []
            : [__('lf.LF_course_cohort_validation_schedule_requires_operation')];
    }

    private function cohortValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'product_id' => $validated['product_id'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'capacity' => $validated['capacity'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], $extra);
    }

    private function findCohort(int $customerId, int $id): object
    {
        $lessonCounts = DB::table('core_course_template_version_lessons as lesson_counts')
            ->selectRaw('COUNT(*)')
            ->whereColumn('lesson_counts.customer_id', 'cohorts.customer_id')
            ->whereColumn('lesson_counts.template_version_id', 'versions.id');
        $activityCounts = DB::table('core_course_template_version_activities as activity_counts')
            ->selectRaw('COUNT(*)')
            ->whereColumn('activity_counts.customer_id', 'cohorts.customer_id')
            ->whereColumn('activity_counts.template_version_id', 'versions.id');

        $cohort = DB::table('core_course_cohorts as cohorts')
            ->leftJoin('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'cohorts.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->leftJoin('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'cohorts.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('cohorts.customer_id', $customerId)
            ->where('cohorts.id', $id)
            ->select(
                'cohorts.*',
                'products.title as product_title',
                'products.product_code',
                'products.registration_starts_at as product_registration_starts_at',
                'products.registration_ends_at as product_registration_ends_at',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code'
            )
            ->selectSub($lessonCounts, 'lesson_count')
            ->selectSub($activityCounts, 'activity_count')
            ->first();

        abort_if(! $cohort, 404);

        return $cohort;
    }

    private function cohortTabs(?object $cohort, string $routePrefix, string $activeTab, array $counts = []): array
    {
        $exists = $cohort !== null;
        $historical = $exists && in_array($cohort->status, ['completed', 'archived'], true);
        $active = $exists && $cohort->status === 'active';
        $hasSessions = ($counts['sessions'] ?? 0) > 0;

        $definitions = [
            'overview' => [true, null],
            'students' => [$exists, 'LF_course_cohort_tab_students_locked_create'],
            'teachers' => [$exists, 'LF_course_cohort_tab_teachers_locked_create'],
            'schedules' => [$exists, 'LF_course_cohort_tab_schedules_locked_create'],
            'sessions' => [$exists, 'LF_course_cohort_tab_sessions_locked_create'],
            'attendance' => [$historical || ($active && $hasSessions), ! $exists
                ? 'LF_course_cohort_tab_attendance_locked_create'
                : ($active ? 'LF_course_cohort_tab_attendance_locked_sessions' : 'LF_course_cohort_tab_attendance_locked_active')],
            'recordings' => [$historical || $active, ! $exists
                ? 'LF_course_cohort_tab_recordings_locked_create'
                : 'LF_course_cohort_tab_recordings_locked_active'],
        ];

        return collect($definitions)->map(function (array $state, string $key) use ($cohort, $routePrefix, $activeTab, $historical, $counts): array {
            [$accessible, $lockedReasonKey] = $state;
            $route = null;
            if ($cohort && $accessible) {
                $route = route($routePrefix.'.show', $key === 'overview'
                    ? ['id' => $cohort->id]
                    : ['id' => $cohort->id, 'tab' => $key]);
            }

            return [
                'key' => $key,
                'label' => __('lf.LF_course_cohort_tab_'.$key),
                'visible' => true,
                'accessible' => $accessible,
                'read_only' => $historical,
                'status' => $key === $activeTab ? 'active' : ($accessible ? ($historical ? 'read_only' : 'available') : 'locked'),
                'note' => __('lf.LF_course_cohort_tab_'.$key.($cohort ? '_detail_note' : '_note')),
                'locked_reason' => $accessible || ! $lockedReasonKey ? null : __('lf.'.$lockedReasonKey),
                'route' => $route,
                'badge' => $counts[$key] ?? null,
            ];
        })->values()->all();
    }
}
