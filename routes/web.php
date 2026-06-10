<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CustomerRegisterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root Domain Public Pages
|--------------------------------------------------------------------------
| Domain gốc: localhost / learnforge.vn
| Chỉ dùng cho landing, features, pricing, services, about, register customer.
| Không dùng cho login/logout/dashboard/admin/teacher/student.
|--------------------------------------------------------------------------
*/

Route::get('/', [PublicSiteController::class, 'home'])
    ->name('public.home');

Route::get('/features', [PublicSiteController::class, 'features'])
    ->name('public.features');

Route::get('/pricing', [PublicSiteController::class, 'pricing'])
    ->name('public.pricing');

Route::get('/services', [PublicSiteController::class, 'services'])
    ->name('public.services');

Route::get('/about', [PublicSiteController::class, 'about'])
    ->name('public.about');

Route::middleware('root.domain')->group(function () {
    Route::get('/register-customer', [CustomerRegisterController::class, 'show'])
        ->name('customer.register');

    Route::post('/register-customer', [CustomerRegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('customer.register.store');
});

/*
|--------------------------------------------------------------------------
| Tenant Auth Routes
|--------------------------------------------------------------------------
| Login/logout/password reset chỉ hoạt động khi resolve được tenant.
| Public tenant register đang tắt; user được tạo bởi customer_admin.
|--------------------------------------------------------------------------
*/

Route::middleware(['tenant'])->group(function () {
    require __DIR__.'/auth.php';
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
        return redirect()->route(match (auth()->user()->role) {
            'customer_admin' => 'admin.dashboard',
            'teacher' => 'teacher.dashboard',
            'student' => 'student.dashboard',
            default => 'public.home',
        });
    })->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Admin Area
|--------------------------------------------------------------------------
*/

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:customer_admin',
])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', function () {
        return view('admin.dashboard');
    })->name('dashboard');

    Route::get('/users', [UserController::class, 'index'])
        ->name('users.index');

    Route::get('/users/create', [UserController::class, 'create'])
        ->name('users.create');

    Route::post('/users', [UserController::class, 'store'])
        ->name('users.store');

    Route::get('/users/{id}/edit', [UserController::class, 'edit'])
        ->name('users.edit');

    Route::put('/users/{id}', [UserController::class, 'update'])
        ->name('users.update');

    Route::post('/users/{id}/toggle-status', [UserController::class, 'toggleStatus'])
        ->name('users.toggle-status');
});

/*
|--------------------------------------------------------------------------
| Teacher Area
|--------------------------------------------------------------------------
*/

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:teacher',
])->prefix('teacher')->name('teacher.')->group(function () {
    Route::get('/', function () {
        return view('teacher.dashboard');
    })->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Student Area
|--------------------------------------------------------------------------
*/

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:student',
])->prefix('student')->name('student.')->group(function () {
    Route::get('/', function () {
        return view('student.dashboard');
    })->name('dashboard');
});
