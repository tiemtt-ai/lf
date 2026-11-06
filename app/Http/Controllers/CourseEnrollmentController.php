<?php

namespace App\Http\Controllers;

use App\Exceptions\BulkEnrollmentAtomicException;
use App\Http\Requests\BulkEnrollmentLifecycleRequest;
use App\Http\Requests\BulkEnrollmentPreflightRequest;
use App\Http\Requests\BulkEnrollmentRequest;
use App\Services\BulkEnrollmentPayload;
use App\Services\BulkEnrollmentService;
use App\Services\BulkEnrollmentSubmissionToken;
use App\Services\CourseEnrollmentLifecycleService;
use App\Services\EnrollmentCreationAction;
use App\Services\EnrollmentEligibilityPolicy;
use App\Support\CourseType;
use App\Support\TenantContext;
use App\Support\XlsxDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseEnrollmentController extends Controller
{
    private const STATUSES = [
        'pending',
        'active',
        'suspended',
        'completed',
        'expired',
        'cancelled',
    ];

    private const SOURCES = [
        'admin',
        'teacher',
        'self_registration',
        'purchase',
        'promotion',
        'import',
        'api',
    ];

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $filters = $this->listFilters($request, $customerId);
        ['keyword' => $keyword, 'status' => $status, 'source' => $source, 'productId' => $productId,
            'courseType' => $courseType, 'enrolledBy' => $enrolledBy, 'enrolledFrom' => $enrolledFrom,
            'enrolledTo' => $enrolledTo] = $filters;

        $enrollments = $this->listQuery($customerId, $filters)
            ->paginate(10)
            ->withQueryString();

        $enrollments->setCollection($enrollments->getCollection()->map(function (object $enrollment): object {
            $enrollment->learning_ended = $this->learningEnded($enrollment);
            $enrollment->reactivation_eligible = $enrollment->status === 'suspended'
                && $enrollment->version_status === 'published'
                && ! $enrollment->learning_ended;

            return $enrollment;
        }));

        return view('course-enrollments.index', [
            'enrollments' => $enrollments,
            'keyword' => $keyword,
            'status' => $status,
            'source' => $source,
            'courseType' => $courseType,
            'productId' => $productId, 'enrolledBy' => $enrolledBy,
            'enrolledFrom' => $enrolledFrom, 'enrolledTo' => $enrolledTo,
            'filterProducts' => DB::table('core_course_products')->where('customer_id', $customerId)->orderBy('title')->get(['id', 'title', 'product_code']),
            'filterCreators' => DB::table('users')->where('customer_id', $customerId)->whereIn('role', ['customer_admin', 'teacher'])->orderBy('name')->get(['id', 'name']),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** The list as .xlsx: the filters on screen, every page, this tenant only. */
    public function export(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $enrollments = $this->listQuery($customerId, $this->listFilters($request, $customerId))
            ->limit(XlsxDownload::MAX_ROWS)
            ->get();

        return XlsxDownload::response(
            [
                __('lf.table_no'), __('lf.LF_export_col_enrollment_id'), __('lf.LF_course_enrollment_common_student'),
                __('lf.LF_common_label_email'), __('lf.LF_common_label_phone'), __('lf.LF_course_enrollment_common_product'),
                __('lf.LF_export_col_product_code'), __('lf.LF_export_col_version'), __('lf.LF_course_enrollment_information_source'),
                __('lf.LF_course_enrollment_common_enrolled_at'), __('lf.LF_course_enrollment_common_access_starts_at'),
                __('lf.LF_course_enrollment_common_access_ends_at'), __('lf.LF_course_enrollment_common_status'),
            ],
            $enrollments->values()->map(fn (object $enrollment, int $index) => [
                $index + 1, $enrollment->id, $enrollment->student_name, $enrollment->student_email, $enrollment->student_phone,
                $enrollment->product_title, $enrollment->product_code, $enrollment->version_code,
                __('lf.LF_course_enrollment_common_source_'.$enrollment->source),
                XlsxDownload::dateTime($enrollment->enrolled_at), XlsxDownload::dateTime($enrollment->access_starts_at),
                XlsxDownload::dateTime($enrollment->access_ends_at), __('lf.LF_course_enrollment_common_'.$enrollment->status),
            ]),
            __('lf.LF_export_file_enrollments'),
            __('lf.LF_course_enrollment_common_title'),
        );
    }

    /** @return array<string, mixed> */
    private function listFilters(Request $request, int $customerId): array
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');
        $source = $request->query('source');
        $productId = $request->integer('product_id') ?: null;
        $courseType = $request->query('course_type');
        $enrolledBy = $request->integer('enrolled_by') ?: null;
        $enrolledFrom = $this->validDateFilter($request->query('enrolled_from'));
        $enrolledTo = $this->validDateFilter($request->query('enrolled_to'));

        if (! in_array($status, self::STATUSES, true)) {
            $status = null;
        }

        if (! in_array($source, self::SOURCES, true)) {
            $source = null;
        }
        if ($productId && ! DB::table('core_course_products')->where('customer_id', $customerId)->where('id', $productId)->exists()) {
            $productId = null;
        }
        if (! in_array($courseType, CourseType::VALUES, true)) {
            $courseType = null;
        }
        if ($enrolledBy && ! DB::table('users')->where('customer_id', $customerId)->where('id', $enrolledBy)->exists()) {
            $enrolledBy = null;
        }

        return compact('keyword', 'status', 'source', 'productId', 'courseType', 'enrolledBy', 'enrolledFrom', 'enrolledTo');
    }

    /** @param  array<string, mixed>  $filters */
    private function listQuery(int $customerId, array $filters)
    {
        ['keyword' => $keyword, 'status' => $status, 'source' => $source, 'productId' => $productId,
            'courseType' => $courseType, 'enrolledBy' => $enrolledBy, 'enrolledFrom' => $enrolledFrom,
            'enrolledTo' => $enrolledTo] = $filters;

        return DB::table('core_course_enrollments as enrollments')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'enrollments.student_id')
                    ->where('students.customer_id', '=', $customerId);
            })
            ->join('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'enrollments.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'enrollments.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('enrollments.customer_id', $customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('students.name', 'like', '%'.$keyword.'%')
                        ->orWhere('students.email', 'like', '%'.$keyword.'%')
                        ->orWhere('products.title', 'like', '%'.$keyword.'%')
                        ->orWhere('products.product_code', 'like', '%'.$keyword.'%')
                        ->orWhere('versions.version_code', 'like', '%'.$keyword.'%');
                    if (ctype_digit($keyword)) {
                        $query->orWhere('enrollments.id', (int) $keyword);
                    }
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('enrollments.status', $status);
            })
            ->when($source, function ($query) use ($source): void {
                $query->where('enrollments.source', $source);
            })
            ->when($productId, fn ($query) => $query->where('enrollments.product_id', $productId))
            ->when($courseType, fn ($query) => $query->where('versions.course_type_snapshot', $courseType))
            ->when($enrolledBy, fn ($query) => $query->where('enrollments.enrolled_by', $enrolledBy))
            ->when($enrolledFrom, fn ($query) => $query->where('enrollments.enrolled_at', '>=', $enrolledFrom.' 00:00:00'))
            ->when($enrolledTo, fn ($query) => $query->where('enrollments.enrolled_at', '<=', $enrolledTo.' 23:59:59'))
            ->orderByDesc('enrollments.created_at')
            ->orderByDesc('enrollments.id')
            ->select(
                'enrollments.*',
                'students.name as student_name',
                'students.email as student_email',
                'students.phone as student_phone',
                'products.title as product_title',
                'products.product_code',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code',
                'versions.status as version_status'
            );
    }

    public function bulkLifecycle(BulkEnrollmentLifecycleRequest $request, CourseEnrollmentLifecycleService $service)
    {
        $action = $request->validated('action');
        $count = $service->bulkTransition(
            $this->customerId(),
            $request->validated('enrollment_ids'),
            $action
        );

        return redirect()->back()->with(
            'success',
            __('lf.LF_course_enrollment_bulk_lifecycle_'.$action.'_success', ['count' => $count])
        );
    }

    public function create(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('course-enrollments.create', [
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function searchStudents(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('q', ''));
        $students = DB::table('users')
            ->where('customer_id', $customerId)
            ->where('role', 'student')
            ->where('status', 'active')
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('email', 'like', '%'.$keyword.'%');
                });
            })
            ->orderBy('name')->orderBy('id')
            ->paginate(10, ['id', 'name', 'email', 'status']);

        $studentItems = collect($students->items());
        $states = $request->integer('product_id') > 0
            ? $this->pairEnrollmentStates($customerId, $studentItems->pluck('id')->all(), [$request->integer('product_id')])
            : [];
        $data = $studentItems->map(function (object $student) use ($request, $states): array {
            $state = $states[$student->id.':'.$request->integer('product_id')] ?? 'none';

            return ['id' => $student->id, 'name' => $student->name, 'email' => $student->email,
                'account_status' => $student->status, 'enrollment_state' => $state];
        });

        return response()->json(['data' => $data, 'pagination' => [
            'current_page' => $students->currentPage(), 'last_page' => $students->lastPage(),
            'total' => $students->total(), 'per_page' => $students->perPage(),
        ]]);
    }

    public function searchProducts(
        Request $request,
        BulkEnrollmentPayload $payloads,
        BulkEnrollmentService $service
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $selection = Validator::make($request->query(), [
            'student_ids' => ['sometimes', 'array', 'max:100'],
            'student_ids.*' => ['integer', 'min:1', 'distinct'],
            'selected_product_ids' => ['sometimes', 'array', 'max:100'],
            'selected_product_ids.*' => ['integer', 'min:1', 'distinct'],
            'enrolled_at' => ['nullable', 'date'],
        ])->validate();
        $enrolledAt = filled($selection['enrolled_at'] ?? null)
            ? Carbon::parse($selection['enrolled_at'])
            : now();
        $studentIds = collect($selection['student_ids'] ?? [])->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values();
        $selectedProductIds = collect($selection['selected_product_ids'] ?? [])->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values();
        if ($studentIds->isNotEmpty()) {
            $eligibleStudentCount = DB::table('users')->where('customer_id', $customerId)
                ->whereIn('id', $studentIds)->where('role', 'student')->where('status', 'active')->count();
            abort_if($eligibleStudentCount !== $studentIds->count(), 422);
        }
        $keyword = trim((string) $request->query('q', ''));
        $productItems = $this->eligibleProductQuery($customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('products.title', 'like', '%'.$keyword.'%')
                        ->orWhere('products.product_code', 'like', '%'.$keyword.'%');
                });
            })
            ->orderBy('products.title')->orderBy('products.id')
            ->get();
        $states = $request->integer('student_id') > 0
            ? $this->pairEnrollmentStates($customerId, [$request->integer('student_id')], $productItems->pluck('id')->all())
            : [];
        $data = $productItems->map(function (object $product) use ($request, $states, $enrolledAt): array {
            $payload = $this->productSearchPayload($product, $enrolledAt);
            $payload['enrollment_state'] = $states[$request->integer('student_id').':'.$product->id] ?? 'none';

            return $payload;
        });

        $selectedEligibility = [];
        if ($studentIds->isNotEmpty()) {
            $eligibilityProductIds = $productItems->pluck('id')->merge($selectedProductIds)->unique()->sort()->values();
            $preflightPayload = $payloads->canonical($studentIds->all(), $eligibilityProductIds->all(), [], [
                'enrolled_at' => $enrolledAt->format('Y-m-d H:i:s'),
            ]);
            $preflight = $service->preflight($customerId, $preflightPayload);
            $pairsByProduct = collect($preflight['pairs'])->groupBy('product_id');
            $eligibility = $eligibilityProductIds->mapWithKeys(function (int $productId) use ($pairsByProduct, $studentIds): array {
                $pairs = $pairsByProduct->get($productId, collect());
                $invalidPairs = $pairs->filter(fn (array $pair): bool => in_array($pair['status'], ['ineligible', 'existing_non_terminal'], true));

                return [(string) $productId => [
                    'eligibility' => $invalidPairs->isEmpty() && $pairs->count() === $studentIds->count() ? 'eligible' : 'ineligible',
                    'valid_pair_count' => $pairs->count() - $invalidPairs->count(),
                    'invalid_pair_count' => $invalidPairs->count(),
                    'invalid_pairs' => $invalidPairs->map(fn (array $pair): array => [
                        'student_id' => $pair['student_id'],
                        'student_name' => $pair['student_name'],
                        'reason' => $pair['reason'],
                        'previous_enrollment_id' => $pair['previous_enrollment_id'],
                    ])->values()->all(),
                ]];
            });
            $selectedEligibility = $selectedProductIds->mapWithKeys(fn (int $id): array => [(string) $id => $eligibility->get((string) $id)])->all();
            $data = $data->map(function (array $product) use ($eligibility): array {
                return $product + $eligibility->get((string) $product['id']);
            })->values();
        }

        $eligible = $studentIds->isEmpty() ? $data : $data->where('eligibility', 'eligible')->values();
        $ineligible = $studentIds->isEmpty() ? collect() : $data->where('eligibility', 'ineligible')->values();
        $eligiblePage = max(1, $request->integer('page', 1));
        $ineligiblePage = max(1, $request->integer('ineligible_page', 1));

        return response()->json([
            'data' => $eligible->forPage($eligiblePage, 10)->values(),
            'pagination' => $this->collectionPagination($eligible->count(), $eligiblePage, 10),
            'ineligible' => [
                'data' => $ineligible->forPage($ineligiblePage, 10)->values(),
                'pagination' => $this->collectionPagination($ineligible->count(), $ineligiblePage, 10),
            ],
            'counts' => ['eligible' => $eligible->count(), 'ineligible' => $ineligible->count()],
            'selected_eligibility' => $selectedEligibility,
        ]);
    }

    public function bulkStore(
        BulkEnrollmentRequest $request,
        BulkEnrollmentPayload $payloads,
        BulkEnrollmentService $service
    ) {
        $customerId = $this->customerId();
        $payload = $payloads->canonical(
            $request->validated('student_ids'),
            $request->validated('product_ids'),
            $request->validated('reenrollment_confirmations', []),
            $request->enrollmentConfiguration()
        );
        try {
            $result = $service->commit(
                $customerId,
                (int) $request->user()->id,
                $request->validated('submission_token'),
                $payload
            );
        } catch (BulkEnrollmentAtomicException $exception) {
            return redirect()->route($this->routePrefix($request).'.create')
                ->withInput()->with('bulk_preflight', $exception->preflight)
                ->withErrors(['submission' => __('lf.LF_bulk_enrollment_atomic_failed')]);
        }

        $submissionId = $result['context']['submission_id'] ?? DB::table('core_course_enrollment_submissions')
            ->where('customer_id', $customerId)
            ->where('admin_id', (int) $request->user()->id)
            ->where('token_hash', hash('sha256', $request->validated('submission_token')))
            ->where('status', 'completed')
            ->value('id');

        return redirect()->route($this->routePrefix($request).'.bulk-result', ['submission' => $submissionId]);
    }

    public function bulkPreflight(
        BulkEnrollmentPreflightRequest $request,
        BulkEnrollmentPayload $payloads,
        BulkEnrollmentSubmissionToken $tokens,
        BulkEnrollmentService $service
    ): JsonResponse {
        $customerId = $this->customerId();
        $payload = $payloads->canonical(
            $request->validated('student_ids'),
            $request->validated('product_ids'),
            $request->validated('reenrollment_confirmations', []),
            $request->validated('configuration', [])
        );
        $result = $service->preflight($customerId, $payload);
        $result['can_continue'] = collect($result['pairs'])
            ->doesntContain(fn (array $pair): bool => in_array($pair['status'], ['ineligible', 'existing_non_terminal'], true));
        $result['submission_token'] = null;

        if ($request->boolean('finalize') && $result['valid']) {
            $result['submission_token'] = $tokens->issue($customerId, (int) $request->user()->id, $payload);
        }

        return response()->json($result);
    }

    public function bulkInvalidate(Request $request, BulkEnrollmentSubmissionToken $tokens): JsonResponse
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate(['submission_token' => ['nullable', 'string', 'size:64']]);
        $tokens->invalidate($this->customerId(), (int) $request->user()->id, $validated['submission_token'] ?? null);

        return response()->json(['invalidated' => true]);
    }

    public function bulkResult(Request $request): View
    {
        $this->authorizeAdmin($request);

        $submissionId = $request->integer('submission');
        abort_unless($submissionId > 0, 404);

        $submission = DB::table('core_course_enrollment_submissions')
            ->where('id', $submissionId)
            ->where('customer_id', $this->customerId())
            ->where('admin_id', (int) $request->user()->id)
            ->where('status', 'completed')
            ->whereNotNull('result')
            ->first(['result']);
        abort_unless($submission, 404);

        $result = json_decode($submission->result, true, flags: JSON_THROW_ON_ERROR);
        $items = collect($result['items'] ?? []);
        $lastPage = max(1, (int) ceil($items->count() / 10));
        $page = min(max(1, $request->integer('page', 1)), $lastPage);
        $itemsPaginator = new LengthAwarePaginator(
            $items->forPage($page, 10)->values(),
            $items->count(),
            10,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('course-enrollments.create', [
            'completedResult' => $result,
            'itemsPaginator' => $itemsPaginator,
            'studentEmails' => DB::table('users')->where('customer_id', $this->customerId())
                ->whereIn('id', $itemsPaginator->pluck('student_id'))->pluck('email', 'id'),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request, EnrollmentCreationAction $creation)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedCreateData($request, $customerId);
        $enrollmentId = $creation->create(
            $customerId,
            (int) $request->user()->id,
            $validated
        );

        return redirect()
            ->route($this->routePrefix($request).'.show', $enrollmentId)
            ->with('success', __('lf.LF_course_enrollment_common_created'));
    }

    public function show(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();

        $enrollment = $this->findEnrollment($customerId, $id);

        $withinWindow = $enrollment->version_status === 'published' && ! $this->learningEnded($enrollment);

        return view('course-enrollments.show', [
            'enrollment' => $enrollment,
            'learningState' => $this->learningState($enrollment),
            'canReactivate' => $withinWindow,
            'canActivate' => $withinWindow && $enrollment->product_status === 'active',
            'previousEnrollmentId' => DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->where('student_id', $enrollment->student_id)->where('product_id', $enrollment->product_id)
                ->where('id', '<', $enrollment->id)->whereIn('status', EnrollmentEligibilityPolicy::TERMINAL)
                ->max('id'),
            'enrolledByName' => $enrollment->enrolled_by
                ? DB::table('users')->where('customer_id', $customerId)->where('id', $enrollment->enrolled_by)->value('name')
                : null,
            'cohorts' => DB::table('core_course_cohort_students as memberships')
                ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                    $join->on('cohorts.id', '=', 'memberships.cohort_id')->where('cohorts.customer_id', $customerId);
                })
                ->where('memberships.customer_id', $customerId)
                ->where('memberships.enrollment_id', $enrollment->id)
                ->where('memberships.status', 'active')
                ->orderByDesc('memberships.id')
                ->get(['cohorts.id', 'cohorts.name', 'cohorts.status', 'cohorts.start_date', 'cohorts.end_date']),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** The edit form is a popup; a saved or typed link to it lands on the enrollment's own page. */
    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();

        $enrollment = $this->findEnrollment($customerId, $id);
        abort_if(in_array($enrollment->status, ['completed', 'expired', 'cancelled'], true), 422);

        if (! $request->ajax()) {
            return redirect()->route($this->routePrefix($request).'.show', $id);
        }

        return view('course-enrollments.dialog', [
            'enrollment' => $enrollment,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function update(Request $request, int $id, CourseEnrollmentLifecycleService $enrollmentPolicy)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findEnrollment($customerId, $id);
        $validated = $this->validatedUpdateData($request);

        DB::transaction(function () use ($customerId, $id, $validated, $enrollmentPolicy): void {
            $enrollment = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $enrollment, 404);
            abort_if(! in_array($enrollment->status, ['pending', 'active', 'suspended'], true), 422);
            $updates = [
                'notes' => $validated['notes'] ?? null,
                'updated_at' => now(),
            ];
            if (isset($validated['enrolled_at'])
                && ! Carbon::parse($validated['enrolled_at'])->equalTo(Carbon::parse($enrollment->enrolled_at))) {
                $enrolledAt = Carbon::parse($validated['enrolled_at']);
                $timeWindows = $enrollmentPolicy->reprojectEnrollment($enrollment, $enrolledAt);
                $updates = array_merge($updates, [
                    'enrolled_at' => $enrolledAt,
                    'access_starts_at' => $timeWindows['access_starts_at'],
                    'access_ends_at' => $timeWindows['access_ends_at'],
                    'review_starts_at' => $timeWindows['review_starts_at'],
                    'review_ends_at' => $timeWindows['review_ends_at'],
                ]);
            }
            DB::table('core_course_enrollments')->where('customer_id', $customerId)->where('id', $id)->update($updates);
        }, 3);

        $saved = redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_enrollment_common_updated'));

        // The popup reloads the page it was opened from, which shows the flashed message.
        return $request->expectsJson()
            ? response()->json(['message' => __('lf.LF_course_enrollment_common_updated'), 'redirect' => url()->previous()])
            : $saved;
    }

    public function activate(Request $request, int $id, CourseEnrollmentLifecycleService $service)
    {
        return $this->runLifecycle($request, $id, fn () => $service->activate($this->customerId(), $id), 'activated');
    }

    public function suspend(Request $request, int $id, CourseEnrollmentLifecycleService $service)
    {
        return $this->runLifecycle($request, $id, fn () => $service->suspend($this->customerId(), $id), 'suspended');
    }

    public function reactivate(Request $request, int $id, CourseEnrollmentLifecycleService $service)
    {
        return $this->runLifecycle($request, $id, fn () => $service->reactivate($this->customerId(), $id), 'reactivated');
    }

    public function cancel(Request $request, int $id, CourseEnrollmentLifecycleService $service)
    {
        return $this->runLifecycle($request, $id, fn () => $service->cancel($this->customerId(), $id), 'cancelled');
    }

    private function validatedCreateData(Request $request, int $customerId): array
    {
        $validator = Validator::make($request->all(), [
            'student_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'access_starts_at' => ['prohibited'],
            'access_ends_at' => ['prohibited'],
            'review_starts_at' => ['prohibited'],
            'review_ends_at' => ['prohibited'],
            'notes' => ['nullable', 'string'],
            'customer_id' => ['prohibited'],
            'version_id' => ['prohibited'],
            'source' => ['prohibited'],
            'source_id' => ['prohibited'],
            'status' => ['prohibited'],
            'enrolled_at' => ['nullable', 'date'],
        ]);

        $validator->after(function ($validator) use ($request, $customerId): void {
            $studentId = (int) $request->input('student_id');
            $productId = (int) $request->input('product_id');

            if ($studentId > 0 && ! $this->studentExists($customerId, $studentId)) {
                $validator->errors()->add(
                    'student_id',
                    __('lf.LF_course_enrollment_validation_student')
                );
            }

            if ($productId > 0 && ! $this->productExists($customerId, $productId)) {
                $validator->errors()->add(
                    'product_id',
                    __('lf.LF_course_enrollment_validation_product')
                );
            }

        });

        return $validator->validate();
    }

    private function validatedUpdateData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'status' => ['prohibited'],
            'access_starts_at' => ['prohibited'],
            'access_ends_at' => ['prohibited'],
            'review_starts_at' => ['prohibited'],
            'review_ends_at' => ['prohibited'],
            'enrolled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $this->rejectImmutableInputs($validator, $request, [
                'customer_id',
                'student_id',
                'product_id',
                'version_id',
            ]);
        });

        return $validator->validate();
    }

    private function runLifecycle(Request $request, int $id, callable $transition, string $messageKey)
    {
        $this->authorizeAdmin($request);
        $transition();

        return redirect()->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_enrollment_lifecycle_'.$messageKey));
    }

    private function rejectImmutableInputs(
        $validator,
        Request $request,
        array $fields
    ): void {
        foreach ($fields as $field) {
            if ($request->has($field) && $request->input($field) !== null) {
                $validator->errors()->add(
                    $field,
                    in_array($field, ['product_id', 'version_id'], true)
                        ? __('lf.LF_course_enrollment_validation_binding_immutable')
                        : __('lf.LF_course_enrollment_validation_immutable')
                );
            }
        }
    }

    /** An open Enrollment whose learning window has passed: the status stays as stored, access is already over. */
    private function learningEnded(object $enrollment): bool
    {
        $endsAt = $enrollment->review_ends_at ?? $enrollment->access_ends_at;

        return in_array($enrollment->status, EnrollmentEligibilityPolicy::NON_TERMINAL, true)
            && $endsAt !== null && Carbon::parse($endsAt)->isPast();
    }

    private function learningState(object $enrollment): ?string
    {
        if (! in_array($enrollment->status, EnrollmentEligibilityPolicy::NON_TERMINAL, true) || ! $enrollment->access_ends_at) {
            return null;
        }

        return match (true) {
            $this->learningEnded($enrollment) => 'ended',
            Carbon::parse($enrollment->access_starts_at)->isFuture() => 'upcoming',
            Carbon::parse($enrollment->access_ends_at)->isPast() => 'review',
            default => 'learning',
        };
    }

    private function findEnrollment(int $customerId, int $id): object
    {
        $enrollment = DB::table('core_course_enrollments as enrollments')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'enrollments.student_id')
                    ->where('students.customer_id', '=', $customerId);
            })
            ->join('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'enrollments.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'enrollments.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('enrollments.customer_id', $customerId)
            ->where('enrollments.id', $id)
            ->select(
                'enrollments.*',
                'students.name as student_name',
                'students.email as student_email',
                'products.title as product_title',
                'products.product_code',
                'products.status as product_status',
                'versions.status as version_status',
                'versions.course_type_snapshot as course_type',
                'products.registration_starts_at',
                'products.registration_ends_at',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code'
            )
            ->first();

        abort_if(! $enrollment, 404);

        return $enrollment;
    }

    private function studentRecord(int $customerId, int $studentId): ?object
    {
        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('id', $studentId)
            ->where('role', 'student')
            ->where('status', 'active')
            ->select('id', 'name', 'email')
            ->first();
    }

    private function eligibleProductQuery(int $customerId)
    {
        return DB::table('core_course_products as products')
            ->join('core_course_product_items as items', function ($join) use ($customerId): void {
                $join->on('items.product_id', '=', 'products.id')
                    ->where('items.customer_id', $customerId)
                    ->where('items.status', 'active');
            })
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', $customerId)
                    ->where('versions.status', 'published');
            })
            ->join('core_course_templates as templates', function ($join) use ($customerId): void {
                $join->on('templates.id', '=', 'items.template_id')
                    ->where('templates.customer_id', $customerId);
            })
            ->where('products.customer_id', $customerId)
            ->where('products.status', 'active')
            ->whereColumn('versions.template_id', 'items.template_id')
            ->whereRaw('(select count(*) from core_course_product_items active_items where active_items.customer_id = products.customer_id and active_items.product_id = products.id and active_items.status = ?) = 1', ['active'])
            ->select(
                'products.id',
                'products.title',
                'products.product_code',
                'versions.course_type_snapshot as course_type',
                'products.access_duration_days',
                'products.review_duration_days',
                'products.registration_starts_at',
                'products.registration_ends_at',
                'versions.id as version_id',
                'versions.version_code',
                'versions.version_number',
                'versions.lesson_count_snapshot as lesson_count',
                DB::raw('(select count(*) from core_course_template_version_activities activities where activities.customer_id = versions.customer_id and activities.template_version_id = versions.id) as activity_count')
            );
    }

    private function productSearchPayload(object $product, ?Carbon $enrolledAt = null): array
    {
        $enrolledAt ??= now();
        $hasRegistrationStart = filled($product->registration_starts_at);
        $hasRegistrationEnd = filled($product->registration_ends_at);
        $registrationStart = $hasRegistrationStart ? Carbon::parse($product->registration_starts_at) : null;
        $registrationEnd = $hasRegistrationEnd ? Carbon::parse($product->registration_ends_at) : null;
        $registrationStatus = match (true) {
            ! $hasRegistrationStart && ! $hasRegistrationEnd => 'unlimited',
            $hasRegistrationStart !== $hasRegistrationEnd
                || $registrationStart->greaterThanOrEqualTo($registrationEnd) => 'invalid',
            $enrolledAt->lt($registrationStart) => 'not_open',
            $enrolledAt->gt($registrationEnd) => 'ended',
            default => 'open',
        };
        $outsideRegistration = in_array($registrationStatus, ['invalid', 'not_open', 'ended'], true);

        return [
            'id' => $product->id,
            'title' => $product->title,
            'code' => $product->product_code,
            'course_type' => $product->course_type,
            'access_duration_days' => $product->access_duration_days,
            'review_duration_days' => $product->review_duration_days,
            'registration_window' => [
                'starts_at' => $registrationStart?->toIso8601String(),
                'ends_at' => $registrationEnd?->toIso8601String(),
                'status' => $registrationStatus,
            ],
            'supports_review' => CourseType::usesAccessDuration($product->course_type)
                && (int) $product->review_duration_days > 0,
            'outside_registration_window' => $outsideRegistration,
            'version' => [
                'code' => $product->version_code,
                'number' => (int) $product->version_number,
                'status' => __('lf.LF_course_enrollment_version_published'),
                'lesson_count' => (int) $product->lesson_count,
                'activity_count' => (int) $product->activity_count,
            ],
        ];
    }

    private function collectionPagination(int $total, int $currentPage, int $perPage): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($currentPage, $lastPage);

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'total' => $total,
            'per_page' => $perPage,
        ];
    }

    private function pairEnrollmentStates(int $customerId, array $studentIds, array $productIds): array
    {
        $states = [];
        $rows = DB::table('core_course_enrollments')->where('customer_id', $customerId)
            ->whereIn('student_id', $studentIds)->whereIn('product_id', $productIds)
            ->get(['student_id', 'product_id', 'status']);
        foreach ($rows->groupBy(fn (object $row): string => $row->student_id.':'.$row->product_id) as $key => $enrollments) {
            $states[$key] = $enrollments->contains(
                fn (object $row): bool => in_array($row->status, ['pending', 'active', 'suspended'], true)
            ) ? 'existing' : 'terminal';
        }

        return $states;
    }

    private function studentExists(int $customerId, int $studentId): bool
    {
        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('id', $studentId)
            ->where('role', 'student')
            ->where('status', 'active')
            ->exists();
    }

    private function productExists(int $customerId, int $productId): bool
    {
        return DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $productId)
            ->where('status', 'active')
            ->exists();
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function validDateFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.course-enrollments',
            default => abort(403),
        };
    }
}
