<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saas_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained('saas_customers')
                ->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('action', 100);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at']);
            $table->index(['customer_id', 'target_user_id']);
            $table->index(['customer_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saas_audit_logs');
    }
};
