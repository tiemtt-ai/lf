<?php

use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CourseCategoryController;
use App\Http\Controllers\CourseTemplateController;
use App\Http\Controllers\CustomerRegisterController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RoleProfileController;
use App\Http\Controllers\TenantWebsiteController;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root Domain Public Pages
|--------------------------------------------------------------------------
| Domain gốc: localhost / learnforge.vn
| Chỉ dùng cho landing, features, pricing, services, about, register customer.
| Không dùng cho login/logout/dashboard hoặc các back office theo role.
|--------------------------------------------------------------------------
*/

Route::get('/features', [PublicSiteController::class, 'features'])
    ->name('public.features');

Route::get('/pricing', [PublicSiteController::class, 'pricing'])
    ->name('public.pricing');

Route::post('/language/{locale}', function (string $locale) {
    session(['locale' => $locale]);

    return redirect()->back();
})->middleware('tenant')
    ->whereIn('locale', ['vi', 'en'])
    ->name('language.update');

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
    Route::get('/', [TenantWebsiteController::class, 'home'])
        ->name('public.home');

    Route::get('/courses', [TenantWebsiteController::class, 'courses'])
        ->name('tenant.courses.index');

    Route::get('/courses/{slug}', [TenantWebsiteController::class, 'course'])
        ->name('tenant.courses.show');

    Route::get('/assessments', [TenantWebsiteController::class, 'assessments'])
        ->name('tenant.assessments');

    Route::get('/services', [TenantWebsiteController::class, 'services'])
        ->name('public.services');

    Route::get('/teachers', [TenantWebsiteController::class, 'teachers'])
        ->name('tenant.teachers');

    Route::get('/about', [TenantWebsiteController::class, 'about'])
        ->name('public.about');

    Route::get('/contact', [TenantWebsiteController::class, 'contact'])
        ->name('tenant.contact');

    Route::delete('/profile', fn () => abort(404));

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
    'role:customer_admin,teacher,student',
])->group(function () {
    Route::get('/dashboard', function () {
        return redirect()->route(match (auth()->user()->role) {
            'customer_admin' => 'admin.dashboard',
            'teacher' => 'teacher.dashboard',
            'student' => 'public.home',
            default => abort(403, 'You do not have permission to access this application.'),
        });
    })->name('dashboard');

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
        return view('admin.dashboard', [
            'tenant' => TenantContext::customer(),
        ]);
    })->name('dashboard');

    Route::get('/organization', [OrganizationController::class, 'edit'])
        ->name('organization.edit');

    Route::patch('/organization', [OrganizationController::class, 'update'])
        ->name('organization.update');

    Route::get('/my-account', [AdminProfileController::class, 'edit'])
        ->name('my-account.edit');

    Route::patch('/my-account', [AdminProfileController::class, 'update'])
        ->name('my-account.update');

    Route::patch('/my-account/password', [AdminProfileController::class, 'updatePassword'])
        ->name('my-account.password.update');

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

    Route::get('/profile', [RoleProfileController::class, 'editTeacher'])
        ->name('profile.edit');

    Route::patch('/profile', [RoleProfileController::class, 'updateTeacher'])
        ->name('profile.update');

    Route::patch('/profile/password', [RoleProfileController::class, 'updateTeacherPassword'])
        ->name('profile.password.update');

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
});

/*
|--------------------------------------------------------------------------
| Student Personalized Experience
|--------------------------------------------------------------------------
*/

Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:student',
])->group(function () {
    Route::get('/my-courses', [TenantWebsiteController::class, 'myCourses'])
        ->name('student.courses.index');

    Route::get('/learning-history', [TenantWebsiteController::class, 'learningHistory'])
        ->name('student.learning-history');

    Route::get('/ai-tutor', [TenantWebsiteController::class, 'aiTutor'])
        ->name('student.ai-tutor');

    Route::get('/profile', [RoleProfileController::class, 'editStudent'])
        ->name('student.profile.edit');

    Route::patch('/profile', [RoleProfileController::class, 'updateStudent'])
        ->name('student.profile.update');

    Route::patch('/profile/password', [RoleProfileController::class, 'updateStudentPassword'])
        ->name('student.profile.password.update');
});
