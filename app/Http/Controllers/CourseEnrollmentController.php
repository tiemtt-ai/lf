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
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

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
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');
        $source = $request->query('source');
        $productId = $request->integer('product_id') ?: null;
        $studentId = $request->integer('student_id') ?: null;
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
        if ($studentId && ! DB::table('users')
            ->where('customer_id', $customerId)
            ->where('id', $studentId)
            ->where('role', 'student')
            ->exists()) {
            $studentId = null;
        }
        if ($enrolledBy && ! DB::table('users')->where('customer_id', $customerId)->where('id', $enrolledBy)->exists()) {
            $enrolledBy = null;
        }

        $enrollments = DB::table('core_course_enrollments as enrollments')
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
            ->when($studentId, fn ($query) => $query->where('enrollments.student_id', $studentId))
            ->when($enrolledBy, fn ($query) => $query->where('enrollments.enrolled_by', $enrolledBy))
            ->when($enrolledFrom, fn ($query) => $query->where('enrollments.enrolled_at', '>=', $enrolledFrom.' 00:00:00'))
            ->when($enrolledTo, fn ($query) => $query->where('enrollments.enrolled_at', '<=', $enrolledTo.' 23:59:59'))
            ->orderByDesc('enrollments.created_at')
            ->orderByDesc('enrollments.id')
            ->select(
                'enrollments.*',
                'students.name as student_name',
                'students.email as student_email',
                'products.title as product_title',
                'products.product_code',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code',
                'versions.status as version_status'
            )
            ->paginate(10)
            ->withQueryString();

        $enrollments->setCollection($enrollments->getCollection()->map(function (object $enrollment): object {
            $learningWindowEndsAt = $enrollment->review_ends_at ?? $enrollment->access_ends_at;
            $enrollment->reactivation_eligible = $enrollment->status === 'suspended'
                && $enrollment->version_status === 'published'
                && ($learningWindowEndsAt === null || Carbon::parse($learningWindowEndsAt)->isFuture());

            return $enrollment;
        }));

        return view('course-enrollments.index', [
            'enrollments' => $enrollments,
            'keyword' => $keyword,
            'status' => $status,
            'source' => $source,
            'productId' => $productId, 'studentId' => $studentId, 'enrolledBy' => $enrolledBy,
            'enrolledFrom' => $enrolledFrom, 'enrolledTo' => $enrolledTo,
            'filterProducts' => DB::table('core_course_products')->where('customer_id', $customerId)->orderBy('title')->get(['id', 'title', 'product_code']),
            'filterStudents' => DB::table('users')->where('customer_id', $customerId)->where('role', 'student')->orderBy('name')->get(['id', 'name', 'email']),
            'filterCreators' => DB::table('users')->where('customer_id', $customerId)->whereIn('role', ['customer_admin', 'teacher'])->orderBy('name')->get(['id', 'name']),
            'routePrefix' => $this->routePrefix($request),
        ]);
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

        return view('course-enrollments.show', [
            'enrollment' => $enrollment,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function edit(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();

        $enrollment = $this->findEnrollment($customerId, $id);
        abort_if(in_array($enrollment->status, ['completed', 'expired', 'cancelled'], true), 422);

        return view('course-enrollments.edit', [
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

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_enrollment_common_updated'));
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
                'products.offering_type',
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
                'products.offering_type',
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
        $outsideRegistration = $hasRegistrationStart !== $hasRegistrationEnd
            || ($hasRegistrationStart && strtotime($product->registration_starts_at) >= strtotime($product->registration_ends_at))
            || ($hasRegistrationStart && $enrolledAt->timestamp < strtotime($product->registration_starts_at))
            || ($hasRegistrationEnd && $enrolledAt->timestamp > strtotime($product->registration_ends_at));

        return [
            'id' => $product->id,
            'title' => $product->title,
            'code' => $product->product_code,
            'offering_type' => $product->offering_type,
            'access_duration_days' => $product->access_duration_days,
            'review_duration_days' => $product->review_duration_days,
            'supports_review' => $product->offering_type === 'self_paced_course'
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
