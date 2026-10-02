<?php

namespace App\Services\Ai;

/**
 * The one definition of `allowed_actions` (contract "Owner amendment — P3-B").
 *
 * These are hints for the review UI, computed on every read for the current
 * actor from state that was already read: never stored, never a capability, and
 * never from an extra Learning read. Every command still rechecks authority,
 * locks and state, so a hint that turns out stale is answered with 403/404/409.
 * The values mirror the conditions the commands themselves apply
 * (AiAuthoringApplicationService), so an action is not offered in a state where
 * its command would refuse it for that state alone.
 */
final class AuthoringAllowedActions
{
    /** What a pending proposal offers. The proposal list offers only this. */
    public const PENDING = ['edit', 'accept', 'reject'];

    /** @return array<int,string> */
    public static function pending(bool $visible, string $status): array
    {
        return $visible && $status === 'pending_review' ? self::PENDING : [];
    }

    /**
     * A stale proposal that was once accepted can be continued by a successor. Only the cheap facts are
     * used here; the six inheritance conditions are checked by the preview, which refuses without
     * disclosing anything when they fail (contract "Owner amendment — P3-C").
     *
     * @return array<int,string>
     */
    public static function stale(string $status, bool $hasAcceptedPayload): array
    {
        return $status === 'stale' && $hasAcceptedPayload ? ['create_successor'] : [];
    }

    /**
     * @param  array<string,mixed>|null  $mapping  payload.mapping of the accepted revision, when readable
     * @param  array<int,object>  $receipts  receipts of the accepted revision (operation, status)
     * @param  bool  $contextUnconfirmed  the Course context changed and no reconfirmation covers it
     * @return array<int,string>
     */
    public static function forProposal(object $proposal, bool $visible, string $actorRole, ?array $mapping, array $receipts, bool $contextUnconfirmed): array
    {
        if (! $visible) {
            return [];
        }
        if ($proposal->status === 'pending_review') {
            return self::PENDING;
        }
        if ($proposal->status !== 'accepted' || $proposal->kind !== 'node_mapping' || $mapping === null) {
            return [];
        }

        $byOperation = [];
        foreach ($receipts as $receipt) {
            $byOperation[$receipt->operation] = $receipt;
        }
        $create = $byOperation['create_node'] ?? null;
        $apply = $byOperation['apply_intent'] ?? null;
        $reuse = ($mapping['mode'] ?? null) === 'reuse_existing';
        // The target exists for an existing Node, or once an admin's Node has been created.
        $hasTarget = $reuse || ($create !== null && $create->status === 'applied');

        $actions = [];
        if ($contextUnconfirmed) {
            $actions[] = 'reconfirm_context';
        }
        if (! $reuse && $create === null && $actorRole === 'admin') {
            $actions[] = 'approve_node';
        }
        if ($hasTarget && ($apply === null || $apply->status !== 'cancelled')) {
            $actions[] = 'preview_target';
            // First confirmation, or pointing an unapplied receipt at what was seen now. After
            // apply, the Course Intent owns the confirmation and the target can only be rejected.
            if ($apply === null || in_array($apply->status, ['awaiting_publication', 'ready_to_apply', 'failed'], true)) {
                $actions[] = 'confirm_target';
            }
            if ($apply !== null && $apply->status === 'applied') {
                $actions[] = 'reject_target';
            }
            if (! $contextUnconfirmed && $apply !== null && $apply->status === 'ready_to_apply') {
                $actions[] = 'apply_intent';
            }
        }
        // Framework-wide and Template-wide admin commands, started from an accepted mapping that names a Framework
        // (contract "Owner amendment — P3-C"); each is previewed before it can be sent.
        if ($actorRole === 'admin' && ($proposal->framework_id ?? null) !== null) {
            $actions[] = 'inherit_draft';
            $actions[] = 'rebase';
        }

        return $actions;
    }

    /**
     * What one receipt of the accepted revision can be asked to do.
     *
     * @return array<int,string>
     */
    public static function forReceipt(object $receipt, string $actorRole, bool $contextUnconfirmed): array
    {
        $actions = [];
        // create_node is retried by an admin only; a changed context would refuse any retry.
        if ($receipt->status === 'failed' && ! $contextUnconfirmed && ($receipt->operation === 'apply_intent' || $actorRole === 'admin')) {
            $actions[] = 'retry';
        }
        if (in_array($receipt->status, ['awaiting_publication', 'ready_to_apply', 'failed'], true)) {
            $actions[] = 'cancel';
        }

        return $actions;
    }
}
