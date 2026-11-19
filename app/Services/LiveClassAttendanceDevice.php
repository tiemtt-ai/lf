<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "One device records attendance for one learner in a Session" (MK 2.4; Owner 2026-11-18), for the QR code and the
 * "Điểm danh" button of a Session in a room. The device is a random id kept in a cookie of the phone's browser, and
 * which learner it recorded is kept in the cache only, for RETENTION_HOURS. It is a barrier, not proof: another
 * browser or a private window is another device. A refused learner is recorded by the teacher by hand.
 */
final class LiveClassAttendanceDevice
{
    public const COOKIE = 'lf_attendance_device';

    public const RETENTION_HOURS = 48;

    /** The entry results that mean this device has recorded this learner. */
    private const CLAIMING = ['recorded', 'mode_changed'];

    /**
     * Runs `$enter` unless this device already recorded another learner in the Session; then the result is
     * `device_used` and nothing is written. A self-study Session is learned at home, where a family may share one
     * device, so the rule does not apply to it.
     *
     * @param  Closure(): array{result: string}  $enter
     * @return array{result: string}
     */
    public function guard(Request $request, int $customerId, int $sessionId, int $userId, bool $applies, Closure $enter): array
    {
        if (! $applies) {
            return $enter();
        }
        $key = 'lf-attendance-device|'.$customerId.'|'.$sessionId.'|'.hash('sha256', $this->id($request));
        $owner = Cache::get($key);
        if ($owner !== null && (int) $owner !== $userId) {
            return ['result' => 'device_used'];
        }

        $outcome = $enter();
        if ($owner === null && in_array($outcome['result'], self::CLAIMING, true)) {
            Cache::add($key, $userId, now()->addHours(self::RETENTION_HOURS));
        }

        return $outcome;
    }

    /** The device id of this browser; a browser without one gets it with the response. */
    private function id(Request $request): string
    {
        $id = $request->cookie(self::COOKIE);
        if (is_string($id) && preg_match('/^[A-Za-z0-9]{40}$/', $id)) {
            return $id;
        }
        $id = Str::random(40);
        Cookie::queue(cookie(self::COOKIE, $id, 60 * 24 * 365, httpOnly: true, sameSite: 'lax'));

        return $id;
    }
}
