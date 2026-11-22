<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\CourseCohortMembershipHistory;
use App\Services\CourseCohortMutationPolicy;
use App\Services\CourseCohortVersionResolver;
use App\Services\CourseEnrollmentLifecycleService;
use App\Services\LiveClassWhiteboardLiveState;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseCohortStudentController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    private const STATUSES = [
        'active',
        'removed',
        'completed',
        'cancelled',
    ];

    private string $cohortRoutePrefix = 'admin.course-cohort-students';

    /**
     * The tenant-wide membership list has no way in from the menu; a learner's class is managed on its Students tab.
     */
    public function index(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $cohortId = (int) $request->query('cohort_id', 0);
        if ($cohortId > 0) {
            return $this->studentsTab($this->findCohortRecord($this->customerId(), $cohortId)->id);
        }

        return redirect()->route('admin.course-cohorts.index');
    }

    public function create(Request $request, int $cohort): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $contextCohort = $this->cohortSummary($customerId, $cohort);
        $eligibilityError = $this->cohortEligibilityError($customerId, $contextCohort);

        if ($eligibilityError) {
            return redirect()->route('admin.course-cohorts.show', ['id' => $contextCohort->id, 'tab' => 'students'])
                ->with('error', $eligibilityError);
        }

        // Learners are added in the student manager of the Students tab.
        return $this->studentsTab((int) $contextCohort->id, ['manage' => 1]);
    }

    public function students(Request $request, int $cohort): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->cohortSummary($this->customerId(), $cohort);

        return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'students']);
    }

    public function manage(Request $request, int $cohort): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $customerId = $this->customerId();
        $contextCohort = $this->cohortSummary($customerId, $cohort);

        if (! $this->cohortAcceptsMembership($contextCohort)) {
            return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'students'])
                ->with('error', __('lf.LF_course_cohort_student_validation_active_cohort'));
        }

        return redirect()->route('admin.course-cohorts.show', [
            'id' => $contextCohort->id,
            'tab' => 'students',
            'manage' => 1,
        ]);
    }

    public function search(Request $request, int $cohort): JsonResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $contextCohort = $this->cohortSummary($customerId, $cohort);
        $managing = $request->boolean('manage');
        abort_if(
            $managing
                ? ! $this->cohortAcceptsMembership($contextCohort)
                : $this->cohortEligibilityError($customerId, $contextCohort),
            422
        );

        $query = trim((string) $request->query('q', ''));
        $numericQuery = preg_replace('/\D+/', '', $query);
        $enrollments = ($managing
            ? $this->manageableEnrollmentsQuery($customerId, $contextCohort)
            : $this->eligibleEnrollmentsQuery($customerId, $contextCohort))
            ->when($query !== '', function ($builder) use ($query, $numericQuery): void {
                $builder->where(function ($builder) use ($query, $numericQuery): void {
                    $builder->where('students.name', 'like', '%'.$query.'%')
                        ->orWhere('students.email', 'like', '%'.$query.'%');

                    if ($numericQuery !== '') {
                        $builder->orWhere('enrollments.id', (int) $numericQuery);
                    }
                });
            })
            ->orderBy('students.name')
            ->orderBy('enrollments.id')
            ->paginate(10);

        $data = collect($enrollments->items())->map(fn (object $enrollment): array => [
            'id' => $enrollment->id,
            'name' => $enrollment->student_name,
            'email' => $enrollment->student_email,
            'code' => 'ENR-'.str_pad((string) $enrollment->id, 6, '0', STR_PAD_LEFT),
            'status' => $enrollment->status,
            'status_label' => __('lf.LF_course_enrollment_common_'.$enrollment->status),
            'source_label' => __('lf.LF_course_enrollment_common_source_'.$enrollment->source),
            'enrolled_at' => $this->formatEnrollmentDateTime($enrollment->enrolled_at),
            'access_starts_at' => $this->formatEnrollmentDateTime($enrollment->access_starts_at),
            'access_ends_at' => $this->formatEnrollmentDateTime($enrollment->access_ends_at),
            'review_starts_at' => $this->formatEnrollmentDateTime($enrollment->review_starts_at),
            'review_ends_at' => $this->formatEnrollmentDateTime($enrollment->review_ends_at),
            'detail_url' => route('admin.course-enrollments.show', $enrollment->id),
            'current' => (bool) ($enrollment->current_membership ?? false),
            'account_active' => ($enrollment->student_status ?? 'active') === 'active',
            'access_ended' => CourseEnrollmentLifecycleService::accessEnded($enrollment, now()),
            'eligible' => $enrollment->status === 'active' && ($enrollment->student_status ?? 'active') === 'active'
                && ! CourseEnrollmentLifecycleService::accessEnded($enrollment, now()),
        ]);

        return response()->json(['data' => $data, 'pagination' => [
            'current_page' => $enrollments->currentPage(),
            'last_page' => $enrollments->lastPage(),
            'total' => $enrollments->total(),
            'per_page' => $enrollments->perPage(),
        ]]);
    }

    public function store(Request $request, int $cohort): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = Validator::make($request->all(), [
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer', 'min:1', 'distinct'],
            'note' => ['nullable', 'string'],
            'cohort_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'version_id' => ['prohibited'],
            'student_id' => ['prohibited'],
            'status' => ['prohibited'],
            'joined_at' => ['prohibited'],
            'left_at' => ['prohibited'],
            'transfer_reason' => ['prohibited'],
        ])->validate();

        try {
            DB::transaction(function () use ($customerId, $cohort, $validated, $request): void {
                $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                    ->where('id', $cohort)->lockForUpdate()->first();
                throw_if(! $cohort, ValidationException::withMessages([
                    'enrollment_ids' => __('lf.LF_course_cohort_student_validation_cohort'),
                ]));
                // ADR-0022: a legacy class bound to a self-paced course takes no new member until it is fixed.
                throw_if(! app(CourseCohortVersionResolver::class)->runsInClass($customerId, $cohort->version_id ? (int) $cohort->version_id : null),
                    ValidationException::withMessages(['enrollment_ids' => __('lf.LF_course_type_cohort_legacy_self_paced')]));

                $enrollmentIds = array_values(array_unique(array_map('intval', $validated['enrollment_ids'])));
                sort($enrollmentIds);

                $this->applyMembershipBatch(
                    $customerId,
                    $cohort,
                    $enrollmentIds,
                    $request->user()?->id,
                    checkEligibility: true,
                    enforceBatchCapacity: true,
                    noteOverride: ['note' => $validated['note'] ?? null],
                );
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'enrollment_ids' => __('lf.LF_course_cohort_student_validation_duplicate'),
                ]);
            }

            throw $exception;
        }

        return redirect()
            ->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'students'])
            ->with('success', __('lf.LF_course_cohort_student_common_created_count', [
                'count' => count($validated['enrollment_ids']),
            ]));
    }

    public function sync(Request $request, int $cohort): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = Validator::make($request->all(), [
            'enrollment_ids' => ['nullable', 'array'],
            'enrollment_ids.*' => ['integer', 'min:1', 'distinct'],
            'roster_snapshot' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'customer_id' => ['prohibited'],
            'cohort_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'version_id' => ['prohibited'],
            'student_id' => ['prohibited'],
            'status' => ['prohibited'],
        ])->validate();

        DB::transaction(function () use ($customerId, $cohort, $validated, $request): void {
            $lockedCohort = DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)->where('id', $cohort)
                ->lockForUpdate()->first();

            throw_if(
                ! $lockedCohort || ! CourseCohortMutationPolicy::canMutate($lockedCohort)
                    || ! $lockedCohort->product_id || ! $lockedCohort->version_id,
                ValidationException::withMessages([
                    'enrollment_ids' => __('lf.LF_course_cohort_student_validation_active_cohort'),
                ])
            );

            $selectedIds = array_values(array_unique(array_map('intval', $validated['enrollment_ids'] ?? [])));
            sort($selectedIds);

            $currentMemberships = DB::table('core_course_cohort_students')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->where('status', 'active')->lockForUpdate()->get()->keyBy('enrollment_id');
            $currentIds = $currentMemberships->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();

            // Sync replaces the whole roster, so a form opened before someone else's
            // change would silently undo it. The snapshot is what that form was shown.
            throw_if(
                $request->exists('roster_snapshot') && (string) ($validated['roster_snapshot'] ?? '') !== implode(',', $currentIds),
                ValidationException::withMessages([
                    'roster_snapshot' => __('lf.LF_course_cohort_student_validation_roster_changed'),
                ])
            );

            $addIds = array_values(array_diff($selectedIds, $currentIds));
            $removeIds = array_values(array_diff($currentIds, $selectedIds));

            if ($lockedCohort->capacity !== null && count($selectedIds) > (int) $lockedCohort->capacity) {
                throw ValidationException::withMessages([
                    'enrollment_ids' => __('lf.LF_course_cohort_student_validation_capacity'),
                ]);
            }

            // ADR-0022: a legacy class bound to a self-paced course can still shrink, but takes no new member.
            throw_if(
                $addIds !== [] && ! app(CourseCohortVersionResolver::class)->runsInClass($customerId, (int) $lockedCohort->version_id),
                ValidationException::withMessages(['enrollment_ids' => __('lf.LF_course_type_cohort_legacy_self_paced')])
            );

            // Removals go first: swapping a student's old seat for their new
            // Enrollment in one save must not count the old seat as taken.
            if ($removeIds) {
                DB::table('core_course_cohort_students')
                    ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                    ->where('status', 'active')->whereIn('enrollment_id', $removeIds)
                    ->update(['status' => 'removed', 'left_at' => now(), 'updated_at' => now()]);
                // ADR-0034: every change of a membership is kept.
                foreach ($currentMemberships->only($removeIds) as $leaving) {
                    CourseCohortMembershipHistory::record($leaving, 'left', $request->user()?->id);
                }
            }

            // Capacity was already validated once for the whole selection above,
            // so the per-row check is skipped here.
            $this->applyMembershipBatch(
                $customerId,
                $lockedCohort,
                $addIds,
                $request->user()?->id,
                checkEligibility: false,
                enforceBatchCapacity: false,
            );

            if ($removeIds) {
                // A student who only changed Enrollment stays in the class and keeps the board.
                $stillSeated = DB::table('core_course_cohort_students')
                    ->where('customer_id', $customerId)->where('cohort_id', $cohort)->where('status', 'active')
                    ->pluck('student_id')->map(fn ($studentId): int => (int) $studentId)->all();
                $this->closeBoardsAfterCommit($customerId, (int) $cohort, array_values(array_diff(
                    $currentMemberships->only($removeIds)->pluck('student_id')->map(fn ($studentId): int => (int) $studentId)->all(),
                    $stillSeated
                )));
            }
        }, 3);

        return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'students'])
            ->with('success', __('lf.LF_course_cohort_student_sync_updated'));
    }

    public function show(Request $request, int $id): RedirectResponse
    {
        $this->authorizeAdmin($request);

        return $this->studentsTab((int) $this->findMembership($this->customerId(), $id)->cohort_id);
    }

    /**
     * The membership form is a popup on the Students tab; this address opens it there.
     */
    public function edit(Request $request, int $id): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $membership = $this->findMembership($this->customerId(), $id);
        abort_if($membership->status !== 'active', 422);

        return $this->studentsTab((int) $membership->cohort_id, ['member' => $membership->id]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $membership = $this->findMembership($customerId, $id);
        abort_if($membership->status !== 'active', 422);
        try {
            $validated = $this->validatedData($request, $customerId, $membership);
        } catch (ValidationException $e) {
            // The popup of this row opens again with what was typed (`_form`), its errors in their own bag.
            return redirect()->to(url()->previous(route('admin.course-cohorts.show', ['id' => $membership->cohort_id, 'tab' => 'students'])))
                ->withErrors($e->errors(), 'membershipEdit')->withInput();
        }
        DB::transaction(function () use ($customerId, $validated, $membership, $id, $request): void {
            $ids = array_values(array_unique([(int) $membership->cohort_id, (int) $validated['cohort_id']]));
            sort($ids);
            $lockedCohorts = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedMembership = DB::table('core_course_cohort_students')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            $enrollment = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->where('id', $lockedMembership->enrollment_id)->lockForUpdate()->first();
            $source = $lockedCohorts->get((int) $lockedMembership->cohort_id);
            $target = $lockedCohorts->get((int) $validated['cohort_id']);
            abort_if(! $source || ! $target || ! $enrollment || ! CourseCohortMutationPolicy::canMutate($source), 422);
            $this->assertAssignmentAllowed($customerId, $target, $enrollment, $id);

            // ADR-0034: a transfer and a changed join date are kept in the history.
            $transferred = (int) $target->id !== (int) $source->id;
            // The popup shows the join date to the minute while it is stored to the second: the same minute is the
            // same date, and the stored seconds stay (an edit of the note alone is no join date change).
            $sameJoinDate = Carbon::parse((string) $lockedMembership->joined_at)->format('Y-m-d H:i')
                === Carbon::parse((string) $validated['joined_at'])->format('Y-m-d H:i');
            if ($sameJoinDate) {
                $validated['joined_at'] = (string) $lockedMembership->joined_at;
            }
            // A transfer that kept the old class's join date joins the new class now: otherwise the new class's earlier
            // sessions would count, and automatic absence would mark them (QA 2026-11-19). A date the admin changed stays.
            if ($transferred && $sameJoinDate) {
                $validated['joined_at'] = now()->format('Y-m-d H:i:s');
            }
            if ($transferred || ! $sameJoinDate) {
                CourseCohortMembershipHistory::record($lockedMembership, $transferred ? 'transferred' : 'joined_at_changed', $request->user()?->id, [
                    'old_joined_at' => $lockedMembership->joined_at, 'new_joined_at' => $validated['joined_at'], 'cohort_id' => (int) $target->id,
                ]);
            }
            DB::table('core_course_cohort_students')->where('customer_id', $customerId)->where('id', $id)->update([
                'cohort_id' => $target->id, 'joined_at' => $validated['joined_at'],
                'left_at' => null, 'status' => 'active',
                'transfer_from_cohort_id' => $target->id !== $source->id ? $source->id : $lockedMembership->transfer_from_cohort_id,
                'transfer_reason' => $validated['transfer_reason'] ?? null,
                'note' => $validated['note'] ?? null, 'updated_at' => now(),
            ]);
            if ((int) $target->id !== (int) $source->id) {
                $this->closeBoardsAfterCommit($customerId, (int) $source->id, [(int) $lockedMembership->student_id]);
            }
        }, 3);

        return $this->studentsTab((int) $membership->cohort_id)
            ->with('success', __('lf.LF_course_cohort_student_common_updated'));
    }

    public function archive(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        DB::transaction(function () use ($customerId, $id, $request): void {
            $membership = DB::table('core_course_cohort_students')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $membership, 404);
            $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $membership->cohort_id)->lockForUpdate()->first();
            abort_if(! $cohort || ! CourseCohortMutationPolicy::canMutate($cohort) || $membership->status !== 'active', 422);
            DB::table('core_course_cohort_students')->where('customer_id', $customerId)->where('id', $id)
                ->update(['status' => 'removed', 'left_at' => now(), 'updated_at' => now()]);
            CourseCohortMembershipHistory::record($membership, 'left', $request->user()?->id);
            $this->closeBoardsAfterCommit($customerId, (int) $membership->cohort_id, [(int) $membership->student_id]);
        }, 3);

        $cohortId = (int) DB::table('core_course_cohort_students')->where('customer_id', $customerId)->where('id', $id)->value('cohort_id');

        return $this->studentsTab($cohortId)
            ->with('success', __('lf.LF_course_cohort_student_common_archived_message'));
    }

    /**
     * Learners who leave a Cohort lose its live Whiteboards at once
     * (ADR-0002 § LiveClass Whiteboard Amendment), after the change commits.
     *
     * @param  array<int, int>  $studentIds
     */
    private function closeBoardsAfterCommit(int $customerId, int $cohortId, array $studentIds): void
    {
        DB::afterCommit(fn () => app(LiveClassWhiteboardLiveState::class)->revokeViewers($customerId, $cohortId, $studentIds));
    }

    private function validatedData(Request $request, int $customerId, ?object $membership = null): array
    {
        $validator = Validator::make($request->all(), [
            'cohort_id' => ['required', 'integer', 'min:1'],
            'enrollment_id' => [$membership ? 'nullable' : 'required', 'integer', 'min:1'],
            // ADR-0034: a learner cannot have joined the class in the future.
            'joined_at' => ['required', 'date', 'before_or_equal:now'],
            'left_at' => ['nullable', 'date', 'after_or_equal:joined_at'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'transfer_reason' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request, $customerId, $membership): void {
            $this->rejectManagedInputs($validator, $request, [
                'customer_id',
                'product_id',
                'student_id',
                'assigned_by',
                'transfer_from_cohort_id',
            ]);

            if ($membership) {
                $this->rejectManagedInputs($validator, $request, ['enrollment_id']);
            }

            if ($request->input('status') !== 'active') {
                $validator->errors()->add('status', __('lf.LF_course_cohort_student_validation_active_status'));
            }

            $cohortId = (int) $request->input('cohort_id');
            $enrollmentId = $membership
                ? (int) $membership->enrollment_id
                : (int) $request->input('enrollment_id');

            $cohort = $cohortId > 0 ? $this->findCohortRecord($customerId, $cohortId, false) : null;
            $enrollment = $enrollmentId > 0
                ? $this->findEnrollmentRecord($customerId, $enrollmentId, false)
                : null;

            if ($cohortId > 0 && ! $cohort) {
                $validator->errors()->add(
                    'cohort_id',
                    __('lf.LF_course_cohort_student_validation_cohort')
                );
            }

            if ($cohort && ! CourseCohortMutationPolicy::canMutate($cohort)) {
                $validator->errors()->add(
                    'cohort_id',
                    __('lf.LF_course_cohort_student_validation_archived_cohort')
                );
            }

            if ($enrollmentId > 0 && ! $enrollment) {
                $validator->errors()->add(
                    'enrollment_id',
                    __('lf.LF_course_cohort_student_validation_enrollment')
                );
            }

            // Business invariants come from the shared rule set so this
            // pre-transaction pass can never drift from the authoritative check
            // inside update()'s transaction. Cohort existence/status above keeps
            // its own field-specific errors; everything else is reported against
            // the Enrollment being moved.
            if ($cohort && $enrollment) {
                $issue = $this->assignmentIssue(
                    $customerId,
                    $cohort,
                    $enrollment,
                    false,
                    0,
                    $membership?->id
                );

                if ($issue !== null) {
                    $validator->errors()->add('enrollment_id', $issue);
                }
            }
        });

        return $validator->validate();
    }

    /** @param  array<string, int|string>  $query */
    private function studentsTab(int $cohortId, array $query = []): RedirectResponse
    {
        return redirect()->route('admin.course-cohorts.show', ['id' => $cohortId, 'tab' => 'students'] + $query);
    }

    private function rejectManagedInputs($validator, Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if ($request->has($field) && $request->input($field) !== null) {
                $validator->errors()->add(
                    $field,
                    __('lf.LF_course_cohort_student_validation_managed')
                );
            }
        }
    }

    private function findMembership(int $customerId, int $id): object
    {
        $membership = DB::table('core_course_cohort_students as memberships')
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'memberships.cohort_id')
                    ->where('cohorts.customer_id', '=', $customerId);
            })
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')
                    ->where('enrollments.customer_id', '=', $customerId);
            })
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'memberships.student_id')
                    ->where('students.customer_id', '=', $customerId);
            })
            ->leftJoin('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'memberships.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->leftJoin('core_course_cohorts as previous_cohorts', function ($join) use ($customerId): void {
                $join->on('previous_cohorts.id', '=', 'memberships.transfer_from_cohort_id')
                    ->where('previous_cohorts.customer_id', '=', $customerId);
            })
            ->where('memberships.customer_id', $customerId)
            ->where('memberships.id', $id)
            ->select(
                'memberships.*',
                'cohorts.name as cohort_name',
                'cohorts.code as cohort_code',
                'enrollments.version_id',
                'students.name as student_name',
                'students.email as student_email',
                'products.title as product_title',
                'products.product_code',
                'previous_cohorts.name as transfer_from_cohort_name'
            )
            ->first();

        abort_if(! $membership, 404);

        return $membership;
    }

    private function findCohortRecord(int $customerId, int $id, bool $abort = true): ?object
    {
        $cohort = DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        abort_if($abort && ! $cohort, 404);

        return $cohort;
    }

    private function findEnrollmentRecord(int $customerId, int $id, bool $abort = true): ?object
    {
        $enrollment = DB::table('core_course_enrollments')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        abort_if($abort && ! $enrollment, 404);

        return $enrollment;
    }

    private function activeMembershipExists(
        int $customerId,
        int $enrollmentId,
        ?int $ignoreMembershipId = null
    ): bool {
        return DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where('enrollment_id', $enrollmentId)
            ->where('status', 'active')
            ->when($ignoreMembershipId, fn ($query) => $query->where('id', '!=', $ignoreMembershipId))
            ->exists();
    }

    /**
     * The student already holds a seat in this Cohort through another Enrollment.
     *
     * Enrollment transitions never touch Membership, so a cancelled or expired
     * cycle keeps its seat until an explicit Remove. A re-enrollment of the same
     * student must not take a second seat next to it: remove the old one first.
     */
    private function studentSeatedThroughAnotherEnrollment(
        int $customerId,
        object $cohort,
        object $enrollment,
        ?int $ignoreMembershipId = null
    ): bool {
        return DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohort->id)
            ->where('student_id', $enrollment->student_id)
            ->where('enrollment_id', '!=', $enrollment->id)
            ->where('status', 'active')
            ->when($ignoreMembershipId, fn ($query) => $query->where('id', '!=', $ignoreMembershipId))
            ->exists();
    }

    private function activeStudentExists(int $customerId, int $studentId): bool
    {
        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('id', $studentId)
            ->where('role', 'student')
            ->where('status', 'active')
            // Lock only where the caller is inside the authoritative write
            // transaction; the pre-transaction validator pass is UX only.
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->exists();
    }

    private function cohortIsFull(int $customerId, object $cohort, ?int $ignoreMembershipId = null, int $extra = 0): bool
    {
        if ($cohort->capacity === null) {
            return false;
        }

        $query = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohort->id)
            ->where('status', 'active');

        if ($ignoreMembershipId) {
            $query->where('id', '!=', $ignoreMembershipId);
        }

        return ($query->count() + $extra) >= (int) $cohort->capacity;
    }

    private function cohortAcceptsMembership(object $cohort): bool
    {
        return CourseCohortMutationPolicy::canMutate($cohort) && $cohort->product_id && $cohort->version_id;
    }

    private function assertAssignmentAllowed(int $customerId, object $cohort, object $enrollment, ?int $ignoreMembershipId = null): void
    {
        $message = $this->assignmentIssue(
            $customerId, $cohort, $enrollment, true, 0, $ignoreMembershipId
        );

        abort_if($message !== null, 422, (string) $message);
    }

    /**
     * Single source of truth for "may this Enrollment sit in this Cohort".
     *
     * Callers differ only in two axes:
     *
     * - `$extraAccepted` — how many rows the caller has already accepted but not
     *   yet made visible to the capacity COUNT. `null` skips the capacity check
     *   entirely, which sync() needs because it validates capacity once for the
     *   whole batch before applying its removals.
     * - `$ignoreMembershipId` — the membership currently being transferred, which
     *   must not count itself as either an occupied seat or a duplicate.
     *
     * Before this was unified, the transfer path ran a shorter list and silently
     * skipped the active-student check, so moving a disabled student between
     * Cohorts succeeded while adding them directly was rejected.
     */
    private function assignmentIssue(
        int $customerId,
        object $cohort,
        object $enrollment,
        bool $checkEligibility,
        ?int $extraAccepted = null,
        ?int $ignoreMembershipId = null
    ): ?string {
        return match (true) {
            $checkEligibility && ! $this->cohortAcceptsMembership($cohort) => __('lf.LF_course_cohort_student_validation_active_cohort'),
            $extraAccepted !== null && $this->cohortIsFull($customerId, $cohort, $ignoreMembershipId, $extraAccepted) => __('lf.LF_course_cohort_student_validation_capacity'),
            $enrollment->status !== 'active' => __('lf.LF_course_cohort_student_validation_active_enrollment'),
            CourseEnrollmentLifecycleService::accessEnded($enrollment, now()) => __('lf.LF_course_cohort_student_validation_access_ended'),
            ! $this->activeStudentExists($customerId, (int) $enrollment->student_id) => __('lf.LF_course_cohort_student_validation_active_student'),
            (int) $cohort->product_id !== (int) $enrollment->product_id => __('lf.LF_course_cohort_student_validation_product_mismatch'),
            (int) $cohort->version_id !== (int) $enrollment->version_id => __('lf.LF_course_cohort_student_validation_version_mismatch'),
            $this->activeMembershipExists($customerId, $enrollment->id, $ignoreMembershipId) => __('lf.LF_course_cohort_student_validation_duplicate'),
            $this->studentSeatedThroughAnotherEnrollment($customerId, $cohort, $enrollment, $ignoreMembershipId) => __('lf.LF_course_cohort_student_validation_student_already_seated'),
            default => null,
        };
    }

    /**
     * Apply one batch of memberships to a Cohort, inside the caller's transaction.
     *
     * store() and sync() previously carried two near-identical copies of this
     * loop. That duplication is how the capacity double-count survived in only
     * one of them, so the two callers now differ only in the flags below.
     *
     * A membership that already exists for the Enrollment is moved rather than
     * recreated: `core_course_cohort_students` keeps one row per Enrollment for
     * all history, and a transfer updates that row instead of adding one.
     *
     * @param  list<int>  $enrollmentIds
     * @param  bool  $checkEligibility  whether the Cohort itself still has to be
     *                                  proven acceptable; sync() has already done
     *                                  that once for the whole batch
     * @param  bool  $enforceBatchCapacity  whether capacity is checked per row,
     *                                      counting the inserts still pending
     * @param  array{note?: string|null}  $noteOverride  store() applies one note to
     *                                                   the batch; sync() must leave
     *                                                   an existing note untouched
     */
    private function applyMembershipBatch(
        int $customerId,
        object $cohort,
        array $enrollmentIds,
        ?int $actorId,
        bool $checkEligibility,
        bool $enforceBatchCapacity,
        array $noteOverride = []
    ): void {
        if ($enrollmentIds === []) {
            return;
        }

        $cohortId = (int) $cohort->id;
        $enrollments = DB::table('core_course_enrollments')
            ->where('customer_id', $customerId)->whereIn('id', $enrollmentIds)
            ->lockForUpdate()->get()->keyBy('id');
        $existingMemberships = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)->whereIn('enrollment_id', $enrollmentIds)
            ->lockForUpdate()->get()->keyBy('enrollment_id');
        $now = now();
        $rows = [];

        foreach ($enrollmentIds as $index => $enrollmentId) {
            $enrollment = $enrollments->get($enrollmentId);
            throw_if(! $enrollment, ValidationException::withMessages([
                "enrollment_ids.{$index}" => __('lf.LF_course_cohort_student_validation_enrollment'),
            ]));
            // Only the still-pending inserts are invisible to the capacity
            // COUNT. A reactivated membership is UPDATEd inside this loop, so
            // the COUNT already includes it — counting it again would reject a
            // batch that still fits.
            $this->throwIfAssignmentIssue(
                $customerId,
                $cohort,
                $enrollment,
                $checkEligibility,
                $enforceBatchCapacity ? count($rows) : null,
                "enrollment_ids.{$index}"
            );
            $existingMembership = $existingMemberships->get($enrollmentId);

            if ($existingMembership) {
                // ADR-0034: added back to the same class, the learner keeps the first join date and the time away
                // is kept as a gap; moved in from another class, the join date is now.
                $sameClass = (int) $existingMembership->cohort_id === $cohortId;
                CourseCohortMembershipHistory::record($existingMembership, $sameClass ? 'rejoined' : 'transferred', $actorId, $sameClass
                    ? ['absent_from' => $existingMembership->left_at ?? $now, 'absent_to' => $now]
                    : ['old_joined_at' => $existingMembership->joined_at, 'new_joined_at' => $now, 'cohort_id' => $cohortId]);
                DB::table('core_course_cohort_students')
                    ->where('customer_id', $customerId)
                    ->where('id', $existingMembership->id)
                    ->update(array_merge([
                        'cohort_id' => $cohortId,
                        'product_id' => $enrollment->product_id,
                        'student_id' => $enrollment->student_id,
                        'assigned_by' => $actorId,
                        'joined_at' => $sameClass ? $existingMembership->joined_at : $now,
                        'left_at' => null,
                        'status' => 'active',
                        'transfer_from_cohort_id' => (int) $existingMembership->cohort_id !== $cohortId
                            ? $existingMembership->cohort_id
                            : $existingMembership->transfer_from_cohort_id,
                        'transfer_reason' => null,
                        'updated_at' => $now,
                    ], $noteOverride));

                continue;
            }

            $rows[] = array_merge([
                'customer_id' => $customerId, 'cohort_id' => $cohortId,
                'enrollment_id' => $enrollment->id, 'product_id' => $enrollment->product_id,
                'student_id' => $enrollment->student_id, 'assigned_by' => $actorId,
                'joined_at' => $now, 'left_at' => null, 'status' => 'active',
                'transfer_from_cohort_id' => null, 'transfer_reason' => null,
                'note' => null, 'metadata' => null,
                'created_at' => $now, 'updated_at' => $now,
            ], $noteOverride);
        }

        if ($rows !== []) {
            DB::table('core_course_cohort_students')->insert($rows);
        }
    }

    private function throwIfAssignmentIssue(int $customerId, object $cohort, object $enrollment, bool $checkEligibility, ?int $extraAccepted, string $errorField): void
    {
        $message = $this->assignmentIssue($customerId, $cohort, $enrollment, $checkEligibility, $extraAccepted);

        if ($message) {
            throw ValidationException::withMessages([$errorField => $message]);
        }
    }

    private function cohortSummary(int $customerId, int $cohortId): object
    {
        $cohort = DB::table('core_course_cohorts as cohorts')
            ->join('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'cohorts.product_id')
                    ->where('products.customer_id', $customerId);
            })
            ->leftJoin('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'cohorts.version_id')
                    ->where('versions.customer_id', $customerId);
            })
            ->where('cohorts.customer_id', $customerId)
            ->where('cohorts.id', $cohortId)
            ->select(
                'cohorts.*',
                'products.title as product_title',
                'products.product_code',
                'versions.version_code'
            )
            ->first();

        abort_if(! $cohort, 404);

        $cohort->active_membership_count = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohort->id)
            ->where('status', 'active')
            ->count();

        return $cohort;
    }

    private function cohortEligibilityError(int $customerId, object $cohort): ?string
    {
        if (! $this->cohortAcceptsMembership($cohort)) {
            return __('lf.LF_course_cohort_student_validation_active_cohort');
        }

        if ($this->cohortIsFull($customerId, $cohort)) {
            return __('lf.LF_course_cohort_student_validation_capacity');
        }

        return null;
    }

    private function eligibleEnrollmentsQuery(int $customerId, object $cohort)
    {
        return DB::table('core_course_enrollments as enrollments')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'enrollments.student_id')
                    ->where('students.customer_id', $customerId)
                    ->where('students.role', 'student')
                    ->where('students.status', 'active');
            })
            ->where('enrollments.customer_id', $customerId)
            ->where('enrollments.product_id', $cohort->product_id)
            ->where('enrollments.version_id', $cohort->version_id)
            ->where('enrollments.status', 'active')
            // A learning window that has ended closes the class to the Enrollment before the expiry sweep runs.
            ->where(fn ($query) => $query->whereNull('enrollments.access_ends_at')->orWhere('enrollments.access_ends_at', '>', now()))
            ->whereNotExists(function ($query) use ($customerId): void {
                $query->selectRaw('1')
                    ->from('core_course_cohort_students as memberships')
                    ->whereColumn('memberships.enrollment_id', 'enrollments.id')
                    ->where('memberships.customer_id', $customerId)
                    ->where('memberships.status', 'active');
            })
            // A student who still holds a seat here through an earlier Enrollment is not offered again.
            ->whereNotExists(function ($query) use ($customerId, $cohort): void {
                $query->selectRaw('1')
                    ->from('core_course_cohort_students as seats')
                    ->whereColumn('seats.student_id', 'enrollments.student_id')
                    ->where('seats.customer_id', $customerId)
                    ->where('seats.cohort_id', $cohort->id)
                    ->where('seats.status', 'active');
            })
            ->select(
                'enrollments.id',
                'enrollments.status',
                'enrollments.source',
                'enrollments.enrolled_at',
                'enrollments.access_starts_at',
                'enrollments.access_ends_at',
                'enrollments.review_starts_at',
                'enrollments.review_ends_at',
                'students.name as student_name',
                'students.email as student_email'
            );
    }

    private function manageableEnrollmentsQuery(int $customerId, object $cohort)
    {
        return DB::table('core_course_enrollments as enrollments')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'enrollments.student_id')
                    ->where('students.customer_id', $customerId)
                    ->where('students.role', 'student');
            })
            ->leftJoin('core_course_cohort_students as current_memberships', function ($join) use ($customerId, $cohort): void {
                $join->on('current_memberships.enrollment_id', '=', 'enrollments.id')
                    ->where('current_memberships.customer_id', $customerId)
                    ->where('current_memberships.cohort_id', $cohort->id)
                    ->where('current_memberships.status', 'active');
            })
            ->where('enrollments.customer_id', $customerId)
            ->where('enrollments.product_id', $cohort->product_id)
            ->where('enrollments.version_id', $cohort->version_id)
            ->where(function ($query) use ($customerId): void {
                $query->whereNotNull('current_memberships.id')
                    ->orWhere(function ($query) use ($customerId): void {
                        $query->where('enrollments.status', 'active')
                            ->where('students.status', 'active')
                            ->whereNotExists(function ($query) use ($customerId): void {
                                $query->selectRaw('1')->from('core_course_cohort_students as memberships')
                                    ->whereColumn('memberships.enrollment_id', 'enrollments.id')
                                    ->where('memberships.customer_id', $customerId)
                                    ->where('memberships.status', 'active');
                            });
                    });
            })
            ->select(
                'enrollments.id',
                'enrollments.status',
                'enrollments.source',
                'enrollments.enrolled_at',
                'enrollments.access_starts_at',
                'enrollments.access_ends_at',
                'enrollments.review_starts_at',
                'enrollments.review_ends_at',
                'students.name as student_name',
                'students.email as student_email',
                'students.status as student_status',
                DB::raw('CASE WHEN current_memberships.id IS NULL THEN 0 ELSE 1 END as current_membership')
            );
    }

    private function formatEnrollmentDateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y H:i') : '—';
    }
}
