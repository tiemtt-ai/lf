<?php

use App\Http\Controllers\CourseCohortController;
use App\Http\Controllers\CourseCohortExportController;
use App\Http\Controllers\CourseCohortOperationController;
use App\Http\Controllers\LiveClassScheduleController;
use App\Http\Controllers\LiveClassSessionOperationsController;
use Illuminate\Support\Facades\Route;

/*
 * The class pages an admin and a class's primary teacher share (ADR-0033 D7): required inside the admin area by
 * course.php and inside the teacher area behind `teacher.may:class.manage`. Each controller checks the scope again
 * (AuthorizesCourseCohortAdmin::authorizeCohortManager). Enrolment, the class teachers, attendance policies,
 * completing a class early, archiving and runtime operations stay in course.php, admin only.
 */

Route::get('/course-cohorts/create', [CourseCohortController::class, 'create'])
    ->name('course-cohorts.create');

Route::post('/course-cohorts', [CourseCohortController::class, 'store'])
    ->name('course-cohorts.store');

Route::get('/course-cohorts/{id}', [CourseCohortController::class, 'show'])
    ->name('course-cohorts.show');

// Excel downloads of one Cohort: the Học viên tab roster and the Điểm danh grid.
Route::get('/course-cohorts/{id}/students/export', [CourseCohortExportController::class, 'students'])
    ->whereNumber('id')->name('course-cohorts.students.export');

Route::get('/course-cohorts/{id}/attendance/export', [CourseCohortExportController::class, 'attendance'])
    ->whereNumber('id')->name('course-cohorts.attendance.export');

Route::get('/course-cohorts/{id}/edit', [CourseCohortController::class, 'edit'])
    ->name('course-cohorts.edit');

Route::put('/course-cohorts/{id}', [CourseCohortController::class, 'update'])
    ->name('course-cohorts.update');

Route::post('/course-cohorts/{id}/activate', [CourseCohortController::class, 'activate'])
    ->name('course-cohorts.activate');

Route::get('/course-cohorts/{cohort}/schedules/create', [LiveClassScheduleController::class, 'create'])
    ->name('course-cohorts.schedules.create');
Route::post('/course-cohorts/{cohort}/schedules/preview', [LiveClassScheduleController::class, 'preview'])
    ->name('course-cohorts.schedules.preview');
Route::post('/course-cohorts/{cohort}/schedules', [LiveClassScheduleController::class, 'store'])
    ->name('course-cohorts.schedules.store');
Route::get('/course-cohorts/{cohort}/schedules/{schedule}', [LiveClassScheduleController::class, 'show'])
    ->name('course-cohorts.schedules.show');
Route::get('/course-cohorts/{cohort}/schedules/{schedule}/edit', [LiveClassScheduleController::class, 'edit'])
    ->name('course-cohorts.schedules.edit');
Route::put('/course-cohorts/{cohort}/schedules/{schedule}', [LiveClassScheduleController::class, 'update'])
    ->name('course-cohorts.schedules.update');
Route::delete('/course-cohorts/{cohort}/schedules/{schedule}', [LiveClassScheduleController::class, 'destroy'])
    ->name('course-cohorts.schedules.destroy');
Route::patch('/course-cohorts/{cohort}/content/lessons/order', [CourseCohortOperationController::class, 'reorderLessons'])
    ->name('course-cohorts.content.lessons.reorder');
Route::patch('/course-cohorts/{cohort}/content/lessons/{lesson}/activities/order', [CourseCohortOperationController::class, 'reorderActivities'])
    ->name('course-cohorts.content.activities.reorder');
Route::post('/course-cohorts/{cohort}/sessions', [CourseCohortOperationController::class, 'storeSession'])
    ->name('course-cohorts.sessions.store');
Route::put('/course-cohorts/{cohort}/sessions/{session}', [CourseCohortOperationController::class, 'updateSession'])
    ->name('course-cohorts.sessions.update');
Route::put('/course-cohorts/{cohort}/sessions/{session}/schedule', [CourseCohortOperationController::class, 'updateSchedule'])
    ->name('course-cohorts.sessions.schedule');
// The meeting link stays editable after the planned start, until the planned end (ADR-0002 § Teacher Session Operations Amendment).
Route::put('/course-cohorts/{cohort}/sessions/{session}/meeting-link', [LiveClassSessionOperationsController::class, 'adminMeetingLink'])
    ->whereNumber(['cohort', 'session'])->name('course-cohorts.sessions.meeting-link');

Route::put('/course-cohorts/{cohort}/sessions/{session}/teachers', [CourseCohortOperationController::class, 'assignSessionTeachers'])
    ->name('course-cohorts.sessions.teachers');
Route::post('/course-cohorts/{cohort}/sessions/{session}/cancel', [CourseCohortOperationController::class, 'cancelSession'])
    ->name('course-cohorts.sessions.cancel');
Route::post('/course-cohorts/{cohort}/sessions/{session}/no-show', [CourseCohortOperationController::class, 'markSessionNoShow'])
    ->name('course-cohorts.sessions.no-show');
