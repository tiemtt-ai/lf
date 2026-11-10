<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGER = 'trg_core_course_enrollments_binding_immutable_bu';

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $exists = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', self::TRIGGER)
            ->exists();
        if ($exists) {
            throw new RuntimeException(
                'Enrollment binding trigger already exists outside this migration history: '.self::TRIGGER
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_core_course_enrollments_binding_immutable_bu
BEFORE UPDATE ON core_course_enrollments
FOR EACH ROW
BEGIN
    IF NOT (OLD.product_id <=> NEW.product_id)
       OR NOT (OLD.version_id <=> NEW.version_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LF_ENROLLMENT_BINDING_IMMUTABLE:trg_core_course_enrollments_binding_immutable_bu';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
    }
};
