<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * K3 (ADR-0006 v1.0.6): `ai_knowledge_chunks.source_role` snapshots the Media
 * region role verbatim, so the Knowledge CHECK must accept every role Media
 * may write. Knowledge was once designed against a Media vocabulary five days
 * stale and refused whole revisions at runtime; this reads the approved
 * schema contract so the same drift fails the build instead.
 */
class KnowledgeSourceRoleVocabularyTest extends TestCase
{
    private const K3_ROLES = [
        'paragraph', 'heading', 'list', 'table', 'figure', 'image', 'chart', 'diagram',
        'geometry', 'formula', 'caption', 'note', 'header', 'footer', 'other',
    ];

    public function test_knowledge_accepts_exactly_the_approved_fifteen_roles(): void
    {
        $knowledge = $this->knowledgeRoles();

        $this->assertSame(count($knowledge), count(array_unique($knowledge)), 'No role may be listed twice.');
        $this->assertEqualsCanonicalizing(self::K3_ROLES, $knowledge);
    }

    /** Media may widen its vocabulary later; Knowledge must widen in the same change set. */
    public function test_knowledge_accepts_every_role_media_may_write(): void
    {
        $this->assertSame([], array_values(array_diff($this->mediaRoles(), $this->knowledgeRoles())),
            'Media region roles missing from chk_akc_source_role: amend ADR-0006 and the Knowledge CHECK with the Media change.');
    }

    /** @return list<string> */
    private function knowledgeRoles(): array
    {
        return $this->roles('ai_knowledge_chunks', '/^`source_role` is null or `source_role` in \(([^()]*)\)$/');
    }

    /** @return list<string> */
    private function mediaRoles(): array
    {
        return $this->roles('media_extracted_regions', '/^`role` in \(([^()]*)\)$/');
    }

    /**
     * The one CHECK of `$table` whose whole expression is the role IN-list of
     * that column. The contract keeps expressions, not constraint names, so the
     * column-specific pattern selects it; literals of other CHECKs never mix in.
     *
     * @return list<string>
     */
    private function roles(string $table, string $pattern): array
    {
        $contract = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/database/LF-SCHEMA-CONTRACT.json'), true, flags: JSON_THROW_ON_ERROR);
        $tables = array_values(array_filter($contract['tables'], fn (array $entry): bool => $entry['name'] === $table));
        $this->assertCount(1, $tables, $table.' must appear once in the schema contract.');

        $lists = [];
        foreach ($tables[0]['checks'] as $check) {
            if (preg_match($pattern, $check['expression'], $match) === 1) {
                $lists[] = $match[1];
            }
        }
        $this->assertCount(1, $lists, 'Exactly one role CHECK expected on '.$table.'.');

        return array_map(function (string $literal): string {
            $this->assertMatchesRegularExpression("/^'[a-z_]+'$/", $literal);

            return trim($literal, "'");
        }, explode(',', $lists[0]));
    }
}
