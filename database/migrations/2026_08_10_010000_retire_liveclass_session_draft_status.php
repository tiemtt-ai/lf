<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the `draft` Session status.
 *
 * `core_liveclass_sessions.md` § Session Status And Time Convention Amendment —
 * 2026-08-10 fixes the canonical set at `scheduled|live|completed|cancelled|
 * no_show`. No approved policy ever defined what `draft` meant for a Session,
 * how a row entered it or how it left, and the application has never written it
 * — the value survived only as the column default from the original migration.
 *
 * Forward-only: the historical migration is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive rather than expected: the application only ever inserts
        // `scheduled`, so this should touch nothing. Normalising before the
        // default changes guarantees no row is left outside the canonical set.
        DB::table('core_liveclass_sessions')
            ->where('status', 'draft')
            ->update(['status' => 'scheduled']);

        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->string('status', 50)->default('scheduled')->change();
        });
    }

    public function down(): void
    {
        // Only the default is restored. Rows are not moved back to `draft`:
        // which of them had ever been `draft` is not recoverable, and inventing
        // that would corrupt operational history.
        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->string('status', 50)->default('draft')->change();
        });
    }
};
