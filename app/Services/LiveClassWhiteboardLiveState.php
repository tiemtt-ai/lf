<?php

namespace App\Services;

use App\Events\LiveClassWhiteboardBroadcast;
use App\Support\LiveClassBoardContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The shared live state of a Session's Whiteboard (ADR-0002 § LiveClass
 * Whiteboard Amendment): the active-presenter lock, the per-Session sequence,
 * the presenter's latest snapshot and the events published after it.
 *
 * Everything lives in the cache, keyed by `customer_id` and Session. It is a
 * read model for late joiners, never a source of truth: losing it loses only
 * what a learner who joins later sees, and the presenter's browser sends a
 * fresh snapshot when asked. Callers pass an authorized LiveClassBoardContext;
 * this class checks only who holds the lock.
 *
 * The snapshot (up to 1 MB) has its own key, apart from the event log. A
 * presenter drawing publishes about ten times a second, and each publish
 * reads and writes only the log.
 */
final class LiveClassWhiteboardLiveState
{
    /** AllVia board events, plus `stroke.append` for long strokes and `state.refresh` for changes too big for one event. */
    public const EVENT_TYPES = [
        'stroke.add', 'stroke.append', 'stroke.remove', 'page.clear', 'board.clear', 'page.replace',
        'note.add', 'note.update', 'note.remove', 'page.change', 'material.change', 'view.change', 'pointer',
        'video.play', 'video.pause', 'video.seek', 'video.close', 'video.sync', 'state.refresh',
    ];

    /** Pointer, scroll/zoom and the media position heartbeat are passed on but not cached and carry no sequence number. */
    public const EPHEMERAL_TYPES = ['pointer', 'view.change', 'video.sync'];

    /** One event stays under Reverb's 10,000-byte message limit with room for the envelope. */
    public const MAX_EVENT_BYTES = 8192;

    public const MAX_BATCH = 40;

    public const MAX_SNAPSHOT_BYTES = 1_048_576;

    /** The lock expires unless the holder publishes or sends a heartbeat. */
    public const LOCK_SECONDS = 60;

    private const SNAPSHOT_KEYS = ['v', 'material', 'page', 'view', 'pages', 'video'];

    /** After this many cached events the presenter is asked for a new snapshot. */
    private const SNAPSHOT_HINT_EVENTS = 200;

    /** Hard cap: past it the log is dropped and late joiners wait for the next snapshot. */
    private const LOG_LIMIT = 2000;

    private const BUFFER_HOURS = 2;

    public static function channelName(int $customerId, int $sessionId): string
    {
        return 'tenant.'.$customerId.'.liveclass-board.'.$sessionId;
    }

    /** @return array{user_id: int, client_id: string, name: string}|null */
    public function presenter(LiveClassBoardContext $context): ?array
    {
        $holder = Cache::get($this->key($context->customerId, $context->sessionId, 'lock'));

        return is_array($holder) ? $holder : null;
    }

    /**
     * Takes or keeps the active-presenter lock for one browser tab. A second
     * presenter becomes a viewer unless they take over explicitly; the
     * previous holder learns it from the `board.presenter` message.
     *
     * @return array{presenting: bool, presenter: array{name: string|null}}
     */
    public function claim(LiveClassBoardContext $context, string $clientId, string $name, bool $takeover): array
    {
        abort_unless($context->canPresent, 403);

        return $this->locked($context, function () use ($context, $clientId, $name, $takeover): array {
            $current = $this->presenter($context);
            $mine = $current !== null && $this->isHolder($current, $context, $clientId);

            if ($current !== null && ! $mine && ! $takeover) {
                return ['presenting' => false, 'presenter' => ['name' => $current['name']]];
            }

            $holder = ['user_id' => $context->userId, 'client_id' => $clientId, 'name' => $name];
            Cache::put($this->key($context->customerId, $context->sessionId, 'lock'), $holder, self::LOCK_SECONDS);

            if (! $mine) {
                $this->broadcast($context->customerId, $context->sessionId, 'board.presenter', [
                    'name' => $name, 'user_id' => $context->userId, 'client_id' => $clientId,
                ]);
            }

            return ['presenting' => true, 'presenter' => ['name' => $name]];
        });
    }

    public function release(LiveClassBoardContext $context, string $clientId): void
    {
        $this->locked($context, function () use ($context, $clientId): void {
            $current = $this->presenter($context);
            if ($current === null || ! $this->isHolder($current, $context, $clientId)) {
                return;
            }
            Cache::forget($this->key($context->customerId, $context->sessionId, 'lock'));
            $this->broadcast($context->customerId, $context->sessionId, 'board.presenter', [
                'name' => null, 'user_id' => null, 'client_id' => null,
            ]);
        });
    }

    /**
     * Validates, numbers, caches and broadcasts a batch from the lock holder.
     *
     * @param  array<int, mixed>  $events
     * @return array{seq: int, snapshot_requested: bool, broadcast: bool}
     */
    public function publish(LiveClassBoardContext $context, string $clientId, array $events): array
    {
        $events = $this->validatedEvents($events);

        return $this->locked($context, function () use ($context, $clientId, $events): array {
            $this->assertHolder($context, $clientId);

            $state = $this->readLog($context);
            $outgoing = [];
            foreach ($events as $event) {
                if (in_array($event['type'], self::EPHEMERAL_TYPES, true)) {
                    $outgoing[] = ['seq' => null, 'client_id' => $clientId] + $event;

                    continue;
                }
                $state['seq']++;
                $event = ['seq' => $state['seq'], 'client_id' => $clientId] + $event;
                $state['events'][] = $event;
                $outgoing[] = $event;
            }

            $snapshotKey = $this->key($context->customerId, $context->sessionId, 'snapshot');
            if (count($state['events']) > self::LOG_LIMIT) {
                $state = ['seq' => $state['seq'], 'snapshot_seq' => $state['seq'], 'events' => [], 'incomplete' => true];
                Cache::forget($snapshotKey);
            } elseif (($state['snapshotted'] ?? false) && ! Cache::has($snapshotKey)) {
                // The snapshot expired or was evicted before the log: ask for a new one.
                $state['snapshotted'] = false;
                $state['incomplete'] = true;
            }
            $this->writeLog($context, $state);

            $broadcast = true;
            foreach ($this->messages($outgoing) as $message) {
                $broadcast = $this->broadcast($context->customerId, $context->sessionId, 'board.events', ['events' => $message]) && $broadcast;
            }

            return [
                'seq' => $state['seq'],
                'snapshot_requested' => $state['incomplete'] || count($state['events']) >= self::SNAPSHOT_HINT_EVENTS,
                'broadcast' => $broadcast,
            ];
        });
    }

    /**
     * Replaces the cached state with the presenter's own board. The snapshot
     * covers every event the holder sent before it, so the log restarts here.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function storeSnapshot(LiveClassBoardContext $context, string $clientId, array $snapshot): int
    {
        $snapshot = array_intersect_key($snapshot, array_flip(self::SNAPSHOT_KEYS));
        if (is_array($snapshot['video'] ?? null)) {
            $snapshot['video']['at'] = self::serverMillis();
        }
        $bytes = strlen((string) json_encode($snapshot));
        if ($bytes > self::MAX_SNAPSHOT_BYTES) {
            throw ValidationException::withMessages(['state' => __('lf.LF_liveclass_whiteboard_sync_too_large')]);
        }

        return $this->locked($context, function () use ($context, $clientId, $snapshot): int {
            $this->assertHolder($context, $clientId);
            $state = $this->readLog($context);
            // Snapshot first, then the log: a reader that sees the new log also finds this snapshot.
            $this->writeEntry($context, 'snapshot', ['seq' => $state['seq'], 'snapshot' => $snapshot]);
            $this->writeLog($context, [
                'seq' => $state['seq'], 'snapshot_seq' => $state['seq'], 'events' => [], 'incomplete' => false, 'snapshotted' => true,
            ]);

            return $state['seq'];
        });
    }

    /**
     * What a late joiner loads after subscribing: the snapshot, the events
     * after it and the current presenter's name.
     *
     * @return array<string, mixed>
     */
    public function state(LiveClassBoardContext $context): array
    {
        $state = $this->readState($context);
        $presenter = $this->presenter($context);

        return $state + ['presenter' => ['name' => $presenter['name'] ?? null], 'now' => self::serverMillis()];
    }

    /**
     * The log and the snapshot read without the lock, log first. A snapshot
     * stored in between is newer than the log and covers all of it; one older
     * than the log's start (dropped with an overfull log) does not count.
     *
     * @return array{seq: int, snapshot: array<string, mixed>|null, snapshot_seq: int, events: array<int, array<string, mixed>>, incomplete: bool}
     */
    private function readState(LiveClassBoardContext $context): array
    {
        $log = $this->readLog($context);
        $entry = Cache::get($this->key($context->customerId, $context->sessionId, 'snapshot'));
        if (! is_array($entry) && is_array($log['snapshot'] ?? null)) {
            // Cached before the snapshot had its own key.
            $entry = ['seq' => $log['snapshot_seq'], 'snapshot' => $log['snapshot']];
        }
        unset($log['snapshot'], $log['snapshotted']);

        $entrySeq = is_array($entry) ? (int) ($entry['seq'] ?? -1) : -1;
        if ($entrySeq > $log['snapshot_seq']) {
            return [
                'seq' => max($log['seq'], $entrySeq), 'snapshot' => $entry['snapshot'], 'snapshot_seq' => $entrySeq,
                'events' => array_values(array_filter($log['events'], fn (array $event): bool => $event['seq'] > $entrySeq)),
                'incomplete' => false,
            ];
        }

        return ['snapshot' => $entrySeq === $log['snapshot_seq'] ? $entry['snapshot'] : null] + $log;
    }

    /** Server time in milliseconds: the one clock every board compares media positions with. */
    public static function serverMillis(): int
    {
        return (int) CarbonImmutable::now()->getTimestampMs();
    }

    /**
     * The one point every way out of a live board goes through: clears the
     * cached state and lock and tells every open board to close.
     */
    public function end(int $customerId, int $sessionId): void
    {
        foreach (['lock', 'state', 'snapshot'] as $part) {
            Cache::forget($this->key($customerId, $sessionId, $part));
        }
        $this->broadcast($customerId, $sessionId, 'board.ended', []);
    }

    /** A Cohort that leaves `active` closes the boards of its live Sessions. */
    public function endCohort(int $customerId, int $cohortId): void
    {
        foreach ($this->liveSessionIds($customerId, $cohortId) as $sessionId) {
            $this->end($customerId, $sessionId);
        }
    }

    /**
     * Learners removed from a Cohort close any board of that Cohort they have
     * open. Their next request is refused anyway; this makes it immediate.
     *
     * @param  array<int, int>  $studentIds
     */
    public function revokeViewers(int $customerId, int $cohortId, array $studentIds): void
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if ($studentIds === []) {
            return;
        }
        foreach ($this->liveSessionIds($customerId, $cohortId) as $sessionId) {
            $this->broadcast($customerId, $sessionId, 'board.revoked', ['user_ids' => $studentIds]);
        }
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<int, array<string, mixed>>
     */
    private function validatedEvents(array $events): array
    {
        if ($events === [] || count($events) > self::MAX_BATCH) {
            throw ValidationException::withMessages(['events' => __('lf.LF_liveclass_whiteboard_sync_invalid')]);
        }

        return array_map(function (mixed $event): array {
            if (! is_array($event) || ! in_array($event['type'] ?? null, self::EVENT_TYPES, true)) {
                throw ValidationException::withMessages(['events' => __('lf.LF_liveclass_whiteboard_sync_invalid')]);
            }
            // Numbering and sender identity always come from the server.
            unset($event['seq'], $event['client_id'], $event['user_id']);
            if (str_starts_with($event['type'], 'video.')) {
                // So does the time of a media event: viewers and late joiners compute the
                // presenter's position from it (video and audio alike).
                $event['at'] = self::serverMillis();
            }
            if (array_key_exists('page', $event)) {
                $page = filter_var($event['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
                if ($page === false) {
                    throw ValidationException::withMessages(['events' => __('lf.LF_liveclass_whiteboard_sync_invalid')]);
                }
                $event['page'] = $page;
            }
            if (strlen((string) json_encode($event)) > self::MAX_EVENT_BYTES) {
                throw ValidationException::withMessages(['events' => __('lf.LF_liveclass_whiteboard_sync_too_large')]);
            }

            return $event;
        }, array_values($events));
    }

    /**
     * Groups consecutive events into as few channel messages as fit the event
     * size limit, so a batch costs a few broadcasts instead of one per event.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function messages(array $events): array
    {
        $messages = [];
        $current = [];
        $bytes = 0;
        foreach ($events as $event) {
            $size = strlen((string) json_encode($event)) + 1;
            if ($current !== [] && $bytes + $size > self::MAX_EVENT_BYTES) {
                $messages[] = $current;
                $current = [];
                $bytes = 0;
            }
            $current[] = $event;
            $bytes += $size;
        }
        if ($current !== []) {
            $messages[] = $current;
        }

        return $messages;
    }

    private function assertHolder(LiveClassBoardContext $context, string $clientId): void
    {
        $current = $this->presenter($context);
        abort_unless($current !== null && $this->isHolder($current, $context, $clientId), 409,
            __('lf.LF_liveclass_whiteboard_sync_not_presenter'));

        // Publishing counts as a heartbeat.
        Cache::put($this->key($context->customerId, $context->sessionId, 'lock'), $current, self::LOCK_SECONDS);
    }

    /** @param array<string, mixed> $holder */
    private function isHolder(array $holder, LiveClassBoardContext $context, string $clientId): bool
    {
        return (int) ($holder['user_id'] ?? 0) === $context->userId && ($holder['client_id'] ?? null) === $clientId;
    }

    /**
     * The event log under the `state` key. One cached before the snapshot had
     * its own key may still carry `snapshot`; readState() honours it.
     *
     * `snapshotted` says a snapshot was stored for this log, so a missing one
     * means it expired.
     *
     * @return array{seq: int, snapshot_seq: int, events: array<int, array<string, mixed>>, incomplete: bool, snapshotted?: bool, snapshot?: mixed}
     */
    private function readLog(LiveClassBoardContext $context): array
    {
        $state = Cache::get($this->key($context->customerId, $context->sessionId, 'state'));

        return is_array($state)
            ? $state + ['incomplete' => false]
            : ['seq' => 0, 'snapshot_seq' => 0, 'events' => [], 'incomplete' => false];
    }

    /** @param array<string, mixed> $state */
    private function writeLog(LiveClassBoardContext $context, array $state): void
    {
        if (array_key_exists('snapshot', $state)) {
            // Moves an older cached snapshot to its own key, once.
            if (is_array($state['snapshot'])) {
                $this->writeEntry($context, 'snapshot', ['seq' => $state['snapshot_seq'], 'snapshot' => $state['snapshot']]);
                $state['snapshotted'] = true;
            }
            unset($state['snapshot']);
        }
        $this->writeEntry($context, 'state', $state);
    }

    /** @param array<string, mixed> $value */
    private function writeEntry(LiveClassBoardContext $context, string $part, array $value): void
    {
        // The Session's planned end plus a buffer, refreshed on every write.
        $now = CarbonImmutable::now();
        $expires = $context->plannedEndAt?->addHours(self::BUFFER_HOURS);
        if ($expires === null || $expires->lessThan($now->addHours(self::BUFFER_HOURS))) {
            $expires = $now->addHours(self::BUFFER_HOURS);
        }
        Cache::put($this->key($context->customerId, $context->sessionId, $part), $value, $expires);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(LiveClassBoardContext $context, callable $callback): mixed
    {
        try {
            return Cache::lock($this->key($context->customerId, $context->sessionId, 'mutex'), 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            abort(503, __('lf.LF_liveclass_whiteboard_sync_busy'));
        }
    }

    /** @param array<string, mixed> $payload */
    private function broadcast(int $customerId, int $sessionId, string $name, array $payload): bool
    {
        try {
            event(new LiveClassWhiteboardBroadcast($customerId, $sessionId, $name, $payload));

            return true;
        } catch (Throwable $exception) {
            // The board keeps working locally; viewers resync when the channel is back.
            report($exception);

            return false;
        }
    }

    /** @return array<int, int> */
    private function liveSessionIds(int $customerId, int $cohortId): array
    {
        return DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)->where('status', 'live')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function key(int $customerId, int $sessionId, string $part): string
    {
        return 'lf-wb:'.$customerId.':'.$sessionId.':'.$part;
    }
}
