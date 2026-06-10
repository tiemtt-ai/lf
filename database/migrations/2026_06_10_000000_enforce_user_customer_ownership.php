<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('customer_id')
            ->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable(false)
                ->change();

            $table->foreign('customer_id')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->change();

            $table->foreign('customer_id')
                ->references('id')
                ->on('saas_customers')
                ->nullOnDelete();
        });
    }
};
