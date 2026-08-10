<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\CourseCohortMutationPolicy;
use App\Services\CourseCohortSessionWindow;
use App\Services\LiveClassSessionLifecycleService;
use App\Services\LiveClassSessionOriginService;
use App\Services\LiveClassSessionPolicy;
use App\Services\LiveClassSessionTime;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseCohortOperationController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    public function __construct(
        private readonly LiveClassSessionPolicy $sessionPolicy,
        private readonly LiveClassSessionOriginService $sessionOriginService,
        private readonly LiveClassSessionLifecycleService $sessionLifecycleService
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
        });

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
                    'teacher_id' => __('lf.LF_course_cohort_teacher_remove_blocked', [
                        'sessions' => implode(', ', $blocking),
                    ]),
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
                'sessions.id', 'sessions.session_no', 'sessions.title',
                'sessions.scheduled_end_at', 'sessions.timezone',
            ]);

        if ($sessions->isEmpty()) {
            return [];
        }

        $sessionIds = $sessions->pluck('id');
        $withEvidence = DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id')
            ->merge(DB::table('core_liveclass_recordings')
                ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id'))
            ->map(fn ($id): int => (int) $id)->unique();
        $now = now();

        return $sessions
            ->reject(fn (object $session): bool => $withEvidence->contains((int) $session->id))
            ->filter(fn (object $session): bool => LiveClassSessionTime::utc(
                $session->scheduled_end_at, $session->timezone
            )->gt($now))
            ->map(fn (object $session): string => '#'.$session->session_no.' '.$session->title)
            ->values()->all();
    }

    public function storeSession(Request $request, int $cohort): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        abort_if(! $cohortRow->version_id, 422);

        if ($request->has('teacher_ids')) {
            $request->merge(['teacher_ids' => $this->deduplicateTeacherIds($request->input('teacher_ids'))]);
        }

        $origin = null;
        if ($request->filled(['schedule_id', 'schedule_slot_id', 'source_local_date'])) {
            $origin = $this->sessionOriginService->resolve(
                $customerId, $cohort, $request->integer('schedule_id'),
                $request->integer('schedule_slot_id'), (string) $request->input('source_local_date')
            );
            $request->merge([
                'scheduled_start_at' => $origin['scheduled_start_at'],
                'scheduled_end_at' => $origin['scheduled_end_at'],
            ]);
        }

        $validated = $request->validate($this->sessionRules($customerId, (int) $cohortRow->version_id));
        $validated = $this->canonicalSessionBinding(
            $customerId,
            (int) $cohortRow->version_id,
            $validated
        );
        $timezone = $origin['timezone'] ?? config('app.timezone');
        $this->validateSessionScheduleWindow($cohortRow, $validated, $timezone);
        $this->validateSessionDoesNotOverlap($customerId, $cohort, $validated, $timezone);
        $sessionTeachers = $this->canonicalSessionTeacherTeam($customerId, $cohort, $validated);

        DB::transaction(function () use ($customerId, $cohort, $cohortRow, $validated, $sessionTeachers, $request, $origin, $timezone): void {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohort)->lockForUpdate()->firstOrFail();
            if ($origin) {
                $origin = $this->sessionOriginService->resolve(
                    $customerId, $cohort, (int) $origin['schedule_id'],
                    (int) $origin['schedule_slot_id'], $origin['source_local_date'], true
                );
                $validated['scheduled_start_at'] = $origin['scheduled_start_at'];
                $validated['scheduled_end_at'] = $origin['scheduled_end_at'];
            }
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
            }
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_created'));
    }

    public function storeSessionBatch(Request $request, int $cohort): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        abort_if(! $cohortRow->version_id, 422);

        // Configuration belonging to an unselected preview row is client-only
        // draft state. Strip it before validation so the backend neither trusts
        // nor validates data that cannot participate in this batch.
        $validationInput = array_merge($request->all(), [
            'occurrences' => collect($request->input('occurrences', []))
                ->map(function ($item): array {
                    $item = is_array($item) ? $item : [];
                    if ((bool) ($item['selected'] ?? false)) {
                        $item['teacher_ids'] = $this->deduplicateTeacherIds($item['teacher_ids'] ?? []);

                        return $item;
                    }

                    return array_intersect_key($item, array_flip([
                        'selected', 'schedule_id', 'schedule_slot_id', 'source_local_date',
                    ]));
                })->all(),
        ]);

        $validated = validator($validationInput, [
            'occurrences' => ['required', 'array', 'min:1', 'max:100'],
            'occurrences.*.selected' => ['nullable', 'boolean'],
            'occurrences.*.schedule_id' => ['required', 'integer'],
            'occurrences.*.schedule_slot_id' => ['required', 'integer'],
            'occurrences.*.source_local_date' => ['required', 'date_format:Y-m-d'],
            'occurrences.*.title' => ['required_if:occurrences.*.selected,1', 'nullable', 'string', 'max:255'],
            'occurrences.*.version_lesson_id' => ['required_if:occurrences.*.selected,1', 'nullable', 'integer'],
            'occurrences.*.version_activity_id' => ['required_if:occurrences.*.selected,1', 'nullable', 'integer'],
            'occurrences.*.teacher_ids' => ['nullable', 'array'],
            // A teacher may teach multiple occurrences in the same batch. Each
            // row is already normalized above, so a wildcard-level `distinct`
            // rule would incorrectly compare IDs across different sessions.
            'occurrences.*.teacher_ids.*' => ['integer', Rule::exists('users', 'id')
                ->where(fn ($query) => $query->where('customer_id', $customerId)->where('role', 'teacher')->where('status', 'active'))],
            'occurrences.*.delivery_mode' => ['required_if:occurrences.*.selected,1', 'nullable', Rule::in(['online', 'offline', 'hybrid'])],
            'room_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $selected = collect($validated['occurrences'])
            ->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false));
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages([
                'occurrences' => __('lf.LF_course_cohort_session_batch_select_required'),
            ]);
        }

        $prepared = $selected->map(function (array $item, int $index) use ($customerId, $cohort, $cohortRow, $validated): array {
            try {
                $origin = $this->sessionOriginService->resolve(
                    $customerId, $cohort, (int) $item['schedule_id'],
                    (int) $item['schedule_slot_id'], $item['source_local_date']
                );
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    "occurrences.$index.source_local_date" => __('lf.LF_course_cohort_session_occurrence_invalid'),
                ]);
            }
            $session = $this->canonicalSessionBinding($customerId, (int) $cohortRow->version_id, [
                'session_type' => 'curriculum',
                'version_lesson_id' => $item['version_lesson_id'],
                'version_activity_id' => $item['version_activity_id'],
                'title' => $item['title'],
                'delivery_mode' => $item['delivery_mode'],
                'scheduled_start_at' => $origin['scheduled_start_at'],
                'scheduled_end_at' => $origin['scheduled_end_at'],
                'teacher_ids' => $item['teacher_ids'] ?? [],
                'room_name' => $validated['room_name'] ?? null,
                'address' => $validated['address'] ?? null,
            ]);
            if (in_array($session['delivery_mode'], ['offline', 'hybrid'], true)
                && (blank($session['room_name']) || blank($session['address']))) {
                throw ValidationException::withMessages([
                    "occurrences.$index.delivery_mode" => __('lf.LF_course_cohort_session_batch_location_required'),
                ]);
            }
            $this->validateSessionScheduleWindow($cohortRow, $session, $origin['timezone']);

            return [
                'input_index' => $index,
                'origin' => $origin,
                'session' => $session,
                'teachers' => $this->canonicalSessionTeacherTeam(
                    $customerId, $cohort, $session, "occurrences.$index.teacher_ids"
                ),
            ];
        });

        try {
            DB::transaction(function () use ($customerId, $cohort, $cohortRow, $prepared, $request): void {
                DB::table('core_course_cohorts')->where('customer_id', $customerId)
                    ->where('id', $cohort)->lockForUpdate()->firstOrFail();
                $nextSessionNo = (int) DB::table('core_liveclass_sessions')
                    ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                    ->lockForUpdate()->max('session_no') + 1;

                foreach ($prepared as $row) {
                    $origin = $this->sessionOriginService->resolve(
                        $customerId, $cohort, $row['origin']['schedule_id'],
                        $row['origin']['schedule_slot_id'], $row['origin']['source_local_date'], true
                    );
                    $session = $row['session'];
                    $session['scheduled_start_at'] = $origin['scheduled_start_at'];
                    $session['scheduled_end_at'] = $origin['scheduled_end_at'];
                    $this->validateSessionDoesNotOverlap(
                        $customerId,
                        $cohort,
                        $session,
                        $origin['timezone'],
                        null,
                        "occurrences.{$row['input_index']}.scheduled_start_at"
                    );
                    $sessionId = DB::table('core_liveclass_sessions')->insertGetId(array_merge([
                        'customer_id' => $customerId,
                        'cohort_id' => $cohort,
                        'template_version_id' => $cohortRow->version_id,
                        'room_id' => null,
                        'session_no' => $nextSessionNo++,
                        'timezone' => $origin['timezone'],
                        'status' => 'scheduled',
                        'created_by' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $this->sessionPayload($session)));
                    $this->replaceSessionTeacherTeam(
                        $customerId, $sessionId, $row['teachers'], (int) $request->user()->id
                    );
                    DB::table('core_liveclass_session_schedule_origins')->insert([
                        'customer_id' => $customerId, 'session_id' => $sessionId,
                        'schedule_id' => $origin['schedule_id'], 'schedule_slot_id' => $origin['schedule_slot_id'],
                        'source_local_date' => $origin['source_local_date'],
                        'source_local_start_time' => $origin['source_local_start_time'],
                        'source_local_end_time' => $origin['source_local_end_time'],
                        'source_timezone' => $origin['source_timezone'],
                        'source_start_at' => $origin['source_start_at'], 'source_end_at' => $origin['source_end_at'],
                        'created_by' => $request->user()->id, 'created_at' => now(),
                    ]);
                }
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'occurrences' => __('lf.LF_course_cohort_session_batch_conflict'),
                ]);
            }
            throw $exception;
        }

        return $this->tab($cohort, 'sessions')->with(
            'success', __('lf.LF_course_cohort_session_batch_created', ['count' => $prepared->count()])
        );
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

        DB::transaction(function () use ($customerId, $cohort, $cohortRow, $session, $validated, $request): void {
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

            // Schedule changes have a dedicated audited workflow. Never trust
            // date/time values submitted through the general edit form.
            $validated['scheduled_start_at'] = $sessionRow->scheduled_start_at;
            $validated['scheduled_end_at'] = $sessionRow->scheduled_end_at;
            $validated = $this->canonicalSessionBinding(
                $customerId,
                (int) $cohortRow->version_id,
                $validated
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
            ->with('success', __('lf.LF_course_cohort_session_updated'));
    }

    public function updateSchedule(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
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
        DB::transaction(function () use ($customerId, $cohort, $cohortRow, $session, $validated, $request): void {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohort)->lockForUpdate()->firstOrFail();
            $sessionRow = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->where('id', $session)->lockForUpdate()->firstOrFail();
            abort_unless($this->sessionPolicy->canReschedule($sessionRow), 422,
                __('lf.LF_course_cohort_session_reschedule_status_invalid'));
            // Reschedule keeps the Session's recorded timezone: the admin enters
            // the new interval in the same frame the Session already uses.
            $this->validateSessionScheduleWindow($cohortRow, $validated, $sessionRow->timezone);
            $this->validateSessionDoesNotOverlap(
                $customerId, $cohort, $validated, $sessionRow->timezone, $session
            );

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

            DB::table('core_liveclass_session_schedule_changes')->insert([
                'customer_id' => $customerId,
                'session_id' => $session,
                'previous_start_at' => $sessionRow->scheduled_start_at,
                'previous_end_at' => $sessionRow->scheduled_end_at,
                'new_start_at' => $validated['scheduled_start_at'],
                'new_end_at' => $validated['scheduled_end_at'],
                'reason' => $validated['reason'] ?? null,
                'changed_by' => $request->user()->id,
                'created_at' => now(),
            ]);
            DB::table('core_liveclass_sessions')->where('customer_id', $customerId)
                ->where('id', $session)->update([
                    'scheduled_start_at' => $validated['scheduled_start_at'],
                    'scheduled_end_at' => $validated['scheduled_end_at'],
                    'status' => 'scheduled',
                    'updated_at' => now(),
                ]);
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_rescheduled'));
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
        $this->sessionLifecycleService->cancel($customerId, $cohort, $session, $validated['reason'] ?? null);

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_lifecycle_cancelled'));
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
        ]);

        $memberships = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('status', 'active')->pluck('student_id', 'enrollment_id');

        DB::transaction(function () use ($validated, $memberships, $customerId, $session, $sessionRow, $request): void {
            foreach ($validated['attendance'] as $item) {
                abort_unless($memberships->has($item['enrollment_id']), 422);
                DB::table('core_liveclass_attendances')->updateOrInsert(
                    [
                        'customer_id' => $customerId,
                        'session_id' => $session,
                        'enrollment_id' => $item['enrollment_id'],
                    ],
                    [
                        'user_id' => $memberships->get($item['enrollment_id']),
                        'version_activity_id' => $sessionRow->version_activity_id,
                        'status' => $item['status'],
                        'attendance_mode' => $item['attendance_mode'] ?? null,
                        'attendance_source' => 'manual',
                        'notes' => $item['notes'] ?? null,
                        'recorded_by' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });

        return $this->tab($cohort, 'attendance', ['session_id' => $session])
            ->with('success', __('lf.LF_course_cohort_attendance_saved'));
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
            'address' => ['nullable', 'string', 'max:2000', 'required_if:delivery_mode,offline,hybrid'],
        ];
    }

    private function canonicalSessionBinding(int $customerId, int $versionId, array $validated): array
    {
        if ($validated['session_type'] === 'operational') {
            $validated['version_lesson_id'] = null;
            $validated['version_activity_id'] = null;

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
        $validated['meeting_url'] = $activity->live_class_url_snapshot;
        $validated['online_provider'] = $this->meetingProviderFromUrl($activity->live_class_url_snapshot);

        return $validated;
    }

    private function meetingProviderFromUrl(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        return match (true) {
            str_contains($host, 'zoom.') => 'Zoom',
            $host === 'meet.google.com' => 'Google Meet',
            str_contains($host, 'teams.microsoft.com'), str_contains($host, 'teams.live.com') => 'Microsoft Teams',
            default => preg_replace('/^www\./', '', $host),
        };
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

    private function validateSessionDoesNotOverlap(
        int $customerId,
        int $cohortId,
        array $validated,
        string $timezone,
        ?int $excludeSessionId = null,
        string $errorField = 'scheduled_start_at'
    ): void {
        $newStart = LiveClassSessionTime::utc($validated['scheduled_start_at'], $timezone);
        $newEnd = LiveClassSessionTime::utc($validated['scheduled_end_at'], $timezone);

        // Stored `scheduled_*` values are wall-clock in each row's own timezone,
        // so they cannot be compared in SQL. The bounds below are a coarse
        // prefilter only: a two-day margin exceeds the widest possible IANA
        // offset spread (-12..+14, i.e. 26 hours), so no genuinely overlapping
        // Session can fall outside it. The authoritative comparison happens in
        // PHP against normalized instants.
        $candidates = DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('scheduled_start_at', '<', $newEnd->addDays(2)->format('Y-m-d H:i:s'))
            ->where('scheduled_end_at', '>', $newStart->subDays(2)->format('Y-m-d H:i:s'))
            ->when($excludeSessionId !== null, fn ($query) => $query->where('id', '!=', $excludeSessionId))
            ->get(['id', 'scheduled_start_at', 'scheduled_end_at', 'timezone']);

        foreach ($candidates as $candidate) {
            if (LiveClassSessionTime::overlaps(
                LiveClassSessionTime::utc($candidate->scheduled_start_at, $candidate->timezone),
                LiveClassSessionTime::utc($candidate->scheduled_end_at, $candidate->timezone),
                $newStart,
                $newEnd
            )) {
                throw ValidationException::withMessages([
                    $errorField => __('lf.LF_course_cohort_session_schedule_overlap'),
                ]);
            }
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
            || DB::table('core_liveclass_session_teachers')
                ->where('customer_id', $customerId)
                ->where('session_id', $session->id)
                ->exists();
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
