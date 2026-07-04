<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_certificate_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('certificate_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('verification_code', 100);
            $table->string('verification_url', 500)->nullable();
            $table->string('result', 50);
            $table->string('certificate_status_snapshot', 50)->nullable();
            $table->string('recipient_name_snapshot')->nullable();
            $table->string('product_title_snapshot')->nullable();
            $table->string('certificate_number_snapshot', 100)->nullable();
            $table->dateTime('verified_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->string('referer', 1000)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('customer_id', 'fk_cert_ver_logs_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('certificate_id', 'fk_cert_ver_logs_certificate')
                ->references('id')
                ->on('core_certificate_issued_certificates')
                ->restrictOnDelete();
            $table->foreign('user_id', 'fk_cert_ver_logs_user')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('customer_id', 'idx_cert_ver_logs_customer');
            $table->index(['customer_id', 'certificate_id'], 'idx_cert_ver_logs_certificate');
            $table->index('verification_code', 'idx_cert_ver_logs_code');
            $table->index(['customer_id', 'result'], 'idx_cert_ver_logs_result');
            $table->index(['customer_id', 'verified_at'], 'idx_cert_ver_logs_verified_at');
            $table->index('ip_address', 'idx_cert_ver_logs_ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_certificate_verification_logs');
    }
};
