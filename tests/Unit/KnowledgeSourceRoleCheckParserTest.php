<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The K3 migration's preflight reads `chk_akc_source_role` back from
 * information_schema and must recognise only the constraint it replaces.
 * K3-R8: an earlier normaliser stripped every backtick, so the literal
 * 'para`graph' became 'paragraph' and a foreign CHECK passed as the baseline.
 * These cases need no database; the physical ones live in
 * AiKnowledgeSourceRoleMigrationMariaDbTest.
 */
class KnowledgeSourceRoleCheckParserTest extends TestCase
{
    private const NARROW = "'paragraph','heading','list','table','figure','caption','header','footer','other'";

    private const ROLES = ['paragraph', 'heading', 'list', 'table', 'figure', 'caption', 'header', 'footer', 'other'];

    #[DataProvider('accepted')]
    public function test_the_stored_forms_of_the_baseline_are_recognised(string $clause): void
    {
        $this->assertSame(self::ROLES, $this->parse($clause));
    }

    /** @return array<string,array{string}> */
    public static function accepted(): array
    {
        return [
            'MariaDB as stored' => ['`source_role` is null or `source_role` in ('.self::NARROW.')'],
            'MariaDB, spacing and keyword case' => ["  `source_role`  IS NULL\n OR `source_role` IN ( ".str_replace(',', ' , ', self::NARROW).' ) '],
            'unquoted identifier' => ['source_role is null or source_role in ('.self::NARROW.')'],
            'MySQL as stored' => ["((`source_role` is null) or (`source_role` in (_utf8mb4'".implode("',_utf8mb4'", self::ROLES)."')))"],
        ];
    }

    #[DataProvider('refused')]
    public function test_any_other_constraint_is_refused(string $clause): void
    {
        $this->assertNull($this->parse($clause));
    }

    /** @return array<string,array{string}> */
    public static function refused(): array
    {
        $narrow = self::NARROW;

        return [
            // K3-R8: literal contents are never rewritten into a valid role.
            'backtick inside a literal' => ["`source_role` is null or `source_role` in ('para`graph',".substr($narrow, 12).')'],
            'doubled quote inside a literal' => ["`source_role` is null or `source_role` in ('para''graph',".substr($narrow, 12).')'],
            'backslash escape inside a literal' => ["`source_role` is null or `source_role` in ('para\\graph',".substr($narrow, 12).')'],
            'upper-case literal' => ["`source_role` is null or `source_role` in ('PARAGRAPH',".substr($narrow, 12).')'],
            'literal with a space' => ["`source_role` is null or `source_role` in ('para graph',".substr($narrow, 12).')'],
            'introducer in the MariaDB form' => ["`source_role` is null or `source_role` in (_binary'paragraph',".substr($narrow, 12).')'],
            'other introducer in the MySQL form' => ["((`source_role` is null) or (`source_role` in (_latin1'".implode("',_latin1'", self::ROLES)."')))"],
            'escaped backtick in the identifier' => ['`source``role` is null or `source_role` in ('.$narrow.')'],
            'another column' => ['`locator_type` is null or `source_role` in ('.$narrow.')'],
            'extra OR branch' => ['`source_role` is null or `source_role` in ('.$narrow.') or 1 = 1'],
            'extra AND branch' => ['`source_role` is null or `source_role` in ('.$narrow.') and 1 = 0'],
            'NULL branch missing' => ['`source_role` in ('.$narrow.')'],
            'IS NOT NULL' => ['`source_role` is not null or `source_role` in ('.$narrow.')'],
            'NOT IN' => ['`source_role` is null or `source_role` not in ('.$narrow.')'],
            'duplicate role' => ['`source_role` is null or `source_role` in ('.$narrow.",'other')"],
            'empty list' => ['`source_role` is null or `source_role` in ()'],
            'trailing comma' => ['`source_role` is null or `source_role` in ('.$narrow.',)'],
            'unterminated literal' => ["`source_role` is null or `source_role` in ('paragraph"],
            'unterminated identifier' => ['`source_role is null'],
            'MySQL form missing a parenthesis' => ["(`source_role` is null) or (`source_role` in (_utf8mb4'paragraph'))"],
        ];
    }

    /** @return list<string>|null */
    private function parse(string $clause): ?array
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php';

        return (new ReflectionMethod($migration, 'nullableInList'))->invoke($migration, $clause);
    }
}
