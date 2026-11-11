<?php

namespace App\Http\Controllers;

use App\Services\TenantCourseCatalogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Quan tâm" (Favorite) of a Course Product on the tenant website (ADR-0030 D4). Marking is idempotent and never
 * enrolls or opens learning (Guardrails Course Rule 6); unmarking always works, even once the Product left the
 * catalogue. Answers JSON to the page's script and redirects back otherwise.
 */
class LearnerCourseFavoriteController extends Controller
{
    private const TABLE = 'core_course_favorites';

    public function __construct(private readonly TenantCourseCatalogService $catalog) {}

    public function store(Request $request, int $product): JsonResponse|RedirectResponse
    {
        $customerId = $this->customerId();
        $studentId = (int) $request->user()->id;
        abort_unless($this->catalog->favoritable($customerId, $studentId, $product), 404);

        $now = now();
        DB::table(self::TABLE)->insertOrIgnore([
            'customer_id' => $customerId,
            'product_id' => $product,
            'student_id' => $studentId,
            'source' => 'website',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->answer($request, true);
    }

    public function destroy(Request $request, int $product): JsonResponse|RedirectResponse
    {
        DB::table(self::TABLE)->where('customer_id', $this->customerId())->where('student_id', (int) $request->user()->id)
            ->where('product_id', $product)->delete();

        return $this->answer($request, false);
    }

    private function answer(Request $request, bool $favorite): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['favorite' => $favorite])
            : back()->with('success', __($favorite ? 'lf.LF_my_learning_favorite_added' : 'lf.LF_my_learning_favorite_removed'));
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if($customerId === null, 404);

        return (int) $customerId;
    }
}
