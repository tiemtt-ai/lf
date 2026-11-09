<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default Meeting Link Amendment 2026-11-09 (core_course_cohorts.md): the class's shared meeting link.
 * A Session's own link wins; this is the default for new Sessions and for a Session left without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            $table->string('default_meeting_url', 2000)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            $table->dropColumn('default_meeting_url');
        });
    }
};
