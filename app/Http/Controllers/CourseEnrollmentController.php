<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
            'students' => $this->students($customerId),
            'products' => $this->products($customerId),
            'statuses' => self::STATUSES,
            'sources' => self::SOURCES,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedCreateData($request, $customerId);
        $version = $this->resolveVersion($customerId, (int) $validated['product_id']);
        $now = now();
        $enrolledAt = $validated['enrolled_at'] ?? $now;

        $enrollmentId = DB::transaction(function () use (
            $request,
            $customerId,
            $validated,
            $version,
            $now,
            $enrolledAt
        ): int {
            $id = DB::table('core_course_enrollments')->insertGetId([
                'customer_id' => $customerId,
                'product_id' => $validated['product_id'],
                'version_id' => $version->id,
                'student_id' => $validated['student_id'],
                'source' => $validated['source'],
                'source_id' => $validated['source_id'] ?? null,
                'enrolled_by' => $request->user()?->id,
                'enrolled_at' => $enrolledAt,
                'access_starts_at' => $validated['access_starts_at'] ?? null,
                'access_ends_at' => $validated['access_ends_at'] ?? null,
                'review_starts_at' => $validated['review_starts_at'] ?? null,
                'review_ends_at' => $validated['review_ends_at'] ?? null,
                'status' => $validated['status'],
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

        return view('course-enrollments.edit', [
            'enrollment' => $enrollment,
            'statuses' => self::STATUSES,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findEnrollment($customerId, $id);
        $validated = $this->validatedUpdateData($request);

        DB::table('core_course_enrollments')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update([
                'status' => $validated['status'],
                'access_starts_at' => $validated['access_starts_at'] ?? null,
                'access_ends_at' => $validated['access_ends_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'updated_at' => now(),
            ]);

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_enrollment_common_updated'));
    }

    private function validatedCreateData(Request $request, int $customerId): array
    {
        $validator = Validator::make($request->all(), [
            'student_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'source' => ['required', Rule::in(self::SOURCES)],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'enrolled_at' => ['nullable', 'date'],
            'access_starts_at' => ['nullable', 'date'],
            'access_ends_at' => ['nullable', 'date'],
            'review_starts_at' => ['nullable', 'date'],
            'review_ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request, $customerId): void {
            $this->rejectImmutableInputs($validator, $request, [
                'customer_id',
                'version_id',
            ]);

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
            'status' => ['required', Rule::in(self::STATUSES)],
            'access_starts_at' => ['nullable', 'date'],
            'access_ends_at' => ['nullable', 'date'],
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

    private function resolveVersion(int $customerId, int $productId): object
    {
        $version = $this->resolvedVersionCandidate($customerId, $productId);

        abort_if(! $version || $version->version_status !== 'published', 422);

        return $version;
    }

    private function resolvedVersionCandidate(int $customerId, int $productId): ?object
    {
        return DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('items.customer_id', $customerId)
            ->where('items.product_id', $productId)
            ->where('items.status', 'active')
            ->orderBy('items.sort_order')
            ->orderBy('items.id')
            ->select(
                'versions.id',
                'versions.status as version_status'
            )
            ->first();
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

    private function students(int $customerId)
    {
        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('role', 'student')
            ->where('status', 'active')
            ->orderBy('name')
            ->select('id', 'name', 'email')
            ->get();
    }

    private function products(int $customerId)
    {
        return DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->orderBy('title')
            ->select('id', 'title', 'product_code', 'status')
            ->get();
    }

    private function studentExists(int $customerId, int $studentId): bool
    {
        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('id', $studentId)
            ->where('role', 'student')
            ->exists();
    }

    private function productExists(int $customerId, int $productId): bool
    {
        return DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $productId)
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
