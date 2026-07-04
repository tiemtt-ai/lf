<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_learning_paths', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('path_code', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('thumbnail_file_id')->nullable();
            $table->string('difficulty_level', 50)->nullable();
            $table->unsignedInteger('estimated_duration_days')->nullable();
            $table->boolean('certificate_available')->default(false);
            $table->string('visibility', 50)->default('public');
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_learning_paths_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_learning_paths_customer');
            $table->index(['customer_id', 'status'], 'idx_learning_paths_status');
            $table->index(['customer_id', 'visibility'], 'idx_learning_paths_visibility');
            $table->index(['customer_id', 'sort_order'], 'idx_learning_paths_sort');
            $table->unique(['customer_id', 'path_code'], 'uniq_learning_path_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_learning_paths');
    }
};
