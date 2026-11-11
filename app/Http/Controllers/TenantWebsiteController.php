<?php

namespace App\Http\Controllers;

use App\Services\TenantCourseCatalogService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenantWebsiteController extends Controller
{
    /** Course cards shown on the tenant home page. */
    private const HOME_COURSE_LIMIT = 4;

    private const CATALOG_PAGE_SIZE = 12;

    public function __construct(private readonly TenantCourseCatalogService $catalog) {}

    public function home(): View
    {
        if (TenantContext::customerId() === null) {
            return view('pages.home');
        }

        $all = $this->catalog->courses(TenantContext::customerId(), $this->studentId());

        return view('tenant.home', [
            ...$this->websiteData(),
            'courses' => $this->catalog->filter($all, type: 'self_paced')->take(self::HOME_COURSE_LIMIT),
            'classes' => $this->catalog->filter($all, type: 'class')->take(self::HOME_COURSE_LIMIT),
        ]);
    }

    public function courses(Request $request): View
    {
        $this->requireTenant();
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:self_paced,class'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = ['q' => trim($validated['q'] ?? ''), 'category' => $validated['category'] ?? null, 'type' => $validated['type'] ?? null];

        $all = $this->catalog->courses(TenantContext::customerId(), $this->studentId());
        $found = $this->catalog->filter($all, $filters['q'], $filters['category'], $filters['type']);
        $page = (int) ($validated['page'] ?? 1);
        $courses = (new LengthAwarePaginator($found->forPage($page, self::CATALOG_PAGE_SIZE)->values(), $found->count(), self::CATALOG_PAGE_SIZE, $page,
            ['path' => $request->url()]))->withQueryString();

        return view('tenant.courses.index', [
            ...$this->websiteData(),
            'courses' => $courses,
            'categories' => $all->pluck('category')->filter()->unique()->sort()->values(),
            'filters' => $filters,
        ]);
    }

    public function course(string $slug): View
    {
        $this->requireTenant();
        $course = $this->catalog->course(TenantContext::customerId(), $this->studentId(), $slug);
        abort_if($course === null, 404);

        return view('tenant.courses.show', [...$this->websiteData(), 'course' => $course]);
    }

    public function services(): View
    {
        if (TenantContext::customerId() === null) {
            return view('pages.services');
        }

        return view('tenant.page', [
            ...$this->websiteData(),
            'title' => 'Services',
            'description' => 'Discover coaching, workshops and learning services from this academy.',
        ]);
    }

    public function teachers(): View
    {
        return $this->tenantPage('Teachers', 'Meet the teachers who design and deliver our learning programs.');
    }

    public function about(): View
    {
        if (TenantContext::customerId() === null) {
            return view('pages.about');
        }

        return view('tenant.page', [
            ...$this->websiteData(),
            'title' => 'About',
            'description' => 'Learn more about our academy, teaching approach and learner community.',
        ]);
    }

    public function contact(): View
    {
        return $this->tenantPage('Contact', 'Talk with our team about courses, services or enrollment.');
    }

    public function learningHistory(): View
    {
        return view('tenant.personalized', [
            ...$this->websiteData(),
            'title' => 'Learning History',
            'description' => 'Review lesson access, video watch activity, assessment attempts and completions.',
            'items' => ['Lesson Access', 'Video Watch', 'Assessment Attempts', 'Completion History'],
        ]);
    }

    public function aiTutor(): View
    {
        return view('tenant.personalized', [
            ...$this->websiteData(),
            'title' => 'AI Tutor',
            'description' => 'Ask questions and receive support based on your courses and learning progress.',
            'items' => ['Course Questions', 'Lesson Support', 'Practice Support', 'AI Recommendations'],
        ]);
    }

    private function tenantPage(string $title, string $description): View
    {
        $this->requireTenant();

        return view('tenant.page', [
            ...$this->websiteData(),
            'title' => $title,
            'description' => $description,
        ]);
    }

    private function requireTenant(): void
    {
        abort_if(TenantContext::customerId() === null, 404);
    }

    private function websiteData(): array
    {
        return [
            'tenant' => TenantContext::customer(),
            'studentMode' => $this->studentId() !== null,
        ];
    }

    /** The signed-in learner of this tenant, if any; staff browse the catalogue like a visitor. */
    private function studentId(): ?int
    {
        $user = Auth::user();

        return $user && $user->role === 'student' && (int) $user->customer_id === (int) TenantContext::customerId()
            ? (int) $user->id : null;
    }
}
