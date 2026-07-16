<?php

namespace App\Services;

use App\Support\CourseProductV2;
use Illuminate\Support\Facades\DB;

class CourseProductLifecyclePolicy
{
    public function __construct(private readonly CourseProductTemplateChangePolicy $usagePolicy) {}

    public function allowedStatuses(object $product, int $customerId): array
    {
        $used = $this->usagePolicy->hasUsage($customerId, (int) $product->id);

        return match ($product->status) {
            'draft' => ['draft', 'active'],
            'active' => $used ? ['active', 'inactive'] : ['active', 'draft', 'inactive'],
            'inactive' => $used ? ['inactive', 'active'] : ['inactive', 'active', 'draft'],
            'archived' => ['archived'],
            default => [],
        };
    }

    public function allows(object $product, int $customerId, string $target): bool
    {
        return in_array($target, $this->allowedStatuses($product, $customerId), true);
    }

    public function activationValid(object $product, int $customerId): bool
    {
        if ($product->status === 'archived') {
            return false;
        }

        if ($product->product_type !== CourseProductV2::PACKAGE_SINGLE) {
            return true;
        }

        return DB::table('core_course_product_items as items')
            ->join('core_course_templates as templates', function ($join) use ($customerId): void {
                $join->on('templates.id', '=', 'items.template_id')
                    ->where('templates.customer_id', '=', $customerId);
            })
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', '=', $customerId)
                    ->where('versions.status', '=', 'published');
            })
            ->where('items.customer_id', $customerId)
            ->where('items.product_id', $product->id)
            ->where('items.status', 'active')
            ->whereColumn('versions.template_id', 'items.template_id')
            ->count() === 1;
    }

    public function action(string $from, string $to): ?string
    {
        return match ([$from, $to]) {
            ['draft', 'active'], ['inactive', 'active'] => 'activate',
            ['active', 'inactive'] => 'deactivate',
            ['active', 'draft'], ['inactive', 'draft'] => 'return_to_draft',
            ['inactive', 'archived'] => 'archive',
            ['archived', 'inactive'] => 'restore',
            default => null,
        };
    }
}
