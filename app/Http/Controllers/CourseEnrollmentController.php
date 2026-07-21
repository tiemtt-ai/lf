<?php

namespace App\Http\Controllers;

use App\Services\CourseEnrollmentLifecycleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if (! in_array($status, self::STATUSES, true)) {
            $status = null;
        }

        if (! in_array($source, self::SOURCES, true)) {
            $source = null;
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
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('enrollments.status', $status);
            })
            ->when($source, function ($query) use ($source): void {
                $query->where('enrollments.source', $source);
            })
            ->orderByDesc('enrollments.enrolled_at')
            ->orderByDesc('enrollments.id')
            ->select(
                'enrollments.*',
                'students.name as student_name',
                'students.email as student_email',
                'products.title as product_title',
                'products.product_code',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code'
            )
            ->paginate(10)
            ->withQueryString();

        return view('course-enrollments.index', [
            'enrollments' => $enrollments,
            'keyword' => $keyword,
            'status' => $status,
            'source' => $source,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();

        return view('course-enrollments.create', [
            'selectedStudent' => old('student_id') ? $this->studentRecord($customerId, (int) old('student_id')) : null,
            'selectedProduct' => old('product_id')
                ? optional($this->eligibleProductQuery($customerId)->where('products.id', (int) old('product_id'))->first(), fn (object $product): array => $this->productSearchPayload($product))
                : null,
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
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $students]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('q', ''));
        $products = $this->eligibleProductQuery($customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('products.title', 'like', '%'.$keyword.'%')
                        ->orWhere('products.product_code', 'like', '%'.$keyword.'%');
                });
            })
            ->orderBy('products.title')
            ->limit(20)
            ->get()
            ->map(fn (object $product): array => $this->productSearchPayload($product));

        return response()->json(['data' => $products]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedCreateData($request, $customerId);
        $now = now();

        $enrollmentId = DB::transaction(function () use (
            $request,
            $customerId,
            $validated,
            $now,
        ): int {
            DB::table('core_course_products')
                ->where('customer_id', $customerId)
                ->where('id', $validated['product_id'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail(['id']);
            $version = $this->resolveVersion(
                $customerId,
                (int) $validated['product_id'],
                true
            );
            $id = DB::table('core_course_enrollments')->insertGetId([
                'customer_id' => $customerId,
                'product_id' => $validated['product_id'],
                'version_id' => $version->id,
                'student_id' => $validated['student_id'],
                'source' => 'admin',
                'source_id' => null,
                'enrolled_by' => $request->user()?->id,
                'enrolled_at' => $now,
                'access_starts_at' => $validated['access_starts_at'] ?? null,
                'access_ends_at' => $validated['access_ends_at'] ?? null,
                'review_starts_at' => $validated['review_starts_at'] ?? null,
                'review_ends_at' => $validated['review_ends_at'] ?? null,
                'status' => 'active',
                'notes' => $validated['notes'] ?? null,
                'completed_at' => null,
                'cancelled_at' => null,
                'expired_at' => null,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('core_course_products')
                ->where('customer_id', $customerId)
                ->where('id', $validated['product_id'])
                ->increment('enrollment_count');

            return $id;
        });

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

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findEnrollment($customerId, $id);
        $validated = $this->validatedUpdateData($request);

        DB::transaction(function () use ($customerId, $id, $validated): void {
            $enrollment = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $enrollment, 404);
            abort_if(! in_array($enrollment->status, ['pending', 'active', 'suspended'], true), 422);
            DB::table('core_course_enrollments')->where('customer_id', $customerId)->where('id', $id)->update([
                'access_starts_at' => $validated['access_starts_at'] ?? null,
                'access_ends_at' => $validated['access_ends_at'] ?? null,
                'review_starts_at' => $validated['review_starts_at'] ?? null,
                'review_ends_at' => $validated['review_ends_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'updated_at' => now(),
            ]);
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
            'access_starts_at' => ['nullable', 'date'],
            'access_ends_at' => ['nullable', 'date', 'after_or_equal:access_starts_at'],
            'review_starts_at' => ['nullable', 'date'],
            'review_ends_at' => ['nullable', 'date', 'after_or_equal:review_starts_at'],
            'notes' => ['nullable', 'string'],
            'customer_id' => ['prohibited'],
            'version_id' => ['prohibited'],
            'source' => ['prohibited'],
            'source_id' => ['prohibited'],
            'status' => ['prohibited'],
            'enrolled_at' => ['prohibited'],
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

            if ($productId > 0 && $this->productExists($customerId, $productId)) {
                $version = $this->resolvedVersionCandidate($customerId, $productId);

                if (! $version) {
                    $validator->errors()->add(
                        'product_id',
                        __('lf.LF_course_enrollment_validation_active_item')
                    );

                    return;
                }

                if ($version->version_status !== 'published') {
                    $validator->errors()->add(
                        'product_id',
                        __('lf.LF_course_enrollment_validation_published_version')
                    );
                }
            }
        });

        return $validator->validate();
    }

    private function validatedUpdateData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'status' => ['prohibited'],
            'access_starts_at' => ['nullable', 'date'],
            'access_ends_at' => ['nullable', 'date', 'after_or_equal:access_starts_at'],
            'review_starts_at' => ['nullable', 'date'],
            'review_ends_at' => ['nullable', 'date', 'after_or_equal:review_starts_at'],
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
                    __('lf.LF_course_enrollment_validation_immutable')
                );
            }
        }
    }

    private function resolveVersion(int $customerId, int $productId, bool $lock = false): object
    {
        $version = $this->resolvedVersionCandidate($customerId, $productId, $lock);

        abort_if(! $version || $version->version_status !== 'published', 422);

        return $version;
    }

    private function resolvedVersionCandidate(int $customerId, int $productId, bool $lock = false): ?object
    {
        $versions = DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('items.customer_id', $customerId)
            ->where('items.product_id', $productId)
            ->where('items.status', 'active')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('items.sort_order')
            ->orderBy('items.id')
            ->select('versions.id', 'versions.status as version_status')
            ->limit(2)
            ->get();

        return $versions->count() === 1 ? $versions->first() : null;
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
            ->where('products.customer_id', $customerId)
            ->where('products.status', 'active')
            ->whereRaw('(select count(*) from core_course_product_items active_items where active_items.customer_id = products.customer_id and active_items.product_id = products.id and active_items.status = ?) = 1', ['active'])
            ->select(
                'products.id',
                'products.title',
                'products.product_code',
                'versions.id as version_id',
                'versions.version_code',
                'versions.version_number',
                'versions.lesson_count_snapshot as lesson_count',
                DB::raw('(select count(*) from core_course_template_version_activities activities where activities.customer_id = versions.customer_id and activities.template_version_id = versions.id) as activity_count')
            );
    }

    private function productSearchPayload(object $product): array
    {
        return [
            'id' => $product->id,
            'title' => $product->title,
            'code' => $product->product_code,
            'version' => [
                'code' => $product->version_code,
                'status' => __('lf.LF_course_enrollment_version_published'),
                'lesson_count' => (int) $product->lesson_count,
                'activity_count' => (int) $product->activity_count,
            ],
        ];
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
