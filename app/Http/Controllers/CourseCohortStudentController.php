<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');
        $cohortId = (int) $request->query('cohort_id', 0);
        $contextCohort = $cohortId > 0 ? $this->findCohortRecord($customerId, $cohortId) : null;

        if (! in_array($status, self::STATUSES, true)) {
            $status = null;
        }

        $memberships = DB::table('core_course_cohort_students as memberships')
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
            ->join('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'memberships.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->where('memberships.customer_id', $customerId)
            ->when($contextCohort, fn ($query) => $query->where('memberships.cohort_id', $contextCohort->id))
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('cohorts.name', 'like', '%'.$keyword.'%')
                        ->orWhere('cohorts.code', 'like', '%'.$keyword.'%')
                        ->orWhere('students.name', 'like', '%'.$keyword.'%')
                        ->orWhere('students.email', 'like', '%'.$keyword.'%')
                        ->orWhere('products.title', 'like', '%'.$keyword.'%')
                        ->orWhere('products.product_code', 'like', '%'.$keyword.'%');
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('memberships.status', $status);
            })
            ->orderByDesc('memberships.joined_at')
            ->orderByDesc('memberships.id')
            ->select(
                'memberships.*',
                'cohorts.name as cohort_name',
                'cohorts.code as cohort_code',
                'students.name as student_name',
                'students.email as student_email',
                'products.title as product_title',
                'products.product_code'
            )
            ->paginate(10)
            ->withQueryString();

        return view('course-cohort-students.index', [
            'memberships' => $memberships,
            'keyword' => $keyword,
            'status' => $status,
            'contextCohort' => $contextCohort,
            'canAddStudents' => $contextCohort && ! $this->cohortEligibilityError($customerId, $contextCohort),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function create(Request $request, ?int $cohort = null): View|RedirectResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();

        if (! $cohort) {
            $legacyCohort = (int) $request->query('cohort_id', 0);

            return $legacyCohort > 0
                ? redirect()->route('admin.course-cohorts.students.create', $legacyCohort)
                : redirect()->route('admin.course-cohorts.index')
                    ->with('error', __('lf.LF_course_cohort_student_validation_context_required'));
        }

        $contextCohort = $this->cohortSummary($customerId, $cohort);
        $eligibilityError = $this->cohortEligibilityError($customerId, $contextCohort);

        if ($eligibilityError) {
            return redirect()->route('admin.course-cohorts.show', ['id' => $contextCohort->id, 'tab' => 'students'])
                ->with('error', $eligibilityError);
        }

        $oldEnrollmentIds = array_filter(array_map('intval', (array) old('enrollment_ids', [])));
        $selectedEnrollments = $oldEnrollmentIds
            ? $this->manageableEnrollmentsQuery($customerId, $contextCohort)
                ->whereIn('enrollments.id', $oldEnrollmentIds)->get()
            : collect();

        return view('course-cohort-students.create', [
            'cohort' => $contextCohort,
            'selectedEnrollments' => $selectedEnrollments,
            'eligibleEnrollmentCount' => $this->eligibleEnrollmentsQuery($customerId, $contextCohort)->count(),
            'mode' => 'create',
        ]);
    }

    public function students(Request $request, int $cohort): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->cohortSummary($this->customerId(), $cohort);

        return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'students']);
    }

    public function manage(Request $request, int $cohort): View|RedirectResponse
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
                ? (! in_array($contextCohort->status, ['draft', 'active'], true) || ! $contextCohort->product_id || ! $contextCohort->version_id)
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
            'eligible' => $enrollment->status === 'active' && ($enrollment->student_status ?? 'active') === 'active',
        ]);

        return response()->json(['data' => $data, 'pagination' => [
            'current_page' => $enrollments->currentPage(),
            'last_page' => $enrollments->lastPage(),
            'total' => $enrollments->total(),
            'per_page' => $enrollments->perPage(),
        ]]);
    }

    public function store(Request $request, ?int $cohort = null): RedirectResponse
    {
        $this->authorizeAdmin($request);

        if (! $cohort) {
            return redirect()->route('admin.course-cohorts.index')
                ->with('error', __('lf.LF_course_cohort_student_validation_context_required'));
        }

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

                $enrollmentIds = array_values(array_unique(array_map('intval', $validated['enrollment_ids'])));
                sort($enrollmentIds);

                $enrollments = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                    ->whereIn('id', $enrollmentIds)->lockForUpdate()->get()->keyBy('id');
                $existingMemberships = DB::table('core_course_cohort_students')
                    ->where('customer_id', $customerId)
                    ->whereIn('enrollment_id', $enrollmentIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('enrollment_id');

                $now = now();
                $rows = [];
                $acceptedCount = 0;

                foreach ($enrollmentIds as $index => $enrollmentId) {
                    $enrollment = $enrollments->get($enrollmentId);
                    throw_if(! $enrollment, ValidationException::withMessages([
                        "enrollment_ids.{$index}" => __('lf.LF_course_cohort_student_validation_enrollment'),
                    ]));
                    $this->throwIfAssignmentIssue($customerId, $cohort, $enrollment, true, $acceptedCount, "enrollment_ids.{$index}");
                    $existingMembership = $existingMemberships->get($enrollmentId);

                    if ($existingMembership) {
                        DB::table('core_course_cohort_students')
                            ->where('customer_id', $customerId)
                            ->where('id', $existingMembership->id)
                            ->update([
                                'cohort_id' => $cohort->id,
                                'product_id' => $enrollment->product_id,
                                'student_id' => $enrollment->student_id,
                                'assigned_by' => $request->user()?->id,
                                'joined_at' => $now,
                                'left_at' => null,
                                'status' => 'active',
                                'transfer_from_cohort_id' => (int) $existingMembership->cohort_id !== (int) $cohort->id
                                    ? $existingMembership->cohort_id
                                    : $existingMembership->transfer_from_cohort_id,
                                'transfer_reason' => null,
                                'note' => $validated['note'] ?? null,
                                'updated_at' => $now,
                            ]);
                        $acceptedCount++;

                        continue;
                    }

                    $rows[] = [
                        'customer_id' => $customerId, 'cohort_id' => $cohort->id,
                        'enrollment_id' => $enrollment->id, 'product_id' => $enrollment->product_id,
                        'student_id' => $enrollment->student_id, 'assigned_by' => $request->user()?->id,
                        'joined_at' => $now, 'left_at' => null, 'status' => 'active',
                        'transfer_from_cohort_id' => null, 'transfer_reason' => null,
                        'note' => $validated['note'] ?? null, 'metadata' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                    $acceptedCount++;
                }

                DB::table('core_course_cohort_students')->insert($rows);
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
                ! $lockedCohort || ! in_array($lockedCohort->status, ['draft', 'active'], true)
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
            $currentIds = $currentMemberships->keys()->map(fn ($id) => (int) $id)->all();
            $addIds = array_values(array_diff($selectedIds, $currentIds));
            $removeIds = array_values(array_diff($currentIds, $selectedIds));

            if ($lockedCohort->capacity !== null && count($selectedIds) > (int) $lockedCohort->capacity) {
                throw ValidationException::withMessages([
                    'enrollment_ids' => __('lf.LF_course_cohort_student_validation_capacity'),
                ]);
            }

            $enrollments = DB::table('core_course_enrollments')
                ->where('customer_id', $customerId)->whereIn('id', $addIds)
                ->lockForUpdate()->get()->keyBy('id');
            $existingMemberships = DB::table('core_course_cohort_students')
                ->where('customer_id', $customerId)
                ->whereIn('enrollment_id', $addIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('enrollment_id');
            $now = now();
            $rows = [];

            foreach ($addIds as $index => $enrollmentId) {
                $enrollment = $enrollments->get($enrollmentId);
                throw_if(! $enrollment, ValidationException::withMessages([
                    "enrollment_ids.{$index}" => __('lf.LF_course_cohort_student_validation_enrollment'),
                ]));
                $this->throwIfAssignmentIssue($customerId, $lockedCohort, $enrollment, false, null, "enrollment_ids.{$index}");
                $existingMembership = $existingMemberships->get($enrollmentId);

                if ($existingMembership) {
                    DB::table('core_course_cohort_students')
                        ->where('customer_id', $customerId)
                        ->where('id', $existingMembership->id)
                        ->update([
                            'cohort_id' => $cohort,
                            'product_id' => $enrollment->product_id,
                            'student_id' => $enrollment->student_id,
                            'assigned_by' => $request->user()?->id,
                            'joined_at' => $now,
                            'left_at' => null,
                            'status' => 'active',
                            'transfer_from_cohort_id' => (int) $existingMembership->cohort_id !== $cohort
                                ? $existingMembership->cohort_id
                                : $existingMembership->transfer_from_cohort_id,
                            'transfer_reason' => null,
                            'updated_at' => $now,
                        ]);

                    continue;
                }

                $rows[] = [
                    'customer_id' => $customerId, 'cohort_id' => $cohort,
                    'enrollment_id' => $enrollment->id, 'product_id' => $enrollment->product_id,
                    'student_id' => $enrollment->student_id, 'assigned_by' => $request->user()?->id,
                    'joined_at' => $now, 'left_at' => null, 'status' => 'active',
                    'transfer_from_cohort_id' => null, 'transfer_reason' => null,
                    'note' => null, 'metadata' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }

            if ($removeIds) {
                DB::table('core_course_cohort_students')
                    ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                    ->where('status', 'active')->whereIn('enrollment_id', $removeIds)
                    ->update(['status' => 'removed', 'left_at' => $now, 'updated_at' => $now]);
            }

            if ($rows) {
                DB::table('core_course_cohort_students')->insert($rows);
            }
        }, 3);

        return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'students'])
            ->with('success', __('lf.LF_course_cohort_student_sync_updated'));
    }

    public function show(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        return view('course-cohort-students.show', [
            'membership' => $this->findMembership($this->customerId(), $id),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function edit(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $membership = $this->findMembership($customerId, $id);
        abort_if($membership->status !== 'active', 422);

        return view('course-cohort-students.edit', [
            'membership' => $membership,
            'cohorts' => $this->cohorts($customerId),
            'statuses' => ['active'],
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $membership = $this->findMembership($customerId, $id);
        abort_if($membership->status !== 'active', 422);
        $validated = $this->validatedData($request, $customerId, $membership);
        DB::transaction(function () use ($customerId, $validated, $membership, $id): void {
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
            abort_if(! $source || ! $target || ! $enrollment || ! in_array($source->status, ['draft', 'active'], true), 422);
            $this->assertAssignmentAllowed($customerId, $target, $enrollment, $id);

            DB::table('core_course_cohort_students')->where('customer_id', $customerId)->where('id', $id)->update([
                'cohort_id' => $target->id, 'joined_at' => $validated['joined_at'],
                'left_at' => null, 'status' => 'active',
                'transfer_from_cohort_id' => $target->id !== $source->id ? $source->id : $lockedMembership->transfer_from_cohort_id,
                'transfer_reason' => $validated['transfer_reason'] ?? null,
                'note' => $validated['note'] ?? null, 'updated_at' => now(),
            ]);
        }, 3);

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_student_common_updated'));
    }

    public function archive(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        DB::transaction(function () use ($customerId, $id): void {
            $membership = DB::table('core_course_cohort_students')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $membership, 404);
            $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $membership->cohort_id)->lockForUpdate()->first();
            abort_if(! $cohort || ! in_array($cohort->status, ['draft', 'active'], true) || $membership->status !== 'active', 422);
            DB::table('core_course_cohort_students')->where('customer_id', $customerId)->where('id', $id)
                ->update(['status' => 'removed', 'left_at' => now(), 'updated_at' => now()]);
        }, 3);

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_student_common_archived_message'));
    }

    private function validatedData(Request $request, int $customerId, ?object $membership = null): array
    {
        $validator = Validator::make($request->all(), [
            'cohort_id' => ['required', 'integer', 'min:1'],
            'enrollment_id' => [$membership ? 'nullable' : 'required', 'integer', 'min:1'],
            'joined_at' => ['required', 'date'],
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

            if ($cohort && ! in_array($cohort->status, ['draft', 'active'], true)) {
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

            if ($enrollment && $enrollment->status !== 'active') {
                $validator->errors()->add('enrollment_id', __('lf.LF_course_cohort_student_validation_active_enrollment'));
            }

            if (! $membership && $enrollment && $this->membershipExists($customerId, $enrollment->id)) {
                $validator->errors()->add(
                    'enrollment_id',
                    __('lf.LF_course_cohort_student_validation_duplicate')
                );
            }

            if ($cohort && $enrollment) {
                if ($cohort->product_id && (int) $cohort->product_id !== (int) $enrollment->product_id) {
                    $validator->errors()->add(
                        'enrollment_id',
                        __('lf.LF_course_cohort_student_validation_product_mismatch')
                    );
                }

                if ($cohort->version_id && (int) $cohort->version_id !== (int) $enrollment->version_id) {
                    $validator->errors()->add(
                        'enrollment_id',
                        __('lf.LF_course_cohort_student_validation_version_mismatch')
                    );
                }

                if ($this->cohortIsFull($customerId, $cohort, $membership?->id)) {
                    $validator->errors()->add(
                        'cohort_id',
                        __('lf.LF_course_cohort_student_validation_capacity')
                    );
                }
            }
        });

        return $validator->validate();
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

    private function membershipExists(int $customerId, int $enrollmentId): bool
    {
        return DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where('enrollment_id', $enrollmentId)
            ->exists();
    }

    private function activeMembershipExists(int $customerId, int $enrollmentId): bool
    {
        return DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where('enrollment_id', $enrollmentId)
            ->where('status', 'active')
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
        return in_array($cohort->status, ['draft', 'active'], true) && $cohort->product_id && $cohort->version_id;
    }

    private function assertAssignmentAllowed(int $customerId, object $cohort, object $enrollment, ?int $ignoreMembershipId = null): void
    {
        abort_if(! $this->cohortAcceptsMembership($cohort), 422,
            __('lf.LF_course_cohort_student_validation_active_cohort'));
        abort_if($enrollment->status !== 'active', 422,
            __('lf.LF_course_cohort_student_validation_active_enrollment'));
        abort_if((int) $cohort->product_id !== (int) $enrollment->product_id, 422,
            __('lf.LF_course_cohort_student_validation_product_mismatch'));
        abort_if((int) $cohort->version_id !== (int) $enrollment->version_id, 422,
            __('lf.LF_course_cohort_student_validation_version_mismatch'));
        abort_if($this->cohortIsFull($customerId, $cohort, $ignoreMembershipId), 422,
            __('lf.LF_course_cohort_student_validation_capacity'));
    }

    /**
     * Shared by store() (per-enrollment, batch capacity via $extraAccepted, cohort
     * eligibility not yet checked) and sync() (per-enrollment, cohort eligibility and
     * capacity already validated once for the whole batch by the caller).
     */
    private function assignmentIssue(int $customerId, object $cohort, object $enrollment, bool $checkEligibility, ?int $extraAccepted = null): ?string
    {
        return match (true) {
            $checkEligibility && ! $this->cohortAcceptsMembership($cohort) => __('lf.LF_course_cohort_student_validation_active_cohort'),
            $extraAccepted !== null && $this->cohortIsFull($customerId, $cohort, null, $extraAccepted) => __('lf.LF_course_cohort_student_validation_capacity'),
            $enrollment->status !== 'active' => __('lf.LF_course_cohort_student_validation_active_enrollment'),
            ! DB::table('users')->where('customer_id', $customerId)->where('id', $enrollment->student_id)
                ->where('role', 'student')->where('status', 'active')->lockForUpdate()->first(['id']) => __('lf.LF_course_cohort_student_validation_active_student'),
            (int) $cohort->product_id !== (int) $enrollment->product_id => __('lf.LF_course_cohort_student_validation_product_mismatch'),
            (int) $cohort->version_id !== (int) $enrollment->version_id => __('lf.LF_course_cohort_student_validation_version_mismatch'),
            $this->activeMembershipExists($customerId, $enrollment->id) => __('lf.LF_course_cohort_student_validation_duplicate'),
            default => null,
        };
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
            ->whereNotExists(function ($query) use ($customerId): void {
                $query->selectRaw('1')
                    ->from('core_course_cohort_students as memberships')
                    ->whereColumn('memberships.enrollment_id', 'enrollments.id')
                    ->where('memberships.customer_id', $customerId)
                    ->where('memberships.status', 'active');
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

    private function cohorts(int $customerId)
    {
        return DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'archived')
            ->orderBy('name')
            ->select('id', 'name', 'code', 'product_id', 'version_id', 'status', 'capacity')
            ->get();
    }

    private function enrollments(int $customerId)
    {
        return DB::table('core_course_enrollments as enrollments')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'enrollments.student_id')
                    ->where('students.customer_id', '=', $customerId);
            })
            ->join('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'enrollments.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->where('enrollments.customer_id', $customerId)
            ->orderBy('students.name')
            ->orderBy('enrollments.id')
            ->select(
                'enrollments.id',
                'enrollments.product_id',
                'enrollments.version_id',
                'students.name as student_name',
                'students.email as student_email',
                'products.title as product_title',
                'products.product_code'
            )
            ->get();
    }
}
