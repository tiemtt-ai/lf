<?php

namespace Tests\Unit;

use App\Services\Ai\AuthoringAllowedActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single definition of `allowed_actions` (contract "Owner amendment — P3-B"),
 * as a pure function of state already read. The HTTP behaviour on a real schema is
 * covered in AiAuthoringHttpMariaDbTest; this pins the whole table, including the
 * rows a real database cannot easily reach (a failed create_node receipt).
 */
class AuthoringAllowedActionsTest extends TestCase
{
    private function proposal(string $status = 'accepted', string $kind = 'node_mapping'): object
    {
        return (object) ['status' => $status, 'kind' => $kind];
    }

    private function receipt(string $operation, string $status): object
    {
        return (object) ['operation' => $operation, 'status' => $status];
    }

    /** @return array<string,array{array<int,object>,string,bool,array<int,string>}> */
    public static function reuseCases(): array
    {
        $r = fn (string $status) => (object) ['operation' => 'apply_intent', 'status' => $status];

        return [
            'nothing confirmed' => [[], 'teacher', false, ['preview_target', 'confirm_target']],
            'awaiting publication' => [[$r('awaiting_publication')], 'teacher', false, ['preview_target', 'confirm_target']],
            'ready to apply' => [[$r('ready_to_apply')], 'teacher', false, ['preview_target', 'confirm_target', 'apply_intent']],
            'failed' => [[$r('failed')], 'admin', false, ['preview_target', 'confirm_target']],
            'applied' => [[$r('applied')], 'teacher', false, ['preview_target', 'reject_target']],
            'cancelled' => [[$r('cancelled')], 'teacher', false, []],
            'context changed, nothing confirmed' => [[], 'teacher', true, ['reconfirm_context', 'preview_target', 'confirm_target']],
            'context changed, ready' => [[$r('ready_to_apply')], 'teacher', true, ['reconfirm_context', 'preview_target', 'confirm_target']],
            'an admin gets no approve_node for an existing Node' => [[], 'admin', false, ['preview_target', 'confirm_target']],
        ];
    }

    /** @param array<int,object> $receipts @param array<int,string> $expected */
    #[DataProvider('reuseCases')]
    public function test_an_existing_node_mapping(array $receipts, string $role, bool $contextUnconfirmed, array $expected): void
    {
        $this->assertSame($expected, AuthoringAllowedActions::forProposal($this->proposal(), true, $role, ['mode' => 'reuse_existing'], $receipts, $contextUnconfirmed));
    }

    public function test_a_new_node_is_approved_by_an_admin_and_only_then_has_a_target(): void
    {
        $new = ['mode' => 'propose_new'];
        $created = fn (string $status) => $this->receipt('create_node', $status);
        $apply = $this->receipt('apply_intent', 'ready_to_apply');

        $this->assertSame([], AuthoringAllowedActions::forProposal($this->proposal(), true, 'teacher', $new, [], false), 'the teacher waits');
        $this->assertSame(['approve_node'], AuthoringAllowedActions::forProposal($this->proposal(), true, 'admin', $new, [], false));
        // Once the Node is being made or failed, approving again is not offered (one creation per revision).
        $this->assertSame([], AuthoringAllowedActions::forProposal($this->proposal(), true, 'admin', $new, [$created('failed')], false));
        $this->assertSame(['preview_target', 'confirm_target'], AuthoringAllowedActions::forProposal($this->proposal(), true, 'teacher', $new, [$created('applied')], false));
        $this->assertSame(
            ['preview_target', 'confirm_target', 'apply_intent'],
            AuthoringAllowedActions::forProposal($this->proposal(), true, 'teacher', $new, [$created('applied'), $apply], false),
        );
    }

    public function test_nothing_is_offered_without_content_or_for_other_kinds_or_states(): void
    {
        $reuse = ['mode' => 'reuse_existing'];
        $this->assertSame([], AuthoringAllowedActions::forProposal($this->proposal(), false, 'admin', $reuse, [], false), 'hidden payload');
        $this->assertSame([], AuthoringAllowedActions::forProposal($this->proposal('accepted', 'summary'), true, 'admin', null, [], true));
        foreach (['rejected', 'stale', 'deletion_pending'] as $status) {
            $this->assertSame([], AuthoringAllowedActions::forProposal($this->proposal($status), true, 'admin', $reuse, [], false), $status);
        }
        $this->assertSame(['edit', 'accept', 'reject'], AuthoringAllowedActions::forProposal($this->proposal('pending_review', 'summary'), true, 'teacher', null, [], false));
        $this->assertSame([], AuthoringAllowedActions::pending(false, 'pending_review'));
        $this->assertSame([], AuthoringAllowedActions::pending(true, 'accepted'));
    }

    /** @return array<string,array{string,string,string,bool,array<int,string>}> */
    public static function receiptCases(): array
    {
        return [
            'apply, awaiting publication' => ['apply_intent', 'awaiting_publication', 'teacher', false, ['cancel']],
            'apply, ready' => ['apply_intent', 'ready_to_apply', 'teacher', false, ['cancel']],
            'apply, failed, teacher' => ['apply_intent', 'failed', 'teacher', false, ['retry', 'cancel']],
            'apply, failed, admin' => ['apply_intent', 'failed', 'admin', false, ['retry', 'cancel']],
            'create_node, failed, teacher' => ['create_node', 'failed', 'teacher', false, ['cancel']],
            'create_node, failed, admin' => ['create_node', 'failed', 'admin', false, ['retry', 'cancel']],
            'failed, context unconfirmed' => ['apply_intent', 'failed', 'teacher', true, ['cancel']],
            'applied' => ['apply_intent', 'applied', 'admin', false, []],
            'cancelled' => ['apply_intent', 'cancelled', 'admin', false, []],
            'create_node applied' => ['create_node', 'applied', 'admin', false, []],
        ];
    }

    /** @param array<int,string> $expected */
    #[DataProvider('receiptCases')]
    public function test_a_receipt(string $operation, string $status, string $role, bool $contextUnconfirmed, array $expected): void
    {
        $this->assertSame($expected, AuthoringAllowedActions::forReceipt($this->receipt($operation, $status), $role, $contextUnconfirmed));
    }

    public function test_a_successor_is_offered_only_for_a_stale_proposal_with_an_accepted_payload(): void
    {
        $this->assertSame(['create_successor'], AuthoringAllowedActions::stale('stale', true));
        $this->assertSame([], AuthoringAllowedActions::stale('stale', false));
        foreach (['pending_review', 'accepted', 'rejected', 'deletion_pending', 'deleted'] as $status) {
            $this->assertSame([], AuthoringAllowedActions::stale($status, true), $status);
        }
    }

    public function test_copying_a_draft_and_rebasing_are_admin_only_and_need_an_accepted_mapping_with_a_framework(): void
    {
        $reuse = ['mode' => 'reuse_existing', 'node_id' => 7, 'definition_id' => 8];
        $with = fn (string $status = 'accepted', string $kind = 'node_mapping', ?int $framework = 5): object => (object) ['status' => $status, 'kind' => $kind, 'framework_id' => $framework];

        $admin = AuthoringAllowedActions::forProposal($with(), true, 'admin', $reuse, [], false);
        $this->assertSame(['inherit_draft', 'rebase'], array_slice($admin, -2));
        $this->assertNotContains('inherit_draft', AuthoringAllowedActions::forProposal($with(), true, 'teacher', $reuse, [], false));
        $this->assertNotContains('rebase', AuthoringAllowedActions::forProposal($with(), true, 'teacher', $reuse, [], false));
        $this->assertNotContains('inherit_draft', AuthoringAllowedActions::forProposal($with(framework: null), true, 'admin', $reuse, [], false), 'no Framework named');
        $this->assertSame([], AuthoringAllowedActions::forProposal($with('accepted', 'summary'), true, 'admin', null, [], false));
        foreach (['pending_review', 'rejected', 'stale'] as $status) {
            $this->assertNotContains('inherit_draft', AuthoringAllowedActions::forProposal($with($status), true, 'admin', $reuse, [], false), $status);
        }
        $this->assertSame([], AuthoringAllowedActions::forProposal($with(), false, 'admin', $reuse, [], false), 'hidden content offers nothing');
    }
}
