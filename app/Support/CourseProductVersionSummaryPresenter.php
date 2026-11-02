<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CourseProductVersionSummaryPresenter
{
    public function present(int $customerId, ?int $productId, bool $canView): array
    {
        $item = $productId ? DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first(['id', 'template_id', 'version_id']) : null;
        $lessonCounts = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->selectRaw('template_version_id, COUNT(*) as lesson_count')
            ->groupBy('template_version_id');
        $activityCounts = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)
            ->selectRaw('template_version_id, COUNT(*) as activity_count')
            ->groupBy('template_version_id');

        $templates = DB::table('core_course_templates as templates')
            ->leftJoin('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.template_id', '=', 'templates.id')
                    ->where('versions.customer_id', '=', $customerId)
                    ->where('versions.status', '=', 'published')
                    ->where('versions.is_current', '=', true);
            })
            ->leftJoinSub($lessonCounts, 'lesson_counts', 'lesson_counts.template_version_id', '=', 'versions.id')
            ->leftJoinSub($activityCounts, 'activity_counts', 'activity_counts.template_version_id', '=', 'versions.id')
            ->where('templates.customer_id', $customerId)
            ->where('templates.status', 'active')
            ->whereExists(function ($query) use ($customerId): void {
                $query->selectRaw('1')
                    ->from('core_course_template_versions as eligible_versions')
                    ->whereColumn('eligible_versions.template_id', 'templates.id')
                    ->where('eligible_versions.customer_id', $customerId)
                    ->where('eligible_versions.status', 'published');
            })
            ->orderBy('templates.title')
            ->select(
                'templates.id',
                'templates.category_id',
                'templates.title as name',
                'templates.status',
                'templates.course_type',
                'versions.id as version_id',
                'versions.version_number',
                'versions.version_code',
                'versions.status as version_status',
                DB::raw('COALESCE(lesson_counts.lesson_count, 0) as lesson_count'),
                DB::raw('COALESCE(activity_counts.activity_count, 0) as activity_count')
            )
            ->get();

        if ($item?->template_id && ! $templates->contains('id', $item->template_id)) {
            $historicalTemplate = DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $item->template_id)
                ->first([
                    'id',
                    'category_id',
                    'title as name',
                    'status',
                    'course_type',
                    DB::raw('NULL as version_id'),
                    DB::raw('NULL as version_number'),
                    DB::raw('NULL as version_code'),
                    DB::raw('NULL as version_status'),
                    DB::raw('0 as lesson_count'),
                    DB::raw('0 as activity_count'),
                ]);
            if ($historicalTemplate) {
                $templates->push($historicalTemplate);
            }
        }

        foreach ($templates as $template) {
            $template->version_summary = null;
            $template->integrity_warning = null;
            $template->published_versions = [];
            $template->is_historical_binding = (int) ($item?->template_id ?? 0) === (int) $template->id
                && $template->status !== 'active';
            $template->status_label = __('lf.LF_course_template_common_'.$template->status);
        }

        $publishedVersions = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('status', 'published')
            ->orderByDesc('version_number')
            ->get(['id', 'template_id', 'version_number', 'version_code', 'published_at', 'course_type_snapshot']);
        foreach ($publishedVersions as $version) {
            $template = $templates->firstWhere('id', $version->template_id);
            if ($template) {
                $template->published_versions[] = [
                    'id' => (int) $version->id,
                    'number_label' => $this->versionNumberLabel((int) $version->version_number),
                    'code' => $version->version_code,
                    'course_type' => $version->course_type_snapshot,
                    'published_at' => $version->published_at
                        ? date('d/m/Y', strtotime($version->published_at)) : null,
                    'view_url' => $canView ? route('admin.course-templates.versions.show', [
                        'templateId' => $version->template_id,
                        'versionId' => $version->id,
                    ]) : null,
                ];
            }
        }

        if ($item?->version_id) {
            $bound = $this->boundVersion($customerId, (int) $item->template_id, (int) $item->version_id);
            $template = $templates->firstWhere('id', $item->template_id);

            if ($bound && $template) {
                $template->version_summary = $this->summary($bound, $canView, 'bound');
            } else {
                Log::warning('Product v2 bound Version integrity issue', [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'product_item_id' => $item->id,
                    'template_id' => $item->template_id,
                    'version_id' => $item->version_id,
                ]);
                if ($template) {
                    $template->version_summary = null;
                    $template->integrity_warning = __('lf.LF_product_v2_version_integrity_warning');
                }
            }
        }

        return [
            'templates' => $templates,
            'selected_template_id' => $item?->template_id,
        ];
    }

    /**
     * Every non-archived Template for the Product template picker (ADR-0022): type, latest
     * published Version, Products using it, and why an ineligible one cannot be chosen.
     */
    public function pickerTemplates(int $customerId): array
    {
        $latest = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('status', 'published')
            ->selectRaw('template_id, MAX(version_number) as version_number')
            ->groupBy('template_id');
        $productCounts = DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->selectRaw('template_id, COUNT(DISTINCT product_id) as product_count')
            ->groupBy('template_id');

        return DB::table('core_course_templates as templates')
            ->leftJoin('core_course_categories as categories', function ($join) use ($customerId): void {
                $join->on('categories.id', '=', 'templates.category_id')->where('categories.customer_id', $customerId);
            })
            ->leftJoinSub($latest, 'latest', 'latest.template_id', '=', 'templates.id')
            ->leftJoin('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.template_id', '=', 'templates.id')
                    ->on('versions.version_number', '=', 'latest.version_number')
                    ->where('versions.customer_id', $customerId);
            })
            ->leftJoinSub($productCounts, 'product_counts', 'product_counts.template_id', '=', 'templates.id')
            ->where('templates.customer_id', $customerId)
            ->where('templates.status', '!=', 'archived')
            ->orderBy('templates.title')
            ->get([
                'templates.id', 'templates.title', 'templates.category_id', 'categories.name as category_name',
                'templates.status', 'templates.course_type',
                'versions.version_number', 'versions.version_code',
                DB::raw('COALESCE(product_counts.product_count, 0) as product_count'),
            ])
            ->map(function (object $template): array {
                $reason = match (true) {
                    $template->status !== 'active' => __('lf.LF_product_template_picker_reason_status', [
                        'status' => __('lf.LF_course_template_common_'.$template->status),
                    ]),
                    $template->version_number === null => __('lf.LF_product_template_picker_reason_unpublished'),
                    $template->category_id === null => __('lf.LF_product_template_picker_reason_no_category'),
                    default => null,
                };

                return [
                    'id' => (int) $template->id,
                    'title' => $template->title,
                    'category_id' => $template->category_id === null ? null : (int) $template->category_id,
                    'category_name' => $template->category_name,
                    'course_type' => $template->course_type,
                    'course_type_label' => __(CourseType::labelKey((string) $template->course_type)),
                    'version_label' => $template->version_number === null ? null : $this->versionNumberLabel((int) $template->version_number),
                    'version_code' => $template->version_code,
                    'product_count' => (int) $template->product_count,
                    'eligible' => $reason === null,
                    'reason' => $reason,
                ];
            })
            // Choosable Templates first; the title order is kept within each group.
            ->sortBy(fn (array $template): int => $template['eligible'] ? 0 : 1)
            ->values()
            ->all();
    }

    private function boundVersion(int $customerId, int $templateId, int $versionId): ?object
    {
        $lessonCounts = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->count();
        $activityCounts = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->count();

        $version = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $versionId)
            ->whereIn('status', ['published', 'deprecated', 'archived'])
            ->first(['id as version_id', 'template_id', 'version_number', 'version_code', 'status as version_status', 'course_type_snapshot']);

        if ($version) {
            $version->lesson_count = $lessonCounts;
            $version->activity_count = $activityCounts;
        }

        return $version;
    }

    private function summary(object $version, bool $canView, string $source): array
    {
        return [
            'id' => (int) $version->version_id,
            'number' => (int) $version->version_number,
            'number_label' => $this->versionNumberLabel((int) $version->version_number),
            'code' => $version->version_code,
            'status' => $version->version_status,
            'course_type' => $version->course_type_snapshot,
            'status_label' => __('lf.LF_course_template_version_status_'.$version->version_status),
            'lesson_count' => (int) $version->lesson_count,
            'activity_count' => (int) $version->activity_count,
            'lesson_text' => trans_choice('lf.LF_product_v2_version_lessons', (int) $version->lesson_count, [
                'count' => (int) $version->lesson_count,
            ]),
            'activity_text' => trans_choice('lf.LF_product_v2_version_activities', (int) $version->activity_count, [
                'count' => (int) $version->activity_count,
            ]),
            'source' => $source,
            'view_url' => $canView ? route('admin.course-templates.versions.show', [
                'templateId' => $version->template_id ?? $version->id,
                'versionId' => $version->version_id,
            ]) : null,
        ];
    }

    public function versionNumberLabel(int $versionNumber): string
    {
        return __('lf.LF_course_product_item_common_version_number', [
            'number' => $versionNumber,
        ]);
    }
}
