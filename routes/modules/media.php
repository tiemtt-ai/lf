<?php

use App\Http\Controllers\MediaCategoryController;
use App\Http\Controllers\MediaFileController;
use Illuminate\Support\Facades\Route;

Route::get('/media', [MediaFileController::class, 'index'])
    ->name('media.index');

Route::delete('/media/bulk', [MediaFileController::class, 'bulkDestroy'])
    ->name('media.bulk-destroy');

Route::delete('/media/{id}', [MediaFileController::class, 'destroy'])
    ->name('media.destroy');

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
