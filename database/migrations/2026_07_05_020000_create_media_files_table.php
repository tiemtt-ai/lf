<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $usesAsciiStorageCollation = Schema::getConnection()->getDriverName()
            !== 'sqlite';

        Schema::create('media_files', function (Blueprint $table) use (
            $usesAsciiStorageCollation
        ) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->string('file_type', 50);
            $table->string('mime_type');
            $table->string('original_name');
            $table->string('display_name');
            $table->string('extension', 32)->nullable();
            $storageDisk = $table->string('storage_disk', 50)->default('s3');
            $storageBucket = $table->string('storage_bucket');
            $table->string('storage_region', 100)->nullable();
            $storageKey = $table->string('storage_key', 1024);

            if ($usesAsciiStorageCollation) {
                $storageDisk->charset('ascii')->collation('ascii_bin');
                $storageBucket->charset('ascii')->collation('ascii_bin');
                $storageKey->charset('ascii')->collation('ascii_bin');
            }

            $table->string('storage_class', 100)->nullable();
            $table->text('cdn_url')->nullable();
            $table->text('public_url')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->string('language', 20)->nullable();
            $table->string('visibility', 50)->default('private');
            $table->string('status', 50)->default('uploading');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_media_files_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('category_id', 'fk_media_files_category')
                ->references('id')
                ->on('media_categories')
                ->restrictOnDelete();
            $table->foreign('uploaded_by', 'fk_media_files_uploader')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_media_files_customer');
            $table->index(['customer_id', 'category_id'], 'idx_media_files_category');
            $table->index(['customer_id', 'uploaded_by'], 'idx_media_files_uploader');
            $table->index(['customer_id', 'file_type'], 'idx_media_files_file_type');
            $table->index(['customer_id', 'visibility'], 'idx_media_files_visibility');
            $table->index(['customer_id', 'status'], 'idx_media_files_status');
            $table->index(['customer_id', 'checksum'], 'idx_media_files_checksum');
            $table->unique(
                ['customer_id', 'storage_disk', 'storage_bucket', 'storage_key'],
                'uk_media_files_storage_key'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
