<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addRequiredColumn(
            'core_course_template_sections',
            'parent_section_id'
        );
        $this->addRequiredColumn(
            'core_course_template_version_sections',
            'parent_version_section_id'
        );
    }

    public function down(): void
    {
        foreach ([
            'core_course_template_sections',
            'core_course_template_version_sections',
        ] as $tableName) {
            if (! Schema::hasColumn($tableName, 'allows_lessons')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('allows_lessons');
            });
        }
    }

    private function addRequiredColumn(string $tableName, string $after): void
    {
        if (! Schema::hasColumn($tableName, 'allows_lessons')) {
            Schema::table($tableName, function (Blueprint $table) use ($after): void {
                $table->boolean('allows_lessons')
                    ->nullable()
                    ->after($after);
            });
        }

        DB::table($tableName)
            ->whereNull('allows_lessons')
            ->update(['allows_lessons' => true]);

        Schema::table($tableName, function (Blueprint $table): void {
            $table->boolean('allows_lessons')
                ->nullable(false)
                ->change();
        });
    }
};
