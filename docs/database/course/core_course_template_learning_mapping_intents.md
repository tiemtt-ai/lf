# Table: core_course_template_learning_mapping_intents

Version: 1.2

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-15

Approval Date: 2026-08-23

Approved By: Architecture Owner

Document Path: database/course/core_course_template_learning_mapping_intents.md

Related ADR: [ADR-0017 — AI-Assisted Learning Authoring](../../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)

## Purpose

Stores a Course Template draft author's intent to map a working Lesson or
Activity to a Node in one explicitly selected, published Learning Framework
Version. An Intent is draft-only Course state; it is not a canonical Learning
Mapping and must never create Evidence or Mastery.

## Fields

| Field | Contract |
| --- | --- |
| `id` | BIGINT UNSIGNED primary key. |
| `customer_id` | Tenant owner; required. |
| `template_id` | Working Course Template owner; its selected Framework Version is stored on `core_course_templates`. |
| `source_type` | `course_template_lesson` or `course_template_activity`. |
| `source_id` | Working Lesson/Activity identity matching `source_type`. |
| `framework_id`, `framework_version_id` | Exact published Learning Framework/Version selected by the Template. |
| `learning_node_id` | Versioned Node belonging to that Framework Version; must be active. |
| `mapping_role` | `teaches`, `practices`, or `assesses`. |
| `weight` | Nullable decimal in `[0,1]`; pedagogical contribution, never confidence. |
| `origin` | Phase 1 is `manual` only; `ai_proposal` is reserved until Proposal persistence/review exists. |
| `created_by`, `created_at`, `updated_by`, `updated_at` | Tenant-scoped audit fields. |

## Constraints

* `core_course_templates.selected_learning_framework_id` and
  `selected_learning_framework_version_id` are nullable draft fields with a
  tenant-safe composite FK; every Intent must match that exact selection.
* Unique `(customer_id, template_id, source_type, source_id, learning_node_id, mapping_role)`.
* Store `framework_id` and use composite FK `(learning_node_id, customer_id,
  framework_id, framework_version_id)` to the Learning Node tenant/version key.
* CHECK restricts Phase 1 `origin` to `manual`, source vocabulary, role and
  weight range. Indexes: `(customer_id, template_id)` and
  `(customer_id, learning_node_id, mapping_role)`.
* Every owner and actor FK is tenant-scoped.
* A Course Template selects exactly one Framework Version explicitly; no
  `latest` or publish-time resolution is stored or permitted.
* Source existence/type/Template containment and published Learning Version/Node
  checks are enforced by the Course-to-Learning owner service in the transaction.

## Publish Promotion

Course publishing snapshots working Lessons/Activities. In the same transaction,
the Course adapter reads all current Intents, replaces each working source ID by
the matching newly-created Version Lesson/Activity ID, and asks Learning to
create `core_learning_node_mappings`. The canonical Mapping uses the published
Course Version ID encoded as decimal text for `source_discriminator`; it supplies
the required immutable `source_snapshot` (label, type and Version identity) and
the trusted Course-adapter signature. Learning revalidates the whitelist,
signature, source snapshot, tenant, published Framework Version and active Node
in the same transaction. A missing source snapshot, deleted/unmapped working
source, retired Node, deprecated/archived Framework Version or any mismatch
fails publish and rolls back the entire Course Version snapshot.

Product bindings remain locked to their exact published Course Version. A later
Template publish creates new canonical Mappings for the new Version only; it
never modifies, deletes or silently rebinds prior Product/Course mappings.

## Lifecycle

Mapping Intent follows the permissions and editing lifecycle currently applied
to Course Template authoring. Phase 1 adds no separate status gate for Intent.
When Course Template authoring gains a unified lifecycle gate, Intent must
follow that gate as well.

This is safe only while an Intent has no effect before publish: canonical
Mapping is created solely by promotion. If a future component reads Intent
before publish, this decision must be reviewed again. The one Intent-specific
rule is coherence, not lifecycle: a selected Framework Version cannot change
while the Template has Intents, and each Intent is composite-FK-bound to that
exact selection. Canonical Mapping is immutable after promotion and is
corrected by the approved invalidation lifecycle in
`core_learning_node_mappings`.

## Step 7 integration amendment — Frozen / Not Implemented, 2026-09-15

Schema update 2026-09-15: origin CHECK, four nullable AI references and their
composite FKs are implemented by the Step 7 packet, verified on temporary
MariaDB 11.4.12/10.4.21. The manual path remains unchanged. Owner-service
commands, publish revalidation and provenance promotion described below are
still Not Implemented. No live database apply is claimed.

### Owner design closure — 2026-09-15

Owner decision: "chốt tài liệu, ko cần reivew quá nhiều". The current Step 7
contract v0.8, six AI table designs and their Course/Learning/ADR design
extensions are approved and Frozen. No additional design-review round is
scheduled by this closure. Earlier Review/pending-Freeze statements below are
historical and superseded for design status only. Implementation remains Not
Implemented. This records Owner approval, NOT reviewer PASS, physical DDL
verification, migration execution or live database/provider authorization.



Owner froze the earlier amendment. That signature is historical and superseded
for this changed shape by the approved P1 remediation directions. Header status
Implemented describes the existing manual path only; this amendment is Review /
Not Implemented, awaiting new review/Freeze. Extension:
origin vocabulary manual/ai_proposal, with nullable BIGINT UNSIGNED
ai_proposal_id and ai_proposal_revision_id. CHECK requires both NULL for manual
and both NOT NULL for ai_proposal. Composite FK
(ai_proposal_revision_id, customer_id, ai_proposal_id) references
ai_authoring_proposal_revisions(id, customer_id, proposal_id), RESTRICT.
Proposal acceptance and current authorization are application checks, not FK
semantics. Parent IDs supplied by a request are never proof of acceptance.

Add nullable BIGINT UNSIGNED ai_target_review_id and ai_context_review_id.
Both are NULL for manual. ai_target_review_id is required for ai_proposal;
ai_context_review_id may be NULL to use proposal.course_context_hash.
Each references (id, customer_id, proposal_id, revision_id) on
ai_authoring_proposal_reviews via (review_id, customer_id, ai_proposal_id,
ai_proposal_revision_id), RESTRICT. AI's port checks action, exact target and
context; FK only proves membership. Target reviews may be confirm_target,
reconfirm_target, rebase_target or reject_target; reject_target explicitly blocks
future publish until resolved, without changing an old Mapping. Context reviews
are reconfirm_context/rebase_target.

Only the Course owner service may create an AI-origin intent after verifying
the exact accepted revision through the AI contract. Assigned teachers may use
this narrowly scoped reviewed-proposal path; manual authoring and publication
rights are not automatically widened. Existing Node/Framework published-only
checks remain. Ordinary select keeps its selection lock; the explicit atomic
rebase command below is the sole exception. No draft Node is inserted here.

Publish revalidates AI-origin intents within the existing snapshot/promotion
transaction. Stale/rejected/deleted proposals or changed edited payload fail
publication; do not drop the intent silently. Accepted Course-context drift can
be resolved by reconfirm_context, not a provider call. Source revision drift
requires a human/generated successor with newly authorized sources. A prompt
code update alone does not invalidate accepted human decisions.
Promotion uses only the new
published Course Version identities. Existing published Versions are not
backfilled. Matching a manual intent's unique key must not relabel it as AI:
return an explicit existing-intent conflict for human resolution.

AI application.intent_id remains a historical receipt so deleting a mutable
Intent is not blocked by AI audit. Retry of an applied receipt cannot recreate
an intentionally removed intent; a new explicit authorized command is required.
The successor command is available without AI; new human approval is required.
This revised design is not implementation or a migration-review waiver.

#### Explicit inherited-Version rebase

Admin previews/approves a complete old/new Intent plan, matched by stable Node
Definition within the same Framework. Target Version must be published; changed
semantics require human confirmation and no old Intent may silently disappear.
Course locks Template, AI locks affected proposals/receipts in stable order and
Learning locks exact Versions then Framework. Under the same transaction Course
removes old mutable Intents, changes selection and recreates explicitly mapped
Intents with new IDs. This order satisfies the immediate selection FK. Any
failure restores old IDs/selection. No FK is disabled and no snapshot is backfilled.

AI-origin replacements retain accepted revision and store the newly appended
rebase_target decision in ai_target_review_id/ai_context_review_id. Original
applied receipt intent_id remains history; it is not rewritten. Different
Definition or payload requires human successor instead. For future Course
publish, AI.assertPublishableIntents validates the replacement's exact IDs and
returns lineage; Course does not read ai_* directly.

Promotion includes content-free AI provenance in immutable source_snapshot:
proposal ID, revision ID, payload hash, target/context confirmation IDs and
effective hashes. Learning validates and writes the snapshot; deleting mutable
Intent later cannot remove Mapping -> Proposal lineage. No source excerpt or
accepted payload is copied into this retained metadata.

## Existing implemented authorization

`customer_admin` is the Phase 1 Course author and may confirm `manual` Intent
directly. AI Proposal/review authorization is deferred with Proposal persistence.
Manual Intent does not become stale when its working source changes; AI Intent
staleness is deferred with Proposal fingerprint persistence. The Template surface
must list orphaned Intents and let the author remove them before publish, because
an orphan intentionally fails the publish transaction closed.

## P2 confirmation/rebase refinement — Frozen / Not Implemented, 2026-09-15

Course.updateProposalConfirmations receives current actor, Intent ID, expected
pointer IDs, proposed AI review IDs and request UUID. It validates source/tenant/
Template/revision through AI's port and updates only the relevant pointer under
Template -> proposal -> application/Intent locks. AI decision append and Course
update commit together. Missing Intent conflicts; it is never silently recreated.
Applied receipt pointers are frozen history; ONLY Intent pointers govern future
publish. The request UUID is persisted on the appended AI review, and replay
returns that outcome after rechecking authorization, without a second update.

Rebase preview includes old/new Course context and target content/hashes.
Every old Intent requires map, remove_explicit with reason, or cancel_rebase
(aborts the entire command). No silent loss. AI map to a different Definition
requires human successor; manual map requires explicit eligible target selection.
Only mapped rows are recreated. Removed rows remain represented in the full
rebase_selection review plan. No old published state changes.
