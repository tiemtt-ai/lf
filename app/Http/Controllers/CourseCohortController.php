<?php

namespace App\Http\Controllers;

use App\Services\CourseCohortLifecycleService;
use App\Services\CourseCohortVersionResolver;
use App\Support\SequentialCodeGenerator;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class CourseCohortController extends Controller
{
    private const STATUSES = [
        'draft',
        'active',
        'completed',
        'archived',
    ];

    public function __construct(
        private readonly CourseCohortVersionResolver $versionResolver,
        private readonly CourseCohortLifecycleService $lifecycleService
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
            ->route($this->routePrefix($request).'.show', $cohortId)
            ->with('success', __('lf.LF_course_cohort_common_created'));
    }

    public function show(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);
        $cohort = $this->findCohort($this->customerId(), $id);

        return view('course-cohorts.show', [
            'cohort' => $cohort,
            'activeMembershipCount' => DB::table('core_course_cohort_students')
                ->where('customer_id', $this->customerId())->where('cohort_id', $id)
                ->where('status', 'active')->count(),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function edit(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $cohort = $this->findCohort($customerId, $id);

        return view('course-cohorts.edit', [
            'cohort' => $cohort,
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
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
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
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request, $customerId, $cohort): void {
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
        });

        return $validator->validate();
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
            'customer_admin' => 'admin.course-cohorts',
            default => abort(403),
        };
    }
}
