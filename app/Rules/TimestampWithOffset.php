<?php

namespace App\Rules;

use App\Services\TeacherJudgmentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A timestamp that states its own offset.
 *
 * LearnForge stores naive wall-clock in the application timezone because every
 * writer does, and that ambiguity is survivable while it is uniform. An
 * ambiguous inbound string is not: a caller's local "now" read as UTC lands
 * seven hours away, and the Teacher Judgment row it produces is protected by
 * trg_ltj_bu_immutable, so there is no repair afterwards.
 *
 * This rule owns the shape only. It shares one pattern with
 * TeacherJudgmentService so the boundary and the domain guard cannot drift, and
 * it does not replace that guard — the service is callable without HTTP.
 */
class TimestampWithOffset implements ValidationRule
{
    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '' || preg_match(TeacherJudgmentService::OCCURRED_AT_OFFSET_PATTERN, $raw) !== 1) {
            $fail('lf.LF_learning_occurred_at_offset_required')->translate();

            return;
        }

        try {
            new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            // Well-formed shape, impossible instant — 2026-13-45T00:00:00+07:00.
            $fail('lf.LF_learning_occurred_at_invalid')->translate();
        }
    }
}
