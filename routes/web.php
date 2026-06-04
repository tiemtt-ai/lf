<?php

use App\Support\TenantContext;
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
| Tenant Test
|--------------------------------------------------------------------------
*/

Route::middleware(['tenant'])->group(function () {
    Route::get('/tenant-test', function () {
        return response()->json([
            'customer_id'   => TenantContext::customerId(),
            'customer_name' => TenantContext::customer()?->name,
            'theme_key'     => TenantContext::themeKey(),
            'layout_key'    => TenantContext::layoutKey(),
        ]);
    });
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

require __DIR__.'/auth.php';