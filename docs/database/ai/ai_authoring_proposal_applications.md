# Table: ai_authoring_proposal_applications

Version: 0.5

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-15

Document Path: database/ai/ai_authoring_proposal_applications.md

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

AI-owned receipt for owner-service handoff, not a canonical write queue with
independent authority. Node creation and intent application are distinct stages.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id, customer_id, proposal_id, revision_id | BIGINT UNSIGNED NOT NULL | Identity/tenant/exact accepted revision |
| application_uuid | CHAR(36) NOT NULL | Stable public command receipt |
| target_hash, command_hash | CHAR(64) NOT NULL | Identity-only operation target hash and immutable request fingerprint |
| expected_basis_hash | CHAR(64) NOT NULL | Original pre-operation content/reuse-basis hash; not the effective confirmation hash |
| result_basis_hash | CHAR(64) NULL | Post-Node-write content fingerprint, not an overwrite of proposal provenance |
| target_review_id | BIGINT UNSIGNED NULL | Exact confirm_target/reconfirm_target decision for Mapping application |
| operation | VARCHAR(32) NOT NULL | create_node or apply_intent |
| status | VARCHAR(32) NOT NULL | awaiting_publication/ready_to_apply/applied/failed/cancelled |
| framework_id, framework_version_id | BIGINT UNSIGNED NOT NULL | Explicit draft or published target according to operation |
| node_id, intent_id | BIGINT UNSIGNED NULL | Results returned by owner services, never client authority |
| approved_by | BIGINT UNSIGNED NULL | Actual approving human |
| error_code | VARCHAR(100) NULL | Sanitized failure code, no provider payload |
| created_at, updated_at | DATETIME(6) NOT NULL | UTC timestamps |
| applied_at | DATETIME(6) NULL | Successful owner write marker |
| cancelled_at | DATETIME(6) NULL | Terminal cancellation time |
| cancellation_kind | VARCHAR(16) NULL | human or system |
| cancelled_by | BIGINT UNSIGNED NULL | Actual actor for human cancellation only |
| cancel_reason_code | VARCHAR(64) NULL | Content-free reason, required when cancelled |

## Indexes and constraints candidate

UNIQUE(customer_id, application_uuid);
UNIQUE(customer_id, revision_id, operation);
INDEX(customer_id, status, id).
FK(target_review_id, customer_id, proposal_id, revision_id) ->
ai_authoring_proposal_reviews(id, customer_id, proposal_id, revision_id) RESTRICT.
Service verifies the referenced action and exact target; FK proves membership,
not human authority. Pointer may advance with a new confirmation only before applied. At applied it
freezes permanently; Course Intent owns the effective post-apply confirmation
pointers. Updating those uses Course.updateProposalConfirmations and never
changes this receipt or a historical Mapping.
Same-parent revision composite FK; tenant-aware Framework/Version and actor
references. Result Node FK includes Framework membership. intent_id is a
historical receipt, not a foreign key to mutable Course state.

The concrete CHECK predicates below define operation/state/result coherence.
NULL branches use explicit IS NULL/IS NOT NULL, never UNKNOWN as rejection.
Trigger enforcement follows the contract's immutable-identity/lifecycle rule.

## Transaction and publication rules

awaiting_admin is computed from accepted propose_new without a creation receipt;
it is not a database status. The creation receipt starts ready_to_apply only
after admin selects the exact draft and approves. No placeholder target IDs.

Receipt + Node/intent write commit atomically through the owner service. A Node
creation receipt can be applied while a separate apply_intent receipt waits for
Framework publication; do not label Node creation as canonical promotion.
Failed retries repeat the same target, not allocate a new identity. Cancelled
work cannot restart silently. A changed target conflicts with the existing
receipt even with a new request UUID. A corrected target requires a successor
proposal and fresh review, never a second creation from the same accepted revision.

Course's existing promotion retains the canonical Mapping unique key for each
newly published Version. This table does not claim that a single intent_id is a
canonical Mapping ID. Exact durable intent-to-reviewed-revision provenance in
Course is specified in its Step 7 amendment (Review / Not Implemented).

## Constraint detail — proposed supplement

CHECK ((status = 'cancelled' AND cancelled_at IS NOT NULL AND cancel_reason_code IS NOT NULL
 AND ((cancellation_kind = 'human' AND cancelled_by IS NOT NULL)
 OR (cancellation_kind = 'system' AND cancelled_by IS NULL)) AND cancellation_kind IS NOT NULL)
 OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancel_reason_code IS NULL
 AND cancellation_kind IS NULL AND cancelled_by IS NULL));
FK(cancelled_by, customer_id) -> users(id, customer_id) RESTRICT.
CHECK (operation IN ('create_node','apply_intent'));
CHECK (status IN ('awaiting_publication','ready_to_apply','applied','failed','cancelled'));
CHECK (operation <> 'create_node' OR status <> 'awaiting_publication');
CHECK (operation <> 'create_node' OR target_review_id IS NULL);
CHECK (operation <> 'apply_intent' OR status NOT IN ('ready_to_apply','applied') OR target_review_id IS NOT NULL);
CHECK ((operation = 'create_node' AND status = 'applied' AND result_basis_hash IS NOT NULL)
 OR ((operation <> 'create_node' OR status <> 'applied') AND result_basis_hash IS NULL));
CHECK ((status = 'failed' AND error_code IS NOT NULL)
 OR (status <> 'failed' AND error_code IS NULL));
CHECK ((status = 'applied' AND applied_at IS NOT NULL AND approved_by IS NOT NULL)
 OR (status <> 'applied' AND applied_at IS NULL));
CHECK (
 (status = 'applied' AND operation = 'create_node' AND node_id IS NOT NULL AND intent_id IS NULL)
 OR (status = 'applied' AND operation = 'apply_intent' AND node_id IS NOT NULL AND intent_id IS NOT NULL)
 OR (status <> 'applied' AND intent_id IS NULL)
);

A non-applied apply_intent may already know its Node. A non-applied create_node
must not claim a Node result: CHECK (operation <> 'create_node'
OR status = 'applied' OR node_id IS NULL).
FK (framework_version_id, customer_id, framework_id) ->
core_learning_framework_versions(id, customer_id, framework_id) RESTRICT;
FK (node_id, customer_id, framework_id, framework_version_id) ->
core_learning_nodes(id, customer_id, framework_id, framework_version_id) RESTRICT;
FK (approved_by, customer_id) -> users(id, customer_id) RESTRICT.
intent_id is the historical receipt exception defined in the contract, not an
inbound FK that prevents deletion of mutable Course intents.

UNIQUE(customer_id, revision_id, operation) permits exactly one create_node and
one apply_intent receipt per accepted revision, across all statuses and targets.
target_hash remains immutable conflict evidence, not an identity dimension that
allows retry to create another Node. No generated column is needed. Under parent
and receipt locks, exact replay returns the existing result; changed input fails.
Owner write and receipt commit together. Different proposals proposing the same
meaning still require reuse ranking/human review; this key is not semantic dedupe.


## Sample

create_node receipt applied -> Node in draft Version. apply_intent receipt waits
for that exact Version to publish, then Course stores intent. Only a subsequent
Course publication produces canonical Mapping. Older Course Versions unchanged.

## Revised command semantics

create_node target_hash hashes tenant/revision/operation/exact draft Version,
before Node exists. apply_intent includes Node ID. expected_basis_hash captures
original content, result_basis_hash post-create content. Effective target content
comes from the pointed confirmation review, not these immutable hashes.

Apply/resume/retry records request UUID/hash and actor in an append-only review.
Awaiting work advances only on an explicit authorized human command after
publication/selection checks. A worker cannot supply human approval. approved_by
retains original actor; retry actor is in review history. Human cancellation
writes its review and receipt together; system cleanup writes explicit system
cancellation metadata, never a fake human review. Applied receipts never cancel.

Explicit Course rebase replaces mutable Intents and stores a rebase_target
review on each replacement. Original receipt target/intent_id remains immutable
history; it does not claim to point at the replacement Intent. Different target
outside that command requires a human successor, not another receipt.

## Stale cancellation — 2026-09-15

A stale proposal's unapplied awaiting_publication/ready_to_apply/failed receipt
may be cancelled by a currently authorized human (stale -> stale audit), or by
system cleanup with source_revision_changed reason. System codes also include
source_deleted/source_detached/owner_missing. Applied and cancelled remain
terminal; cancelling a stale receipt neither reads its payload nor undoes a Node.
