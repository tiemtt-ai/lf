<?php

use App\Http\Controllers\CourseCategoryController;
use App\Http\Controllers\CourseTemplateController;
use App\Http\Controllers\CourseTemplateActivityController;
use App\Http\Controllers\CourseTemplateLessonController;
use App\Http\Controllers\CourseTemplateSectionController;
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

Route::get('/course-templates', [CourseTemplateController::class, 'index'])
    ->name('course-templates.index');

Route::get('/course-templates/create', [CourseTemplateController::class, 'create'])
    ->name('course-templates.create');

Route::post('/course-templates', [CourseTemplateController::class, 'store'])
    ->name('course-templates.store');

Route::get('/course-templates/{id}/edit', [CourseTemplateController::class, 'edit'])
    ->name('course-templates.edit');

Route::put('/course-templates/{id}', [CourseTemplateController::class, 'update'])
    ->name('course-templates.update');

Route::delete('/course-templates/{id}', [CourseTemplateController::class, 'destroy'])
    ->name('course-templates.destroy');

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
