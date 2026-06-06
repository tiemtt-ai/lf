<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CustomerRegisterController;
use App\Http\Controllers\ProfileController;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicSiteController;

/*
|--------------------------------------------------------------------------
| Root Domain Public Pages
|--------------------------------------------------------------------------
| Domain gốc: localhost / learnforge.vn
| Chỉ dùng cho landing, giới thiệu, pricing, register customer.
| Không dùng cho login/logout/user dashboard.
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

Route::get('/register-customer', [CustomerRegisterController::class, 'show'])
    ->name('customer.register');

Route::post('/register-customer', [CustomerRegisterController::class, 'store'])
    ->name('customer.register.store');

/*
|--------------------------------------------------------------------------
| Tenant Test
|--------------------------------------------------------------------------
*/

Route::middleware(['tenant'])->get('/tenant-test', function () {
    $customer = TenantContext::customer();

    return [
        'host' => request()->getHost(),
        'customer_id' => TenantContext::customerId(),
        'slug' => $customer?->slug,
        'theme_key' => $customer?->theme_key,
        'layout_key' => $customer?->layout_key,
    ];
})->name('tenant.test');

/*
|--------------------------------------------------------------------------
| Tenant Auth Routes
|--------------------------------------------------------------------------
| Login/logout/register/password reset chỉ hoạt động trên tenant domain:
| visang1.localhost:8000/login
| kaha.learnforge.vn/login
|--------------------------------------------------------------------------
*/

Route::get('/login', function () {
    abort(404);
});

Route::post('/login', function () {
    abort(404);
});

Route::post('/logout', function () {
    abort(404);
});

Route::get('/register', function () {
    abort(404);
});

Route::get('/forgot-password', function () {
    abort(404);
});

Route::middleware(['tenant'])->group(function () {
    require __DIR__ . '/auth.php';
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
| Admin Area
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
])->prefix('teacher')->group(function () {
    Route::get('/', function () {
        return view('teacher.dashboard');
    })->name('teacher.dashboard');
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
])->prefix('student')->group(function () {
    Route::get('/', function () {
        return view('student.dashboard');
    })->name('student.dashboard');
});