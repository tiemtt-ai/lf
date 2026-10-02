<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Static guard for the browser protocol of the AI proposal review section
 * (design §4.1, §4.3). The script shows content that came from a model or from
 * uploaded Media, so it must never parse that content as markup or code, keep it
 * in browser storage, or write it to the console. A source scan cannot prove the
 * script correct; it stops the specific regressions that would break those rules.
 */
class AiAuthoringScriptProtocolTest extends TestCase
{
    /**
     * `target[key] = …` (also `+=`, `||=` and the like) whatever the key expression and the spacing.
     * A comparison (`===`, `>=`) is not an assignment.
     */
    private const COMPUTED_ASSIGNMENT = '/([\w.)\]]+)\s*\[([^\[\]]+)\]\s*(?:\*\*|\?\?|\|\||&&|[-+*\/%|&^])?=(?!=)/';

    /** Words that can stand before a destructuring pattern, which is not an indexed assignment. */
    private const KEYWORDS = ['const', 'let', 'var', 'return', 'of', 'in', 'case', 'typeof', 'yield', 'await', 'else', 'new', 'delete', 'void'];

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        // Comments may describe a forbidden API; only code is checked.
        $code = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/ai-authoring.js');
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $this->source = (string) preg_replace('#(?<![:\'"])//[^\n]*#', '', $code);
    }

    /** @return array<string,array{string}> */
    public static function forbidden(): array
    {
        return [
            'innerHTML' => ['innerHTML'],
            'outerHTML' => ['outerHTML'],
            'insertAdjacentHTML' => ['insertAdjacentHTML'],
            'document.write' => ['document.write'],
            'eval' => ['eval('],
            'Function constructor' => ['new Function'],
            'localStorage' => ['localStorage'],
            'sessionStorage' => ['sessionStorage'],
            'IndexedDB' => ['indexedDB'],
            'Cache API' => ['caches.'],
            'service worker' => ['serviceWorker'],
            'console' => ['console.'],
            'cookies' => ['document.cookie'],
            'history state' => ['history.pushState'],
            'string timers' => ['setTimeout("'],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_the_script_never_uses(string $needle): void
    {
        $this->assertStringNotContainsString($needle, $this->source);
    }

    public function test_no_forbidden_member_is_reached_through_a_built_string(): void
    {
        // element['inner' + 'HTML'] would slip past the list above; the behavioural
        // tests (tests/js) do not look for sinks, so property access by a built
        // string is refused outright.
        $this->assertDoesNotMatchRegularExpression('/\[\s*[\'"`][^\]]*[\'"`]\s*\+/', $this->source);
        foreach (['window[', 'globalThis[', 'document[', 'Reflect.', 'atob(', 'fromCharCode', 'Object.assign(', 'Object.defineProperty(', 'srcdoc'] as $needle) {
            $this->assertStringNotContainsString($needle, $this->source);
        }
    }

    public function test_a_property_is_only_assigned_by_a_computed_key_in_the_known_places(): void
    {
        // node[member] = value would reach innerHTML through a variable. Every place that
        // assigns by a computed key is listed; a new one has to be looked at and added here.
        preg_match_all(self::COMPUTED_ASSIGNMENT, $this->source, $found, PREG_SET_ORDER);
        $assigned = [];
        foreach ($found as $match) {
            $key = trim($match[2]);
            // A quoted string or a number is a fixed key, not a computed one.
            if (in_array($match[1], self::KEYWORDS, true) || preg_match('/^(?:\'[^\']*\'|"[^"]*"|`[^`$]*`|\d+)$/', $key) === 1) {
                continue;
            }
            $assigned[] = $match[1].'['.preg_replace('/\s+/', '', $key).']';
        }
        sort($assigned);

        $this->assertSame(
            ['controls[name]', 'errors[name]', 'this.createBoxes[kind]', 'this.filters[name]'],
            $assigned,
        );
    }

    public function test_the_computed_key_rule_sees_a_key_with_spaces_and_ignores_a_comparison(): void
    {
        foreach ([
            'node[member] = x', 'node[ member ] = x', "node[\tmember\n]=x", 'node[member] += x', 'node[member] ||= x',
            'node[member] ??= x', 'node[(member)] = x', 'node[member.name] = x', "node['inner' + 'HTML'] = x", 'node [member] = x',
        ] as $sample) {
            $this->assertSame(1, preg_match(self::COMPUTED_ASSIGNMENT, $sample), $sample);
        }
        foreach (['if (node[member] === x) {}', 'if (node[member] !== x) {}', 'if (node[member] >= x) {}', 'if (node[member] <= x) {}'] as $comparison) {
            $this->assertSame(0, preg_match(self::COMPUTED_ASSIGNMENT, $comparison), $comparison);
        }
    }

    public function test_every_read_disables_the_browser_cache_and_refuses_redirects(): void
    {
        $this->assertStringContainsString("cache: 'no-store'", $this->source);
        $this->assertStringContainsString("redirect: 'manual'", $this->source);
        $this->assertStringContainsString("credentials: 'same-origin'", $this->source);
    }

    public function test_commands_carry_the_csrf_token_and_a_fresh_or_frozen_request_id(): void
    {
        $this->assertStringContainsString("headers['X-CSRF-TOKEN'] = this.csrfToken", $this->source);
        $this->assertStringContainsString("headers['Content-Type'] = 'application/json'", $this->source);
        // An id is reused only for the very same command whose outcome is unknown.
        $this->assertStringContainsString('previous && previous.fingerprint === fingerprint ? previous.requestId : newRequestId()', $this->source);
        $this->assertStringContainsString('this.pendings.set(scope, { fingerprint, requestId })', $this->source);
        // Every command kind freezes its id the same way.
        foreach (["'detail'", "'generate'"] as $scope) {
            $this->assertStringContainsString('this.frozenId('.$scope, $this->source);
            $this->assertStringContainsString('this.rememberUnknown('.$scope, $this->source);
        }
    }

    public function test_a_decision_needs_the_shared_confirmation_dialog(): void
    {
        $this->assertStringContainsString("typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open", $this->source);
        $this->assertStringNotContainsString('confirm(', str_replace('LFConfirm', '', str_replace('window.LFConfirm.open', '', $this->source)));
        $this->assertStringNotContainsString('window.confirm', $this->source);
    }

    public function test_content_is_only_ever_written_as_text(): void
    {
        $this->assertStringContainsString('node.textContent = String(text)', $this->source);
        $this->assertStringNotContainsString('.html(', $this->source);
    }

    public function test_a_late_response_is_dropped_by_a_sequence_check(): void
    {
        // The check must come straight after each awaited read or write, before
        // anything from the response is used.
        $this->assertMatchesRegularExpression(
            '/await this\.get\(`\$\{this\.urls\.proposals\}\/\$\{uuid\}`\);\s*if \(seq !== this\.detailSeq\)/',
            $this->source,
            'The detail read must be followed by a sequence check.',
        );
        $this->assertMatchesRegularExpression(
            '/await this\.get\(`\$\{this\.urls\.proposals\}\?\$\{query\}`\);\s*if \(seq !== this\.listSeq\)/',
            $this->source,
            'The list read must be followed by a sequence check.',
        );
        $this->assertMatchesRegularExpression(
            '/await this\.request\(method, url, \{ \.\.\.command, request_id: requestId \}\);.*?const stillHere = seq === this\.detailSeq;/s',
            $this->source,
            'A command must learn whether the reader moved on before touching the screen.',
        );
    }

    public function test_the_page_lifecycle_takes_content_out_and_revalidates(): void
    {
        $this->assertStringContainsString("addEventListener('pagehide'", $this->source);
        $this->assertStringContainsString("addEventListener('pageshow'", $this->source);
        $this->assertStringContainsString('event.persisted', $this->source);
    }
}
