<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseProductMediaAuthorizer
{
    private const SLOTS = [
        'image' => ['field' => 'intro_image_media_file_id', 'usage' => 'intro_image', 'type' => 'image'],
        'video' => ['field' => 'intro_video_media_file_id', 'usage' => 'intro_video', 'type' => 'video'],
        'document' => ['field' => 'intro_document_media_file_id', 'usage' => 'intro_document', 'type' => 'document'],
    ];

    public function resolve(int $customerId, int $productId, int $mediaFileId, string $slot): ?object
    {
        $definition = self::SLOTS[$slot] ?? null;
        if (! $definition) {
            return null;
        }

        $product = DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $productId)
            ->first();
        if (! $product
            || (int) $product->{$definition['field']} !== $mediaFileId
            || ($slot === 'video' && $product->intro_video_source !== 'upload')) {
            return null;
        }

        return DB::table('media_files as media')
            ->join('media_file_usages as usages', function ($join) use ($customerId, $productId, $definition): void {
                $join->on('usages.media_file_id', '=', 'media.id')
                    ->where('usages.customer_id', $customerId)
                    ->where('usages.owner_type', 'course_product')
                    ->where('usages.owner_id', $productId)
                    ->where('usages.usage_type', $definition['usage'])
                    ->where('usages.status', 'active');
            })
            ->where('media.customer_id', $customerId)
            ->where('media.id', $mediaFileId)
            ->where('media.file_type', $definition['type'])
            ->where('media.status', 'ready')
            ->select('media.*')
            ->first();
    }

    public function authorize(int $customerId, int $productId, int $mediaFileId, string $slot): object
    {
        $media = $this->resolve($customerId, $productId, $mediaFileId, $slot);
        abort_if(! $media, 404);

        return $media;
    }
}
