<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_enrollment_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('admin_id');
            $table->char('token_hash', 64);
            $table->char('payload_hash', 64);
            $table->json('student_ids');
            $table->json('product_ids');
            $table->json('reenrollment_confirmations');
            $table->json('configuration');
            $table->unsignedSmallInteger('pair_count');
            $table->string('status', 50);
            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cces_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('admin_id', 'fk_cces_admin')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->unique('token_hash', 'uk_cces_token_hash');
            $table->index(
                ['customer_id', 'admin_id', 'status', 'expires_at'],
                'idx_cces_admin_status_expiry'
            );
            $table->index(
                ['customer_id', 'created_at'],
                'idx_cces_customer_created'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_enrollment_submissions');
    }
};
