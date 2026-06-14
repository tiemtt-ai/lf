<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenantWebsiteController extends Controller
{
    public function home(): View
    {
        if (TenantContext::customerId() === null) {
            return view('pages.home');
        }

        return view('tenant.home', $this->websiteData());
    }

    public function courses(): View
    {
        $this->requireTenant();

        return view('tenant.courses.index', $this->websiteData());
    }

    public function course(string $slug): View
    {
        $this->requireTenant();

        $data = $this->websiteData();
        $course = collect($data['courses'])->firstWhere('slug', $slug);

        abort_if($course === null, 404);

        return view('tenant.courses.show', [...$data, 'course' => $course]);
    }

    public function assessments(): View
    {
        return $this->tenantPage('Assessments', 'Explore placement tests, quizzes and course assessments.');
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

    public function myCourses(): View
    {
        return view('tenant.personalized', [
            ...$this->websiteData(),
            'title' => 'My Courses',
            'description' => 'Enrolled, in-progress, completed and favorite courses, plus purchased services.',
            'items' => ['Enrolled Courses', 'In Progress Courses', 'Completed Courses', 'Favorite Courses', 'Purchased Services'],
        ]);
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
        $studentMode = Auth::check() && Auth::user()->role === 'student';

        return [
            'tenant' => TenantContext::customer(),
            'studentMode' => $studentMode,
            'courses' => [
                [
                    'slug' => 'korean-communication',
                    'title' => 'Practical Korean Communication',
                    'teacher' => 'Min-jun Lee',
                    'summary' => 'Build confident speaking skills for everyday and workplace situations.',
                    'price' => '$79',
                    'progress' => 72,
                    'enrolled' => $studentMode,
                    'favorite' => false,
                ],
                [
                    'slug' => 'topik-grammar',
                    'title' => 'TOPIK II Grammar Foundations',
                    'teacher' => 'Seo-yeon Kim',
                    'summary' => 'Master essential grammar patterns with guided practice and assessments.',
                    'price' => '$59',
                    'progress' => 0,
                    'enrolled' => false,
                    'favorite' => $studentMode,
                ],
                [
                    'slug' => 'business-english',
                    'title' => 'Business English Essentials',
                    'teacher' => 'Alex Morgan',
                    'summary' => 'Communicate clearly in meetings, presentations and professional writing.',
                    'price' => 'Free',
                    'progress' => 0,
                    'enrolled' => false,
                    'favorite' => false,
                ],
            ],
        ];
    }
}
