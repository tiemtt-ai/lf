<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTables();

            return;
        }

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_template_sections', 'display_order')) {
                $table->unsignedInteger('display_order')
                    ->default(1)
                    ->after('description');
            }
        });

        if (Schema::hasColumn('core_course_template_sections', 'sort_order')) {
            DB::table('core_course_template_sections')
                ->update(['display_order' => DB::raw('sort_order')]);
        }

        $this->dropForeignIfNotSqlite('core_course_template_sections', 'fk_ccts_parent');
        $this->dropIndexIfExists('core_course_template_sections', 'idx_ccts_parent');
        $this->dropIndexIfExists('core_course_template_sections', 'idx_ccts_sort');
        $this->dropIndexIfExists('core_course_template_sections', 'idx_ccts_status');
        $this->dropIndexIfExists('core_course_template_sections', 'uk_ccts_code');
        $this->dropIndexIfExists('core_course_template_sections', 'uk_ccts_sort');

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            $this->dropColumnsIfPresent($table, 'core_course_template_sections', [
                'parent_section_id',
                'code',
                'short_title',
                'thumbnail_file_id',
                'sort_order',
                'is_required',
                'unlock_rule',
                'estimated_duration_minutes',
                'total_lessons',
                'status',
                'metadata',
            ]);

            $table->index(
                ['customer_id', 'template_id', 'display_order'],
                'idx_ccts_display_order'
            );
        });

        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_template_version_sections', 'display_order')) {
                $table->unsignedInteger('display_order')
                    ->default(1)
                    ->after('description_snapshot');
            }
        });

        if (Schema::hasColumn('core_course_template_version_sections', 'sort_order')) {
            DB::table('core_course_template_version_sections')
                ->update(['display_order' => DB::raw('sort_order')]);
        }

        $this->dropForeignIfNotSqlite(
            'core_course_template_version_sections',
            'fk_cctvs_parent'
        );
        $this->dropIndexIfExists('core_course_template_version_sections', 'idx_cctvs_parent');
        $this->dropIndexIfExists('core_course_template_version_sections', 'idx_cctvs_sort');
        $this->dropIndexIfExists('core_course_template_version_sections', 'uk_cctvs_sort');

        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            $this->dropColumnsIfPresent($table, 'core_course_template_version_sections', [
                'parent_version_section_id',
                'code_snapshot',
                'short_title_snapshot',
                'thumbnail_file_id_snapshot',
                'sort_order',
                'is_required',
                'unlock_rule_snapshot',
                'estimated_duration_minutes',
                'total_lessons',
                'status_snapshot',
                'metadata_snapshot',
            ]);

            $table->index(
                ['customer_id', 'template_version_id', 'display_order'],
                'idx_cctvs_display_order'
            );
        });
    }

    private function rebuildSqliteTables(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');
        DB::statement('PRAGMA legacy_alter_table=ON');

        Schema::dropIfExists('core_course_template_sections_legacy');
        Schema::dropIfExists('core_course_template_version_sections_legacy');

        Schema::rename(
            'core_course_template_sections',
            'core_course_template_sections_legacy'
        );
        Schema::create('core_course_template_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(1);
            $table->timestamps();

            $table->foreign('customer_id', 'fk_ccts_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('template_id', 'fk_ccts_template')
                ->references('id')
                ->on('core_course_templates')
                ->restrictOnDelete();
        });
        DB::table('core_course_template_sections')->insertUsing(
            [
                'id',
                'customer_id',
                'template_id',
                'title',
                'description',
                'display_order',
                'created_at',
                'updated_at',
            ],
            DB::table('core_course_template_sections_legacy')->select([
                'id',
                'customer_id',
                'template_id',
                'title',
                'description',
                DB::raw('sort_order as display_order'),
                'created_at',
                'updated_at',
            ])
        );

        Schema::rename(
            'core_course_template_version_sections',
            'core_course_template_version_sections_legacy'
        );
        Schema::create(
            'core_course_template_version_sections',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('template_version_id');
                $table->unsignedBigInteger('source_template_section_id');
                $table->string('title_snapshot');
                $table->text('description_snapshot')->nullable();
                $table->unsignedInteger('display_order')->default(1);
                $table->timestamps();

                $table->foreign('customer_id', 'fk_cctvs_customer')
                    ->references('id')
                    ->on('saas_customers')
                    ->restrictOnDelete();
                $table->foreign(
                    'template_version_id',
                    'fk_cctvs_version'
                )
                    ->references('id')
                    ->on('core_course_template_versions')
                    ->restrictOnDelete();
            }
        );
        DB::table('core_course_template_version_sections')->insertUsing(
            [
                'id',
                'customer_id',
                'template_version_id',
                'source_template_section_id',
                'title_snapshot',
                'description_snapshot',
                'display_order',
                'created_at',
                'updated_at',
            ],
            DB::table('core_course_template_version_sections_legacy')->select([
                'id',
                'customer_id',
                'template_version_id',
                'source_template_section_id',
                'title_snapshot',
                'description_snapshot',
                DB::raw('sort_order as display_order'),
                'created_at',
                'updated_at',
            ])
        );

        Schema::dropIfExists('core_course_template_sections_legacy');
        Schema::dropIfExists('core_course_template_version_sections_legacy');

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            $table->index('customer_id', 'idx_ccts_customer');
            $table->index(
                ['customer_id', 'template_id'],
                'idx_ccts_template'
            );
            $table->index(
                ['customer_id', 'template_id', 'display_order'],
                'idx_ccts_display_order'
            );
        });
        Schema::table(
            'core_course_template_version_sections',
            function (Blueprint $table): void {
                $table->index('customer_id', 'idx_cctvs_customer');
                $table->index(
                    ['customer_id', 'template_version_id'],
                    'idx_cctvs_version'
                );
                $table->index(
                    ['customer_id', 'source_template_section_id'],
                    'idx_cctvs_source'
                );
                $table->index(
                    ['customer_id', 'template_version_id', 'display_order'],
                    'idx_cctvs_display_order'
                );
                $table->unique(
                    [
                        'customer_id',
                        'template_version_id',
                        'source_template_section_id',
                    ],
                    'uk_cctvs_source'
                );
            }
        );

        DB::statement('PRAGMA legacy_alter_table=OFF');
        DB::statement('PRAGMA foreign_keys=ON');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('core_course_template_sections', 'idx_ccts_display_order');

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_template_sections', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(1)->after('description');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'parent_section_id')) {
                $table->unsignedBigInteger('parent_section_id')->nullable()->after('template_id');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'code')) {
                $table->string('code', 100)->nullable()->after('parent_section_id');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'short_title')) {
                $table->string('short_title', 100)->nullable()->after('title');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'thumbnail_file_id')) {
                $table->unsignedBigInteger('thumbnail_file_id')->nullable()->after('description');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('sort_order');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'unlock_rule')) {
                $table->string('unlock_rule', 50)->default('immediate')->after('is_required');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'estimated_duration_minutes')) {
                $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('unlock_rule');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'total_lessons')) {
                $table->unsignedInteger('total_lessons')->default(0)->after('estimated_duration_minutes');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'status')) {
                $table->string('status', 50)->default('active')->after('total_lessons');
            }

            if (! Schema::hasColumn('core_course_template_sections', 'metadata')) {
                $table->json('metadata')->nullable()->after('status');
            }
        });

        if (Schema::hasColumn('core_course_template_sections', 'display_order')) {
            DB::table('core_course_template_sections')
                ->update(['sort_order' => DB::raw('display_order')]);
        }

        Schema::table('core_course_template_sections', function (Blueprint $table): void {
            if (Schema::hasColumn('core_course_template_sections', 'display_order')) {
                $table->dropColumn('display_order');
            }
        });

        $this->dropIndexIfExists('core_course_template_version_sections', 'idx_cctvs_display_order');

        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_template_version_sections', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(1)->after('description_snapshot');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'parent_version_section_id')) {
                $table->unsignedBigInteger('parent_version_section_id')->nullable()->after('source_template_section_id');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'code_snapshot')) {
                $table->string('code_snapshot', 100)->nullable()->after('parent_version_section_id');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'short_title_snapshot')) {
                $table->string('short_title_snapshot', 100)->nullable()->after('title_snapshot');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'thumbnail_file_id_snapshot')) {
                $table->unsignedBigInteger('thumbnail_file_id_snapshot')->nullable()->after('description_snapshot');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('sort_order');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'unlock_rule_snapshot')) {
                $table->string('unlock_rule_snapshot', 50)->default('immediate')->after('is_required');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'estimated_duration_minutes')) {
                $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('unlock_rule_snapshot');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'total_lessons')) {
                $table->unsignedInteger('total_lessons')->default(0)->after('estimated_duration_minutes');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'status_snapshot')) {
                $table->string('status_snapshot', 50)->default('active')->after('total_lessons');
            }

            if (! Schema::hasColumn('core_course_template_version_sections', 'metadata_snapshot')) {
                $table->json('metadata_snapshot')->nullable()->after('status_snapshot');
            }
        });

        if (Schema::hasColumn('core_course_template_version_sections', 'display_order')) {
            DB::table('core_course_template_version_sections')
                ->update(['sort_order' => DB::raw('display_order')]);
        }

        Schema::table('core_course_template_version_sections', function (Blueprint $table): void {
            if (Schema::hasColumn('core_course_template_version_sections', 'display_order')) {
                $table->dropColumn('display_order');
            }
        });
    }

    private function dropColumnsIfPresent(
        Blueprint $table,
        string $tableName,
        array $columns
    ): void {
        foreach ($columns as $column) {
            if (Schema::hasColumn($tableName, $column)) {
                $table->dropColumn($column);
            }
        }
    }

    private function dropForeignIfNotSqlite(string $tableName, string $name): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($name): void {
            $table->dropForeign($name);
        });
    }

    private function dropIndexIfExists(string $tableName, string $name): void
    {
        $indexes = collect(Schema::getIndexes($tableName))
            ->pluck('name')
            ->map(fn (string $index): string => strtolower($index))
            ->all();

        if (! in_array(strtolower($name), $indexes, true)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }
};
