<?php

use App\Http\Controllers\MediaCategoryController;
use App\Http\Controllers\MediaFileController;
use Illuminate\Support\Facades\Route;

Route::get('/media', [MediaFileController::class, 'index'])
    ->name('media.index');

// Storage clean-up dialog: scan (nothing deleted), then purge the findings in small batches with progress.
// The library as a picker for forms: ready Media of one type (JSON).
Route::get('/media/picker', [MediaFileController::class, 'picker'])
    ->name('media.picker');

Route::get('/media/cleanup/status', [MediaFileController::class, 'cleanupStatus'])
    ->name('media.cleanup-status');

Route::get('/media/cleanup/scan', [MediaFileController::class, 'cleanupScan'])
    ->name('media.cleanup-scan');

Route::post('/media/cleanup/run', [MediaFileController::class, 'cleanupRun'])
    ->name('media.cleanup-run');

Route::delete('/media/bulk', [MediaFileController::class, 'bulkDestroy'])
    ->name('media.bulk-destroy');

// Who a Media is shared with (ADR-0021): owner or admin; a share someone still uses cannot be revoked (409).
Route::put('/media/{id}/shares', [MediaFileController::class, 'updateShares'])
    ->name('media.shares.update');

Route::delete('/media/{id}', [MediaFileController::class, 'destroy'])
    ->name('media.destroy');

// Don rac storage: admin bam, khong co lich tu dong. Xem MediaStorageResidueSweeper.
Route::post('/media/purge-orphan-storage', [MediaFileController::class, 'purgeOrphanStorage'])
    ->name('media.purge-orphan-storage');

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
