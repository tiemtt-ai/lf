<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('product_code', 100);
            $table->string('product_type', 50);
            $table->string('title');
            $table->string('slug');
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('thumbnail_type', 50);
            $table->string('thumbnail_image', 500)->nullable();
            $table->string('thumbnail_video_source', 50)->nullable();
            $table->string('thumbnail_video_url', 1000)->nullable();
            $table->unsignedBigInteger('thumbnail_video_media_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->string('currency', 10);
            $table->string('enrollment_type', 50);
            $table->unsignedInteger('max_students')->nullable();
            $table->unsignedInteger('enrollment_count')->default(0);
            $table->unsignedInteger('access_duration_days')->nullable();
            $table->unsignedInteger('review_duration_days')->nullable();
            $table->boolean('is_certificate_enabled')->default(false);
            $table->boolean('is_refundable')->default(false);
            $table->unsignedInteger('refund_days')->nullable();
            $table->json('tags')->nullable();
            $table->string('badge_type', 50)->nullable();
            $table->boolean('show_enrollment_count')->default(true);
            $table->unsignedInteger('display_enrollment_count')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('visibility', 50);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->timestamp('registration_starts_at')->nullable();
            $table->timestamp('registration_ends_at')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->string('status', 50);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_ccp_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_ccp_customer');
            $table->index(
                ['customer_id', 'product_type'],
                'idx_ccp_customer_type'
            );
            $table->index(
                ['customer_id', 'product_code'],
                'idx_ccp_customer_code'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_ccp_customer_status'
            );
            $table->index(
                ['customer_id', 'visibility'],
                'idx_ccp_customer_visibility'
            );
            $table->index(
                ['customer_id', 'slug'],
                'idx_ccp_customer_slug'
            );
            $table->index(
                ['customer_id', 'price'],
                'idx_ccp_customer_price'
            );
            $table->index(
                ['customer_id', 'sale_starts_at'],
                'idx_ccp_customer_sale_start'
            );
            $table->index(
                ['customer_id', 'sale_ends_at'],
                'idx_ccp_customer_sale_end'
            );
            $table->index(
                ['customer_id', 'access_duration_days'],
                'idx_ccp_customer_access_days'
            );
            $table->index(
                ['customer_id', 'is_featured'],
                'idx_ccp_customer_featured'
            );
            $table->index(
                ['customer_id', 'badge_type'],
                'idx_ccp_customer_badge'
            );
            $table->index(
                ['customer_id', 'registration_starts_at'],
                'idx_ccp_customer_reg_start'
            );
            $table->index(
                ['customer_id', 'registration_ends_at'],
                'idx_ccp_customer_reg_end'
            );
            $table->index(
                ['customer_id', 'created_by'],
                'idx_ccp_customer_creator'
            );
            $table->index(
                ['customer_id', 'published_at'],
                'idx_ccp_customer_published'
            );
            $table->unique(
                ['customer_id', 'slug'],
                'uk_ccp_customer_slug'
            );
            $table->unique(
                ['customer_id', 'product_code'],
                'uk_ccp_customer_code'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_products');
    }
};
