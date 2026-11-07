<?php

use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Assessment\AssessmentLearnerController;
use App\Http\Controllers\CustomerRegisterController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\MediaFileDeliveryController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RoleProfileController;
use App\Http\Controllers\TeacherCohortJudgmentController;
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

Route::post('/language/{locale}', function (string $locale) {
    session(['locale' => $locale]);

    return redirect()->back();
})->middleware('tenant')
    ->whereIn('locale', ['vi', 'en'])
    ->name('language.update');

Route::middleware(['tenant', 'root.domain'])->group(function () {
    Route::get('/features', [PublicSiteController::class, 'features'])
        ->name('public.features');

    Route::get('/pricing', [PublicSiteController::class, 'pricing'])
        ->name('public.pricing');

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
| Đây là frontend giao diện "người dùng/ học viên" của mỗi Tenant
|--------------------------------------------------------------------------
*/

Route::middleware(['tenant'])->group(function () {
    Route::get('/media/files/{mediaFile}/signed', [MediaFileDeliveryController::class, 'show'])
        ->middleware('signed')
        ->name('media.files.signed');

    Route::get('/', [TenantWebsiteController::class, 'home'])
        ->name('public.home');

    Route::get('/courses', [TenantWebsiteController::class, 'courses'])
        ->name('tenant.courses.index');

    Route::get('/courses/{slug}', [TenantWebsiteController::class, 'course'])
        ->name('tenant.courses.show');

    // Kiểm tra (ADR-0025 D2): quizzes open for registration; introductions only, no questions or answers.
    Route::get('/assessments', [AssessmentLearnerController::class, 'index'])
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

    Route::get('/my-account/password', [AdminProfileController::class, 'editPassword'])
        ->name('my-account.password.edit');

    Route::patch('/my-account/password', [AdminProfileController::class, 'updatePassword'])
        ->name('my-account.password.update');

    Route::get('/users', [UserController::class, 'index'])
        ->name('users.index');

    Route::get('/users/export', [UserController::class, 'export'])
        ->name('users.export');

    Route::get('/users/create', [UserController::class, 'create'])
        ->name('users.create');

    Route::post('/users', [UserController::class, 'store'])
        ->name('users.store');

    Route::get('/users/{id}/edit', [UserController::class, 'edit'])
        ->name('users.edit');

    Route::get('/users/{id}/password', [UserController::class, 'editPassword'])
        ->name('users.password.edit');

    Route::put('/users/{id}', [UserController::class, 'update'])
        ->name('users.update');

    Route::post('/users/{id}/toggle-status', [UserController::class, 'toggleStatus'])
        ->name('users.toggle-status');

    $registerCourseProductRoutes = true;
    $registerCourseTemplateLifecycleRoutes = true;
    require __DIR__.'/modules/course.php';
    require __DIR__.'/modules/media.php';
    require __DIR__.'/modules/learning.php';
    require __DIR__.'/modules/assessment.php';
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

    Route::get('/profile/password', [RoleProfileController::class, 'editTeacherPassword'])
        ->name('profile.password.edit');

    Route::patch('/profile/password', [RoleProfileController::class, 'updateTeacherPassword'])
        ->name('profile.password.update');

    // Teacher Judgment surface (Course Scope Amendment, 2026-10-11).
    Route::get('/cohorts', [TeacherCohortJudgmentController::class, 'index'])->name('cohorts.index');
    Route::get('/cohorts/{cohort}/judgments', [TeacherCohortJudgmentController::class, 'show'])->whereNumber('cohort')->name('cohorts.show');
    Route::post('/cohorts/{cohort}/judgments', [TeacherCohortJudgmentController::class, 'store'])->whereNumber('cohort')->name('cohorts.judgments.store');

    // Media library for teachers: their own Media and what was shared with them (ADR-0021).
    Route::get('/media', [MediaFileController::class, 'index'])->name('media.index');
    Route::get('/media/picker', [MediaFileController::class, 'picker'])->name('media.picker');
    Route::put('/media/{id}/shares', [MediaFileController::class, 'updateShares'])->whereNumber('id')->name('media.shares.update');
    Route::delete('/media/bulk', [MediaFileController::class, 'bulkDestroy'])->name('media.bulk-destroy');

    $registerCourseProductRoutes = false;
    $registerCourseTemplateLifecycleRoutes = false;
    $registerCategoryManagement = false;
    $registerTemplateTeacherManagement = false;
    require __DIR__.'/modules/course.php';

    $registerAssessmentCategoryManagement = false;
    require __DIR__.'/modules/assessment.php';
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

    // Kiểm tra for students: "Đề thi của tôi", self-registration, taking an attempt and its result (ADR-0025).
    Route::controller(AssessmentLearnerController::class)->prefix('/assessments')->name('student.assessments.')
        ->whereNumber(['id', 'registrationId', 'questionId', 'mediaId'])->group(function () {
            Route::get('/my', 'mine')->name('mine');
            Route::post('/{id}/register', 'register')->name('register');
            Route::post('/registrations/{registrationId}/start', 'start')->name('start');
            Route::get('/attempts/{id}', 'show')->name('attempts.show');
            Route::post('/attempts/{id}/answers', 'save')->name('attempts.save');
            Route::post('/attempts/{id}/recordings/{questionId}', 'record')->name('attempts.record');
            Route::post('/attempts/{id}/files/{questionId}', 'attach')->name('attempts.files.store');
            Route::delete('/attempts/{id}/files/{questionId}/{mediaId}', 'detach')->name('attempts.files.destroy');
            Route::post('/attempts/{id}/submit', 'submit')->name('attempts.submit');
            Route::get('/attempts/{id}/result', 'result')->name('attempts.result');
        });

    Route::get('/profile', [RoleProfileController::class, 'editStudent'])
        ->name('student.profile.edit');

    Route::patch('/profile', [RoleProfileController::class, 'updateStudent'])
        ->name('student.profile.update');

    Route::patch('/profile/password', [RoleProfileController::class, 'updateStudentPassword'])
        ->name('student.profile.password.update');
});
