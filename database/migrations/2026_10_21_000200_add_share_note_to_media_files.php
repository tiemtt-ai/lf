<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            // What the owner tells the people a Media is shared with (purpose, where it fits): ADR-0021 D11. Empty when private.
            $table->string('share_note', 300)->nullable()->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropColumn('share_note');
        });
    }
};
