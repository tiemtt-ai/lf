<?php

use App\Models\User;
use App\Services\LiveClassWhiteboardService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// One private channel per live Session's Whiteboard (ADR-0002 § LiveClass Whiteboard
// Amendment): presenters and viewers who pass the same checks as the board page.
// Subscribers only listen; client events are switched off in config/reverb.php.
Broadcast::channel('tenant.{customerId}.liveclass-board.{sessionId}', function (User $user, string $customerId, string $sessionId): bool {
    if (! ctype_digit($customerId) || ! ctype_digit($sessionId) || (int) TenantContext::customerId() !== (int) $customerId) {
        return false;
    }

    return app(LiveClassWhiteboardService::class)->boardAccess((int) $customerId, $user, (int) $sessionId) !== null;
});
