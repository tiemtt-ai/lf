<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `core_course_cohort_students.joined_at`, `core_course_completions.completed_at`,
 * `core_course_enrollment_submissions.expires_at` and
 * `core_course_enrollments.enrolled_at` were declared as plain
 * `$table->timestamp('...')` with no explicit default in their origin
 * migrations. Each is the first non-nullable TIMESTAMP column in its table,
 * so on a server running with `explicit_defaults_for_timestamp = OFF`
 * (legacy MySQL/MariaDB behaviour, confirmed active on this deployment)
 * MySQL/MariaDB silently attaches BOTH `DEFAULT CURRENT_TIMESTAMP` AND
 * `ON UPDATE CURRENT_TIMESTAMP` to it — neither of which was requested or
 * declared anywhere in application code, migrations, or
 * docs/database/LF-SCHEMA-CONTRACT.json (contract declares only
 * `default: current_timestamp()`, no on-update).
 *
 * The `ON UPDATE CURRENT_TIMESTAMP` half is an active data-integrity bug:
 * these columns record a historical occurrence moment (when an enrollment
 * happened, when a membership started, when a submission expires-by).
 * Any later `UPDATE` on the row for an unrelated reason (status change,
 * cohort transfer, etc.) silently overwrites the original moment with the
 * time of that unrelated update. Confirmed already happened on this
 * database: 5 of 11 `core_course_enrollments` rows and 3 of 7
 * `core_course_cohort_students` rows have `enrolled_at`/`joined_at`
 * identical to `updated_at` and materially different from `created_at`.
 *
 * This migration makes the default explicit (matching the contract) and
 * removes the accidental on-update clause. It does not touch existing row
 * values — MODIFY COLUMN only changes the column's metadata. The rows
 * already corrupted by the on-update behaviour are left as-is: their true
 * original timestamp cannot be reconstructed from this table alone, and no
 * other evidence source was identified. See the Regression Audit report for
 * this change for the full investigation.
 */
return new class extends Migration
{
    private const TARGETS = [
        'core_course_cohort_students' => 'joined_at',
        'core_course_completions' => 'completed_at',
        'core_course_enrollment_submissions' => 'expires_at',
        'core_course_enrollments' => 'enrolled_at',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TARGETS as $table => $column) {
            DB::statement(
                "ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Best-effort revert to the bare declaration the origin migrations
        // used. On a server with explicit_defaults_for_timestamp = OFF this
        // will again implicitly gain DEFAULT/ON UPDATE CURRENT_TIMESTAMP —
        // that ambiguity is inherent to the original migrations, not
        // something this rollback can resolve.
        foreach (self::TARGETS as $table => $column) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL");
        }
    }
};
