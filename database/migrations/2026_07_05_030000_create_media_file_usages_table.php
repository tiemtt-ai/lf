<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_file_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('owner_type', 100);
            $table->unsignedBigInteger('owner_id');
            $table->string('usage_type', 100);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_media_file_usages_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('media_file_id', 'fk_media_file_usages_file')
                ->references('id')
                ->on('media_files')
                ->restrictOnDelete();
            $table->foreign('created_by', 'fk_media_file_usages_creator')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('customer_id', 'idx_media_file_usages_customer');
            $table->index(
                ['customer_id', 'media_file_id'],
                'idx_media_file_usages_file'
            );
            $table->index(
                ['customer_id', 'owner_type', 'owner_id'],
                'idx_media_file_usages_owner'
            );
            $table->index(
                ['customer_id', 'owner_type', 'owner_id', 'usage_type'],
                'idx_media_file_usages_owner_type'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_media_file_usages_status'
            );
            $table->unique(
                [
                    'customer_id',
                    'media_file_id',
                    'owner_type',
                    'owner_id',
                    'usage_type',
                ],
                'uk_media_file_usages_mapping'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_usages');
    }
};
