<?php

namespace Tests\Unit;

use App\Support\Database\TriggerCreationPreflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every branch of the decision, including binary-log cases a test server cannot switch on. */
class TriggerCreationPreflightTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_decision(bool $binaryLog, bool $trust, bool $trigger, bool $super, ?string $expected): void
    {
        $this->assertSame($expected, TriggerCreationPreflight::decide($binaryLog, $trust, $trigger, $super));
    }

    /** @return array<string,array{bool,bool,bool,bool,?string}> */
    public static function cases(): array
    {
        return [
            'no binlog, TRIGGER granted' => [false, false, true, false, null],
            'no TRIGGER privilege' => [false, false, false, true, 'LF_MIGRATION_PREFLIGHT_TRIGGER_PRIVILEGE'],
            'binlog, no trust, no SUPER' => [true, false, true, false, 'LF_MIGRATION_PREFLIGHT_BINLOG_TRIGGER'],
            'binlog, no trust, SUPER' => [true, false, true, true, null],
            'binlog, trust on, no SUPER' => [true, true, true, false, null],
            'binlog without TRIGGER still refused first' => [true, true, false, true, 'LF_MIGRATION_PREFLIGHT_TRIGGER_PRIVILEGE'],
        ];
    }
}
