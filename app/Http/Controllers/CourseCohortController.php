<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use App\Support\SequentialCodeGenerator;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseCohortController extends Controller
{
    private const STATUSES = [
        'draft',
        'active',
        'completed',
        'archived',
    ];

    public function __construct(private readonly MediaService $mediaService) {}

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
            ->leftJoin('users as teachers', function ($join) use ($customerId): void {
                $join->on('teachers.id', '=', 'cohorts.teacher_id')
                    ->where('teachers.customer_id', '=', $customerId);
            })
            ->where('cohorts.customer_id', $customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('cohorts.name', 'like', '%'.$keyword.'%')
                        ->orWhere('cohorts.code', 'like', '%'.$keyword.'%')
                        ->orWhere('products.title', 'like', '%'.$keyword.'%')
                        ->orWhere('products.product_code', 'like', '%'.$keyword.'%')
                        ->orWhere('versions.version_code', 'like', '%'.$keyword.'%')
                        ->orWhere('teachers.name', 'like', '%'.$keyword.'%');
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('cohorts.status', $status);
            })
            ->orderBy('cohorts.name')
            ->orderBy('cohorts.id')
            ->select(
                'cohorts.*',
                'products.title as product_title',
                'products.product_code',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code',
                'teachers.name as teacher_name',
                'teachers.email as teacher_email'
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
            'products' => $this->products($customerId),
            'versions' => $this->versions($customerId),
            'teachers' => $this->teachers($customerId),
            'statuses' => self::STATUSES,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        $cohortId = DB::table('core_course_cohorts')->insertGetId(
            $this->cohortValues($validated, [
                'customer_id' => $customerId,
                'code' => SequentialCodeGenerator::next(
                    $customerId,
                    'core_course_cohorts',
                    'code',
                    'COH'
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        $this->attachUploadedMedia($request, $cohortId);

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
            'cohortMedia' => $this->ownerMedia('course_cohort', $id),
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
            'products' => $this->products($customerId),
            'versions' => $this->versions($customerId),
            'teachers' => $this->teachers($customerId),
            'statuses' => self::STATUSES,
            'cohortMedia' => $this->ownerMedia('course_cohort', $id),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findCohort($customerId, $id);
        $validated = $this->validatedData($request, $customerId);

        DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update($this->cohortValues($validated, [
                'updated_at' => now(),
            ]));

        $this->attachUploadedMedia($request, $id);

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_common_updated'));
    }

    public function archive(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findCohort($customerId, $id);

        DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update([
                'status' => 'archived',
                'updated_at' => now(),
            ]);

        return redirect()
            ->route($this->routePrefix($request).'.show', $id)
            ->with('success', __('lf.LF_course_cohort_common_archived_message'));
    }

    private function validatedData(Request $request, int $customerId): array
    {
        $validator = Validator::make($this->validationInput($request), [
            'product_id' => ['nullable', 'integer', 'min:1'],
            'version_id' => ['nullable', 'integer', 'min:1'],
            'teacher_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'cohort_document_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'cohort_attachment_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
        ]);

        $validator->after(function ($validator) use ($request, $customerId): void {
            $productId = (int) $request->input('product_id');
            $versionId = (int) $request->input('version_id');
            $teacherId = (int) $request->input('teacher_id');

            if ($productId > 0 && ! $this->productExists($customerId, $productId)) {
                $validator->errors()->add(
                    'product_id',
                    __('lf.LF_course_cohort_validation_product')
                );
            }

            if ($versionId > 0 && ! $this->versionExists($customerId, $versionId)) {
                $validator->errors()->add(
                    'version_id',
                    __('lf.LF_course_cohort_validation_version')
                );
            }

            if ($teacherId > 0 && ! $this->teacherExists($customerId, $teacherId)) {
                $validator->errors()->add(
                    'teacher_id',
                    __('lf.LF_course_cohort_validation_teacher')
                );
            }
        });

        return $validator->validate();
    }

    private function validationInput(Request $request): array
    {
        $fields = [
            'product_id',
            'version_id',
            'teacher_id',
            'name',
            'description',
            'status',
            'capacity',
            'start_date',
            'end_date',
            'notes',
        ];

        $input = array_intersect_key(
            $request->request->all(),
            array_flip($fields)
        );

        foreach (['cohort_document_file', 'cohort_attachment_file'] as $field) {
            if ($request->hasFile($field)) {
                $input[$field] = $request->file($field);
            }
        }

        return $input;
    }

    private function cohortValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'product_id' => $validated['product_id'] ?? null,
            'version_id' => $validated['version_id'] ?? null,
            'teacher_id' => $validated['teacher_id'] ?? null,
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
        $cohort = DB::table('core_course_cohorts as cohorts')
            ->leftJoin('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'cohorts.product_id')
                    ->where('products.customer_id', '=', $customerId);
            })
            ->leftJoin('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'cohorts.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->leftJoin('users as teachers', function ($join) use ($customerId): void {
                $join->on('teachers.id', '=', 'cohorts.teacher_id')
                    ->where('teachers.customer_id', '=', $customerId);
            })
            ->where('cohorts.customer_id', $customerId)
            ->where('cohorts.id', $id)
            ->select(
                'cohorts.*',
                'products.title as product_title',
                'products.product_code',
                'versions.title_snapshot as version_title',
                'versions.version_number',
                'versions.version_code',
                'teachers.name as teacher_name',
                'teachers.email as teacher_email'
            )
            ->first();

        abort_if(! $cohort, 404);

        return $cohort;
    }

    private function products(int $customerId)
    {
        return DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->orderBy('title')
            ->select('id', 'title', 'product_code', 'status')
            ->get();
    }

    private function versions(int $customerId)
    {
        return DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('status', 'published')
            ->orderBy('title_snapshot')
            ->orderBy('version_number')
            ->select('id', 'title_snapshot', 'version_number', 'version_code')
            ->get();
    }

    private function teachers(int $customerId)
    {
        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->orderBy('name')
            ->select('id', 'name', 'email')
            ->get();
    }

    private function productExists(int $customerId, int $productId): bool
    {
        return DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $productId)
            ->exists();
    }

    private function versionExists(int $customerId, int $versionId): bool
    {
        return DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('id', $versionId)
            ->where('status', 'published')
            ->exists();
    }

    private function teacherExists(int $customerId, int $teacherId): bool
    {
        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('id', $teacherId)
            ->where('role', 'teacher')
            ->exists();
    }

    private function attachUploadedMedia(Request $request, int $cohortId): void
    {
        foreach ([
            'cohort_document_file' => ['document', 'document'],
            'cohort_attachment_file' => ['document', 'attachment'],
        ] as $field => [$fileType, $usageType]) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $mediaFile = $this->mediaService->upload(
                $request->file($field),
                [
                    'file_type' => $fileType,
                    'module' => 'course',
                    'entity_type' => 'cohorts',
                    'entity_id' => $cohortId,
                    'purpose' => $usageType,
                    'display_name' => $request->input('name'),
                ],
                (int) $request->user()->id
            );

            $this->mediaService->attachUsage(
                (int) $mediaFile->id,
                'course_cohort',
                $cohortId,
                $usageType
            );
        }
    }

    private function ownerMedia(string $ownerType, int $ownerId): object
    {
        return $this->mediaService
            ->getOwnerMedia($ownerType, $ownerId)
            ->map(function (object $media): object {
                $media->signed_url = $this->mediaService->generateSignedUrl(
                    (int) $media->id
                );

                return $media;
            });
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
