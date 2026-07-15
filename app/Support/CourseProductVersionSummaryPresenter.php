<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CourseProductVersionSummaryPresenter
{
    public function present(int $customerId, ?int $productId, bool $canView): array
    {
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
            ->orderBy('templates.title')
            ->select(
                'templates.id',
                'templates.category_id',
                'templates.title as name',
                'versions.id as version_id',
                'versions.version_code',
                'versions.status as version_status',
                DB::raw('COALESCE(lesson_counts.lesson_count, 0) as lesson_count'),
                DB::raw('COALESCE(activity_counts.activity_count, 0) as activity_count')
            )
            ->get();

        foreach ($templates as $template) {
            $template->version_summary = $template->version_id
                ? $this->summary($template, $canView, 'activation_candidate')
                : null;
            $template->integrity_warning = null;
        }

        $item = $productId ? DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first(['id', 'template_id', 'version_id']) : null;

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
            ->first(['id as version_id', 'template_id', 'version_code', 'status as version_status']);

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
            'code' => $version->version_code,
            'status' => $version->version_status,
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
}
