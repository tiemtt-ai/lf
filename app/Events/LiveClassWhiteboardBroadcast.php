<?php

namespace App\Events;

use App\Services\LiveClassWhiteboardLiveState;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * One message on a Session's private board channel (ADR-0002 § LiveClass
 * Whiteboard Amendment, Real-time sync). Sent immediately, never queued, so
 * the order on the channel is the order the server assigned.
 *
 * Names: `board.events` (presenter events in order, with their sequence numbers),
 * `board.presenter` (the active presenter changed), `board.ended` (the board
 * closed) and `board.revoked` (some learners lost access).
 */
final class LiveClassWhiteboardBroadcast implements ShouldBroadcastNow
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $customerId,
        public readonly int $sessionId,
        public readonly string $name,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(LiveClassWhiteboardLiveState::channelName($this->customerId, $this->sessionId));
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
