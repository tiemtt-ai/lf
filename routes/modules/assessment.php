<?php

use App\Http\Controllers\Assessment\AssessmentCategoryController;
use App\Http\Controllers\Assessment\AssessmentPassageController;
use App\Http\Controllers\Assessment\AssessmentQuestionController;
use App\Http\Controllers\Assessment\AssessmentQuestionImportController;
use App\Http\Controllers\Assessment\AssessmentQuizBuilderController;
use App\Http\Controllers\Assessment\AssessmentQuizController;
use App\Http\Controllers\Assessment\AssessmentRegistrationController;
use App\Http\Controllers\Assessment\AssessmentResultController;
use App\Http\Controllers\Assessment\AssessmentRubricController;
use App\Http\Controllers\Assessment\AssessmentWorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| Module Kiểm tra (ADR-0025 D2). Required inside the admin and teacher groups, so the
| prefix, name and middleware come from the caller. Categories are admin-only
| (LF-Core-Assessment, Roles And Experience); teachers only pick them.
|
| Sub-items not built yet render a placeholder page; each moves to its own controller
| when that sub-item is built.
*/

$assessmentCategoryManagement = $registerAssessmentCategoryManagement ?? true;
$assessmentPlaceholders = array_diff(
    AssessmentWorkspaceController::sectionsFor($assessmentCategoryManagement),
    ['categories', 'questions', 'passages', 'rubrics', 'quizzes', 'registrations', 'results'],
);

Route::prefix('assessments')->name('assessments.')->group(function () use ($assessmentCategoryManagement, $assessmentPlaceholders): void {
    Route::get('/', [AssessmentWorkspaceController::class, 'home'])->name('home');

    if ($assessmentCategoryManagement) {
        Route::get('/categories', [AssessmentCategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/create', [AssessmentCategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [AssessmentCategoryController::class, 'store'])->name('categories.store');
        Route::delete('/categories', [AssessmentCategoryController::class, 'bulkDestroy'])->name('categories.bulk-destroy');
        Route::get('/categories/{id}/edit', [AssessmentCategoryController::class, 'edit'])->whereNumber('id')->name('categories.edit');
        Route::put('/categories/{id}', [AssessmentCategoryController::class, 'update'])->whereNumber('id')->name('categories.update');
        Route::post('/categories/{id}/toggle-status', [AssessmentCategoryController::class, 'toggleStatus'])->whereNumber('id')->name('categories.toggle-status');
        // Giáo viên chấm theo danh mục (ADR-0027 D2), inherited by sub-categories.
        Route::get('/categories/{id}/graders', [AssessmentCategoryController::class, 'editGraders'])->whereNumber('id')->name('categories.graders.edit');
        Route::put('/categories/{id}/graders', [AssessmentCategoryController::class, 'updateGraders'])->whereNumber('id')->name('categories.graders.update');
    }

    // Admins manage every question of the tenant, teachers their own (the service scopes each query).
    Route::get('/questions', [AssessmentQuestionController::class, 'index'])->name('questions.index');
    Route::get('/questions/create', [AssessmentQuestionController::class, 'create'])->name('questions.create');
    Route::post('/questions', [AssessmentQuestionController::class, 'store'])->name('questions.store');
    Route::delete('/questions', [AssessmentQuestionController::class, 'bulkDestroy'])->name('questions.bulk-destroy');
    // Nhập / Xuất Excel câu hỏi: popup, import (all rows or none), the template file and export in the same columns.
    Route::get('/questions/import', [AssessmentQuestionImportController::class, 'create'])->name('questions.import.create');
    Route::post('/questions/import', [AssessmentQuestionImportController::class, 'store'])->name('questions.import.store');
    Route::get('/questions/import/template', [AssessmentQuestionImportController::class, 'template'])->name('questions.import.template');
    Route::get('/questions/export', [AssessmentQuestionImportController::class, 'export'])->name('questions.export');
    Route::get('/questions/{id}/edit', [AssessmentQuestionController::class, 'edit'])->whereNumber('id')->name('questions.edit');
    Route::put('/questions/{id}', [AssessmentQuestionController::class, 'update'])->whereNumber('id')->name('questions.update');

    Route::get('/passages', [AssessmentPassageController::class, 'index'])->name('passages.index');
    Route::get('/passages/create', [AssessmentPassageController::class, 'create'])->name('passages.create');
    Route::post('/passages', [AssessmentPassageController::class, 'store'])->name('passages.store');
    Route::delete('/passages', [AssessmentPassageController::class, 'bulkDestroy'])->name('passages.bulk-destroy');
    Route::get('/passages/{id}', [AssessmentPassageController::class, 'show'])->whereNumber('id')->name('passages.show');
    Route::get('/passages/{id}/edit', [AssessmentPassageController::class, 'edit'])->whereNumber('id')->name('passages.edit');
    Route::put('/passages/{id}', [AssessmentPassageController::class, 'update'])->whereNumber('id')->name('passages.update');
    Route::post('/passages/{id}/questions/{questionId}/move', [AssessmentPassageController::class, 'moveQuestion'])
        ->whereNumber(['id', 'questionId'])->name('passages.questions.move');

    // Tiêu chí chấm (ADR-0026): admins manage every rubric, teachers their own and use the admins' too.
    Route::get('/rubrics', [AssessmentRubricController::class, 'index'])->name('rubrics.index');
    Route::get('/rubrics/create', [AssessmentRubricController::class, 'create'])->name('rubrics.create');
    Route::post('/rubrics', [AssessmentRubricController::class, 'store'])->name('rubrics.store');
    Route::delete('/rubrics', [AssessmentRubricController::class, 'bulkDestroy'])->name('rubrics.bulk-destroy');
    Route::get('/rubrics/{id}/edit', [AssessmentRubricController::class, 'edit'])->whereNumber('id')->name('rubrics.edit');
    Route::put('/rubrics/{id}', [AssessmentRubricController::class, 'update'])->whereNumber('id')->name('rubrics.update');
    Route::post('/rubrics/{id}/toggle-status', [AssessmentRubricController::class, 'toggleStatus'])->whereNumber('id')->name('rubrics.toggle-status');

    Route::get('/quizzes', [AssessmentQuizController::class, 'index'])->name('quizzes.index');
    Route::get('/quizzes/create', [AssessmentQuizController::class, 'create'])->name('quizzes.create');
    Route::post('/quizzes', [AssessmentQuizController::class, 'store'])->name('quizzes.store');
    Route::delete('/quizzes', [AssessmentQuizController::class, 'bulkDestroy'])->name('quizzes.bulk-destroy');
    Route::get('/quizzes/{id}/edit', [AssessmentQuizController::class, 'edit'])->whereNumber('id')->name('quizzes.edit');
    Route::put('/quizzes/{id}', [AssessmentQuizController::class, 'update'])->whereNumber('id')->name('quizzes.update');

    // The quiz page: sections, chosen questions (step 2) and publishing / lifecycle (step 3).
    Route::controller(AssessmentQuizBuilderController::class)->prefix('/quizzes/{id}')->whereNumber(['id', 'sectionId', 'itemId'])
        ->name('quizzes.')->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::get('/sections/create', 'createSection')->name('sections.create');
            Route::post('/sections', 'storeSection')->name('sections.store');
            Route::get('/sections/{sectionId}/edit', 'editSection')->name('sections.edit');
            Route::put('/sections/{sectionId}', 'updateSection')->name('sections.update');
            Route::delete('/sections/{sectionId}', 'destroySection')->name('sections.destroy');
            Route::post('/sections/{sectionId}/move', 'moveSection')->name('sections.move');
            Route::get('/questions/pick', 'pickQuestions')->name('questions.pick');
            Route::post('/questions', 'addQuestions')->name('questions.add');
            Route::delete('/questions', 'removeItems')->name('questions.remove');
            Route::get('/questions/{itemId}/edit', 'editItem')->name('questions.edit');
            Route::put('/questions/{itemId}', 'updateItem')->name('questions.update');
            Route::post('/questions/{itemId}/move', 'moveItem')->name('questions.move');
            Route::post('/publish', 'publish')->name('publish');
            Route::post('/archive', 'archive')->name('archive');
            Route::post('/restore', 'restore')->name('restore');
            Route::post('/registration', 'registration')->name('registration');
            // Giao chấm đề (ADR-0027 D2): the admin or the quiz creator names the teachers who grade this quiz.
            Route::get('/graders', 'editGraders')->name('graders.edit');
            Route::put('/graders', 'updateGraders')->name('graders.update');
        });

    // Ghi danh: grant a published quiz to students, cancel; never deleted.
    Route::get('/registrations', [AssessmentRegistrationController::class, 'index'])->name('registrations.index');
    Route::get('/registrations/create', [AssessmentRegistrationController::class, 'create'])->name('registrations.create');
    Route::post('/registrations', [AssessmentRegistrationController::class, 'store'])->name('registrations.store');
    Route::delete('/registrations', [AssessmentRegistrationController::class, 'bulkCancel'])->name('registrations.bulk-cancel');

    // Kết quả & Chấm bài: attempts read from their frozen version; essay / speaking graded by hand.
    Route::get('/results', [AssessmentResultController::class, 'index'])->name('results.index');
    Route::get('/results/export', [AssessmentResultController::class, 'export'])->name('results.export');
    Route::get('/results/statistics', [AssessmentResultController::class, 'statistics'])->name('results.statistics');
    // Giao chấm (admin, quiz creator), Nhận chấm (teacher), Trả lại (ADR-0027 D3).
    Route::get('/results/assign', [AssessmentResultController::class, 'assignDialog'])->name('results.assign.create');
    Route::post('/results/assign', [AssessmentResultController::class, 'assign'])->name('results.assign.store');
    Route::post('/results/{id}/claim', [AssessmentResultController::class, 'claim'])->whereNumber('id')->name('results.claim');
    Route::post('/results/{id}/release', [AssessmentResultController::class, 'release'])->whereNumber('id')->name('results.release');
    Route::get('/results/{id}', [AssessmentResultController::class, 'show'])->whereNumber('id')->name('results.show');
    Route::post('/results/{id}/answers/{answerId}/grade', [AssessmentResultController::class, 'grade'])
        ->whereNumber(['id', 'answerId'])->name('results.grade');

    foreach ($assessmentPlaceholders as $section) {
        Route::get('/'.$section, [AssessmentWorkspaceController::class, 'placeholder'])
            ->defaults('section', $section)
            ->name($section.'.index');
    }
});
