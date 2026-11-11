<?php

namespace App\Services;

use App\Support\CourseProductV2;
use App\Support\CourseType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model of the tenant website catalogue (`/courses`, `/courses/{slug}`): Course Products that are
 * `active`, `public`, inside their display window and bound to a published Version. Content comes from
 * that published Version (Course Guardrails Rule 3); the learner's own Enrollment, when there is one,
 * links to "Khóa học của tôi". It never writes.
 */
class TenantCourseCatalogService
{
    private const LIMIT = 200;

    public function __construct(
        private readonly ProductCourseVersionResolver $resolver,
        private readonly LearnerMyLearningService $learning,
    ) {}

    /** A price as the learner reads it: whole units for VND, two decimals otherwise. */
    public static function money(string $amount, ?string $currency): string
    {
        $vietnamese = app()->getLocale() === 'vi';
        $decimals = strtoupper((string) $currency) === 'VND' ? 0 : 2;

        return number_format((float) $amount, $decimals, $vietnamese ? ',' : '.', $vietnamese ? '.' : ',').' '.$currency;
    }

    /** @return Collection<int, object> */
    public function courses(int $customerId, ?int $studentId, ?int $limit = null): Collection
    {
        $products = $this->visibleProducts($customerId)
            ->orderByDesc('products.is_featured')->orderBy('products.sort_order')->orderByDesc('products.id')
            ->limit(self::LIMIT)
            ->get();

        return $this->present($customerId, $studentId, $products)->take($limit ?? self::LIMIT)->values();
    }

    /** One visible Product by slug with its Version outline; null when it is not on the catalogue. */
    public function course(int $customerId, ?int $studentId, string $slug): ?object
    {
        $product = $this->visibleProducts($customerId)->where('products.slug', $slug)->first();
        $course = $product ? $this->present($customerId, $studentId, collect([$product]))->first() : null;
        if (! $course) {
            return null;
        }

        $lessons = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->where('template_version_id', $course->version_id)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'version_section_id', 'title_snapshot as title', 'duration_seconds']);
        $sections = DB::table('core_course_template_version_sections')
            ->where('customer_id', $customerId)->where('template_version_id', $course->version_id)
            ->orderBy('display_order')->orderBy('id')
            ->get(['id', 'title_snapshot as title']);
        $bySection = $lessons->groupBy('version_section_id');
        $course->outline = $sections
            ->map(fn (object $section): object => (object) ['title' => $section->title, 'lessons' => $bySection->get($section->id, collect())->pluck('title')->all()])
            ->filter(fn (object $section): bool => $section->lessons !== [])
            ->values();
        $orphans = $lessons->reject(fn (object $lesson): bool => $sections->contains('id', $lesson->version_section_id));
        if ($orphans->isNotEmpty()) {
            $course->outline->push((object) ['title' => null, 'lessons' => $orphans->pluck('title')->all()]);
        }
        $course->duration_seconds = (int) $lessons->sum('duration_seconds');

        return $course;
    }

    private function visibleProducts(int $customerId)
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');

        return DB::table('core_course_products as products')
            ->where('products.customer_id', $customerId)
            ->where('products.status', 'active')
            ->where('products.visibility', 'public')
            ->where(fn ($query) => $query->whereNull('products.available_from')->orWhere('products.available_from', '<=', $now))
            ->where(fn ($query) => $query->whereNull('products.available_until')->orWhere('products.available_until', '>', $now))
            ->select('products.id', 'products.slug', 'products.title', 'products.short_description', 'products.description',
                'products.uses_custom_description', 'products.price', 'products.currency', 'products.promotion_enabled',
                'products.discount_type', 'products.discount_value', 'products.sale_starts_at', 'products.sale_ends_at',
                'products.status', 'products.registration_starts_at', 'products.registration_ends_at',
                'products.access_duration_days', 'products.review_duration_days', 'products.is_featured');
    }

    /**
     * Keeps Products bound to one published Version and adds what the card and the detail page show.
     *
     * @param  Collection<int, object>  $products
     * @return Collection<int, object>
     */
    private function present(int $customerId, ?int $studentId, Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return collect();
        }

        $bindings = collect($this->resolver->resolveMany($customerId, $products->pluck('id')->all()))
            ->map(fn (array $result) => $result['binding'])->filter();
        $versions = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)->whereIn('id', $bindings->pluck('version_id')->unique()->values())
            ->get(['id', 'template_id', 'title_snapshot', 'short_description_snapshot', 'description_snapshot',
                'category_name_snapshot', 'difficulty_level_snapshot', 'course_type_snapshot', 'lesson_count_snapshot'])
            ->keyBy('id');
        $teachers = $this->learning->templateTeachers($customerId, $versions->pluck('template_id')->unique()->values()->all());
        $lessonCounts = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->whereIn('template_version_id', $versions->keys())
            ->groupBy('template_version_id')->selectRaw('template_version_id, COUNT(*) as total')
            ->pluck('total', 'template_version_id');
        $enrollments = $studentId ? $this->learnerEnrollments($customerId, $studentId, $products->pluck('id')->all()) : collect();
        $now = Carbon::now();

        return $products
            ->filter(fn (object $product): bool => $versions->has($bindings->get($product->id)?->version_id))
            ->map(function (object $product) use ($bindings, $versions, $teachers, $lessonCounts, $enrollments, $now): object {
                $version = $versions->get($bindings->get($product->id)->version_id);
                $custom = (bool) $product->uses_custom_description;
                $selling = $this->promotionRunning($product, $now)
                    ? CourseProductV2::sellingPrice((string) $product->price, true, $product->discount_type, $product->discount_value)
                    : CourseProductV2::sellingPrice((string) $product->price, false, null, null);
                $enrollment = $enrollments->get($product->id);

                return (object) [
                    'id' => (int) $product->id,
                    'slug' => $product->slug,
                    'title' => $product->title ?: $version->title_snapshot,
                    'summary' => $custom ? $product->short_description : $version->short_description_snapshot,
                    'description' => $custom ? $product->description : $version->description_snapshot,
                    'version_id' => (int) $version->id,
                    'course_type' => CourseType::normalize($version->course_type_snapshot),
                    'category' => $version->category_name_snapshot,
                    'level' => LearnerMyLearningService::levelLabel($version->difficulty_level_snapshot),
                    'lesson_total' => (int) ($lessonCounts[$version->id] ?? 0),
                    'teachers' => $teachers->get($version->template_id, collect())->all(),
                    'currency' => $product->currency,
                    'price' => (string) $product->price,
                    'selling_price' => $selling,
                    'discounted' => $this->cents($selling) < $this->cents((string) $product->price),
                    'is_free' => $this->cents($selling) === 0,
                    'access_duration_days' => $product->access_duration_days ? (int) $product->access_duration_days : null,
                    'review_duration_days' => $product->review_duration_days ? (int) $product->review_duration_days : null,
                    'registration_open' => CourseProductV2::registrationOpen($product, $now),
                    'is_featured' => (bool) $product->is_featured,
                    'enrollment' => $enrollment,
                    'learn_url' => $enrollment?->url,
                ];
            })
            ->values();
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /** Inclusive start, exclusive end, app-timezone wall clock like every Product DATETIME. */
    private function promotionRunning(object $product, Carbon $now): bool
    {
        return (bool) $product->promotion_enabled && $product->discount_value !== null
            && (! $product->sale_starts_at || $now->gte(Carbon::parse($product->sale_starts_at)))
            && (! $product->sale_ends_at || $now->lt(Carbon::parse($product->sale_ends_at)));
    }

    /**
     * The learner's latest visible Enrollment of each Product and where "Vào học" leads.
     *
     * @return Collection<int, object>
     */
    private function learnerEnrollments(int $customerId, int $studentId, array $productIds): Collection
    {
        $rows = DB::table('core_course_enrollments as enrollments')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'enrollments.version_id')->where('versions.customer_id', $customerId);
            })
            ->where('enrollments.customer_id', $customerId)->where('enrollments.student_id', $studentId)
            ->whereIn('enrollments.product_id', $productIds)
            ->where('enrollments.status', '!=', 'cancelled')
            ->orderByDesc('enrollments.id')
            ->get(['enrollments.id', 'enrollments.product_id', 'enrollments.status', 'versions.course_type_snapshot as course_type'])
            ->unique('product_id');
        $seats = DB::table('core_course_cohort_students as seats')
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'seats.cohort_id')->where('cohorts.customer_id', $customerId);
            })
            ->where('seats.customer_id', $customerId)->where('seats.student_id', $studentId)
            ->whereIn('seats.enrollment_id', $rows->pluck('id'))
            ->where('seats.status', 'active')->whereNull('seats.left_at')->where('cohorts.status', '!=', 'draft')
            ->orderByDesc('seats.id')
            ->pluck('seats.cohort_id', 'seats.enrollment_id');

        return $rows->mapWithKeys(function (object $row) use ($seats): array {
            $isClass = in_array($row->course_type, CourseType::COHORT_VALUES, true);
            $cohortId = $seats[$row->id] ?? null;
            $row->url = match (true) {
                ! $isClass => route('student.courses.show', $row->id),
                $cohortId !== null => route('student.courses.classes.show', $cohortId),
                default => route('student.courses.index', ['tab' => 'classes']),
            };

            return [(int) $row->product_id => $row];
        });
    }
}
