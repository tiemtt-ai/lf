<?php

namespace App\Http\Controllers;

use App\Services\LiveClassWhiteboardLiveState;
use App\Services\LiveClassWhiteboardService;
use App\Support\LiveClassBoardContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Whiteboard sync endpoints (ADR-0002 § LiveClass Whiteboard Amendment,
 * Real-time sync). Each role reaches them through its own route group; the
 * group sets `board_role`, and the same checks as the board page run on every
 * request. Only presenters publish; learners read the state and status.
 */
final class LiveClassWhiteboardSyncController extends Controller
{
    private const CLIENT_ID_RULE = ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'];

    public function __construct(
        private readonly LiveClassWhiteboardService $whiteboards,
        private readonly LiveClassWhiteboardLiveState $live,
    ) {}

    /** What a board loads after subscribing: snapshot, later events and the presenter's name. */
    public function state(Request $request): JsonResponse
    {
        return $this->noStore(response()->json($this->live->state($this->context($request))));
    }

    /** A cheap check that the board is still open to this user; viewers call it every minute. */
    public function status(Request $request): JsonResponse
    {
        $presenter = $this->live->presenter($this->context($request));

        return $this->noStore(response()->json(['live' => true, 'presenter' => ['name' => $presenter['name'] ?? null]]));
    }

    /** Takes, keeps (heartbeat) or takes over the active-presenter lock for one browser tab. */
    public function presence(Request $request): JsonResponse
    {
        $context = $this->presenterContext($request);
        $validated = $request->validate(['client_id' => self::CLIENT_ID_RULE, 'takeover' => ['sometimes', 'boolean']]);

        return $this->noStore(response()->json(
            $this->live->claim($context, $validated['client_id'], (string) $request->user()->name, $request->boolean('takeover'))
        ));
    }

    public function events(Request $request): JsonResponse
    {
        $context = $this->presenterContext($request);
        $validated = $request->validate([
            'client_id' => self::CLIENT_ID_RULE,
            'events' => ['required', 'array', 'min:1', 'max:'.LiveClassWhiteboardLiveState::MAX_BATCH],
        ]);

        return $this->noStore(response()->json($this->live->publish($context, $validated['client_id'], $validated['events'])));
    }

    public function snapshot(Request $request): JsonResponse
    {
        $context = $this->presenterContext($request);
        $validated = $request->validate(['client_id' => self::CLIENT_ID_RULE, 'state' => ['required', 'array']]);

        return $this->noStore(response()->json([
            'seq' => $this->live->storeSnapshot($context, $validated['client_id'], $validated['state']),
        ]));
    }

    /** Sent with navigator.sendBeacon when the presenter's window closes. */
    public function release(Request $request): Response
    {
        $context = $this->presenterContext($request);
        $validated = $request->validate(['client_id' => self::CLIENT_ID_RULE]);
        $this->live->release($context, $validated['client_id']);

        return response()->noContent();
    }

    private function presenterContext(Request $request): LiveClassBoardContext
    {
        $context = $this->context($request);
        abort_unless($context->canPresent, 403);

        return $context;
    }

    private function context(Request $request): LiveClassBoardContext
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);
        $userId = (int) $request->user()->id;
        $session = (int) $request->route('session');

        return match ($request->route()?->defaults['board_role'] ?? null) {
            'teacher' => $this->whiteboards->teacherContext((int) $customerId, $userId, $session),
            'admin' => $this->whiteboards->adminContext((int) $customerId, $userId, (int) $request->route('cohort'), $session),
            'student' => $this->whiteboards->learnerContext((int) $customerId, $userId, $session),
            default => abort(404),
        };
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store');
    }
}
