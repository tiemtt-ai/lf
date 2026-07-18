<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('lesson_count');
        });

        $positions = [];
        DB::table('core_course_templates')
            ->orderBy('customer_id')
            ->orderBy('category_id')
            ->orderBy('id')
            ->select(['id', 'customer_id', 'category_id'])
            ->get()
            ->each(function (object $template) use (&$positions): void {
                $scope = $template->customer_id.':'.($template->category_id ?? 'null');
                $positions[$scope] = ($positions[$scope] ?? -1) + 1;

                DB::table('core_course_templates')
                    ->where('id', $template->id)
                    ->update(['sort_order' => $positions[$scope]]);
            });

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->index(
                ['customer_id', 'category_id', 'sort_order', 'id'],
                'idx_cct_category_sort'
            );
        });
    }

    public function down(): void
    {
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->dropIndex('idx_cct_category_sort');
            $table->dropColumn('sort_order');
        });
    }
};
