<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_certificate_download_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('certificate_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->string('source', 50)->default('web');
            $table->string('ip_address', 100)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->string('referer_url', 1000)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->dateTime('activity_at');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('customer_id', 'fk_cert_dl_logs_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('certificate_id', 'fk_cert_dl_logs_certificate')
                ->references('id')
                ->on('core_certificate_issued_certificates')
                ->restrictOnDelete();
            $table->foreign('user_id', 'fk_cert_dl_logs_user')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('customer_id', 'idx_cert_dl_logs_customer');
            $table->index(['customer_id', 'certificate_id'], 'idx_cert_dl_logs_certificate');
            $table->index(['customer_id', 'user_id'], 'idx_cert_dl_logs_user');
            $table->index(['customer_id', 'action'], 'idx_cert_dl_logs_action');
            $table->index(['customer_id', 'source'], 'idx_cert_dl_logs_source');
            $table->index(['customer_id', 'activity_at'], 'idx_cert_dl_logs_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_certificate_download_logs');
    }
};
