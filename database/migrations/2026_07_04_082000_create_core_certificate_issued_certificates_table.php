<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_certificate_issued_certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('certificate_template_id');
            $table->unsignedBigInteger('certificate_template_product_id')->nullable();
            $table->unsignedBigInteger('completion_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('version_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('certificate_number', 100);
            $table->string('verification_code', 100);
            $table->string('verification_url', 500)->nullable();
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->string('product_title_snapshot')->nullable();
            $table->string('product_code_snapshot', 100)->nullable();
            $table->string('course_template_title_snapshot')->nullable();
            $table->string('template_code_snapshot', 100)->nullable();
            $table->string('template_name_snapshot')->nullable();
            $table->unsignedInteger('certificate_template_version_snapshot')->nullable();
            $table->unsignedInteger('course_template_version_number_snapshot')->nullable();
            $table->string('render_engine_snapshot', 50)->nullable();
            $table->json('layout_data_snapshot')->nullable();
            $table->string('completion_rule_snapshot', 100)->nullable();
            $table->decimal('final_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->dateTime('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('issue_source', 50)->default('system');
            $table->string('issue_note', 500)->nullable();
            $table->unsignedBigInteger('file_id')->nullable();
            $table->string('file_url', 1000)->nullable();
            $table->string('qr_code_data', 1000)->nullable();
            $table->string('status', 50)->default('issued');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->unsignedBigInteger('reissued_from_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cert_issued_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('certificate_template_id', 'fk_cert_issued_template')
                ->references('id')
                ->on('core_certificate_templates')
                ->restrictOnDelete();
            $table->foreign('certificate_template_product_id', 'fk_cert_issued_mapping')
                ->references('id')
                ->on('core_certificate_template_products')
                ->restrictOnDelete();
            $table->foreign('completion_id', 'fk_cert_issued_completion')
                ->references('id')
                ->on('core_course_completions')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_cert_issued_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_cert_issued_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_cert_issued_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_cert_issued_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('issued_by', 'fk_cert_issued_issued_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('revoked_by', 'fk_cert_issued_revoked_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('reissued_from_id', 'fk_cert_issued_reissued_from')
                ->references('id')
                ->on('core_certificate_issued_certificates')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_cert_issued_customer');
            $table->index(['customer_id', 'certificate_template_id'], 'idx_cert_issued_template');
            $table->index(
                ['customer_id', 'certificate_template_product_id'],
                'idx_cert_issued_mapping'
            );
            $table->index(['customer_id', 'completion_id'], 'idx_cert_issued_completion');
            $table->index(['customer_id', 'enrollment_id'], 'idx_cert_issued_enrollment');
            $table->index(['customer_id', 'product_id'], 'idx_cert_issued_product');
            $table->index(['customer_id', 'version_id'], 'idx_cert_issued_version');
            $table->index(['customer_id', 'student_id'], 'idx_cert_issued_student');
            $table->index(['customer_id', 'issued_at'], 'idx_cert_issued_issued_at');
            $table->index(['customer_id', 'expires_at'], 'idx_cert_issued_expires_at');
            $table->index(['customer_id', 'status'], 'idx_cert_issued_status');
            $table->index(['customer_id', 'verification_code'], 'idx_cert_issued_verification');
            $table->index(['customer_id', 'reissued_from_id'], 'idx_cert_issued_reissued_from');
            $table->unique(['customer_id', 'certificate_number'], 'uk_cert_issued_number');
            $table->unique(['customer_id', 'verification_code'], 'uk_cert_issued_verification');
            $table->unique(['customer_id', 'completion_id'], 'uk_cert_issued_completion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_certificate_issued_certificates');
    }
};
