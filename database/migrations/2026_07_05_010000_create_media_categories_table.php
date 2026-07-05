<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('color', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_media_categories_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('parent_id', 'fk_media_categories_parent')
                ->references('id')
                ->on('media_categories')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_media_categories_customer');
            $table->index(['customer_id', 'parent_id'], 'idx_media_categories_parent');
            $table->index(['customer_id', 'status'], 'idx_media_categories_status');
            $table->unique(['customer_id', 'slug'], 'uk_media_categories_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_categories');
    }
};
