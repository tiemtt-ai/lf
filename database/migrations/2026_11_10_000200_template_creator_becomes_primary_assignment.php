<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-11-10 (core_course_template_teachers.md § Creator And Access Amendment):
 * teacher access to a Template comes only from an active assignment. A teacher who created a
 * Template had access through `created_by`, so each such creator gets that access as a
 * `primary` assignment: an inactive row of theirs is reactivated, otherwise a row is added.
 * No other assignment changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('core_course_templates as templates')
            ->join('users as creators', function ($join): void {
                $join->on('creators.id', '=', 'templates.created_by')
                    ->on('creators.customer_id', '=', 'templates.customer_id');
            })
            ->where('creators.role', 'teacher')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('core_course_template_teachers as assignments')
                    ->whereColumn('assignments.template_id', 'templates.id')
                    ->whereColumn('assignments.teacher_id', 'templates.created_by')
                    ->where('assignments.status', 'active');
            })
            ->orderBy('templates.id')
            ->select(['templates.id', 'templates.customer_id', 'templates.created_by'])
            ->get()
            ->each(function (object $template) use ($now): void {
                $existing = DB::table('core_course_template_teachers')
                    ->where('customer_id', $template->customer_id)
                    ->where('template_id', $template->id)
                    ->where('teacher_id', $template->created_by)
                    ->first(['id']);

                if ($existing) {
                    DB::table('core_course_template_teachers')->where('id', $existing->id)->update([
                        'role' => 'primary', 'status' => 'active',
                        'assigned_by' => $template->created_by, 'assigned_at' => $now, 'updated_at' => $now,
                    ]);

                    return;
                }

                $maximumOrder = DB::table('core_course_template_teachers')
                    ->where('customer_id', $template->customer_id)
                    ->where('template_id', $template->id)
                    ->max('sort_order');

                DB::table('core_course_template_teachers')->insert([
                    'customer_id' => $template->customer_id,
                    'template_id' => $template->id,
                    'teacher_id' => $template->created_by,
                    'role' => 'primary',
                    'sort_order' => $maximumOrder === null ? 0 : (int) $maximumOrder + 1,
                    'status' => 'active',
                    'assigned_by' => $template->created_by,
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    /** The added rows are ordinary assignments from here on; nothing tells them apart to undo. */
    public function down(): void {}
};
