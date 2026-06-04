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
        Schema::table('saas_customers', function (Blueprint $table) {
            $table->string('subdomain')->nullable()->unique()->after('slug');
            $table->string('custom_domain')->nullable()->unique()->after('subdomain');

            $table->string('theme_key')->default('default')->after('custom_domain');
            $table->string('layout_key')->default('default')->after('theme_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saas_customers', function (Blueprint $table) {
            //
        });
    }
};
