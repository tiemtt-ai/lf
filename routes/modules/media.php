<?php

use App\Http\Controllers\MediaCategoryController;
use Illuminate\Support\Facades\Route;

Route::get('/media-categories', [MediaCategoryController::class, 'index'])
    ->name('media-categories.index');

Route::get('/media-categories/create', [MediaCategoryController::class, 'create'])
    ->name('media-categories.create');

Route::post('/media-categories', [MediaCategoryController::class, 'store'])
    ->name('media-categories.store');

Route::get('/media-categories/{id}/edit', [MediaCategoryController::class, 'edit'])
    ->name('media-categories.edit');

Route::put('/media-categories/{id}', [MediaCategoryController::class, 'update'])
    ->name('media-categories.update');

Route::post('/media-categories/{id}/archive', [MediaCategoryController::class, 'archive'])
    ->name('media-categories.archive');
