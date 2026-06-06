<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerRegisterController;
use App\Support\TenantContext;
use App\Http\Controllers\Admin\UserController;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('pages.home');
});

Route::get('/register-customer', [CustomerRegisterController::class, 'show'])
    ->name('customer.register');

Route::post('/register-customer', [CustomerRegisterController::class, 'store'])
    ->name('customer.register.store');

Route::middleware(['tenant'])->get('/tenant-test', function () {
    $customer = TenantContext::customer();

    return [
        'host' => request()->getHost(),
        'customer_id' => TenantContext::customerId(),
        'slug' => $customer?->slug,
        'theme_key' => $customer?->theme_key,
        'layout_key' => $customer?->layout_key,
    ];
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
        return view('admin.dashboard');
    })->name('admin.dashboard');

    Route::get('/users', [UserController::class, 'index'])
        ->name('admin.users.index');

    Route::get('/users/create', [UserController::class, 'create'])
        ->name('admin.users.create');

    Route::post('/users', [UserController::class, 'store'])
        ->name('admin.users.store');

    Route::get('/users/{id}/edit', [UserController::class, 'edit'])
        ->name('admin.users.edit');

    Route::put('/users/{id}', [UserController::class, 'update'])
        ->name('admin.users.update');

    Route::post('/users/{id}/toggle-status', [UserController::class, 'toggleStatus'])
        ->name('admin.users.toggle-status');
});

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:teacher',
])->prefix('teacher')->group(function () {
    Route::get('/', function () {
        return view('teacher.dashboard');
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
        return view('student.dashboard');
    })->name('student.dashboard');
});

require __DIR__.'/auth.php';