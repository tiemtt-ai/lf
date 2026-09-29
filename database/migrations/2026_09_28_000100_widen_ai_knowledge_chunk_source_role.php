<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * K3 (ADR-0006 Amendment v1.0.6, ai_knowledge_chunks.md § Media role vocabulary
 * alignment): `source_role` snapshots the Media region role verbatim, so its
 * CHECK must allow the fifteen roles ADR-0019 v1.7 approved for Media instead
 * of the nine that preceded them. Only the permitted value set changes: no
 * column, identity, data or history is touched.
 *
 * Both directions verify the exact constraint they replace before any DDL and
 * swap it in one ALTER, so the table is never without the CHECK. down() never
 * narrows while a row carries one of the new roles: that row is evidence.
 */
return new class extends Migration
{
    private const TABLE = 'ai_knowledge_chunks';

    private const CONSTRAINT = 'chk_akc_source_role';

    /** The Foundation vocabulary, in the order its migration declared it. */
    private const NARROW = ['paragraph', 'heading', 'list', 'table', 'figure', 'caption', 'header', 'footer', 'other'];

    /** `chk_mer_role` of media_extracted_regions, in its declared order. */
    private const WIDE = [
        'paragraph', 'heading', 'list', 'table', 'figure', 'image', 'chart', 'diagram',
        'geometry', 'formula', 'caption', 'note', 'header', 'footer', 'other',
    ];

    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }
        $this->assertCheckIs(self::NARROW, 'up');
        $this->swapTo(self::WIDE);
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }
        $this->assertCheckIs(self::WIDE, 'down');

        $newRoles = array_values(array_diff(self::WIDE, self::NARROW));
        $holding = DB::table(self::TABLE)->whereIn('source_role', $newRoles)->count();
        if ($holding > 0) {
            throw new RuntimeException(
                'Rollback refused: '.$holding.' '.self::TABLE.' row(s) of any tenant or status carry a role '
                .'outside the narrow vocabulary ('.implode(', ', $newRoles).'). They are snapshots of Media '
                .'evidence and must not be deleted or rewritten to narrow the CHECK.'
            );
        }
        // Writers must be paused before this point (ai_knowledge_chunks.md § K3):
        // a row inserted after the count makes the narrowing ALTER fail on
        // validation, and the table keeps the wide CHECK.
        $this->swapTo(self::NARROW);
    }

    /** SQLite does not keep these CHECKs; it never proves enforcement. */
    private function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @param list<string> $roles */
    private function swapTo(array $roles): void
    {
        DB::statement('ALTER TABLE '.self::TABLE
            .' DROP CONSTRAINT '.self::CONSTRAINT.','
            .' ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (source_role IS NULL OR source_role IN ('
            .implode(',', array_map(fn (string $role): string => "'".$role."'", $roles)).'))');
    }

    /**
     * Stops before DDL unless exactly one `chk_akc_source_role` exists on this
     * table, checks are enforced, and its expression is literally the nullable
     * IN-list of `$expected`. Unknown states are reported, never guessed at.
     *
     * @param  list<string>  $expected
     */
    private function assertCheckIs(array $expected, string $direction): void
    {
        $refuse = fn (string $found): RuntimeException => new RuntimeException(
            self::TABLE.'.'.self::CONSTRAINT.' is not in the state '.$direction.'() replaces ('.$found.'). '
            .'No DDL was issued. Inspect the constraint and the migrations ledger and follow the recovery '
            .'procedure in docs/database/ai/ai_knowledge_chunks.md § Media role vocabulary alignment (K3); '
            .'do not re-run blindly.'
        );

        // MariaDB can switch CHECK enforcement off per session; the ALTER must
        // never run, and never be verified, in that mode.
        $version = strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version);
        if (str_contains($version, 'mariadb')
            && (int) DB::selectOne('SELECT @@check_constraint_checks AS enforced')->enforced !== 1) {
            throw $refuse('check_constraint_checks is off');
        }

        $clauses = array_map(fn (object $row): string => (string) $row->CHECK_CLAUSE, DB::select(
            'SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc'
            .' JOIN information_schema.CHECK_CONSTRAINTS cc'
            .' ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME'
            .' WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?'
            .' AND tc.CONSTRAINT_TYPE = ? AND tc.CONSTRAINT_NAME = ?',
            [self::TABLE, 'CHECK', self::CONSTRAINT],
        ));
        if (count($clauses) !== 1) {
            throw $refuse(count($clauses).' matching constraints');
        }

        $roles = $this->nullableInList($clauses[0]);
        if ($roles === null) {
            throw $refuse('unrecognized expression');
        }
        $sortedFound = $roles;
        $sortedExpected = $expected;
        sort($sortedFound);
        sort($sortedExpected);
        if ($sortedFound !== $sortedExpected) {
            throw $refuse(count($roles).' roles: '.implode(',', $roles));
        }
    }

    /**
     * The role list of `source_role IS NULL OR source_role IN (...)`, or null
     * for any other expression.
     *
     * The clause is tokenized first, so quoting is removed only from the
     * identifier it quotes and a string literal keeps its exact contents
     * (K3-R8: stripping every backtick turned the literal 'para`graph' into
     * 'paragraph'). Two token sequences are accepted: MariaDB's stored form,
     * and MySQL's, which wraps each operand in parentheses and may put a
     * `_utf8mb4` introducer before a literal. A literal must be plain
     * lower-case `[a-z_]`: an escape, a quote, a backtick or another case is a
     * different constraint, refused rather than repaired.
     *
     * @return list<string>|null
     */
    private function nullableInList(string $clause): ?array
    {
        $tokens = $this->tokens($clause);
        if ($tokens === null) {
            return null;
        }

        $forms = [
            ['ID', 'is', 'null', 'or', 'ID', 'in', '(', 'LIST', ')'],
            ['(', '(', 'ID', 'is', 'null', ')', 'or', '(', 'ID', 'in', '(', 'LIST_MYSQL', ')', ')', ')'],
        ];
        foreach ($forms as $form) {
            $roles = $this->match($tokens, $form);
            if ($roles !== null) {
                return count($roles) === count(array_unique($roles)) ? $roles : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0:string,1:string}>  $tokens
     * @param  list<string>  $form
     * @return list<string>|null
     */
    private function match(array $tokens, array $form): ?array
    {
        $at = 0;
        $roles = null;
        foreach ($form as $expected) {
            if ($expected === 'ID') {
                [$kind, $value] = $tokens[$at++] ?? ['', ''];
                if (! in_array($kind, ['word', 'quoted'], true) || strtolower($value) !== 'source_role') {
                    return null;
                }
            } elseif ($expected === 'LIST' || $expected === 'LIST_MYSQL') {
                $roles = [];
                while (true) {
                    if ($expected === 'LIST_MYSQL' && ($tokens[$at] ?? null) === ['introducer', '_utf8mb4']) {
                        $at++;
                    }
                    [$kind, $value] = $tokens[$at++] ?? ['', ''];
                    if ($kind !== 'literal' || preg_match('/^[a-z_]+$/', $value) !== 1) {
                        return null;
                    }
                    $roles[] = $value;
                    if (($tokens[$at] ?? null) !== ['punct', ',']) {
                        break;
                    }
                    $at++;
                }
            } elseif (in_array($expected, ['(', ')'], true)) {
                if (($tokens[$at++] ?? null) !== ['punct', $expected]) {
                    return null;
                }
            } elseif (($tokens[$at++] ?? null) !== ['word', $expected]) {
                return null;
            }
        }

        return $at === count($tokens) ? $roles : null;
    }

    /**
     * Words (lower-cased), backtick-quoted identifiers, string literals,
     * introducers and `(`, `)`, `,`. A literal with a doubled quote or a
     * backslash escape comes back as `escaped`, which no form accepts; any other
     * character becomes `other`. Null for an unterminated quote.
     *
     * @return list<array{0:string,1:string}>|null
     */
    private function tokens(string $clause): ?array
    {
        $tokens = [];
        $length = strlen($clause);
        for ($i = 0; $i < $length;) {
            $char = $clause[$i];
            if (ctype_space($char)) {
                $i++;
            } elseif ($char === '`') {
                $value = '';
                for ($i++; ; $i++) {
                    if ($i >= $length) {
                        return null;
                    }
                    if ($clause[$i] === '`') {
                        if (($clause[$i + 1] ?? '') !== '`') {
                            break;
                        }
                        $i++;
                    }
                    $value .= $clause[$i];
                }
                $i++;
                $tokens[] = ['quoted', $value];
            } elseif ($char === "'") {
                $value = '';
                $escaped = false;
                for ($i++; ; $i++) {
                    if ($i >= $length) {
                        return null;
                    }
                    if ($clause[$i] === '\\') {
                        $escaped = true;
                        $i++;
                    } elseif ($clause[$i] === "'") {
                        if (($clause[$i + 1] ?? '') !== "'") {
                            break;
                        }
                        $escaped = true;
                        $i++;
                    }
                    $value .= $clause[$i] ?? '';
                }
                $i++;
                $tokens[] = [$escaped ? 'escaped' : 'literal', $value];
            } elseif (in_array($char, ['(', ')', ','], true)) {
                $tokens[] = ['punct', $char];
                $i++;
            } elseif (preg_match('/\G[A-Za-z0-9_$]+/', $clause, $word, 0, $i) === 1) {
                $i += strlen($word[0]);
                $isIntroducer = $word[0][0] === '_' && ($clause[$i] ?? '') === "'";
                $tokens[] = [$isIntroducer ? 'introducer' : 'word', strtolower($word[0])];
            } else {
                $tokens[] = ['other', $char];
                $i++;
            }
        }

        return $tokens;
    }
};
