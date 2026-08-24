<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('ALTER TABLE core_course_templates ADD CONSTRAINT chk_cct_learning_selection_pair CHECK ((selected_learning_framework_id IS NULL) = (selected_learning_framework_version_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('ALTER TABLE core_course_templates DROP CONSTRAINT chk_cct_learning_selection_pair');
        }
    }
};
