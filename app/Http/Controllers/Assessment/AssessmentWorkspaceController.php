<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Entry of the Kiểm tra module in the back office (ADR-0025 D2).
 * Sub-items not built yet render a placeholder page; each moves to its own controller when built.
 */
final class AssessmentWorkspaceController extends Controller
{
    /** Menu order: foundation first, grading last. */
    public const SECTIONS = ['categories', 'questions', 'passages', 'rubrics', 'quizzes', 'registrations', 'results'];

    /** @return list<string> */
    public static function sectionsFor(bool $withCategories): array
    {
        return array_values(array_filter(
            self::SECTIONS,
            fn (string $section): bool => $withCategories || $section !== 'categories',
        ));
    }

    public function home(Request $request): RedirectResponse
    {
        $area = $request->user()->role === 'teacher' ? 'teacher' : 'admin';

        return redirect()->route($area.'.assessments.questions.index');
    }

    public function placeholder(string $section): View
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return view('assessments.placeholder', ['section' => $section]);
    }
}
