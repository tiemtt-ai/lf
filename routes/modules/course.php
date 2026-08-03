<?php

use App\Http\Controllers\CourseActivityMediaPreviewController;
use App\Http\Controllers\CourseCategoryController;
use App\Http\Controllers\CourseCohortController;
use App\Http\Controllers\CourseCohortOperationController;
use App\Http\Controllers\CourseCohortStudentController;
use App\Http\Controllers\CourseEnrollmentController;
use App\Http\Controllers\CourseProductController;
use App\Http\Controllers\CourseProductMediaPreviewController;
use App\Http\Controllers\CourseTemplateActivityController;
use App\Http\Controllers\CourseTemplateController;
use App\Http\Controllers\CourseTemplateLessonController;
use App\Http\Controllers\CourseTemplateMediaPreviewController;
use App\Http\Controllers\CourseTemplateSectionController;
use App\Http\Controllers\CourseTemplateTeacherController;
use App\Http\Controllers\CourseTemplateVersionMediaPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/course-categories', [CourseCategoryController::class, 'index'])
    ->name('course-categories.index');

Route::get('/course-categories/create', [CourseCategoryController::class, 'create'])
    ->name('course-categories.create');

Route::post('/course-categories', [CourseCategoryController::class, 'store'])
    ->name('course-categories.store');

Route::get('/course-categories/{id}/edit', [CourseCategoryController::class, 'edit'])
    ->name('course-categories.edit');

Route::put('/course-categories/{id}', [CourseCategoryController::class, 'update'])
    ->name('course-categories.update');

Route::post('/course-categories/{id}/toggle-status', [CourseCategoryController::class, 'toggleStatus'])
    ->name('course-categories.toggle-status');

if ($registerCourseProductRoutes ?? false) {
    Route::get('/course-cohorts', [CourseCohortController::class, 'index'])
        ->name('course-cohorts.index');

    Route::get('/course-cohorts/create', [CourseCohortController::class, 'create'])
        ->name('course-cohorts.create');

    Route::post('/course-cohorts', [CourseCohortController::class, 'store'])
        ->name('course-cohorts.store');

    Route::get('/course-cohorts/{id}', [CourseCohortController::class, 'show'])
        ->name('course-cohorts.show');

    Route::get('/course-cohorts/{id}/edit', [CourseCohortController::class, 'edit'])
        ->name('course-cohorts.edit');

    Route::put('/course-cohorts/{id}', [CourseCohortController::class, 'update'])
        ->name('course-cohorts.update');

    Route::post('/course-cohorts/{id}/activate', [CourseCohortController::class, 'activate'])
        ->name('course-cohorts.activate');

    Route::post('/course-cohorts/{id}/complete', [CourseCohortController::class, 'complete'])
        ->name('course-cohorts.complete');

    Route::post('/course-cohorts/{id}/archive', [CourseCohortController::class, 'archive'])
        ->name('course-cohorts.archive');

    Route::post('/course-cohorts/{cohort}/teachers', [CourseCohortOperationController::class, 'storeTeacher'])
        ->name('course-cohorts.teachers.store');
    Route::delete('/course-cohorts/{cohort}/teachers/{assignment}', [CourseCohortOperationController::class, 'removeTeacher'])
        ->name('course-cohorts.teachers.destroy');
    Route::post('/course-cohorts/{cohort}/sessions', [CourseCohortOperationController::class, 'storeSession'])
        ->name('course-cohorts.sessions.store');
    Route::put('/course-cohorts/{cohort}/sessions/{session}', [CourseCohortOperationController::class, 'updateSession'])
        ->name('course-cohorts.sessions.update');
    Route::put('/course-cohorts/{cohort}/sessions/{session}/schedule', [CourseCohortOperationController::class, 'updateSchedule'])
        ->name('course-cohorts.sessions.schedule');
    Route::put('/course-cohorts/{cohort}/sessions/{session}/attendance', [CourseCohortOperationController::class, 'saveAttendance'])
        ->name('course-cohorts.sessions.attendance');
    Route::post('/course-cohorts/{cohort}/sessions/{session}/recordings', [CourseCohortOperationController::class, 'storeRecording'])
        ->name('course-cohorts.sessions.recordings.store');

    Route::get('/course-cohorts/{cohort}/students/create', [CourseCohortStudentController::class, 'create'])
        ->name('course-cohorts.students.create');

    Route::get('/course-cohorts/{cohort}/students', [CourseCohortStudentController::class, 'students'])
        ->name('course-cohorts.students.index');

    Route::get('/course-cohorts/{cohort}/students/edit', [CourseCohortStudentController::class, 'manage'])
        ->name('course-cohorts.students.edit');

    Route::get('/course-cohorts/{cohort}/students/search', [CourseCohortStudentController::class, 'search'])
        ->name('course-cohorts.students.search');

    Route::post('/course-cohorts/{cohort}/students', [CourseCohortStudentController::class, 'store'])
        ->name('course-cohorts.students.store');

    Route::put('/course-cohorts/{cohort}/students', [CourseCohortStudentController::class, 'sync'])
        ->name('course-cohorts.students.sync');

    Route::get('/course-cohort-students', [CourseCohortStudentController::class, 'index'])
        ->name('course-cohort-students.index');

    Route::get('/course-cohort-students/create', [CourseCohortStudentController::class, 'create'])
        ->name('course-cohort-students.create');

    Route::post('/course-cohort-students', [CourseCohortStudentController::class, 'store'])
        ->name('course-cohort-students.store');

    Route::get('/course-cohort-students/{id}', [CourseCohortStudentController::class, 'show'])
        ->name('course-cohort-students.show');

    Route::get('/course-cohort-students/{id}/edit', [CourseCohortStudentController::class, 'edit'])
        ->name('course-cohort-students.edit');

    Route::put('/course-cohort-students/{id}', [CourseCohortStudentController::class, 'update'])
        ->name('course-cohort-students.update');

    Route::post('/course-cohort-students/{id}/archive', [CourseCohortStudentController::class, 'archive'])
        ->name('course-cohort-students.archive');

    Route::get('/course-enrollments', [CourseEnrollmentController::class, 'index'])
        ->name('course-enrollments.index');

    Route::get('/course-enrollments/create', [CourseEnrollmentController::class, 'create'])
        ->name('course-enrollments.create');

    Route::get('/course-enrollments/students/search', [CourseEnrollmentController::class, 'searchStudents'])
        ->name('course-enrollments.students.search');

    Route::get('/course-enrollments/products/search', [CourseEnrollmentController::class, 'searchProducts'])
        ->name('course-enrollments.products.search');

    Route::post('/course-enrollments', [CourseEnrollmentController::class, 'store'])
        ->name('course-enrollments.store');

    Route::post('/course-enrollments/bulk', [CourseEnrollmentController::class, 'bulkStore'])
        ->name('course-enrollments.bulk-store');

    Route::post('/course-enrollments/bulk/preflight', [CourseEnrollmentController::class, 'bulkPreflight'])
        ->name('course-enrollments.bulk-preflight');

    Route::post('/course-enrollments/bulk/invalidate', [CourseEnrollmentController::class, 'bulkInvalidate'])
        ->name('course-enrollments.bulk-invalidate');

    Route::post('/course-enrollments/bulk-lifecycle', [CourseEnrollmentController::class, 'bulkLifecycle'])
        ->name('course-enrollments.bulk-lifecycle');

    Route::get('/course-enrollments/bulk/result', [CourseEnrollmentController::class, 'bulkResult'])
        ->name('course-enrollments.bulk-result');

    Route::get('/course-enrollments/{id}', [CourseEnrollmentController::class, 'show'])
        ->name('course-enrollments.show');

    Route::get('/course-enrollments/{id}/edit', [CourseEnrollmentController::class, 'edit'])
        ->name('course-enrollments.edit');

    Route::put('/course-enrollments/{id}', [CourseEnrollmentController::class, 'update'])
        ->name('course-enrollments.update');

    Route::post('/course-enrollments/{id}/activate', [CourseEnrollmentController::class, 'activate'])->name('course-enrollments.activate');
    Route::post('/course-enrollments/{id}/suspend', [CourseEnrollmentController::class, 'suspend'])->name('course-enrollments.suspend');
    Route::post('/course-enrollments/{id}/reactivate', [CourseEnrollmentController::class, 'reactivate'])->name('course-enrollments.reactivate');
    Route::post('/course-enrollments/{id}/cancel', [CourseEnrollmentController::class, 'cancel'])->name('course-enrollments.cancel');

    Route::get('/course-products', [CourseProductController::class, 'index'])
        ->name('course-products.index');

    Route::get('/course-products/create', [CourseProductController::class, 'create'])
        ->name('course-products.create');

    Route::post('/course-products', [CourseProductController::class, 'store'])
        ->name('course-products.store');

    Route::get('/course-products/{id}/edit', [CourseProductController::class, 'edit'])
        ->name('course-products.edit');

    Route::put('/course-products/{id}', [CourseProductController::class, 'update'])
        ->name('course-products.update');

    Route::post('/course-products/{id}/archive', [CourseProductController::class, 'archive'])
        ->name('course-products.archive');

    Route::post('/course-products/{id}/restore', [CourseProductController::class, 'restore'])
        ->name('course-products.restore');

    Route::get('/course-products/{productId}/media/{slot}/{mediaFileId}', [CourseProductMediaPreviewController::class, 'show'])
        ->whereIn('slot', ['image', 'video', 'document'])
        ->name('course-products.media.preview');

    Route::post(
        '/course-products/{productId}/items',
        [CourseProductController::class, 'storeItem']
    )->name('course-products.items.store');

    Route::delete(
        '/course-products/{productId}/items/{itemId}',
        [CourseProductController::class, 'destroyItem']
    )->name('course-products.items.destroy');

    Route::post(
        '/course-products/{productId}/relations',
        [CourseProductController::class, 'storeRelation']
    )->name('course-products.relations.store');

    Route::delete(
        '/course-products/{productId}/relations/{relationId}',
        [CourseProductController::class, 'destroyRelation']
    )->name('course-products.relations.destroy');

    Route::delete('/course-products/{id}', [CourseProductController::class, 'destroy'])
        ->name('course-products.destroy');
}

Route::get('/course-templates', [CourseTemplateController::class, 'index'])
    ->name('course-templates.index');

Route::get('/course-templates/create', [CourseTemplateController::class, 'create'])
    ->name('course-templates.create');

Route::post('/course-templates', [CourseTemplateController::class, 'store'])
    ->name('course-templates.store');

Route::get('/course-templates/{id}', [CourseTemplateController::class, 'show'])
    ->name('course-templates.show');

Route::get('/course-templates/{id}/edit', [CourseTemplateController::class, 'edit'])
    ->name('course-templates.edit');

Route::get(
    '/course-templates/{templateId}/media/{slot}/{mediaFileId}',
    [CourseTemplateMediaPreviewController::class, 'show']
)->whereIn('slot', ['image', 'video', 'document'])
    ->name('course-templates.media.preview');

Route::get(
    '/course-templates/{templateId}/activities/{activityId}/media/{slot}/{mediaFileId}',
    [CourseActivityMediaPreviewController::class, 'show']
)->whereIn('slot', ['video', 'audio', 'document'])
    ->name('course-templates.activities.media.preview');

Route::put('/course-templates/{id}', [CourseTemplateController::class, 'update'])
    ->name('course-templates.update');

Route::delete('/course-templates/{id}', [CourseTemplateController::class, 'destroy'])
    ->name('course-templates.destroy');

Route::get(
    '/course-templates/{templateId}/teachers',
    [CourseTemplateTeacherController::class, 'index']
)->name('course-templates.teachers.index');

Route::get(
    '/course-templates/{templateId}/teachers/create',
    [CourseTemplateTeacherController::class, 'create']
)->name('course-templates.teachers.create');

Route::post(
    '/course-templates/{templateId}/teachers',
    [CourseTemplateTeacherController::class, 'store']
)->name('course-templates.teachers.store');

Route::get(
    '/course-templates/{templateId}/teachers/{assignmentId}/edit',
    [CourseTemplateTeacherController::class, 'edit']
)->name('course-templates.teachers.edit');

Route::put(
    '/course-templates/{templateId}/teachers/{assignmentId}',
    [CourseTemplateTeacherController::class, 'update']
)->name('course-templates.teachers.update');

Route::delete(
    '/course-templates/{templateId}/teachers/{assignmentId}',
    [CourseTemplateTeacherController::class, 'destroy']
)->name('course-templates.teachers.destroy');

Route::get(
    '/course-templates/{templateId}/lessons/create',
    [CourseTemplateLessonController::class, 'createDirect']
)->name('course-templates.lessons.create');

Route::post(
    '/course-templates/{templateId}/lessons',
    [CourseTemplateLessonController::class, 'storeDirect']
)->name('course-templates.lessons.store');

Route::get(
    '/course-templates/{templateId}/lessons/{lessonId}/edit',
    [CourseTemplateLessonController::class, 'editDirect']
)->name('course-templates.lessons.edit');

Route::put(
    '/course-templates/{templateId}/lessons/{lessonId}',
    [CourseTemplateLessonController::class, 'updateDirect']
)->name('course-templates.lessons.update');

Route::delete(
    '/course-templates/{templateId}/lessons/{lessonId}',
    [CourseTemplateLessonController::class, 'destroyDirect']
)->name('course-templates.lessons.destroy');

Route::get(
    '/course-templates/{templateId}/lessons/{lessonId}/activities',
    [CourseTemplateActivityController::class, 'indexDirect']
)->name('course-templates.lessons.activities.index');

Route::get(
    '/course-templates/{templateId}/lessons/{lessonId}/activities/create',
    [CourseTemplateActivityController::class, 'createDirect']
)->name('course-templates.lessons.activities.create');

Route::post(
    '/course-templates/{templateId}/lessons/{lessonId}/activities',
    [CourseTemplateActivityController::class, 'storeDirect']
)->name('course-templates.lessons.activities.store');

Route::get(
    '/course-templates/{templateId}/lessons/{lessonId}/activities/{activityId}',
    [CourseTemplateActivityController::class, 'showDirect']
)->name('course-templates.lessons.activities.show');

Route::get(
    '/course-templates/{templateId}/lessons/{lessonId}/activities/{activityId}/edit',
    [CourseTemplateActivityController::class, 'editDirect']
)->name('course-templates.lessons.activities.edit');

Route::put(
    '/course-templates/{templateId}/lessons/{lessonId}/activities/{activityId}',
    [CourseTemplateActivityController::class, 'updateDirect']
)->name('course-templates.lessons.activities.update');

Route::delete(
    '/course-templates/{templateId}/lessons/{lessonId}/activities/{activityId}',
    [CourseTemplateActivityController::class, 'destroyDirect']
)->name('course-templates.lessons.activities.destroy');

Route::get(
    '/course-templates/{templateId}/sections',
    [CourseTemplateSectionController::class, 'index']
)->name('course-templates.sections.index');

Route::get(
    '/course-templates/{templateId}/sections/create',
    [CourseTemplateSectionController::class, 'create']
)->name('course-templates.sections.create');

Route::post(
    '/course-templates/{templateId}/sections',
    [CourseTemplateSectionController::class, 'store']
)->name('course-templates.sections.store');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/edit',
    [CourseTemplateSectionController::class, 'edit']
)->name('course-templates.sections.edit');

Route::put(
    '/course-templates/{templateId}/sections/{sectionId}',
    [CourseTemplateSectionController::class, 'update']
)->name('course-templates.sections.update');

Route::delete(
    '/course-templates/{templateId}/sections/{sectionId}',
    [CourseTemplateSectionController::class, 'destroy']
)->name('course-templates.sections.destroy');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/lessons',
    [CourseTemplateLessonController::class, 'index']
)->name('course-templates.sections.lessons.index');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/create',
    [CourseTemplateLessonController::class, 'create']
)->name('course-templates.sections.lessons.create');

Route::post(
    '/course-templates/{templateId}/sections/{sectionId}/lessons',
    [CourseTemplateLessonController::class, 'store']
)->name('course-templates.sections.lessons.store');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/edit',
    [CourseTemplateLessonController::class, 'edit']
)->name('course-templates.sections.lessons.edit');

Route::put(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}',
    [CourseTemplateLessonController::class, 'update']
)->name('course-templates.sections.lessons.update');

Route::delete(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}',
    [CourseTemplateLessonController::class, 'destroy']
)->name('course-templates.sections.lessons.destroy');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/activities',
    [CourseTemplateActivityController::class, 'index']
)->name('course-templates.sections.lessons.activities.index');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/activities/create',
    [CourseTemplateActivityController::class, 'create']
)->name('course-templates.sections.lessons.activities.create');

Route::post(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/activities',
    [CourseTemplateActivityController::class, 'store']
)->name('course-templates.sections.lessons.activities.store');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/activities/{activityId}',
    [CourseTemplateActivityController::class, 'show']
)->name('course-templates.sections.lessons.activities.show');

Route::get(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/activities/{activityId}/edit',
    [CourseTemplateActivityController::class, 'edit']
)->name('course-templates.sections.lessons.activities.edit');

Route::put(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/activities/{activityId}',
    [CourseTemplateActivityController::class, 'update']
)->name('course-templates.sections.lessons.activities.update');

Route::delete(
    '/course-templates/{templateId}/sections/{sectionId}/lessons/{lessonId}/activities/{activityId}',
    [CourseTemplateActivityController::class, 'destroy']
)->name('course-templates.sections.lessons.activities.destroy');

if ($registerCourseTemplateLifecycleRoutes ?? false) {
    Route::get(
        '/course-templates/{templateId}/versions/{versionId}/media/{slot}/{mediaFileId}',
        [CourseTemplateVersionMediaPreviewController::class, 'show']
    )->whereIn('slot', ['image', 'video', 'document'])
        ->name('course-templates.versions.media.preview');
    Route::post(
        '/course-templates/{id}/publish',
        [CourseTemplateController::class, 'publish']
    )->name('course-templates.publish');
    Route::post(
        '/course-templates/{id}/archive',
        [CourseTemplateController::class, 'archive']
    )->name('course-templates.archive');

    Route::get(
        '/course-templates/{templateId}/versions/{versionId}',
        [CourseTemplateController::class, 'showVersion']
    )->name('course-templates.versions.show');

    Route::post(
        '/course-templates/{templateId}/versions/{versionId}/duplicate-to-draft',
        [CourseTemplateController::class, 'duplicateVersionToDraft']
    )->name('course-templates.versions.duplicate-to-draft');
}
