<?php

use App\Http\Controllers\CourseCategoryController;
use App\Http\Controllers\CourseTemplateController;
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
