<?php

namespace App\Services;

use App\Support\AuditLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The operational part of a Session that its own teachers may change (ADR-0002 § Teacher Session Operations
 * Amendment): the meeting link, the room, the address and the attendance tool. The delivery mode, the time and the
 * teaching team stay with `customer_admin`. Every change is written to `saas_audit_logs`.
 */
final class LiveClassSessionOperations
{
    public const ATTENDANCE_TOOLS = ['board', 'button'];

    public function __construct(private readonly LiveClassSessionPolicy $policy) {}

    /**
     * What may be changed now. `place` (room, address, attendance tool) follows the existing edit rule: scheduled,
     * not started, no evidence, the planned start still ahead. `link` is that rule plus one exception: the meeting
     * link alone stays editable until the planned end, also while the Session is live.
     *
     * @return array{place: bool, link: bool}
     */
    public function editable(int $customerId, object $session, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $general = $this->policy->canEdit($session, $this->hasEvidence($customerId, (int) $session->id), $now);
        $remote = in_array($session->delivery_mode, ['online', 'hybrid'], true);
        $beforeEnd = CarbonImmutable::instance($now)->utc()
            ->lte(LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null));

        return [
            'place' => $general && in_array($session->delivery_mode, ['offline', 'hybrid'], true),
            'link' => $remote && ($general || (in_array($session->status, ['scheduled', 'live'], true) && $beforeEnd)),
        ];
    }

    /**
     * Applies the fields the Session currently lets through and audits the change. Keys that are absent, or that
     * the Session does not let through now, are left as they are.
     *
     * @param  array{meeting_url?: ?string, room_name?: ?string, address?: ?string, attendance_tool?: ?string}  $input
     * @return array<string, mixed> the columns that changed, with their new values
     */
    public function update(Request $request, int $customerId, object $session, array $input): array
    {
        $editable = $this->editable($customerId, $session);
        $values = [];
        if ($editable['link'] && array_key_exists('meeting_url', $input)) {
            $url = filled($input['meeting_url']) ? trim((string) $input['meeting_url']) : null;
            $values['meeting_url_snapshot'] = $url;
            $values['online_provider'] = LiveClassMeetingProvider::fromUrl($url);
        }
        if ($editable['place']) {
            if (array_key_exists('room_name', $input)) {
                $values['room_name_snapshot'] = filled($input['room_name']) ? trim((string) $input['room_name']) : null;
            }
            if (array_key_exists('address', $input)) {
                $values['address_snapshot'] = filled($input['address']) ? trim((string) $input['address']) : null;
            }
            if (array_key_exists('attendance_tool', $input)
                && ! LiveClassPracticeSession::lesson($customerId, isset($session->version_lesson_id) ? (int) $session->version_lesson_id : null)) {
                $values['attendance_tool'] = in_array($input['attendance_tool'], self::ATTENDANCE_TOOLS, true) ? $input['attendance_tool'] : 'board';
            }
        }

        return DB::transaction(function () use ($request, $customerId, $session, $values): array {
            $current = DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $session->id)
                ->lockForUpdate()->first(array_merge(['id'], array_keys($values)));
            $changed = collect($values)->filter(fn ($value, string $column): bool => ($current->{$column} ?? null) !== $value)->all();
            // The attendance tool is stored as NULL until someone chooses one; NULL and `board` are the same tool.
            if (($changed['attendance_tool'] ?? null) === 'board' && $current->attendance_tool === null) {
                unset($changed['attendance_tool']);
            }
            if ($changed === []) {
                return [];
            }
            DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $session->id)
                ->update($changed + ['updated_at' => now()]);
            AuditLog::record($request, $customerId, 'liveclass_session.operations_updated', null,
                ['session_id' => (int) $session->id] + collect($changed)->map(fn ($value, string $column) => $current->{$column})->all(),
                ['session_id' => (int) $session->id] + $changed);

            return $changed;
        });
    }

    private function hasEvidence(int $customerId, int $sessionId): bool
    {
        return DB::table('core_liveclass_attendances')->where('customer_id', $customerId)->where('session_id', $sessionId)->exists()
            || DB::table('core_liveclass_recordings')->where('customer_id', $customerId)->where('session_id', $sessionId)->exists();
    }
}
