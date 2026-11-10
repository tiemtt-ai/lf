<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\CourseCohortContentReorder;
use App\Services\CourseCohortContentTree;
use App\Services\CourseCohortMutationPolicy;
use App\Services\CourseCohortSessionWindow;
use App\Services\CourseCohortVersionResolver;
use App\Services\LiveClassAttendanceCheckIn;
use App\Services\LiveClassCohortSessionReadModel;
use App\Services\LiveClassMeetingProvider;
use App\Services\LiveClassSessionLifecycleService;
use App\Services\LiveClassSessionOriginService;
use App\Services\LiveClassSessionOverlap;
use App\Services\LiveClassSessionPolicy;
use App\Services\LiveClassSessionTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseCohortOperationController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    public function __construct(
        private readonly LiveClassSessionPolicy $sessionPolicy,
        private readonly LiveClassSessionOriginService $sessionOriginService,
        private readonly LiveClassSessionLifecycleService $sessionLifecycleService,
        private readonly LiveClassCohortSessionReadModel $sessionReadModel,
        private readonly CourseCohortContentTree $contentTree,
        private readonly CourseCohortContentReorder $contentReorder
    ) {}

    public function storeTeacher(Request $request, int $cohort): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        if ($request->input('role') === 'primary_teacher') {
            $request->merge([
                'assigned_from' => $cohortRow->start_date,
                'assigned_to' => $cohortRow->end_date,
            ]);
        }
        $assignedTeacherIds = DB::table('core_course_cohort_teachers')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohort)
            ->where('status', 'active')
            ->pluck('teacher_id')
            ->all();
        $assignedFromRules = ['nullable', 'date'];
        $assignedToRules = ['nullable', 'date', 'after_or_equal:assigned_from'];

        if ($cohortRow->start_date) {
            $assignedFromRules[] = 'after_or_equal:'.$cohortRow->start_date;
            $assignedToRules[] = 'after_or_equal:'.$cohortRow->start_date;
        }

        if ($cohortRow->end_date) {
            $assignedFromRules[] = 'before_or_equal:'.$cohortRow->end_date;
            $assignedToRules[] = 'before_or_equal:'.$cohortRow->end_date;
        }

        $validated = $request->validate([
            'teacher_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->where('role', 'teacher')
                    ->where('status', 'active')),
                Rule::notIn($assignedTeacherIds),
            ],
            'role' => ['required', Rule::in(['primary_teacher', 'teacher', 'assistant'])],
            'assigned_from' => $assignedFromRules,
            'assigned_to' => $assignedToRules,
        ], [
            'teacher_id.required' => __('lf.LF_course_cohort_teacher_validation_teacher_required'),
            'teacher_id.integer' => __('lf.LF_course_cohort_teacher_validation_teacher_invalid'),
            'teacher_id.exists' => __('lf.LF_course_cohort_teacher_validation_teacher_invalid'),
            'teacher_id.not_in' => __('lf.LF_course_cohort_teacher_validation_teacher_assigned'),
            'role.required' => __('lf.LF_course_cohort_teacher_validation_role_required'),
            'role.in' => __('lf.LF_course_cohort_teacher_validation_role_invalid'),
            'assigned_from.date' => __('lf.LF_course_cohort_teacher_validation_date_invalid'),
            'assigned_from.after_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
            'assigned_from.before_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
            'assigned_to.date' => __('lf.LF_course_cohort_teacher_validation_date_invalid'),
            'assigned_to.after_or_equal' => __('lf.LF_course_cohort_teacher_validation_end_before_start'),
            'assigned_to.before_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
        ]);

        if ($validated['role'] === 'primary_teacher') {
            if (! $cohortRow->start_date || ! $cohortRow->end_date) {
                throw ValidationException::withMessages([
                    'role' => __('lf.LF_course_cohort_teacher_validation_primary_period_required'),
                ]);
            }

            $validated['assigned_from'] = $cohortRow->start_date;
            $validated['assigned_to'] = $cohortRow->end_date;
        }

        DB::transaction(function () use ($customerId, $cohort, $validated, $request): void {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohort)->lockForUpdate()->firstOrFail();

            if ($validated['role'] === 'primary_teacher') {
                DB::table('core_course_cohort_teachers')
                    ->where('customer_id', $customerId)
                    ->where('cohort_id', $cohort)
                    ->where('role', 'primary_teacher')
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->update(['role' => 'teacher', 'updated_at' => now()]);
            }

            DB::table('core_course_cohort_teachers')->updateOrInsert(
                [
                    'customer_id' => $customerId,
                    'cohort_id' => $cohort,
                    'teacher_id' => $validated['teacher_id'],
                ],
                [
                    'role' => $validated['role'],
                    'assigned_from' => $validated['assigned_from'] ?? null,
                    'assigned_to' => $validated['assigned_to'] ?? null,
                    'status' => 'active',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $this->fillEmptySessionTeams($customerId, $cohort, $validated, (int) $request->user()->id);
        });

        return $this->tab($cohort, 'teachers')
            ->with('success', __('lf.LF_course_cohort_teacher_saved'));
    }

    /**
     * A Schedule saved before the class had teachers leaves Sessions without a team, and those block activation.
     * The new teacher joins the ones still to come within their period; a Session that has a team keeps it.
     *
     * @param  array<string, mixed>  $assignment
     */
    private function fillEmptySessionTeams(int $customerId, int $cohort, array $assignment, int $actorId): void
    {
        $from = $assignment['assigned_from'] ?? null;
        $to = $assignment['assigned_to'] ?? null;
        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->where('sessions.customer_id', $customerId)
            ->where('sessions.cohort_id', $cohort)
            ->where('sessions.status', 'scheduled')
            ->whereNull('sessions.primary_teacher_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('core_liveclass_session_teachers as team')
                ->whereColumn('team.session_id', 'sessions.id')
                ->where('team.customer_id', $customerId))
            ->lockForUpdate()
            ->get(['sessions.id', 'sessions.scheduled_start_at', 'sessions.scheduled_end_at', 'sessions.timezone']);

        foreach ($sessions as $session) {
            $startsOn = substr((string) $session->scheduled_start_at, 0, 10);
            $endsOn = substr((string) $session->scheduled_end_at, 0, 10);
            if (LiveClassSessionTime::utc($session->scheduled_start_at, $session->timezone)->gt(now())
                && (! $from || $startsOn >= substr((string) $from, 0, 10))
                && (! $to || $endsOn <= substr((string) $to, 0, 10))) {
                $this->replaceSessionTeacherTeam($customerId, (int) $session->id, [[
                    'teacher_id' => (int) $assignment['teacher_id'],
                    'role' => $assignment['role'] === 'assistant' ? 'assistant' : 'teacher',
                ]], $actorId);
            }
        }
    }

    /**
     * Changes the role and period of an active assignment; the teacher stays, and Session teams are never rewritten.
     * Same-transaction, fail-closed check as removal: an upcoming Session of this teacher must stay inside the period.
     */
    public function updateTeacher(Request $request, int $cohort, int $assignment): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        if ($request->input('role') === 'primary_teacher') {
            $request->merge([
                'assigned_from' => $cohortRow->start_date,
                'assigned_to' => $cohortRow->end_date,
            ]);
        }

        $assignedFromRules = ['nullable', 'date'];
        $assignedToRules = ['nullable', 'date', 'after_or_equal:assigned_from'];
        if ($cohortRow->start_date) {
            $assignedFromRules[] = 'after_or_equal:'.$cohortRow->start_date;
            $assignedToRules[] = 'after_or_equal:'.$cohortRow->start_date;
        }
        if ($cohortRow->end_date) {
            $assignedFromRules[] = 'before_or_equal:'.$cohortRow->end_date;
            $assignedToRules[] = 'before_or_equal:'.$cohortRow->end_date;
        }

        $validated = $request->validateWithBag('teacherEdit', [
            'role' => ['required', Rule::in(['primary_teacher', 'teacher', 'assistant'])],
            'assigned_from' => $assignedFromRules,
            'assigned_to' => $assignedToRules,
        ], [
            'role.required' => __('lf.LF_course_cohort_teacher_validation_role_required'),
            'role.in' => __('lf.LF_course_cohort_teacher_validation_role_invalid'),
            'assigned_from.date' => __('lf.LF_course_cohort_teacher_validation_date_invalid'),
            'assigned_from.after_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
            'assigned_from.before_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
            'assigned_to.date' => __('lf.LF_course_cohort_teacher_validation_date_invalid'),
            'assigned_to.after_or_equal' => __('lf.LF_course_cohort_teacher_validation_end_before_start'),
            'assigned_to.before_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
        ]);

        if ($validated['role'] === 'primary_teacher' && (! $cohortRow->start_date || ! $cohortRow->end_date)) {
            throw ValidationException::withMessages([
                'role' => __('lf.LF_course_cohort_teacher_validation_primary_period_required'),
            ])->errorBag('teacherEdit');
        }

        DB::transaction(function () use ($customerId, $cohort, $assignment, $validated, $cohortRow): void {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohort)->lockForUpdate()->firstOrFail();
            $assignmentRow = DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->where('id', $assignment)->where('status', 'active')->lockForUpdate()->first();
            abort_if(! $assignmentRow, 404);

            $isPrimary = $validated['role'] === 'primary_teacher';
            $from = $isPrimary ? $cohortRow->start_date : ($validated['assigned_from'] ?? null);
            $to = $isPrimary ? $cohortRow->end_date : ($validated['assigned_to'] ?? null);

            if (! $isPrimary) {
                $blocking = $this->upcomingSessionRowsForTeacher($customerId, $cohort, (int) $assignmentRow->teacher_id)
                    // Assignment ranges are plain local dates; the stored Session wall-clock is already local.
                    ->filter(fn (object $session): bool => ($from && substr((string) $session->scheduled_start_at, 0, 10) < substr((string) $from, 0, 10))
                        || ($to && substr((string) $session->scheduled_end_at, 0, 10) > substr((string) $to, 0, 10)))
                    ->map(fn (object $session): string => '#'.$session->session_no.' '.$session->title)
                    ->values()->all();
                if ($blocking !== []) {
                    throw ValidationException::withMessages([
                        'assigned_period' => __('lf.LF_course_cohort_teacher_period_blocked'),
                        'blocked_sessions' => $blocking,
                    ])->errorBag('teacherEdit');
                }
            }

            if ($isPrimary) {
                DB::table('core_course_cohort_teachers')
                    ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                    ->where('role', 'primary_teacher')->where('status', 'active')
                    ->where('id', '!=', $assignment)
                    ->lockForUpdate()
                    ->update(['role' => 'teacher', 'updated_at' => now()]);
            }

            DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)->where('id', $assignment)
                ->update([
                    'role' => $validated['role'],
                    'assigned_from' => $from,
                    'assigned_to' => $to,
                    'updated_at' => now(),
                ]);
        }, 3);

        return $this->tab($cohort, 'teachers')
            ->with('success', __('lf.LF_course_cohort_teacher_saved'));
    }

    public function removeTeacher(Request $request, int $cohort, int $assignment): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->setupCohort($customerId, $cohort);

        DB::transaction(function () use ($customerId, $cohort, $assignment): void {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohort)->lockForUpdate()->firstOrFail();
            $assignmentRow = DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->where('id', $assignment)->lockForUpdate()->first();
            abort_if(! $assignmentRow, 404);

            // Fail-closed per core_course_cohort_teachers.md § Assignment
            // Deactivation Policy: never cascade into session_teachers, and
            // never leave an upcoming Session pointing at an assignment that no
            // longer resolves.
            $blocking = $this->upcomingSessionsForTeacher($customerId, $cohort, (int) $assignmentRow->teacher_id);
            if ($blocking !== []) {
                throw ValidationException::withMessages([
                    'remove_teacher_'.$assignment => __('lf.LF_course_cohort_teacher_remove_blocked'),
                    'remove_teacher_sessions_'.$assignment => $blocking,
                ]);
            }

            DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)->where('id', $assignment)
                ->update(['status' => 'inactive', 'updated_at' => now()]);
        }, 3);

        return $this->tab($cohort, 'teachers')
            ->with('success', __('lf.LF_course_cohort_teacher_removed'));
    }

    /**
     * Sessions that still depend on this Teacher and therefore block removal:
     * not cancelled/no-show, still ahead by normalized instant, and without
     * operational evidence.
     *
     * A Session that already took place — or that already carries Attendance or
     * a Recording — keeps its `core_liveclass_session_teachers` row as
     * historical evidence of who taught it. Such a row is never deleted and
     * never blocks removal, so a Teacher whose only remaining link is the past
     * can always be released.
     *
     * @return list<string>
     */
    private function upcomingSessionsForTeacher(int $customerId, int $cohortId, int $teacherId): array
    {
        return $this->upcomingSessionRowsForTeacher($customerId, $cohortId, $teacherId)
            ->map(fn (object $session): string => '#'.$session->session_no.' '.$session->title)
            ->values()->all();
    }

    private function upcomingSessionRowsForTeacher(int $customerId, int $cohortId, int $teacherId): Collection
    {
        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->join('core_liveclass_session_teachers as assignments', function ($join) use ($customerId, $teacherId): void {
                $join->on('assignments.session_id', '=', 'sessions.id')
                    ->where('assignments.customer_id', '=', $customerId)
                    ->where('assignments.teacher_id', '=', $teacherId);
            })
            ->where('sessions.customer_id', $customerId)
            ->where('sessions.cohort_id', $cohortId)
            ->whereNotIn('sessions.status', ['cancelled', 'no_show'])
            ->orderBy('sessions.session_no')
            ->get([
                'sessions.id', 'sessions.session_no', 'sessions.title', 'sessions.status',
                'sessions.scheduled_start_at', 'sessions.scheduled_end_at', 'sessions.timezone',
            ]);

        if ($sessions->isEmpty()) {
            return $sessions;
        }

        $sessionIds = $sessions->pluck('id');
        $withEvidence = DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id')
            ->merge(DB::table('core_liveclass_recordings')
                ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id'))
            ->map(fn ($id): int => (int) $id)->unique();
        $now = now();

        // A live Session always blocks, even with check-ins or past its planned end: the teacher is teaching it now.
        return $sessions
            ->filter(fn (object $session): bool => $session->status === 'live' || (
                ! $withEvidence->contains((int) $session->id)
                && LiveClassSessionTime::utc($session->scheduled_end_at, $session->timezone)->gt($now)
            ))
            ->values();
    }

    /**
     * A programme session is edited on its lesson row: saving it is an edit of the lesson
     * for the admin, even when its date from the Schedule creates the Session only now.
     */
    private function sessionSavedMessage(Request $request, string $default): string
    {
        if (! $request->filled('opened_version_activity_id')) {
            return __($default);
        }

        return __('lf.LF_course_cohort_session_saved_lesson');
    }

    public function storeSession(Request $request, int $cohort): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        $this->assertRunsInClass($customerId, $cohortRow);
        abort_if(! $cohortRow->version_id, 422);

        if ($request->has('teacher_ids')) {
            $request->merge(['teacher_ids' => $this->deduplicateTeacherIds($request->input('teacher_ids'))]);
        }

        $origin = null;
        $movedInterval = null;
        if ($request->filled(['schedule_id', 'schedule_slot_id', 'source_local_date'])) {
            $origin = $this->sessionOriginService->resolve(
                $customerId, $cohort, $request->integer('schedule_id'),
                $request->integer('schedule_slot_id'), (string) $request->input('source_local_date')
            );
            // The admin changed the proposed date while creating: the occurrence is still used, and the move is audited.
            if ($request->boolean('apply_schedule')) {
                $movedInterval = $this->requestedInterval($request);
            }
            $request->merge([
                'scheduled_start_at' => $origin['scheduled_start_at'],
                'scheduled_end_at' => $origin['scheduled_end_at'],
            ]);
        }

        $validated = $request->validate($this->sessionRules($customerId, (int) $cohortRow->version_id));
        // A date opened on the programme takes its name from the Template; its link may be the session's own (empty falls back to the Template's).
        if ($request->filled('opened_version_activity_id') && $validated['session_type'] === 'curriculum') {
            $validated['title'] = (string) DB::table('core_course_template_version_lessons')->where('customer_id', $customerId)
                ->where('template_version_id', $cohortRow->version_id)->where('id', $validated['version_lesson_id'])->value('title_snapshot');
        }
        $validated = $this->canonicalSessionBinding(
            $customerId,
            (int) $cohortRow->version_id,
            $validated,
            $cohortRow->default_meeting_url ?? null
        );
        $timezone = $origin['timezone'] ?? config('app.timezone');
        if ($movedInterval) {
            $validated = array_merge($validated, $movedInterval);
        }
        $this->validateSessionScheduleWindow($cohortRow, $validated, $timezone);
        $this->validateSessionDoesNotOverlap($customerId, $cohort, $validated, $timezone);
        if (! $origin || $movedInterval) {
            $this->validateDateIsNotOnProgramme($customerId, $cohortRow, $validated, $timezone, $origin ? $request->integer('schedule_slot_id').'|'.$request->input('source_local_date') : null);
        }
        $sessionTeachers = $this->canonicalSessionTeacherTeam($customerId, $cohort, $validated);

        DB::transaction(function () use ($customerId, $cohort, $validated, $sessionTeachers, $request, $origin, $timezone, $movedInterval): void {
            $cohortRow = $this->lockSetupCohort($customerId, $cohort);
            if ($origin) {
                $origin = $this->sessionOriginService->resolve(
                    $customerId, $cohort, (int) $origin['schedule_id'],
                    (int) $origin['schedule_slot_id'], $origin['source_local_date'], true
                );
                // A date opened from the programme before a drag may since belong to another Activity (ADR-0023 §8).
                if ($request->filled('opened_version_activity_id')) {
                    $proposal = $this->contentTree->proposals(
                        $customerId, $cohort, (int) $cohortRow->version_id,
                        $this->sessionReadModel->forCohort($customerId, $cohort),
                        $this->sessionReadModel->plannedOccurrences($customerId, $cohort)
                    )[$request->integer('opened_version_activity_id')] ?? null;
                    if (($proposal['occurrence_key'] ?? null) !== implode('|', [$origin['schedule_id'], $origin['schedule_slot_id'], $origin['source_local_date']])) {
                        throw ValidationException::withMessages([
                            'opened_version_activity_id' => __('lf.LF_course_cohort_order_form_stale'),
                        ]);
                    }
                }
                if (! $movedInterval) {
                    $validated['scheduled_start_at'] = $origin['scheduled_start_at'];
                    $validated['scheduled_end_at'] = $origin['scheduled_end_at'];
                }
            }
            $this->validateSessionScheduleWindow($cohortRow, $validated, $timezone);
            $this->validateSessionDoesNotOverlap($customerId, $cohort, $validated, $timezone);
            $sessionNo = (int) DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->lockForUpdate()->max('session_no') + 1;

            $sessionId = DB::table('core_liveclass_sessions')->insertGetId(array_merge([
                'customer_id' => $customerId,
                'cohort_id' => $cohort,
                'template_version_id' => $cohortRow->version_id,
                'room_id' => null,
                'session_no' => $sessionNo,
                // Same value the overlap/window checks above were computed
                // with, so the stored row can never disagree with them.
                'timezone' => $timezone,
                'status' => 'scheduled',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ], $this->sessionPayload($validated)));

            $this->replaceSessionTeacherTeam($customerId, $sessionId, $sessionTeachers, (int) $request->user()->id);

            if ($origin) {
                DB::table('core_liveclass_session_schedule_origins')->insert([
                    'customer_id' => $customerId,
                    'session_id' => $sessionId,
                    'schedule_id' => $origin['schedule_id'],
                    'schedule_slot_id' => $origin['schedule_slot_id'],
                    'source_local_date' => $origin['source_local_date'],
                    'source_local_start_time' => $origin['source_local_start_time'],
                    'source_local_end_time' => $origin['source_local_end_time'],
                    'source_timezone' => $origin['source_timezone'],
                    'source_start_at' => $origin['source_start_at'],
                    'source_end_at' => $origin['source_end_at'],
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                ]);
                if ($movedInterval) {
                    $this->recordScheduleChange(
                        $customerId, $sessionId, $origin['scheduled_start_at'], $origin['scheduled_end_at'],
                        $movedInterval, $request->input('reason'), (int) $request->user()->id
                    );
                }
            }
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', $this->sessionSavedMessage($request, 'lf.LF_course_cohort_session_created'));
    }

    public function updateSession(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        abort_if(! $cohortRow->version_id, 422);

        if ($request->has('teacher_ids')) {
            $request->merge(['teacher_ids' => $this->deduplicateTeacherIds($request->input('teacher_ids'))]);
        }

        $validated = $request->validate($this->sessionRules($customerId, (int) $cohortRow->version_id));
        $requestedInterval = $request->boolean('apply_schedule') ? $this->requestedInterval($request) : null;

        DB::transaction(function () use ($customerId, $cohort, $session, $validated, $request, $requestedInterval): void {
            $cohortRow = $this->lockSetupCohort($customerId, $cohort);
            $sessionRow = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)
                ->where('cohort_id', $cohort)
                ->where('id', $session)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $this->sessionPolicy->canEdit(
                    $sessionRow,
                    $this->sessionHasEvidence($customerId, $session),
                    now()
                ),
                422,
                __('lf.LF_course_cohort_session_edit_locked')
            );
            // A form opened before a drag must not undo it (ADR-0023 §8).
            if ($request->filled('opened_version_activity_id')
                && $request->integer('opened_version_activity_id') !== (int) $sessionRow->version_activity_id) {
                throw ValidationException::withMessages([
                    'opened_version_activity_id' => __('lf.LF_course_cohort_order_form_stale'),
                ]);
            }

            // What comes from the Template is not edited on the class. The join link belongs to the Session:
            // a request without one keeps it, an empty one falls back to the Template's in canonicalSessionBinding().
            if ($sessionRow->session_type === 'curriculum') {
                $validated = array_merge($validated, [
                    'session_type' => 'curriculum',
                    'title' => $sessionRow->title,
                    'version_lesson_id' => $sessionRow->version_lesson_id,
                    'version_activity_id' => $sessionRow->version_activity_id,
                    'meeting_url' => array_key_exists('meeting_url', $validated) ? $validated['meeting_url'] : $sessionRow->meeting_url_snapshot,
                ]);
            }

            // A new date and time counts only when the form says it moved the
            // Session, and is then audited like any reschedule; otherwise the
            // stored interval stands whatever was submitted.
            $moved = $requestedInterval !== null && ! (
                $this->sameMinute($requestedInterval['scheduled_start_at'], $sessionRow->scheduled_start_at)
                && $this->sameMinute($requestedInterval['scheduled_end_at'], $sessionRow->scheduled_end_at)
            );
            $validated['scheduled_start_at'] = $moved ? $requestedInterval['scheduled_start_at'] : $sessionRow->scheduled_start_at;
            $validated['scheduled_end_at'] = $moved ? $requestedInterval['scheduled_end_at'] : $sessionRow->scheduled_end_at;
            if ($moved) {
                $this->validateSessionDoesNotOverlap($customerId, $cohort, $validated, $sessionRow->timezone, $session);
                $this->validateDateIsNotOnProgramme($customerId, $cohortRow, $validated, $sessionRow->timezone);
                $this->recordScheduleChange(
                    $customerId, $session, $sessionRow->scheduled_start_at, $sessionRow->scheduled_end_at,
                    $requestedInterval, $request->input('reason'), (int) $request->user()->id
                );
            }
            $validated = $this->canonicalSessionBinding(
                $customerId,
                (int) $cohortRow->version_id,
                $validated,
                $cohortRow->default_meeting_url ?? null
            );
            $this->validateSessionScheduleWindow($cohortRow, $validated, $sessionRow->timezone);
            $sessionTeachers = $this->canonicalSessionTeacherTeam($customerId, $cohort, $validated);

            DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)
                ->where('id', $session)
                ->update(array_merge($this->sessionPayload($validated), [
                    'updated_at' => now(),
                ]));

            $this->replaceSessionTeacherTeam($customerId, $session, $sessionTeachers, (int) $request->user()->id);
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', $this->sessionSavedMessage($request, 'lf.LF_course_cohort_session_updated'));
    }

    public function updateSchedule(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->setupCohort($customerId, $cohort);
        $validated = $request->validate([
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
            'reason' => ['nullable', 'string', 'max:1000'],
            // Reschedule moves the Session interval only. Re-pointing a Session
            // at a different Schedule occurrence is occurrence reuse, which
            // ADR-0002 § Session Status Vocabulary And Replacement Scope
            // Clarification leaves unauthorized — an Origin is immutable once
            // written. Rejecting these outright keeps the contract honest
            // instead of accepting and silently discarding them.
            'schedule_id' => ['prohibited'],
            'schedule_slot_id' => ['prohibited'],
            'source_local_date' => ['prohibited'],
        ]);
        DB::transaction(function () use ($customerId, $cohort, $session, $validated, $request): void {
            $cohortRow = $this->lockSetupCohort($customerId, $cohort);
            $sessionRow = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->where('id', $session)->lockForUpdate()->firstOrFail();
            abort_unless($this->sessionPolicy->canReschedule($sessionRow, now()), 422,
                __('lf.LF_course_cohort_session_reschedule_status_invalid'));
            if ($this->sameMinute($validated['scheduled_start_at'], $sessionRow->scheduled_start_at)
                && $this->sameMinute($validated['scheduled_end_at'], $sessionRow->scheduled_end_at)) {
                throw ValidationException::withMessages([
                    'scheduled_start_at' => __('lf.LF_course_cohort_session_reschedule_unchanged'),
                ]);
            }
            // Reschedule keeps the Session's recorded timezone: the admin enters
            // the new interval in the same frame the Session already uses.
            $this->validateSessionScheduleWindow($cohortRow, $validated, $sessionRow->timezone);
            $this->validateSessionDoesNotOverlap(
                $customerId, $cohort, $validated, $sessionRow->timezone, $session
            );
            $this->validateDateIsNotOnProgramme($customerId, $cohortRow, $validated, $sessionRow->timezone);

            $teacherIds = DB::table('core_liveclass_session_teachers')
                ->where('customer_id', $customerId)
                ->where('session_id', $session)
                ->pluck('teacher_id')->all();
            if ($sessionRow->primary_teacher_id && ! in_array((int) $sessionRow->primary_teacher_id, array_map('intval', $teacherIds), true)) {
                $teacherIds[] = (int) $sessionRow->primary_teacher_id;
            }
            $this->canonicalSessionTeacherTeam($customerId, $cohort, [
                'teacher_ids' => $teacherIds,
                'scheduled_start_at' => $validated['scheduled_start_at'],
                'scheduled_end_at' => $validated['scheduled_end_at'],
            ], 'scheduled_start_at');

            $this->recordScheduleChange(
                $customerId, $session, $sessionRow->scheduled_start_at, $sessionRow->scheduled_end_at,
                $validated, $validated['reason'] ?? null, (int) $request->user()->id
            );
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_rescheduled'));
    }

    /** Saves one group of the class's Lessons: the direct ones, or one Section's. */
    public function reorderLessons(Request $request, int $cohort): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->reorderableCohort($customerId, $cohort);
        $validated = $request->validate([
            'version_section_id' => ['nullable', 'integer'],
            'lesson_ids' => ['required', 'array', 'min:1'],
            'lesson_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $this->contentReorder->reorderLessons(
            $customerId,
            $cohortRow,
            isset($validated['version_section_id']) ? (int) $validated['version_section_id'] : null,
            array_map('intval', array_values($validated['lesson_ids'])),
            (int) $request->user()->id
        );
        $request->session()->flash('success', __('lf.LF_course_template_lesson_reorder_saved'));

        return response()->json(['message' => __('lf.LF_course_template_lesson_reorder_saved')]);
    }

    /** Saves the order of one Lesson's Activities in the class. */
    public function reorderActivities(Request $request, int $cohort, int $lesson): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->reorderableCohort($customerId, $cohort);
        $validated = $request->validate([
            'activity_ids' => ['required', 'array', 'min:1'],
            'activity_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $this->contentReorder->reorderActivities(
            $customerId,
            $cohortRow,
            $lesson,
            array_map('intval', array_values($validated['activity_ids'])),
            (int) $request->user()->id
        );
        $request->session()->flash('success', __('lf.LF_course_template_activity_reorder_saved'));

        return response()->json(['message' => __('lf.LF_course_template_activity_reorder_saved')]);
    }

    private function reorderableCohort(int $customerId, int $cohort): object
    {
        $cohortRow = $this->setupCohort($customerId, $cohort);
        $this->assertRunsInClass($customerId, $cohortRow);
        abort_if(! $cohortRow->version_id, 422);

        return $cohortRow;
    }

    /**
     * The date and time typed in the Session form, checked like a reschedule.
     *
     * @return array{scheduled_start_at: string, scheduled_end_at: string}
     */
    private function requestedInterval(Request $request): array
    {
        $interval = $request->validate([
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return ['scheduled_start_at' => $interval['scheduled_start_at'], 'scheduled_end_at' => $interval['scheduled_end_at']];
    }

    private function sameMinute(string $first, string $second): bool
    {
        return Carbon::parse($first)->format('Y-m-d H:i') === Carbon::parse($second)->format('Y-m-d H:i');
    }

    /**
     * Moves the Session and appends its audit row. Schedule, Slot and Origin never change.
     *
     * @param  array{scheduled_start_at: string, scheduled_end_at: string}  $interval
     */
    private function recordScheduleChange(
        int $customerId,
        int $sessionId,
        string $previousStart,
        string $previousEnd,
        array $interval,
        ?string $reason,
        int $actorId
    ): void {
        DB::table('core_liveclass_session_schedule_changes')->insert([
            'customer_id' => $customerId,
            'session_id' => $sessionId,
            'previous_start_at' => $previousStart,
            'previous_end_at' => $previousEnd,
            'new_start_at' => $interval['scheduled_start_at'],
            'new_end_at' => $interval['scheduled_end_at'],
            'reason' => $reason,
            'changed_by' => $actorId,
            'created_at' => now(),
        ]);
        DB::table('core_liveclass_sessions')->where('customer_id', $customerId)
            ->where('id', $sessionId)->update([
                'scheduled_start_at' => $interval['scheduled_start_at'],
                'scheduled_end_at' => $interval['scheduled_end_at'],
                'status' => 'scheduled',
                'updated_at' => now(),
            ]);
    }

    public function assignSessionTeachers(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->setupCohort($customerId, $cohort);

        if ($request->has('teacher_ids')) {
            $request->merge(['teacher_ids' => $this->deduplicateTeacherIds($request->input('teacher_ids'))]);
        }

        $validated = $request->validate([
            'teacher_ids' => ['required', 'array', 'min:1'],
            'teacher_ids.*' => ['integer', Rule::exists('users', 'id')
                ->where(fn ($query) => $query->where('customer_id', $customerId)->where('role', 'teacher')->where('status', 'active'))],
        ]);

        DB::transaction(function () use ($customerId, $cohort, $session, $validated, $request): void {
            $cohortRow = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohort)->lockForUpdate()->firstOrFail();
            $sessionRow = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->where('id', $session)->lockForUpdate()->firstOrFail();
            $hasTeacher = $this->sessionHasAssignedTeacher($customerId, $sessionRow);
            abort_unless(
                $this->sessionPolicy->canAssignLateTeachers(
                    $sessionRow,
                    $cohortRow->status,
                    $hasTeacher,
                    $this->sessionHasEvidence($customerId, $session),
                    now()
                ),
                422,
                __('lf.LF_course_cohort_session_assign_teachers_locked')
            );

            $team = $this->canonicalSessionTeacherTeam($customerId, $cohort, [
                'teacher_ids' => $validated['teacher_ids'],
                'scheduled_start_at' => $sessionRow->scheduled_start_at,
                'scheduled_end_at' => $sessionRow->scheduled_end_at,
            ]);
            $this->replaceSessionTeacherTeam($customerId, $session, $team, (int) $request->user()->id);
            DB::table('core_liveclass_sessions')->where('customer_id', $customerId)
                ->where('id', $session)->update(['updated_at' => now()]);
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_assign_teachers_saved'));
    }

    public function startSession(Request $request, int $cohort, int $session): RedirectResponse
    {
        $this->sessionLifecycleService->start($this->authorizeAdmin($request), $cohort, $session);

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_lifecycle_started'));
    }

    public function completeSession(Request $request, int $cohort, int $session): RedirectResponse
    {
        $this->sessionLifecycleService->complete($this->authorizeAdmin($request), $cohort, $session);

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_lifecycle_completed'));
    }

    public function cancelSession(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        // ADR-0023 §9: cancelling a programme session moves its lesson and the later ones one position down, in one transaction.
        $shifted = DB::transaction(function () use ($customerId, $cohort, $session, $validated, $request): bool {
            $this->sessionLifecycleService->cancel($customerId, $cohort, $session, $validated['reason'] ?? null);

            return $this->contentReorder->shiftAfterCancel($customerId, $this->cohort($customerId, $cohort), $session, (int) $request->user()->id);
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __($shifted ? 'lf.LF_course_cohort_session_lifecycle_cancelled_shifted' : 'lf.LF_course_cohort_session_lifecycle_cancelled'));
    }

    public function markSessionNoShow(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->sessionLifecycleService->markNoShow($customerId, $cohort, $session, $validated['reason'] ?? null);

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_lifecycle_no_show'));
    }

    public function saveAttendance(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        abort_unless(config('liveclass.attendance_enabled'), 403,
            __('lf.LF_course_cohort_attendance_feature_disabled'));
        $this->activeCohort($customerId, $cohort);
        $sessionRow = DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('id', $session)->firstOrFail();
        abort_unless($this->sessionHasAssignedTeacher($customerId, $sessionRow), 422,
            __('lf.LF_course_cohort_session_runtime_teacher_required'));
        abort_unless($this->sessionPolicy->canRecordAttendance($sessionRow, now()), 422,
            __('lf.LF_course_cohort_attendance_session_invalid'));
        $validated = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.enrollment_id' => ['required', 'integer'],
            'attendance.*.status' => ['required', Rule::in(['registered', 'present', 'late', 'absent', 'excused'])],
            'attendance.*.attendance_mode' => ['nullable', Rule::in(['online', 'offline'])],
            'attendance.*.notes' => ['nullable', 'string', 'max:1000'],
            // What the row showed when the page was loaded: a row the admin did not touch is left alone,
            // so a QR scan made after the page opened is never overwritten by a stale form.
            'attendance.*.loaded_status' => ['nullable', Rule::in(['registered', 'present', 'late', 'absent', 'excused'])],
            'attendance.*.loaded_mode' => ['nullable', Rule::in(['online', 'offline'])],
            'attendance.*.loaded_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $skipped = 0;

        $memberships = DB::table('core_course_cohort_students as memberships')
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')->where('enrollments.customer_id', '=', $customerId);
            })
            ->where('memberships.customer_id', $customerId)->where('memberships.cohort_id', $cohort)
            ->where('memberships.status', 'active')
            ->get(['memberships.student_id', 'memberships.enrollment_id', 'enrollments.status as enrollment_status'])
            ->keyBy('enrollment_id');

        DB::transaction(function () use ($validated, $memberships, $customerId, $session, $sessionRow, $request, &$skipped): void {
            $existing = DB::table('core_liveclass_attendances')
                ->where('customer_id', $customerId)->where('session_id', $session)->lockForUpdate()
                ->get(['id', 'enrollment_id', 'status', 'attendance_mode', 'notes'])->keyBy('enrollment_id');
            foreach ($validated['attendance'] as $item) {
                abort_unless($memberships->has($item['enrollment_id']), 422);
                $values = [
                    'status' => $item['status'],
                    'attendance_mode' => $item['attendance_mode'] ?? null,
                    'notes' => ($item['notes'] ?? '') === '' ? null : $item['notes'],
                ];
                if (array_key_exists('loaded_status', $item)
                    && $values['status'] === ($item['loaded_status'] ?? 'registered')
                    && $values['attendance_mode'] === ($item['loaded_mode'] ?? null)
                    && $values['notes'] === (($item['loaded_notes'] ?? '') === '' ? null : $item['loaded_notes'])) {
                    continue;
                }
                // Only an active Enrollment is recorded (DOC-CONFLICT-0011); the others are left as they are.
                if ($memberships->get($item['enrollment_id'])->enrollment_status !== 'active') {
                    $skipped++;

                    continue;
                }
                $row = $existing->get($item['enrollment_id']);
                // An unchanged row keeps its source, mode and check-in time (a QR scan stays a scan).
                if ($row ? ((string) $row->status === $values['status'] && $row->attendance_mode === $values['attendance_mode']
                        && (($row->notes ?? '') === '' ? null : $row->notes) === $values['notes'])
                    : ($values['status'] === 'registered' && $values['attendance_mode'] === null && $values['notes'] === null)) {
                    continue;
                }
                $values += [
                    'user_id' => $memberships->get($item['enrollment_id'])->student_id,
                    'version_activity_id' => $sessionRow->version_activity_id,
                    'attendance_source' => 'manual',
                    'recorded_by' => $request->user()->id,
                    'updated_at' => now(),
                ];
                if ($row) {
                    DB::table('core_liveclass_attendances')->where('id', $row->id)->update($values);
                } else {
                    DB::table('core_liveclass_attendances')->insert($values + [
                        'customer_id' => $customerId,
                        'session_id' => $session,
                        'enrollment_id' => $item['enrollment_id'],
                        'created_at' => now(),
                    ]);
                }
            }
        });

        $message = __('lf.LF_course_cohort_attendance_saved');
        if ($skipped > 0) {
            $message .= ' '.__('lf.LF_course_cohort_attendance_skipped_inactive', ['count' => $skipped]);
        }

        return $this->tab($cohort, 'attendance', ['session_id' => $session])->with('success', $message);
    }

    public function storeRecording(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        abort_unless(config('liveclass.recording_enabled'), 403,
            __('lf.LF_course_cohort_recording_feature_disabled'));
        $this->activeCohort($customerId, $cohort);
        $sessionRow = DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('id', $session)->firstOrFail();
        abort_unless($this->sessionHasAssignedTeacher($customerId, $sessionRow), 422,
            __('lf.LF_course_cohort_session_runtime_teacher_required'));
        abort_unless($this->sessionPolicy->canCreateRecording($sessionRow, now()), 422,
            __('lf.LF_course_cohort_recording_session_invalid'));
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'recording_url' => ['required', 'url', 'max:2000'],
            'replay_available_from' => ['nullable', 'date'],
            'replay_available_until' => ['nullable', 'date', 'after:replay_available_from'],
        ]);

        DB::table('core_liveclass_recordings')->insert([
            'customer_id' => $customerId,
            'session_id' => $session,
            'title' => trim($validated['title']),
            'recording_url' => $validated['recording_url'] ?? null,
            'replay_available_from' => $validated['replay_available_from'] ?? null,
            'replay_available_until' => $validated['replay_available_until'] ?? null,
            'visibility' => 'cohort',
            'status' => 'processing',
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->tab($cohort, 'recordings')
            ->with('success', __('lf.LF_course_cohort_recording_created'));
    }

    private function sessionRules(int $customerId, int $versionId): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'session_type' => ['required', Rule::in(['curriculum', 'operational'])],
            'version_lesson_id' => ['nullable', 'required_if:session_type,curriculum', 'integer', Rule::exists('core_course_template_version_lessons', 'id')
                ->where(fn ($query) => $query->where('customer_id', $customerId)->where('template_version_id', $versionId))],
            'version_activity_id' => ['nullable', 'required_if:session_type,curriculum', 'integer'],
            'teacher_ids' => ['nullable', 'array'],
            'teacher_ids.*' => ['integer', Rule::exists('users', 'id')
                ->where(fn ($query) => $query->where('customer_id', $customerId)->where('role', 'teacher')->where('status', 'active'))],
            'delivery_mode' => ['required', Rule::in(['online', 'offline', 'hybrid'])],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
            'online_provider' => ['nullable', 'string', 'max:50'],
            'meeting_url' => ['nullable', 'url', 'max:2000'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'room_name' => ['nullable', 'string', 'max:255', 'required_if:delivery_mode,offline,hybrid'],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** An empty join link takes the class's default, then the Template's (core_course_cohorts § Default Meeting Link). */
    private function canonicalSessionBinding(int $customerId, int $versionId, array $validated, ?string $cohortMeetingUrl = null): array
    {
        $cohortMeetingUrl = filled($cohortMeetingUrl) ? trim($cohortMeetingUrl) : null;
        if ($validated['session_type'] === 'operational') {
            $validated['version_lesson_id'] = null;
            $validated['version_activity_id'] = null;
            if (! filled($validated['meeting_url'] ?? null) && $cohortMeetingUrl) {
                $validated['meeting_url'] = $cohortMeetingUrl;
                $validated['online_provider'] = filled($validated['online_provider'] ?? null)
                    ? $validated['online_provider'] : LiveClassMeetingProvider::fromUrl($cohortMeetingUrl);
            }

            return $validated;
        }

        $lessonId = (int) $validated['version_lesson_id'];
        $activityId = (int) $validated['version_activity_id'];
        abort_unless(DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->where('id', $lessonId)
            ->exists(), 422);
        $activity = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->where('version_lesson_id', $lessonId)
            ->where('activity_type', 'live_class')
            ->where('id', $activityId)
            ->first(['id', 'live_class_url_snapshot']);
        abort_unless($activity, 422);

        $validated['version_lesson_id'] = $lessonId;
        $validated['version_activity_id'] = $activityId;
        // The Session owns its join URL; the class's link, then the Activity's, are only defaults.
        $meetingUrl = filled($validated['meeting_url'] ?? null)
            ? trim($validated['meeting_url'])
            : ($cohortMeetingUrl ?? $activity->live_class_url_snapshot);
        $validated['meeting_url'] = $meetingUrl;
        $validated['online_provider'] = LiveClassMeetingProvider::fromUrl($meetingUrl);

        return $validated;
    }

    private function sessionPayload(array $validated): array
    {
        $online = in_array($validated['delivery_mode'], ['online', 'hybrid'], true);
        $offline = in_array($validated['delivery_mode'], ['offline', 'hybrid'], true);

        return [
            'session_type' => $validated['session_type'],
            'version_lesson_id' => $validated['version_lesson_id'],
            'version_activity_id' => $validated['version_activity_id'],
            'primary_teacher_id' => null,
            'title' => trim($validated['title']),
            'delivery_mode' => $validated['delivery_mode'],
            'scheduled_start_at' => $validated['scheduled_start_at'],
            'scheduled_end_at' => $validated['scheduled_end_at'],
            'online_provider' => $online ? ($validated['online_provider'] ?? null) : null,
            'meeting_url_snapshot' => $online ? ($validated['meeting_url'] ?? null) : null,
            'facility_name_snapshot' => $offline ? ($validated['facility_name'] ?? null) : null,
            'room_name_snapshot' => $offline ? ($validated['room_name'] ?? null) : null,
            'address_snapshot' => $offline ? ($validated['address'] ?? null) : null,
        ];
    }

    private function canonicalSessionTeacherTeam(
        int $customerId,
        int $cohortId,
        array $validated,
        string $scheduleErrorField = 'teacher_ids'
    ): array {
        $teacherIds = collect($validated['teacher_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)->uniqueStrict()->values();
        if ($teacherIds->isEmpty()) {
            return [];
        }

        $assignments = DB::table('core_course_cohort_teachers')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->whereIn('teacher_id', $teacherIds)
            ->where('status', 'active')
            ->get(['teacher_id', 'role', 'assigned_from', 'assigned_to'])
            ->keyBy(fn ($assignment) => (int) $assignment->teacher_id);
        // Deliberately NOT normalized to UTC. `core_course_cohort_teachers`
        // stores plain local DATE ranges, and the availability rule compares the
        // Session's local calendar date — which the stored wall-clock string
        // already is. Converting here would shift the date across midnight.
        $startsOn = Carbon::parse($validated['scheduled_start_at'])->startOfDay();
        $endsOn = Carbon::parse($validated['scheduled_end_at'])->startOfDay();
        $team = [];

        foreach ($teacherIds as $teacherId) {
            $assignment = $assignments->get($teacherId);
            $available = $assignment
                && (! $assignment->assigned_from || $startsOn->gte(Carbon::parse($assignment->assigned_from)->startOfDay()))
                && (! $assignment->assigned_to || $endsOn->lte(Carbon::parse($assignment->assigned_to)->startOfDay()));
            if (! $available) {
                throw ValidationException::withMessages([
                    $scheduleErrorField => __('lf.LF_course_cohort_session_teacher_unavailable'),
                ]);
            }

            $team[] = [
                'teacher_id' => $teacherId,
                'role' => $assignment->role === 'assistant' ? 'assistant' : 'teacher',
            ];
        }

        return $team;
    }

    private function deduplicateTeacherIds(mixed $teacherIds): mixed
    {
        if (! is_array($teacherIds)) {
            return $teacherIds;
        }

        return collect($teacherIds)
            ->unique(fn ($teacherId) => is_scalar($teacherId) ? (string) $teacherId : serialize($teacherId))
            ->values()
            ->all();
    }

    private function validateSessionScheduleWindow(object $cohort, array $validated, string $timezone): void
    {
        // Cohort has no timezone column: its operating period is a local
        // calendar range, and the only frame that makes the boundary meaningful
        // is the Session's own timezone. Anchoring the window there keeps every
        // value in one frame instead of mixing it with the server default.
        $window = CourseCohortSessionWindow::for($cohort, $timezone);

        if ($window === null) {
            throw ValidationException::withMessages([
                'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_cohort_period_required'),
            ]);
        }

        $sessionStart = LiveClassSessionTime::utc($validated['scheduled_start_at'], $timezone);
        $sessionEnd = LiveClassSessionTime::utc($validated['scheduled_end_at'], $timezone);

        if ($sessionStart->lt($window['min'])) {
            throw ValidationException::withMessages([
                'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_before_minimum'),
            ]);
        }
        if ($sessionEnd->gt($window['max'])) {
            throw ValidationException::withMessages([
                'scheduled_end_at' => __('lf.LF_course_cohort_session_schedule_after_cohort'),
            ]);
        }
    }

    /**
     * The Sessions tab shows a Schedule date on each lesson without a Session. Moving another Session
     * onto such a date is refused like an overlap, or two lessons would end up at the same time.
     *
     * @param  array{scheduled_start_at: string, scheduled_end_at: string}  $validated
     * @param  string|null  $ownOccurrence  "slot|date" of the occurrence this Session is being created from
     */
    private function validateDateIsNotOnProgramme(int $customerId, object $cohort, array $validated, string $timezone, ?string $ownOccurrence = null): void
    {
        $proposals = $this->contentTree->proposals(
            $customerId,
            (int) $cohort->id,
            $cohort->version_id ? (int) $cohort->version_id : null,
            $this->sessionReadModel->forCohort($customerId, (int) $cohort->id),
            $this->sessionReadModel->plannedOccurrences($customerId, (int) $cohort->id)
        );
        $start = LiveClassSessionTime::utc($validated['scheduled_start_at'], $timezone);
        $end = LiveClassSessionTime::utc($validated['scheduled_end_at'], $timezone);

        foreach ($proposals as $proposal) {
            $occurrence = $proposal['occurrence'];
            if (! $occurrence || $ownOccurrence === $occurrence['schedule_slot_id'].'|'.$occurrence['date']) {
                continue;
            }
            if (LiveClassSessionTime::overlaps(
                LiveClassSessionTime::utc($occurrence['date'].' '.$occurrence['start_time'], $occurrence['timezone']),
                LiveClassSessionTime::utc($occurrence['date'].' '.$occurrence['end_time'], $occurrence['timezone']),
                $start,
                $end
            )) {
                throw ValidationException::withMessages([
                    'scheduled_start_at' => __('lf.LF_course_cohort_session_date_on_programme', ['lesson' => $proposal['lesson_title']]),
                ]);
            }
        }
    }

    private function validateSessionDoesNotOverlap(
        int $customerId,
        int $cohortId,
        array $validated,
        string $timezone,
        ?int $excludeSessionId = null,
        string $errorField = 'scheduled_start_at'
    ): void {
        if (LiveClassSessionOverlap::exists(
            $customerId,
            $cohortId,
            LiveClassSessionTime::utc($validated['scheduled_start_at'], $timezone),
            LiveClassSessionTime::utc($validated['scheduled_end_at'], $timezone),
            $excludeSessionId
        )) {
            throw ValidationException::withMessages([
                $errorField => __('lf.LF_course_cohort_session_schedule_overlap'),
            ]);
        }
    }

    private function replaceSessionTeacherTeam(
        int $customerId,
        int $sessionId,
        array $team,
        int $actorId
    ): void {
        DB::table('core_liveclass_session_teachers')
            ->where('customer_id', $customerId)
            ->where('session_id', $sessionId)
            ->delete();

        if ($team === []) {
            return;
        }

        $now = now();
        DB::table('core_liveclass_session_teachers')->insert(array_map(
            fn (array $assignment): array => array_merge($assignment, [
                'customer_id' => $customerId,
                'session_id' => $sessionId,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $team
        ));
    }

    private function sessionHasEvidence(int $customerId, int $sessionId): bool
    {
        return DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)->where('session_id', $sessionId)->exists()
            || DB::table('core_liveclass_recordings')
                ->where('customer_id', $customerId)->where('session_id', $sessionId)->exists();
    }

    private function sessionHasAssignedTeacher(int $customerId, object $session): bool
    {
        return (bool) $session->primary_teacher_id
            || LiveClassAttendanceCheckIn::sessionHasTeacher($customerId, (int) $session->id);
    }

    private function cohort(int $customerId, int $id): object
    {
        return DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)->where('id', $id)->firstOrFail();
    }

    private function setupCohort(int $customerId, int $id): object
    {
        $cohort = $this->cohort($customerId, $id);
        abort_unless(CourseCohortMutationPolicy::canMutate($cohort), 422,
            __('lf.LF_course_cohort_setup_status_invalid'));

        return $cohort;
    }

    /**
     * The Cohort read again under its row lock: the period and status judged by a Session
     * write are the ones that stand when it is stored, not those read before the lock.
     */
    private function lockSetupCohort(int $customerId, int $id): object
    {
        $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)
            ->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless(CourseCohortMutationPolicy::canMutate($cohort), 422,
            __('lf.LF_course_cohort_setup_status_invalid'));

        return $cohort;
    }

    /** ADR-0022: a legacy class bound to a self-paced course gets no new session until it is fixed. */
    private function assertRunsInClass(int $customerId, object $cohort): void
    {
        abort_unless(app(CourseCohortVersionResolver::class)->runsInClass($customerId, $cohort->version_id ? (int) $cohort->version_id : null), 422,
            __('lf.LF_course_type_cohort_legacy_self_paced'));
    }

    private function activeCohort(int $customerId, int $id): object
    {
        $cohort = $this->cohort($customerId, $id);
        abort_unless($cohort->status === 'active', 422,
            __('lf.LF_course_cohort_runtime_requires_active'));

        return $cohort;
    }

    private function tab(int $cohort, string $tab, array $query = []): RedirectResponse
    {
        return redirect()->route('admin.course-cohorts.show', array_merge([
            'id' => $cohort,
            'tab' => $tab,
        ], $query));
    }
}
