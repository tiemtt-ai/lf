<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('offering_type', 50)->nullable();
            $table->boolean('uses_custom_description')->default(false);
            $table->boolean('uses_custom_intro_media')->default(false);
            $table->unsignedBigInteger('intro_image_media_file_id')->nullable();
            $table->string('intro_video_source', 50)->nullable();
            $table->unsignedBigInteger('intro_video_media_file_id')->nullable();
            $table->string('intro_video_embed_url', 2048)->nullable();
            $table->string('intro_video_provider', 50)->nullable();
            $table->unsignedBigInteger('intro_document_media_file_id')->nullable();
            $table->boolean('promotion_enabled')->default(false);
            $table->string('discount_type', 50)->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();

            $table->foreign('category_id', 'fk_ccp_v2_category')
                ->references('id')->on('core_course_categories')->restrictOnDelete();
            $table->foreign('intro_image_media_file_id', 'fk_ccp_v2_intro_image')
                ->references('id')->on('media_files')->restrictOnDelete();
            $table->foreign('intro_video_media_file_id', 'fk_ccp_v2_intro_video')
                ->references('id')->on('media_files')->restrictOnDelete();
            $table->foreign('intro_document_media_file_id', 'fk_ccp_v2_intro_doc')
                ->references('id')->on('media_files')->restrictOnDelete();
            $table->index(['customer_id', 'category_id', 'sort_order'], 'idx_ccp_v2_cat_sort');
            $table->index(['customer_id', 'offering_type'], 'idx_ccp_v2_offering');
        });

        Schema::table('core_course_product_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('template_id')->nullable();
            $table->foreign('template_id', 'fk_ccpi_v2_template')
                ->references('id')->on('core_course_templates')->restrictOnDelete();
            $table->index(['customer_id', 'template_id'], 'idx_ccpi_v2_template');
            $table->unique(['customer_id', 'product_id', 'template_id'], 'uk_ccpi_v2_product_template');
        });

        DB::table('core_course_product_items')
            ->whereNull('template_id')
            ->orderBy('id')
            ->eachById(function (object $item): void {
                $version = DB::table('core_course_template_versions')
                    ->where('id', $item->version_id)
                    ->where('customer_id', $item->customer_id)
                    ->first(['template_id']);

                if (! $version) {
                    Log::warning('Product v2 unresolved Product Item Template backfill.', [
                        'item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'customer_id' => $item->customer_id,
                    ]);

                    return;
                }

                DB::table('core_course_product_items')->where('id', $item->id)->update([
                    'template_id' => $version->template_id,
                ]);
            });

        DB::table('core_course_products')
            ->whereNull('category_id')
            ->orderBy('id')
            ->eachById(function (object $product): void {
                $categories = DB::table('core_course_product_items as items')
                    ->join('core_course_templates as templates', 'templates.id', '=', 'items.template_id')
                    ->where('items.customer_id', $product->customer_id)
                    ->where('items.product_id', $product->id)
                    ->where('templates.customer_id', $product->customer_id)
                    ->distinct()->pluck('templates.category_id')->filter()->values();

                if ($categories->count() === 1) {
                    DB::table('core_course_products')->where('id', $product->id)->update([
                        'category_id' => $categories->first(),
                    ]);
                } elseif ($categories->count() !== 0) {
                    Log::warning('Product v2 ambiguous Product category backfill.', [
                        'product_id' => $product->id,
                        'customer_id' => $product->customer_id,
                        'category_ids' => $categories->all(),
                    ]);
                }
            });

        Schema::table('core_course_product_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('version_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('core_course_product_items', function (Blueprint $table): void {
            $table->dropUnique('uk_ccpi_v2_product_template');
            $table->dropIndex('idx_ccpi_v2_template');
            $table->dropForeign('fk_ccpi_v2_template');
            $table->dropColumn('template_id');
        });

        Schema::table('core_course_products', function (Blueprint $table): void {
            $table->dropIndex('idx_ccp_v2_cat_sort');
            $table->dropIndex('idx_ccp_v2_offering');
            $table->dropForeign('fk_ccp_v2_category');
            $table->dropForeign('fk_ccp_v2_intro_image');
            $table->dropForeign('fk_ccp_v2_intro_video');
            $table->dropForeign('fk_ccp_v2_intro_doc');
            $table->dropColumn([
                'category_id', 'offering_type', 'uses_custom_description',
                'uses_custom_intro_media', 'intro_image_media_file_id',
                'intro_video_source', 'intro_video_media_file_id',
                'intro_video_embed_url', 'intro_video_provider',
                'intro_document_media_file_id', 'promotion_enabled',
                'discount_type', 'discount_value',
            ]);
        });
    }
};
