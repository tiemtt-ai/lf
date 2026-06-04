<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('pages.home');
});

/*
|--------------------------------------------------------------------------
| Authenticated Tenant Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Profile
|--------------------------------------------------------------------------
*/

Route::middleware([
    'tenant',
    'auth',
    'tenant.user',
])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| LF User Access Areas
|--------------------------------------------------------------------------
*/

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:customer_admin',
])->prefix('admin')->group(function () {
    Route::get('/', function () {
        return 'LF Admin Area';
    })->name('admin.dashboard');
});

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:teacher',
])->prefix('teacher')->group(function () {
    Route::get('/', function () {
        return 'LF Teacher Area';
    })->name('teacher.dashboard');
});

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:student',
])->prefix('student')->group(function () {
    Route::get('/', function () {
        return 'LF Student Area';
    })->name('student.dashboard');
});

require __DIR__.'/auth.php';