<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_template_sections', 'parent_section_id')) {
                $table->unsignedBigInteger('parent_section_id')
                    ->nullable()
                    ->after('template_id');
            }
            if (! Schema::hasColumn('core_course_template_sections', 'allows_lessons')) {
                $table->boolean('allows_lessons')->nullable()->after('parent_section_id');
            }
        });

        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_template_version_sections', 'parent_version_section_id')) {
                $table->unsignedBigInteger('parent_version_section_id')
                    ->nullable()
                    ->after('source_template_section_id');
            }
            if (! Schema::hasColumn('core_course_template_version_sections', 'allows_lessons')) {
                $table->boolean('allows_lessons')->nullable()->after('parent_version_section_id');
            }
        });

        // Before this capability existed, every Section accepted Lessons. This
        // backfill preserves that historical behavior only for existing rows;
        // new writes must always provide an explicit value.
        DB::table('core_course_template_sections')
            ->whereNull('allows_lessons')
            ->update(['allows_lessons' => true]);
        DB::table('core_course_template_version_sections')
            ->whereNull('allows_lessons')
            ->update(['allows_lessons' => true]);

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            $table->boolean('allows_lessons')->nullable(false)->change();
        });
        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            $table->boolean('allows_lessons')->nullable(false)->change();
        });

        $this->dropIndexIfExists('core_course_template_sections', 'idx_ccts_display_order');
        $this->dropIndexIfExists('core_course_template_version_sections', 'idx_cctvs_display_order');

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            $this->indexIfMissing(
                $table,
                'core_course_template_sections',
                ['customer_id', 'template_id', 'parent_section_id'],
                'idx_ccts_parent'
            );
            $this->indexIfMissing(
                $table,
                'core_course_template_sections',
                ['customer_id', 'template_id', 'parent_section_id', 'display_order'],
                'idx_ccts_display_order'
            );
        });

        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            $this->indexIfMissing(
                $table,
                'core_course_template_version_sections',
                ['customer_id', 'template_version_id', 'parent_version_section_id'],
                'idx_cctvs_parent'
            );
            $this->indexIfMissing(
                $table,
                'core_course_template_version_sections',
                ['customer_id', 'template_version_id', 'parent_version_section_id', 'display_order'],
                'idx_cctvs_display_order'
            );
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('core_course_template_sections', function (Blueprint $table): void {
                $table->foreign('parent_section_id', 'fk_ccts_parent')
                    ->references('id')
                    ->on('core_course_template_sections')
                    ->restrictOnDelete();
            });

            Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
                $table->foreign('parent_version_section_id', 'fk_cctvs_parent')
                    ->references('id')
                    ->on('core_course_template_version_sections')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->dropForeignIfExists(
                'core_course_template_sections',
                'fk_ccts_parent'
            );
            $this->dropForeignIfExists(
                'core_course_template_version_sections',
                'fk_cctvs_parent'
            );
        }

        $this->dropIndexIfExists('core_course_template_sections', 'idx_ccts_display_order');
        $this->dropIndexIfExists('core_course_template_sections', 'idx_ccts_parent');
        $this->dropIndexIfExists('core_course_template_version_sections', 'idx_cctvs_display_order');
        $this->dropIndexIfExists('core_course_template_version_sections', 'idx_cctvs_parent');

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            if (Schema::hasColumn('core_course_template_sections', 'parent_section_id')) {
                $table->dropColumn('parent_section_id');
            }
            if (Schema::hasColumn('core_course_template_sections', 'allows_lessons')) {
                $table->dropColumn('allows_lessons');
            }

            $this->indexIfMissing(
                $table,
                'core_course_template_sections',
                ['customer_id', 'template_id', 'display_order'],
                'idx_ccts_display_order'
            );
        });

        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            if (Schema::hasColumn('core_course_template_version_sections', 'parent_version_section_id')) {
                $table->dropColumn('parent_version_section_id');
            }
            if (Schema::hasColumn('core_course_template_version_sections', 'allows_lessons')) {
                $table->dropColumn('allows_lessons');
            }

            $this->indexIfMissing(
                $table,
                'core_course_template_version_sections',
                ['customer_id', 'template_version_id', 'display_order'],
                'idx_cctvs_display_order'
            );
        });
    }

    private function indexIfMissing(
        Blueprint $table,
        string $tableName,
        array $columns,
        string $name
    ): void {
        if ($this->hasIndex($tableName, $name)) {
            return;
        }

        $table->index($columns, $name);
    }

    private function dropIndexIfExists(string $tableName, string $name): void
    {
        if (! $this->hasIndex($tableName, $name)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function dropForeignIfExists(string $tableName, string $name): void
    {
        $foreignKeys = collect(Schema::getForeignKeys($tableName))
            ->pluck('name')
            ->map(fn (string $foreignKey): string => strtolower($foreignKey))
            ->all();

        if (! in_array(strtolower($name), $foreignKeys, true)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($name): void {
            $table->dropForeign($name);
        });
    }

    private function hasIndex(string $tableName, string $name): bool
    {
        return collect(Schema::getIndexes($tableName))
            ->pluck('name')
            ->map(fn (string $index): string => strtolower($index))
            ->contains(strtolower($name));
    }
};
