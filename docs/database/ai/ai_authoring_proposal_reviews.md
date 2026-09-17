# Table: ai_authoring_proposal_reviews

Version: 0.4

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-15

Document Path: database/ai/ai_authoring_proposal_reviews.md

## Schema implementation — 2026-09-15

The v0.8 migration packet is implemented and reconstructed on temporary
MariaDB 11.4.12 and 10.4.21 databases. This status describes schema only,
not the authoring services, live database apply, provider activation or Step 7
completion. Owner's scoped pre-migration review waiver is recorded in the
Authoring Proposal Contract; no independent PASS is claimed. Earlier
Not Implemented/no-DDL statements below are historical and superseded for
schema status. Physical metadata is harvested into LF-SCHEMA-CONTRACT.json.

## Authority

### Owner design closure — 2026-09-15

Owner decision: "chốt tài liệu, ko cần reivew quá nhiều". The current Step 7
contract v0.8, six AI table designs and their Course/Learning/ADR design
extensions are approved and Frozen. No additional design-review round is
scheduled by this closure. Earlier Review/pending-Freeze statements below are
historical and superseded for design status only. Implementation remains Not
Implemented. This records Owner approval, NOT reviewer PASS, physical DDL
verification, migration execution or live database/provider authorization.


Owner froze the earlier shape on 2026-09-14. That decision is historical and
superseded for this revised shape after findings P1-1..P1-6. Owner approved the
six remediation directions, not a new Freeze or review PASS.
The [Step 7 contract](../../platform/LF-AI-Authoring-Proposal-Contract.md) v0.8
defines the revision. Current status: Review / Not Implemented; no DDL verified.

## Purpose and relationships

Append-only record of edits, accept/reject and administrator Node approval,
always against an exact revision in the same Proposal and tenant.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id, customer_id, proposal_id, revision_id | BIGINT UNSIGNED NOT NULL | Identity and exact reviewed revision |
| actor_id | BIGINT UNSIGNED NOT NULL | Actual human, not worker impersonation |
| request_uuid | CHAR(36) NOT NULL | Idempotency key per item command |
| command_hash | CHAR(64) NOT NULL | Actor/operation/target/payload fingerprint |
| action | VARCHAR(32) NOT NULL | edit/accept/reject/approve_node/confirm_target/reconfirm_target/reconfirm_context/rebase_target/cancel_application/apply_intent/retry_application/inherit_draft/rebase_selection/reject_target |
| target_snapshot | JSON NULL | Mapping target and dependency snapshot; content subject to erasure |
| target_hash | CHAR(64) NULL | Immutable hash of target snapshot; retained after erasure |
| context_snapshot | JSON NULL | Reconfirmed Course-only DTO; erased with other content |
| context_hash | CHAR(64) NULL | Hash of reconfirmed Course DTO |
| application_id | BIGINT UNSIGNED NULL | Historical application identity for command audit; service validates same parent/revision |
| result_version_id | BIGINT UNSIGNED NULL | Exact result Version for inherit_draft/rebase_selection, retained after snapshot erasure |
| result_framework_id | BIGINT UNSIGNED NULL | Framework membership of result Version |
| reason_code | VARCHAR(64) NULL | Required bounded code for cancellation; no free text |
| from_status, to_status | VARCHAR(32) NOT NULL | Parent state transition snapshot |
| reason | TEXT NULL | Optional human explanation; content retention applies |
| created_at | DATETIME(6) NOT NULL | UTC decision |
| erased_at | DATETIME(6) NULL | Reason erasure marker |

## Indexes and constraints candidate

UNIQUE(customer_id, request_uuid); INDEX(customer_id, proposal_id, id).
UNIQUE(id, customer_id, proposal_id, revision_id) supports exact application confirmation.
FK(revision_id, customer_id, proposal_id) ->
ai_authoring_proposal_revisions(id, customer_id, proposal_id) RESTRICT.
Actor FK tenant-aware. Listed action/state vocabulary CHECK; erased_at requires
reason NULL. Transition validity, active assignment and Node approval role are
service-level checks under parent lock; no claim that a CHECK verifies roles.

Review identity/action/actor/hash are immutable. The contract's BEFORE UPDATE/
DELETE triggers allow only reason/target_snapshot/context_snapshot-to-NULL and erased_at under a deletion_pending
parent, with every other column unchanged. No trigger is dropped for cleanup.

## Sample
## Constraint detail — proposed supplement

CHECK (action IN ('edit','accept','reject','approve_node','confirm_target','reconfirm_target','reject_target','rebase_target','inherit_draft','rebase_selection','reconfirm_context','cancel_application','apply_intent','retry_application'));
CHECK (erased_at IS NULL OR (reason IS NULL AND target_snapshot IS NULL AND context_snapshot IS NULL));
CHECK ((action IN ('confirm_target','reconfirm_target','reject_target','rebase_target','inherit_draft','rebase_selection') AND target_hash IS NOT NULL
 AND ((erased_at IS NULL AND target_snapshot IS NOT NULL) OR (erased_at IS NOT NULL AND target_snapshot IS NULL)))
 OR (action NOT IN ('confirm_target','reconfirm_target','reject_target','rebase_target','inherit_draft','rebase_selection') AND target_hash IS NULL AND target_snapshot IS NULL));
CHECK (
 (action = 'edit' AND from_status = 'pending_review' AND to_status = 'pending_review')
 OR (action = 'accept' AND from_status = 'pending_review' AND to_status = 'accepted')
 OR (action = 'reject' AND from_status = 'pending_review' AND to_status = 'rejected')
 OR (action = 'approve_node' AND from_status = 'accepted' AND to_status = 'accepted')
 OR (action = 'cancel_application' AND from_status = 'stale' AND to_status = 'stale')
 OR (action IN ('confirm_target','reconfirm_target','reject_target','rebase_target','inherit_draft','rebase_selection','reconfirm_context','cancel_application','apply_intent','retry_application') AND from_status = 'accepted' AND to_status = 'accepted')
);

CHECK ((action IN ('reconfirm_context','rebase_target') AND context_hash IS NOT NULL
 AND ((erased_at IS NULL AND context_snapshot IS NOT NULL) OR (erased_at IS NOT NULL AND context_snapshot IS NULL)))
 OR (action NOT IN ('reconfirm_context','rebase_target') AND context_hash IS NULL AND context_snapshot IS NULL));
CHECK ((action IN ('cancel_application','apply_intent','retry_application') AND application_id IS NOT NULL)
 OR (action NOT IN ('cancel_application','apply_intent','retry_application') AND application_id IS NULL));
CHECK ((action = 'cancel_application' AND reason_code IS NOT NULL)
 OR (action <> 'cancel_application' AND reason_code IS NULL));

CHECK ((action IN ('inherit_draft','rebase_selection') AND result_version_id IS NOT NULL AND result_framework_id IS NOT NULL)
 OR (action NOT IN ('inherit_draft','rebase_selection') AND result_version_id IS NULL AND result_framework_id IS NULL));
FK(result_version_id, customer_id, result_framework_id) ->
core_learning_framework_versions(id, customer_id, framework_id) RESTRICT.
Service verifies Framework membership, admin authorization and result from the
owner command. No claim that actor or ownership semantics are proven by this FK.

All three state/action fields are NOT NULL. Admin direct approval writes accept
then approve_node in one transaction with distinct deterministic item command
UUIDs; collision with different inputs is a conflict. Review permission and
revision freshness still require locked owner/service checks.

Target confirmation does not revise accepted payload or recreate a Node. The
target snapshot contains exact IDs and relevant dependency content; schema is
versioned. Reconfirmation is same-target only, authorized under proposal locks.
Different target identity requires a human successor except rebase_target,
which confirms an inherited same-Definition Version transition under the Course
rebase command. It contains old/new IDs and target hashes, never changes an old
receipt. Reconfirm_context records a Course-only snapshot, not a source re-seal. Hash remains when the snapshot
is erased; no target text is copied into non-erasable command metadata.

FK (actor_id, customer_id) -> users(id, customer_id) RESTRICT.
No unique(proposal_id, action): multiple edits are legitimate. Duplicate accept
is prevented by locked state transition and request idempotency, not a broad
constraint that would discard decision history.


Teacher accepts revision 2; admin approves Node from that same revision later.
Admin direct acceptance/approval writes both distinct decisions atomically.
Never mutate teacher audit to pretend the teacher had administrator authority.

## Confirmation and stale cancellation — 2026-09-15

cancel_application is allowed accepted -> accepted or stale -> stale. Human
cancellation retains actor/reason/request UUID and cancels only unapplied work.
System source_revision_changed cancellation uses receipt system metadata, not
a fabricated reviewer. Applied receipt confirmation pointer is immutable;
post-apply confirmation/rejection is recorded here but attached to mutable
Intent only through Course.updateProposalConfirmations.

Rebase_selection snapshot records the COMPLETE plan with map/remove_explicit/
cancel_rebase dispositions and reasons; a cancelled command makes no owner writes.
For each retained AI Intent rebase_target confirms both old/new Course context
and target hashes displayed in preview. No implicit context acceptance.
