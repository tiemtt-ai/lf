# AI Authoring Proposal Contract

Version: 0.9

Document Status: Frozen

Implementation Status: Partial

Last Updated: 2026-10-01

Document Path: platform/LF-AI-Authoring-Proposal-Contract.md

## Authority and scope

Current implementation: schema/migration and local physical checks are complete.
Backend services for generation, read, edit/accept/reject, admin Node approval,
target/context confirmation and rejection, Intent application, publish-time
revalidation with canonical lineage, human successors with restricted
inheritance, inherited drafts and explicit rebase, erasure and request recovery
are implemented and verified on a temporary MariaDB 11.4.12 schema with a fake
provider (see the [implementation evidence](../quality/LF-AI-Authoring-Proposal-Implementation-Review.md)).
HTTP v1 routes/controllers, strict JSON validation, bulk review, keyset listing
and source-scope pagination are implemented with local MariaDB/fake-provider
checks (see the HTTP scope in that implementation evidence).
The 2026-09-16 implementation review findings have been remediated: generation
persistence failures log content-free diagnostics only, with regression coverage
for parent-path checks, foreign-Activity bulk items and outermost-commit erasure.
Owner accepted backend + HTTP v1 on 2026-09-17 under a waiver of independent
review ("miễn trừ review độc lập, chốt nghiệm thu Bước 7"); the waiver is not an
independent PASS and is recorded in the same implementation report.
The Activity-page review UI (P3-A to P3-C) is now implemented and locally tested; see
[LF-AI-Authoring-Review-UI-Design](LF-AI-Authoring-Review-UI-Design.md) and the "Owner instruction — UI status"
block below. It has no independent review PASS for P3-B and P3-C. Partial here describes the whole contract,
not a new design amendment, independent PASS, provider activation or live apply.

### Owner amendment — P3-B, 2026-09-30

Owner approved (D12, D13, D17 of [LF-AI-Authoring-P3B-Amendment](LF-AI-Authoring-P3B-Amendment.md)) two additive
changes to the read contract, needed by the review UI after acceptance. They add response values only; no schema,
no new endpoint, no new write, no change to authority of any command.

- `allowed_actions` remains advisory, recomputed on every read for the current actor, never stored, never a capability
  token. The closed vocabulary is extended. Proposal detail: `edit`, `accept`, `reject` (pending_review, unchanged);
  `preview_target`, `confirm_target`, `reject_target`, `apply_intent`, `reconfirm_context`, `approve_node` (accepted
  states, admin only for `approve_node`), computed from already-read state and never from an extra Learning read.
  Each element of `applications` gains its own `allowed_actions`: `retry` (failed; `create_node` admin only) and
  `cancel` (awaiting_publication, ready_to_apply or failed; never applied). The four existing application fields are
  unchanged and no receipt snapshot is serialized. The proposal list keeps returning only the pending_review values.
  Unknown values must be ignored by clients. A POST still rechecks everything.
- Proposal detail of a visible `node_mapping` with `mode = reuse_existing` gains `mapping_node`, a sibling of `payload`
  (never inside it): `node_id`, `definition_id`, `code`, `label`, `node_type`, `description` (at most 500 characters)
  and `status`, or `null` when the exact stored Framework/Version/Node pair is not a published active Node whose
  definition matches. No `criteria`. It is read through a narrow Learning owner read keyed by the proposal's own stored
  Framework/Version pair; it opens display labels of the tenant's own selected Framework to actors with current AI
  authority (admin, or teacher with an active primary/assistant/reviewer assignment) and grants no other Learning
  access. It is disclosed only where `payload` is disclosed, under the same Media audit-before-disclosure rule.
- Clarification (B6): in the proposal detail of an `accepted` proposal, `context_changed` is `true` exactly while the
  Course context differs from the one last covered by a human `reconfirm_context` of the accepted revision (or, with
  none, from the context at generation), the same rule that offers `reconfirm_context` in `allowed_actions`. Before, it
  ignored reconfirmations and stayed `true` for good. Other statuses are unchanged. No schema, endpoint or write changes.
- Not part of this amendment: a Node-candidate endpoint (deferred), successors, inherited drafts and rebase (P3-C).
  The sentence above about no new HTTP endpoint stays in force.

### Owner instruction — UI status, 2026-10-01

Owner instructed the remaining documentation to be completed (option "B") once the P3-A, P3-B and P3-C slices were
built. The statements that the review UI is not implemented are replaced by this one: the Activity-page review UI
for teachers and admins exists for P3-A (list, read, edit, accept, reject, bulk, generation request), P3-B (existing
Node, target, apply, receipts retry and cancel, new Node approval, context reconfirmation) and P3-C (successor for a
changed source, inherited draft, rebase). Evidence is local: Node behavior tests, PHP tests, MariaDB 11.4 on a
disposable instance and a real browser on a disposable database. P3-A has an independent review closed by Owner on
2026-09-30; **P3-B and P3-C have no independent review: Owner chose not to send them (2026-10-01), which is a waiver, not a
PASS.** Not delivered: choosing another Node (P3-B B5), the other four successor reasons, and the manual checks listed
in the design document, section 13.17. No provider is activated.

### Owner amendment — P3-C, 2026-10-01

Owner approved (D18–D27 of [LF-AI-Authoring-P3C-Amendment](LF-AI-Authoring-P3C-Amendment.md)) additive read-contract changes needed by the
review UI for successors, inherited drafts and rebase. Response values only; no schema, no new endpoint, no new write, no change to the
authority of any command.

- `allowed_actions` on a proposal detail gains `create_successor` (status `stale` with an earlier accepted revision; admin or assigned
  teacher), `inherit_draft` and `rebase` (`accepted` `node_mapping` with a Framework; admin only). Advisory like every value; a POST rechecks.
- Proposal detail gains `inherited_decision_draft` (boolean) and `successor_reason` (string or null), the stored columns, so the next reviewer
  sees the flag and the reason required by "Restricted decision inheritance". Proposals generated by a model return `false` and `null`.
- `inherited-draft-preview` and `rebase-preview` (admin only) gain a top-level `display` object outside the hashed plan: Node labels
  (`code`, `label`, `node_type`; description at most 500 characters; no criteria), source titles of this Template's Intents, and the Intents
  of this Template affected by excluded Nodes (other Templates are not listed). `plan_hash` and `preview_hash` are unchanged.
- The UI supports successor reason `source_revision_changed` only; the other four reasons and choosing another Node in a rebase wait for the
  Node-candidate list (P3-B D14).

### Owner final service decisions — 2026-09-16

Owner approved the reviewed follow-up package: audit through Media, preserve and
implement retry_application, explicit successor source scope, and the remaining
conditions below. This supersedes the pending-assumption disposition in the
earlier service-decision block; it is not independent verification.

- Proposal content reads and restricted successor previews are audited through
  MediaDerivedRetrievalAudit into media_access_logs, read_derived / ai, using
  authoring-specific operation metadata. No schema change. Record tenant, actor,
  proposal/revision, source identity, correlation UUID and allowed/denied decision;
  never payload/rationale/excerpt/URL. Failure to append aborts disclosure. A
  missing in-tenant Media reference denies access without inventing a foreign key.
- Source excerpts are not stored. AI payload is still derived content and remains
  subject to source-access checks and erasure.
- Missing provider configuration refuses new generation before reading Media or
  creating a request. Existing replay still requires current Course authority.
- Node reuse is exact identity in the tenant/Framework, active and type-compatible.
  Never overwrite existing names or criteria. Content differences require an
  explicit reviewed reuse choice, not automatic substitution.
- approved_by retains the original approval actor. Retry records its own actor
  in an append-only retry_application review. Preserve failed -> ready_to_apply
  and the exact receipt/target; recheck current authority, sources, context and
  destination, then perform owner write and receipt update atomically. No provider
  call, second receipt or restart of applied/cancelled work.
- Deleted Media/missing Activity triggers content erasure. Detach immediately
  denies content but does not itself erase it. Reattach never restores erased
  content or bypasses revision and authority checks.
- A successor command identifies an explicit set of current source anchors;
  reject an empty, stale, unknown, duplicate or over-limit selection instead of
  silently truncating it. Preview discloses the offered scope and selection limit.
  Every predecessor source must still pass the six inheritance conditions, even
  when it is outside the new selected subset. Store the selected anchors in the
  existing sealed source set and include selection in the idempotency hash.
- invalid_proposal details are content-free validation or prerequisite codes.
  awaiting_admin/awaiting_publication describe waiting prerequisites, not malformed
  user text. Never return exception internals or source content in errors.

No provider activation, live database apply or overall Step 7 closure is implied.

### Owner service decisions — 2026-09-16

Owner approved the following four directions after the service implementation
report. This dated addendum supplements the Frozen v0.8 design; it does not
retroactively expand earlier approvals or certify implementation of the audit.

1. **Proposal-content read audit is required.** Record the actor, tenant,
   proposal/revision identifiers and allowed/denied outcome without payload.
   Select the audit store only after checking domain ownership and align its
   contract before implementation. This approves the requirement, not a new
   table, a `media_access_logs` event type or an exemption from Media Read audit.
   Audit storage/amendment and code/tests remain outstanding.
2. **Application error vocabulary:** `proposal_intent_conflict` denotes a
   collision with an existing manual Intent; do not overwrite it silently.
   `proposal_successor_required` denotes an attempted AI Intent rebase to a
   different Definition; require the approved successor flow rather than
   silently changing the reviewed decision. These are application errors,
   not new Model Run/provider error codes.
3. **Framework basis:** generation and human successors must use the Template's
   current Framework selection, for admins as well as teachers. A different
   basis requires changing the selection through the authorized owner flow;
   admin status is not a bypass. Preserve `framework_selection_conflict`.
4. **Model input v1:** offer document `extracted_text`, audio/video `transcript`
   and video `video_frame_text` only. Region/table/formula input is deferred;
   permitted source anchors do not imply these inputs are implemented or that
   the model receives all document content.

Only these four directions are approved by this decision. Other implementer
assumptions were pending at this decision's date; their subsequent approval and
implementation are recorded in the final service decision above and the
[consolidated evidence](../quality/LF-AI-Authoring-Proposal-Implementation-Review.md).
No provider activation, real AI/chat requirement,
live migration, new review waiver or Step 7 completion is implied.

### Owner migration authorization — 2026-09-15

Owner confirmed "triển khai" in response to the explicit proposal to waive
Architecture Review for this v0.8 packet and implement/test its migration on
temporary databases. This waives only the AGENTS.md pre-migration
Architecture Review passed condition for these six AI tables and the associated
Course Intent references. It is NOT an independent PASS or a repository-wide
waiver. Approved/Frozen database and ADR prerequisites remain in force.
Physical constraints, rollback and tenant tests remain required. No permission
to apply to learnforge_db, activate providers or declare all Step 7 complete is
implied. Earlier statements that no Step 7 waiver exists are historical.

### Owner design closure — 2026-09-15

Owner decision: "chốt tài liệu, ko cần reivew quá nhiều". The current Step 7
contract v0.8, six AI table designs and their Course/Learning/ADR design
extensions are approved and Frozen. No additional design-review round is
scheduled by this closure. Earlier Review/pending-Freeze statements below are
historical and superseded for design status only. Implementation was Not
Implemented at design closure; the current scope is stated above. This records Owner approval, NOT reviewer PASS, physical DDL
verification, migration execution or live database/provider authorization.


Revised design for Step 7 under [ADR-0017](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md).
The recorded [Learning Gate 2 PASS](../quality/LF-Learning-Gate-2-Independent-Review.md)
satisfies the Learning prerequisite, not this packet's migration gate.

### Owner Approval and Freeze — 2026-09-14 — historical

Owner decision: **Duyệt và Frozen thiết kế packet Bước 7 hiện tại.**
Scope: this contract v0.6, its five linked Proposal table designs, and the Step 7
AI Foundation inventory/Course Mapping Intent amendments. The earlier terms
candidate/proposed and requests for packet approval below record drafting history;
this decision supersedes those pending-design-approval statements only.

Implementation Status was Not Implemented at that decision. No migration/backend completion,
independent review PASS, physical DDL evidence, provider activation, production
apply or review waiver is implied. AGENTS.md Architecture Review passed remains
a separate gate unless explicitly waived. Retention/legal-hold production
amendments and real-provider eligibility remain outside this design approval.

### Owner remediation direction approval — 2026-09-14

Owner approved all six directions after CHANGES REQUIRED ("tôi duyệt"):
inherited Learning drafts and explicit Course rebase; human context confirmation
and successors; durable generation requests; cancellation audit; code-versioned
prompts; backend/frontend gate separation. This v0.7 shape supersedes the v0.6
Freeze for the affected packet only. At that stage it was Review / Not Implemented.
Direction approval is not a new Freeze, review PASS or waiver.

### Owner approval — restricted inheritance and P2 remediation, 2026-09-15

Owner approved option (a) with six mandatory conditions: unchanged original file
identity/fingerprint and active usage; copy decision fields only; live checks by
the copying actor; predecessor/reason/inherited-draft audit; reuse only a valid
already-created Node; exception limited to Step 7 accepted payload. Owner also
approved P2 fixes. The detailed rules below are normative. This is not a Freeze,
review PASS, waiver, Media policy amendment or provider activation.

Owner decisions in the current task: AI suggests; assigned teachers and tenant
admins edit/accept/reject. New Nodes require actual customer_admin approval into
an explicit draft Framework Version. Admin may approve directly without a prior
teacher decision. Accept never publishes. New Mapping intent applies at the next
Course Version publication; no backfill or silent rebinding of existing Versions,
Products or Enrollments.

Owner additionally approved, 2026-09-14: seal the source set atomically at
generation; an unrelated Node addition does not invalidate approval of a target
Node. Revalidate reuse globally before Node creation, but validate the specific
target and its dependencies before Mapping application. Changed target content
requires explicit human reconfirmation, never another AI call or duplicate Node.
This approval covers those policies, not the complete physical packet.

Backend workflow may be verified with a fake provider. Real AI, provider
activation and frontend chat are not closure prerequisites. The teacher review
UI is not implemented by this contract itself; it is delivered by LF-AI-Authoring-Review-UI-Design and must be
reported with the review status recorded there (no independent PASS for P3-B and P3-C).

## Domain allocation

AI owns proposal revisions, source anchors, review audit and handoff receipts.
Course owns draft context/Mapping Intent and publication snapshots. Learning
owns Node creation and canonical Mapping. AI never writes Course/Learning tables.
Media owns source authorization and revision checks; raw table reads are not an
alternative to Media Read. All requests use resolved TenantContext.

Without a Framework, allow summary/concept proposals only. Learning objectives
need Course/Activity context; competency and node_mapping need an explicit
Framework basis. Reuse candidates precede propose_new, within the same tenant
and Framework. Confidence is not pedagogical weight.

## Persistence packet

- [ai_authoring_generation_requests](../database/ai/ai_authoring_generation_requests.md)

- [ai_authoring_proposals](../database/ai/ai_authoring_proposals.md)
- [ai_authoring_proposal_revisions](../database/ai/ai_authoring_proposal_revisions.md)
- [ai_authoring_proposal_sources](../database/ai/ai_authoring_proposal_sources.md)
- [ai_authoring_proposal_reviews](../database/ai/ai_authoring_proposal_reviews.md)
- [ai_authoring_proposal_applications](../database/ai/ai_authoring_proposal_applications.md)

Shared proposed physical rules: id/customer_id BIGINT UNSIGNED NOT NULL,
primary key id, UNIQUE(id, customer_id), customer FK to saas_customers(id)
RESTRICT; explicit UTC
DATETIME(6) timestamps, no implicit TIMESTAMP default/on-update. Hashes are
SHA-256 hex CHAR(64); no generated column depending on untrimmed CHAR.
Cross-tenant/parent references require composite keys, not independent scalar
FKs. JSON has valid syntax plus application-level kind-specific validation.
Migration must preflight all six tables and affected Course references for rows before ANY down DDL, fail
closed if populated, and never backfill/rewrite historical Media evidence.

## Commands and authorization

Exact URLs and HTTP authority are fixed in “HTTP endpoint and authorization
contract — 2026-09-16” below. Backend command contract:

| Command | Inputs besides authenticated actor | Result and authority |
| --- | --- | --- |
| Generate | Activity ID, explicit Framework basis when required, request UUID | Authorized source/context snapshot; one completed Model Run, bounded proposal items |
| Read/list | Activity or proposal IDs, cursor | Current assignment + source authorization; no stale/deleted text |
| Edit | Proposal ID, expected revision/lock_version, payload, request UUID | Append human revision and audit atomically; pending_review only |
| Accept/reject | Proposal ID, expected revision/lock_version, request UUID, optional reason | Exact revision and decision atomic; no publish; no targetless receipt |
| Approve Node | Accepted revision, explicit draft Framework Version, request UUID | Actual active admin; Learning owner service, no impersonation |
| Confirm/reconfirm target | Accepted revision, application ID, expected target hash, request UUID | Human decision and snapshot; no provider call or Node creation |
| Reconfirm context | Accepted revision, current Course DTO hash, request UUID | Human decision; original source seal unchanged |
| Human successor | Predecessor accepted revision, current sources/context, edited payload, request UUID | New pending_review item without provider execution |
| Cancel application | Application ID, reason code, request UUID | Human audit plus terminal cancellation, no undo of applied work |
| Inherit draft / rebase selection | Initiating accepted proposal, explicit Versions and reviewed plan, request UUID | Admin-only owner commands with immutable decision receipts |
| Apply intent / resume | Accepted revision, exact published Framework/Node target, request UUID | Course owner service validates selected Framework and source |
| Promote | Trusted Course publication context | Existing Learning promotion, exact published identities only |

Teachers must have current active assignment to the proposal's Template. Admin
must be active in that tenant. Check on every read/mutation and again at deferred
handoff. A lost assignment does not silently reassign authority to a worker;
require a fresh authorized decision. Unauthorized IDs return non-disclosing errors.
Clients cannot set tenant, actor, run, provenance, publish state or allocate receipt
IDs. A server-issued application UUID may select an existing receipt; it grants
no authority and must belong to the addressed proposal and tenant.

Bulk review is per-item atomic with explicit per-item outcomes, not an ambiguous
all-success response. Every mutation includes an idempotency UUID and command
fingerprint. Same UUID/different payload is a conflict; exact replay returns the
stored outcome only after current authorization. Lock parent proposal before
allocating revision/decision; expected version mismatch is a conflict.

## Payload and provenance

Candidate payload schema version 1: kind plus title/body, confidence [0,1],
rationale and source references. node_mapping adds reuse_existing/propose_new,
exact reuse IDs or proposed code/label/criteria, role teaches/practices/assesses,
and separate nullable weight [0,1]. Unknown keys and inconsistent branches are
rejected. Candidate bounds: 100 items per generation, 64 KiB UTF-8 per item,
JSON depth 8. These are design bounds, not measured model quality.

Provider/model/prompt template scope/version/hash are inherited from the immutable
Model Run; proposal references that run, not independently editable duplicates.
Human edits create revisions without inventing a provider run. Store source
anchors and canonical context hash including Activity pedagogical context,
Course audience/level and selected Framework basis. Normalize deterministically;
include a named context schema version. Do not use a mutable timestamp as identity.

Before read/accept/admin approval/apply/publish, revalidate Media access and exact
revision plus pedagogical context. Source revision drift marks work stale; access loss denies content immediately.
Pending context drift becomes stale; prompt drift applies only to generated
pending proposals, not human successors. Accepted Course-context drift blocks
apply/publish as proposal_context_changed until a human reconfirm_context.
Do not overwrite provenance. Context hashing must exclude administrative
counters and the act of attaching this proposal itself, avoiding self-invalidation.
The context schema is defined below, with a separate Course-only hash.

## Human approval and publication

Review state and application state are separate. Teacher accepting propose_new
waits for admin; admin may accept and approve in one explicit atomic command.
awaiting_admin is computed from accepted propose_new without a create_node
receipt, not a persisted application status. Admin chooses the exact draft before
creating its ready_to_apply receipt. Learning creates the Node in that draft;
this receipt becomes applied, without waiting for publication. A separate
apply_intent receipt can wait for publication and explicit Template selection.
Do not insert draft Nodes into the existing published-only Course Intent.

Ordinary select still refuses selection changes with existing Intents. The new
explicit inherited-Version rebase below is the only exception; no silent rebinding. After intent application, the next explicit Course
publication uses existing snapshot/promotion transaction. Recheck accepted
revision/source freshness in that transaction. No asynchronous canonical write
after publication and no Mapping added to already published Course Versions.

Summary/concept acceptance only records reviewed AI content. Updating Course
instructions or objectives requires a separately defined Course owner command.
Acceptance creates no Evidence, Mastery, progress or enrollment side effect.

## Deletion and recovery

Source deletion/detach immediately denies reads/applications via source validation,
independent of cleanup scheduling. Proposed source-deletion policy: tombstone
proposal and erase revision payloads/excerpts; retain hashes, IDs and audit.
Do not cascade-delete canonical Mapping or Node; correction uses Learning
invalidation. Production retention/legal-hold exceptions require separate approval; normal
source-loss erasure is part of this design.

Node owner write + application receipt update share one DB transaction; retry
uses the same receipt. Never repeat a provider call to reconstruct an accepted
payload. Intent and promotion receipts similarly accompany the owner transaction.
No claim that an external provider ran exactly once across a process crash;
uncertain runs follow the existing controlled recovery/quota policy.

## Approved schema and remaining service implementation

This revision defines SIX additions beyond the twelve-table Foundation.
The earlier five-table Freeze is historical. Owner froze the revised v0.8
inventory and Course amendment on 2026-09-15 and separately authorized migration
under the scoped waiver above.

Course Intent now permits manual/ai_proposal with physical provenance constraints.
Accepted-revision semantics and the freshness guard at publish remain service
work, without changing manual behavior. New teacher-facing Learning candidate
read/AI-intent operations need narrowly scoped authority, not removal of admin
guards from existing authoring/publish services.

The design points below are resolved in the Frozen packet. Historical references
and normal erasure are decided; production retention exceptions remain separate.
DDL and triggers have local MariaDB 11.4.12/10.4.21 evidence, including concurrent
source sealing. Owner-accessor services are not implemented. Docs lint alone
does not establish DDL correctness, and neither DDL tests nor a review waiver
establish provider quality or live deployment readiness.

### Revision creation without circular references

Remove the stored current_revision_id from the candidate. Current revision is
MAX(revision_no) scoped by proposal_id/customer_id, with a supporting unique key.
Generation inserts parent, revision 1 and anchors in one transaction. Every
proposal is sealed before that transaction commits: set sources_sealed_at,
source_count and source_set_hash from its ordered canonical anchors. Readers and
all review/application commands fail closed if unsealed, count/hash mismatched
or missing revision. No unsealed proposal may be returned as generation success.
Source loss blocks the old proposal; a human/generated successor may use new
authorized sources. The sealed old source set is never edited or resurrected.

Every
mutation locks the parent before reading/allocating a revision; accepts reference
the exact revision ID, never an implicit latest value at application time.
Readers fail closed for missing revision/content. No temporary status, disabled
FK checks, deferred constraint or reverse FK is required. The invariant that a
parent has at least one revision is service-enforced and needs rollback tests.

### One owner operation per accepted revision

Application identity is UNIQUE(customer_id, revision_id, operation), not
target_hash. One create_node and one apply_intent are allowed per accepted
revision. Target/input hashes remain immutable and mismatches produce conflict.
Locking and a shared owner-write/receipt transaction prevent duplicate effects;
the unique key prevents a second receipt under another UUID/target. A cancelled
or applied receipt cannot be evaded by retry. Retargeting needs a human successor
and renewed review except the explicit inherited-Version rebase below, which
preserves old receipts. Existing Course intents are reused by normal
subsequent publications; no extra AI receipt is needed per published Version.

### Historical references and cleanup

The historical-ID exception below is a proposed explicit policy, not an omitted
FK. Do not add a restrictive Course FK merely to make all references uniform.
On missing/deleted Course context, deny all proposal payload reads and handoff,
mark proposal deletion_pending and cancel unapplied receipts. Reconciliation
must scan by tenant and key, including missing Activities and pending erasures;
it cannot rely solely on MediaFileDeleted events. Finalize only after erasing all
revision payloads, source excerpts, review target/context snapshots and free-text reasons; retain IDs/hashes
and decision metadata. No automatic Node/Mapping deletion or quota refund.
The cleanup implementation and approved retention exceptions remain future work.

Required negative tests: parent insert followed by failed revision insert leaves
no parent; orphan parent is unreadable; concurrent edit/accept returns conflict;
two connections with different request UUIDs/targets cannot create two Nodes
from one revision; retry cannot restore a removed Intent; deleting Activity is
not blocked by Proposal history and makes that history's content inaccessible.

## Acceptance evidence required

### Immutability and erasure enforcement — proposed packet rule

No trigger is dropped during normal cleanup. No session variable disables audit
protection. Use BEFORE UPDATE/DELETE triggers on revisions, source anchors and
reviews. DELETE always raises LF_PROPOSAL_HISTORY_IMMUTABLE. UPDATE either is a
complete no-op or is the single allowed erasure transition below; every other
change raises the same error (SQLSTATE 45000).

For an erasure transition all of these must be true:

1. The same-tenant parent exists and is deletion_pending (not merely stale).
2. OLD.erased_at IS NULL and NEW.erased_at IS NOT NULL.
3. The content field is NULL in NEW: payload for revision, excerpt for source,
   reason AND target_snapshot AND context_snapshot for review. Optional fields may already be NULL.
4. EVERY other column is unchanged using NULL-safe equality, with binary
   comparison for string/hash identity. Actor, decision, hashes, timestamps,
   source anchors and IDs cannot change. Enumerate all columns in the actual
   trigger; tests must catch any new unprotected column.

The parent row is locked by both edits/reviews and erasure. BEFORE INSERT on
revisions/reviews/sources checks parent tenant/state and refuses new rows once
deletion_pending/deleted. Revision insertion requires pending_review; source
insertion locks the same-tenant parent and requires sources_sealed_at IS NULL
and pending_review. Once sealed it is refused even if still pending_review.
Sealing is one-way: parent trigger allows NULL -> timestamp/count/hash once,
verifies COUNT(same-tenant child sources) = NEW.source_count AND NEW.source_count > 0;
all three seal fields are then immutable. The
canonical set hash is computed/verified by the service, not claimed as SQL JSON
hash equivalence. Rollback reverts parent/revision/anchors/seal together.
Review insertion
requires the documented action/state transition in the service transaction.
The database parent-state check supplements, never replaces, authorization.

Parent status cannot return from deletion_pending/deleted. Before parent becomes
deleted, require every child content field NULL and every child erased_at set;
implement this as a parent BEFORE UPDATE check in addition to service checks.
Keep source identity hashes and locator (no raw text/URL) after erasure. Reviews
may still be listed as content-free audit to authorized admins; the ordinary
proposal endpoint returns no deleted content. No provider payload goes in errors.

Cleanup transaction: lock parent -> deletion_pending -> cancel non-applied
applications -> erase children in bounded batches -> final completeness check ->
deleted. Batches may commit while deletion_pending; reads remain denied. A crash
resumes remaining un-erased rows and never rehydrates payload. Applied receipts
remain historical; cleanup does not undo an existing Learning Node or Mapping.
No raw-content copy is retained in application metadata or a diagnostic log.

Parent immutable fields: customer/id/UUID, generation attempt/ordinal, kind,
Course IDs, Model Run, context contract/hash, Framework basis, predecessor and
creator/time. Only status, lock_version, updated_at and deletion timestamps may
advance, according to state rules. Application target/request identity and
accepted revision are similarly immutable; only permitted lifecycle/results
advance. target_review_id may advance with an appended same-target confirmation only
before applied; it freezes at applied. Intent owns post-apply pointers.
Define matching triggers alongside child triggers in the packet.

Retention/legal hold for production remains separately approved; do not invent
an exemption that retains readable content after source loss. If a future hold
requires raw content preservation, it needs a restricted-retention design and
amendment; it must not reopen normal proposal reads. Current candidate implements
source-loss erasure, not a legal-hold facility.

### Owner-service ports — proposed v1 DTOs

Names describe new narrow methods/contracts, not claims that code exists.
Existing LearningFrameworkReadService is admin-scoped; do not simply remove its
guards to let teachers enumerate all Framework drafts.

| Owner / operation | Input | Result and checks |
| --- | --- | --- |
| Course: proposalContext | actor, Activity ID | Authorized tenant/template/lesson/activity IDs; type/title/instructions, audience/level if owned fields exist, stable ordered position; current assignment; no signed URLs |
| Learning: proposalBasis | actor, authorized Course context, explicit Framework/Version | Same-tenant basis, lifecycle, deterministic content fingerprint, bounded candidate Definitions/Nodes and criteria |
| Course: assertProposalContext | actor, Activity ID, expected course_context_hash | Current authorization/context or named conflict; locks Template for mutation |
| Learning: approveProposedNode | actual admin, explicit draft Version, reviewed revision/payload, receipt identity | Existing Learning authoring service creates/reuses Definition and Node atomically; returns exact IDs and post-write basis fingerprint |
| Course: updateProposalConfirmations | current authorized actor, Intent ID, expected current review IDs, new AI-validated review IDs, request UUID | Locks Template/proposal/Intent; validates same tenant/revision/target; atomically appends AI decision and updates Course pointers without replacing Mapping |
| Course: applyReviewedIntent | authorized actor, accepted revision, explicit published Node/Framework, receipt identity | Creates origin=ai_proposal intent through Course service; returns intent ID; selection/source checks retained |
| Learning: promote | trusted Course publication adapter, exact published source and Node | Existing canonical Mapping semantics and idempotency; no AI entry point |

Course DTO fields: context_schema_version='course-authoring-v1', customer_id,
template_id, lesson_id, activity_id, activity_type, title, instructions,
audience (nullable), level (nullable), ordered_position (ordered ancestor IDs and
sort values). Optional absent Course concepts are explicit NULL, never fabricated
from filename or model inference. Map physical Course fields inside Course only.

Learning DTO fields: basis_schema_version='learning-authoring-v1', customer_id,
framework_id, framework_version_id, status, content_hash, candidates[]. Each
candidate carries definition_id/node_id, version identity, code/label/description,
criteria, status and reference evidence for ranking. Content hash covers relevant
Framework/Version authoring content and ordered Definitions/Nodes/criteria, not
published_at, updated_at or publish status. A pure publish of unchanged content
therefore keeps the content hash; a draft content edit changes it.

Teacher basis reads require current Course assignment and the explicitly selected
published Template Framework basis. New draft creation targets are selected by
admin approval, not arbitrary teacher draft enumeration. AI can propose a new
Node against a published basis; admin chooses the exact draft in that same
Framework, with renewed reviewed content/context validation.

For create_node, recheck the complete current Framework basis and reuse candidates
under owner locks before writing; never blindly create against stale candidates.
Preserve original proposal basis_hash and record result_basis_hash as post-write
audit evidence, not a permanent prerequisite for all later Mapping operations.

For apply_intent and publish, Learning supplies targetSnapshot: exact Framework/
Version/Node/Definition IDs, code/label/description, criteria, mapping-relevant
ancestor/relationship semantics and dependency IDs, plus target_schema_version
and target_hash. Learning defines and includes every mapping-relevant dependency;
it does not hash all unrelated sibling Nodes. Node status and published Version
eligibility are checked live, separately from content hashing. Adding unrelated
Node B therefore does not invalidate A; changing A or a dependency does.

Before application, append an initial confirm_target review of that exact snapshot.
This may be atomic with accept for a published reuse target. For a new draft Node,
teacher/admin confirms its result after creation; this is not a second create_node
command. A changed target requires reconfirm_target with a fresh snapshot, same
Node/Version identity and same accepted payload, appended to review history.
Only authorized teacher/admin may confirm within Course scope; draft target read
is restricted to the result Node of this accepted proposal, not general draft
enumeration. It grants no Learning edit/publish privilege.

Before apply, application.target_review_id identifies the effective confirmation.
It may change under locks with appended human decisions only while unapplied.
At applied it freezes as historical evidence. Thereafter Course Intent's
ai_target_review_id/ai_context_review_id alone govern future publication; Course
updates them through updateProposalConfirmations, never through an AI table write.
Old receipt/review evidence remains immutable. A mismatch blocks apply/publish as
proposal_target_changed, but does not revert accepted to pending_review or stale
the proposal solely due to target drift. Source loss still blocks it; accepted Course-context drift uses reconfirm_context.
Rejecting the changed target records cancel_application and cancels unapplied work. Choosing a different Node/
Version or changing accepted payload requires a human successor, except for the
explicit inherited-Version rebase below. No AI call,
new Node, source re-seal or overwrite of original provenance is involved.

No accessor grants a teacher permission to publish or to change manual Learning
authoring. Candidate output uses cursor pagination; bounds default 25/max100.
Canonical hash arrays use stable IDs/order and explicit NULLs; no collection
depends on database default ordering. Acceptance and deferred application check
the current permitted actor, not merely a stored historical role string.

### Request/response contract — v1

Single-item commands use UUID request_id. Version guards are operation-specific
as fixed in the HTTP table below: positive integer expected_lock_version and,
for edit/decision/Node approval, expected_revision_no. No implicit numeric coercion of
arbitrary strings; unknown fields are rejected. Edit includes payload and
payload_schema_version. Accept/reject includes action and optional reason (max
2000 characters). Admin Node approval includes framework_id and exact
framework_version_id plus the accepted revision number; it never accepts a
publish flag. Tenant and actor come only from authenticated context.

Generate accepts activity_id, request_id, requested_kinds and optional paired
framework_id/framework_version_id. Same request_id with different canonical
inputs is rejected, including while the original run is still unresolved.
Successful response contains proposal UUIDs, current revision numbers and
review/application states; no signed crop URLs or hidden provider credentials.

Read returns proposal UUID, kind, current revision, validated payload, source
citations, confidence/rationale, review history, application summary and allowed
actions computed for the current actor. Forbidden/stale/deleted payload is not
included. List is keyset-paginated, default 25, maximum 100. Bulk review is at
most 100 item commands, each with a distinct request_id, returning ordered
per-item outcomes; it does not accept an unbounded all-items selector.

Outcome codes (application-local, not new ai_model_runs error vocabulary):
proposal_not_found (404; also unauthorized foreign identity),
proposal_forbidden (403 for a visible object/action), invalid_proposal (422),
proposal_revision_conflict (409), proposal_idempotency_conflict (409),
proposal_stale (409), framework_selection_conflict (409). A successful acceptance
waiting for admin/publication returns success with that application state, not
an error and not canonical promotion. Unexpected storage errors fail the item
transaction and must not be disguised as accepted.

### HTTP endpoint and authorization contract — 2026-09-16

Settled on Owner instruction to finalize endpoints and permissions. This is a
transport specification for the approved backend decisions, not evidence of
implemented routes, independent review PASS or permission to activate a provider.
The Frozen domain design remains in force; overall implementation stays Partial.

#### Route boundary and current authority

Use the existing web/session route groups, not a new login or bearer-token API:

- Admin prefix `/admin`, name prefix `admin.`, middleware `tenant`, `auth`,
  `verified`, `tenant.user`, `role:customer_admin`.
- Teacher prefix `/teacher`, name prefix `teacher.`, same middleware except
  `role:teacher`. Shared endpoints are registered in both groups; admin-only
  endpoints are registered only in the admin group.
- JSON clients send `Accept: application/json`; mutation bodies use JSON and
  the existing CSRF protection. Do not exempt these endpoints from CSRF.
- Tenant and actor come exclusively from resolved tenant/session context.
  Validate the complete Template -> Activity -> proposal -> application chain
  within that tenant. Foreign, missing or unassigned object identities produce
  the same 404; a visible object with a prohibited action produces 403.
- Admin must be active in the tenant. Teacher must be active with a current
  active Template assignment of role primary, assistant or reviewer, as decided
  by CourseAuthoringContextService. These assignment roles do not grant admin
  Learning operations. Student, guest and cross-tenant access are not allowed.
- Controller authorization never replaces service authorization. Recheck at
  mutation/owner handoff and replay. Media currentRevision/source checks govern
  content disclosure; admin has no bypass. No source access means no payload,
  rationale, title/body projection, citation or excerpt in any response.

Let `B = /{admin|teacher}/course-templates/{templateId}/activities/{activityId}/ai-authoring`.
Let `P = B/proposals/{proposalUuid}`. These are path abbreviations only; the
literal route groups remain role-specific. Route names use the group prefix plus
`course-templates.activities.ai-authoring.` and the operation key below.
UUID path selectors are validated; integer path IDs are positive. No generic
unscoped proposal lookup or client-selectable customer_id is exposed.

#### Endpoint matrix

`Both` means authorized admin or assigned teacher, not every authenticated user.
All POST/PATCH rows require request_id UUID. `L` means expected_lock_version;
`R` means expected_revision_no. Required guards are checked atomically by the
service, not only by a controller preflight. Other fields are listed explicitly.

| Method and path | Operation key | Authority | Input / owner command |
| --- | --- | --- | --- |
| GET B/proposals | proposals.index | Both | cursor, limit, optional status and kind; metadata-only keyset list |
| POST B/generation-requests | generation-requests.store | Both | requested_kinds, optional paired framework_id/framework_version_id; generate with Activity from path |
| GET B/generation-requests/{generationRequestUuid} | generation-requests.show | Both | Status and proposal UUIDs only; authorize request's Activity, no raw prompt/output |
| GET P | proposals.show | Both | show with current authorization and Media disclosure audit |
| PATCH P | proposals.update | Both | L, R, payload_schema_version, payload; edit pending_review |
| POST P/decisions | proposals.decide | Both | L, R, action=accept or reject, optional reason max 2000 characters |
| POST B/proposal-decisions | proposals.bulk-decide | Both | items: bounded explicit decision commands, each with proposal_uuid and its own request_id, L, R and action |
| GET P/target-preview | proposals.target-preview | Both | Current permitted target snapshot/hash; no implicit confirmation |
| POST P/target-confirmations | proposals.confirm-target | Both | L, expected_target_hash; confirmTarget also records reconfirmation where required |
| POST P/target-rejections | proposals.reject-target | Both | L, expected_target_hash; rejectTarget |
| GET P/context-preview | proposals.context-preview | Both | Current Course context and course_context_hash through Course owner port |
| POST P/context-confirmations | proposals.reconfirm-context | Both | L, expected_course_context_hash; reconfirmContext |
| POST P/node-approvals | proposals.approve-node | Admin only | L, R, framework_id, framework_version_id identifying the explicit draft; approveNode |
| POST P/intent-applications | proposals.apply-intent | Both | L; applyIntent/resume, only the reviewed current target |
| POST P/applications/{applicationUuid}/retry | proposals.applications.retry | Both for apply_intent; admin only for create_node | L; retryApplication on exact failed receipt; preserve approved_by |
| POST P/applications/{applicationUuid}/cancel | proposals.applications.cancel | Both | L, reason_code; cancelApplication, never undo applied work |
| GET P/successor-source-scope | proposals.successor-source-scope | Both | Current anchor identities, cursor/limit and selection_limit=200; no source text |
| GET P/successor-preview | proposals.successor-preview | Both | Restricted decision-only inheritance preview with audit; no old rationale/citations |
| POST P/successors | proposals.successors.store | Both | reason, payload and selected_anchor_hashes as specified below; create |
| GET P/inherited-draft-preview | proposals.inherited-draft-preview | Admin only | base_version_id; authorize proposal, then Learning inheritance preview |
| POST P/inherited-drafts | proposals.inherit-draft | Admin only | base_version_id, version_code, title, expected_source_graph_hash, expected_plan_hash; inheritDraft |
| GET P/rebase-preview | proposals.rebase-preview | Admin only | target_version_id; complete reviewed plan through rebasePreview |
| POST P/rebases | proposals.rebase-selection | Admin only | target_version_id, dispositions, expected_preview_hash; rebaseSelection |

No new HTTP endpoint for canonical Mapping promotion, Course/Framework publish,
provider activation, erasure, worker control or generation-request recovery is
part of v1. Publish remains with existing owner endpoints and revalidates every
AI Intent in the publication transaction. Pending cancellation/running recovery
remain controlled admin service operations; do not expose a browser boolean as
proof that a worker has stopped. Their future HTTP transport needs its own
operational evidence contract, not an automatic timeout takeover.

#### Validation, pagination and replay

- Reject unknown fields and client-supplied activity_id (already in the path),
  customer_id, actor_id, provider/model, run identity, status, audit identity or
  publish flags. Route identifiers and body identifiers must never silently
  override one another. Framework/Node selections remain owner-validated.
- Hash guards are lowercase SHA-256 hex; request/selector UUIDs are normalized
  before hashing. JSON IDs/version guards are integers, not arbitrary numeric
  strings. Query/path integers use strict positive decimal validation.
- Human cancellation reason_code matches `[a-z][a-z0-9_]{0,63}`; the server
  assigns cancellation_kind=human and cancelled_by. Client input cannot assert
  system cleanup authority. Existing kind/payload/Framework validation and
  version-code/title bounds from the owner contracts continue to apply.
- Successor reason is one of source_revision_changed, context_changed,
  target_changed, intent_removed, human_correction. The first requires payload
  NULL and the six approved inheritance conditions; the other reasons require
  a valid edited payload. selected_anchor_hashes is always a nonempty unique set
  of at most 200 current offered hashes. Sorting fixes idempotency identity, not
  authority. Never carry old rationale or source references via inheritance.
- Lists use default 25/max 100, stable ascending ID keysets. Source-scope pages
  use ascending anchor hash. Opaque tamper-resistant cursors bind tenant, actor,
  parent, filters and order; source cursors additionally bind current revision
  identity and refuse changed scope with 409 proposal_stale. A cursor is not an
  authorization grant. No offset/all selector or inaccessible global totals.
- Proposal list items contain UUID, kind, state, revision/lock versions,
  content_denied and allowed_actions; no payload-derived title or snippet.
  Detail/history/application output is allowlisted and bounded; do not serialize
  raw database rows or historical review snapshots. Any content-bearing preview
  follows the same Media audit-before-disclosure rule as show. Failed audit
  aborts disclosure. Metadata-only listings are not evidence-content reads.
- Bulk permits at most 100 distinct proposals and distinct per-item request IDs.
  Its outer request_id is correlation only, not a batch transaction/replay key;
  item keys are authoritative. Validate the envelope before work; process valid
  items in supplied order, each in its own transaction, with explicit outcomes.
- All single mutations use existing command idempotency with current authority
  on replay. Replay returns the stored content-free outcome, not an old payload
  snapshot. Successor and inheritance/rebase use their sealed inputs/plan hashes
  rather than an invented proposal lock-version field; validate their current
  predecessor/target again inside the transaction.

#### Response and error mapping

Successful reads and completed/replayed commands return HTTP 200 with
`{data: ..., error: null}`. Pending/running generation returns 202 with a status
resource URL; this describes persisted request state, not a promise that a new
queue worker was launched. Commands return IDs, current states and version
guards, not proposal text. An accepted item awaiting admin/publication is 200;
it must never claim published or materialized Mapping.

Errors use `{data: null, error: {code, details}}`, with allowlisted content-free
details. Use 404 proposal_not_found, 403 proposal_forbidden, 422 invalid_proposal;
409 for revision/idempotency/stale/selection conflicts and
proposal_context_changed, proposal_target_changed, proposal_intent_conflict,
proposal_successor_required. Provider gate refusal is 409 with its approved
gate code, not a success or provider activation. Unexpected infrastructure/audit
failure returns sanitized 503 service_unavailable, without SQL/stack/payload.
The service_unavailable value is HTTP-only, not a new Model Run error code.
Session/verified/CSRF/rate-limit failures retain framework 401/403/419/429
semantics; do not disguise them as successful domain commands.

Bulk returns 200 with ordered items containing proposal_uuid, request_id,
http_status and each item's data/error; envelope validation failure is 422.
Responses use Cache-Control: no-store. allowed_actions is advisory and computed
for the current actor, never a capability token or substitute for checking POST.

#### HTTP implementation acceptance

Route adapters, strict requests, allowlisted response DTOs and
list/status/context/inheritance-preview wrappers are implemented. The HTTP read
adapter paginates sourceScope; it does not expose the service's full array.
Teacher/admin route middleware, parent identity checks, audit failure, JSON
empty-string preservation, cursor scope and human-only paths are exercised with
the fake provider on isolated MariaDB. This is implementer evidence, not an
independent PASS, production deployment or real-provider activation.

Tests must cover both role prefixes; guest/student/foreign/unassigned/inactive
denials; path-parent and receipt tampering; lost assignment on replay; source
detach/tombstone/stale and failed audit with no disclosure; admin-only Node and
rebase; retry actor versus original approval; CSRF; invalid fields/guards;
mixed bulk outcomes; cursor tampering/revision changes; duplicate requests;
accept without publish; and zero provider calls on human-only paths. Use a fake
provider. Real AI, frontend chat and provider activation are not closing gates.

### State transitions and locking

Review: pending_review -> accepted | rejected | stale | deletion_pending;
accepted -> stale | deletion_pending; rejected -> deletion_pending;
stale -> deletion_pending; deletion_pending -> deleted. Editing appends a
revision only while pending_review. No other transition. Historical reviews
remain unchanged when their aggregate later becomes stale/deleted.

Application initially ready_to_apply for create_node, or
awaiting_publication/ready_to_apply for apply_intent according to target state.
awaiting_publication -> ready_to_apply | cancelled;
ready_to_apply -> applied | failed | cancelled;
failed -> ready_to_apply | cancelled, with renewed source/authority checks;
applied and cancelled are terminal. create_node never waits for publication
before its write; apply_intent may wait for the target draft to publish.
Internal prerequisite checks and the actual owner write share a transaction.

Lock ordering is Course Templates (sorted IDs) -> generation request (if used)
-> proposals including predecessors (sorted IDs) -> applications (sorted IDs)
-> Learning Versions (sorted IDs) -> Framework -> Nodes.
Read-only discovery may identify IDs first, but all decisions revalidate under
locks. No successor path may lock request/predecessor before Template. Initial
request insert is a short separate transaction with no other owner locks.
Claim/completion/cancellation then follow the shared order; a generated provider
call runs outside every database transaction.
No provider/network call under these locks. A publish path must not invert this
order. Exact replay returns an existing result, never advances a stale revision.

### Verified target inventory and proposed historical references

Read-only source inspection, not a live-database verification:

| Target | Evidence | Intended FK |
| --- | --- | --- |
| users(id, customer_id) | migration 2026_08_13_000000_add_user_tenant_composite_unique.php | Actor references, RESTRICT |
| ai_model_runs(id, customer_id) | schema contract entry | Generating run, RESTRICT |
| media_files(id, customer_id) | existing AI Foundation media FK precedent | Source anchor, RESTRICT |
| core_learning_framework_versions(id, customer_id, framework_id) | schema contract unique | Exact Framework membership, RESTRICT |
| core_learning_nodes(id, customer_id, framework_id, framework_version_id) | schema contract unique | Result Node membership, RESTRICT |
| Working Activity / mutable Course Intent | Current delete paths and mutable identity | Proposed historical IDs, no inbound RESTRICT preventing owner deletion |

Proposed exception: template_id/activity_id and application.intent_id are durable
historical identifiers validated by Course's owner service on insertion and every
use, not authorization-bearing foreign keys. This prevents audit rows preventing
legitimate Course deletion. Course deletion immediately makes proposal unreadable
and inapplicable; content cleanup must reconcile missing Course context as well
as deleted Media. This exception remains part of the revised design. Never silently replace the tenant checks with bare IDs.

### Canonical context fingerprint v1

Hash canonical UTF-8 JSON with sorted object keys, ordered source anchors and
explicit NULL values. Inputs: context_schema_version, tenant ID, template ID,
activity ID/type/title/instructions, ordered Activity location in its lesson,
Course audience/level values supplied by Course owner, exact selected Framework
basis, sorted source anchor hashes and prompt contract identity. Array order
is semantically significant except explicitly sorted anchor sets.

Store original Learning content hash separately as proposal.basis_hash, paired
with the optional Framework basis; a combined context_hash alone cannot later
prove which Learning content was reviewed. For the approved new-Node path, an
explicit selection change to the exact target recorded in the admin receipt is
permitted, with all non-Framework Course context still checked separately.
Original proposal hashes remain immutable; this is no permission to use latest.

Do not hash working_revision wholesale: attaching this proposal's intent changes
that counter and would invalidate itself. Store initial source/context identity
immutably. Expected publication of the exact draft Framework is a permitted
transition, not permission to use latest; any content changes to that draft after
approval require renewed review only when the specific Mapping target or its
dependencies change; unrelated sibling additions do not. Before creation the
full reuse basis is still rechecked. The Learning owner must expose deterministic
content fingerprint for this check. Missing owner fields/accessors are an
implementation dependency, not a reason to query another domain directly.

HIGH regression plan includes role/assignment revocation and cross-tenant/parent
tampering, plus these Owner-approved policy cases: source insert/update after seal is refused even
while pending_review; initial-generation failure rolls back the whole set; adding
unrelated Node B does not block A; changing A's criteria blocks Mapping until a
same-target human confirmation; confirmation replay is idempotent and makes zero
provider calls and zero Node creations; erasure clears target snapshots as well
as review reasons while preserving hashes and decisions.

Also test edit/accept two-connection races, replay conflict, no teacher Node creation,
admin direct approval, stale source/context at each boundary, no AI direct
canonical write, both-version publication, no backfill, atomic failure/retry,
source erasure with audit preserved. Physical CHECK/FK/unique/rollback tests on
isolated MariaDB 11.4 and deployment engine 10.4. Fake-provider tests verify
workflow, not model quality. No migration or code has been written in this packet.


## Remediation contract v2 — incorporated into the Frozen v0.8 shape

This section defines the revised command semantics; table Fields/CHECKs mirror
it. No backend or DDL is claimed implemented. Ordinary manual paths retain their
behavior unless the caller explicitly chooses the new rebase command.

### P1-1 — Inherited draft and explicit Course rebase

Learning.createInheritedDraft is an active customer_admin command taking exact
published base Version, request UUID and expected source graph hash. It previews a copy of eligible active Nodes into a new draft with the same
node_definition_id and snapshot content, and same-Version semantic relations
whose endpoints are retained. Retired Nodes or inactive Definitions are excluded
with their incident relations in an explicit exclusion plan, acknowledged by
admin; they are never silently reactivated. An active Node with inactive
Definition is also excluded. Missing endpoints indicate integrity failure and
block the copy; retired endpoints alone do not. The preview exposes lost
dependencies and every affected Intent. Admin may accept exclusions or cancel;
if no eligible Node remains, fail as proposal_inheritance_empty.
It copies no canonical Mapping, Evidence, Mastery or transition/carry-forward
policy. Existing createDraftVersion remains the empty-draft command.

Within Step 7 these two new commands require an initiating accepted proposal;
they do not introduce a general manual-only rebase feature. inherit_draft and
rebase_selection are append-only review actions on that proposal, recording
request UUID/hash, actual admin and result_version_id. Lock the initiating
proposal (and all affected proposals in sorted order) before lookup/write;
owner operation and decision commit together. Exact replay returns the recorded
result Version without repeating the copy/rebase; changed input conflicts.
The decision's target_snapshot contains the reviewed plan and old/new identity
hashes, subject to erasure. result_version_id/hash remain content-free audit.

Course.rebaseFrameworkSelection is admin-only, after exact target Version is
published. A preview returns every old Intent and its proposed replacement,
matching the same Definition in the same Framework, plus old/new target hashes.
Each existing Intent must receive exactly one disposition: map, remove_explicit
(with required reason), or cancel_rebase (abort all). Missing/ambiguous targets
cannot be auto-mapped; admin selects an eligible target or explicitly removes
that Intent. AI map retains Definition/payload or requires a human successor;
manual map may select another eligible Node with explicit confirmation.
Preview includes old/new Course context and target snapshots/hashes; map on an
AI Intent explicitly confirms both. remove_explicit is recorded in the complete
rebase_selection audit plan and has no replacement row. No silent disappearance. Input includes request UUID, expected Template
working_revision, both Versions and a complete reviewed plan. The command locks
Template and affected proposals/receipts in stable ID order, then Learning
Versions in stable ID order before Framework, matching Learning authoring order.
All target eligibility, sources and human authority are revalidated.

The selection FK is immediate. Therefore Course snapshots the complete plan,
removes the old mutable Intents, updates selection and inserts only explicitly mapped replacement Intents
inside ONE transaction; it never disables FKs. Rollback restores the original set.
New Intent IDs are expected; AI's original applied receipt retains its historical
intent_id. A new rebase_target review on each AI revision records old/new
Version/Node IDs, unchanged Definition, current context and target hashes.
Its review ID is stored on the replacement Course Intent; AI validates this exact
confirmation, not the original receipt's target, at future publish. No second
create_node/apply_intent receipt is allocated. Changed Definition or accepted
payload requires a human successor instead. Source invalidity fails rebase.
Published Course Versions, Mappings, Products and Enrollments are never updated.

An apply_intent receipt waiting for publication is resumed by an explicit
authorized teacher/admin Apply intent command with UUID. It checks exact Version
published, Template selection and a fresh target confirmation, then transitions
awaiting_publication -> ready_to_apply -> applied atomically with the owner write.
No worker invents an approval or changes Template selection.

### P1-2 — Human correction without another provider call

Store course_context_hash independently from original combined context_hash.
It covers the Course DTO only (type/title/instructions, audience/level, ordered
ancestor IDs/positions); source_set_hash, basis_hash and prompt identity remain
separate. Reconfirm_context appends a human review against the accepted revision,
with the current Course DTO snapshot/hash and from/to status accepted. The review
does not change accepted payload or sealed sources. Course Intent stores the
effective context review ID; apply/publish compare current Course-only hash to
that decision, or the original hash when no reconfirmation exists. Pending
context drift remains stale; prompt drift stales generated pending proposals only.
Human-successor prompt/run metadata is historical and is not compared to the
currently deployed prompt. A deployed prompt update alone does not
invalidate an already human-accepted Intent.

Any changed title/type/instructions/audience/level/location requires confirmation,
not automatic equivalence. Actor sees the new context and must confirm meaning.
No normal payload read bypass is added: the correction command can expose the
old accepted payload for Course-only changes when exact sources still pass.
For source-revision drift, only the restricted decision projection below is
available through the successor command. Missing Course/source denies content.

Human successor is a separate command with request UUID and command hash, using
the generation-request ledger with mode=human_successor. It records an exact
predecessor proposal/revision, creates a new pending_review proposal and revision 1
origin=human_successor, and snapshots currently authorized sources/context.
model_run_id remains the inherited original run as lineage, NOT a new execution.
The predecessor need not still be accepted after becoming stale, but its exact
revision must have an earlier accept decision. New approval is always required.
The actor may copy only the explicitly authorized projection below. Ordinary
stale reads remain denied. If inheritance checks fail, no old payload is exposed;
the actor may author new content with newly authorized sources, but cannot
resurrect erased content.
Changing sources, target Definition or accepted payload uses this command.
Recreating an intentionally deleted Intent also uses a successor; replay of the
old applied receipt never restores it. All these paths make zero provider calls.

### P1-3 — Durable Generate idempotency

ai_authoring_generation_requests owns UNIQUE(customer_id, request_uuid), command
hash, actor, mode, stable run UUID, status and item_count before any provider call.
Canonical input hash includes actor, Activity, requested kinds (sorted unique),
explicit Framework basis and mode/predecessor when applicable. Winning insert
claims the request; another caller reads the stored state and never starts a
second execution. Different hash is proposal_idempotency_conflict even before
items exist. An unresolved request returns its state, not a new run.

Generated requests persist a stable run UUID before entering the existing provider
gate. Bind that exact run; do not use correlation_id as uniqueness. No network call
under a database lock. Completion writes bounded items/revisions/sources/seals
and request completed/item_count atomically, including item_count=0. On crash
after provider execution, reconcile the same run; never automatically execute it
again. Unknown execution needs existing controlled recovery, not timeout retry.
Human-successor mode completes its single item atomically with no provider run.

### P1-4 — Cancellation and command audit

cancel_application is a human review action accepted -> accepted OR stale -> stale, taking request
UUID, application identity, expected lock version and a bounded reason code.
Receipt cancellation and append-only decision commit together. Receipt stores
cancelled_at, cancellation_kind=human, cancelled_by and cancel_reason_code.
Source/owner cleanup uses cancellation_kind=system, cancelled_by=NULL and a named
reason (source_deleted, source_detached, source_revision_changed, owner_missing); it creates no fake human
review. Cancellation is terminal, content-free, idempotent and cannot undo applied
owner writes. Rejecting an already applied target appends reject_target
(accepted -> accepted, exact target snapshot/hash), and Course points the mutable
Intent's ai_target_review_id at that rejection in the same transaction. AI's
publish port refuses a rejection until a fresh explicit confirmation replaces
the pointer, or a human successor/rebase/removal resolves it. The applied receipt
is not cancelled. Historical Mapping correction still uses invalidation.

Every explicit apply/resume/retry also appends an apply_intent or retry_application
review with UUID/hash, accepted -> accepted. approved_by stays the original
approval actor; the retry actor is in its own audit. All primary/assistant/reviewer
assignments may review within their active Template assignment; only actual admin
may create Node/rebase/publish. Replay always rechecks current permission.

### P1-5 — Versioned code prompt contract

Use code-owned immutable prompt contract ID learnforge.authoring.proposal,
integer version and SHA-256 of canonical template body, variables schema and
output schema. Do not include correlation ID, rendered source text or run UUID.
Pass explicit promptHash (sha256-prefixed digest) and promptVersion to the gate;
require prompt_hash_basis=prompt_template. Database promptTemplateId/scope remain
NULL because no ai_prompt_templates row is claimed to exist. Store logical prompt
contract ID in the generation request; proposals resolve it through their request
and immutable run. Human successors retain predecessor prompt/run lineage.
Same template across requests has the same hash. Generated pending proposal
freshness checks the current registered contract version/hash; human successors
retain historical provenance without a current-prompt gate. Accepted content
follows human review.
A blocked pre-prompt run may retain request_envelope under the general gate, but
it cannot produce a Step 7 generated proposal.

### Hash and dependency definitions (P2)

application.target_hash is an immutable identity hash: create_node hashes tenant,
revision, operation and exact draft Framework/Version (Node does not yet exist);
apply_intent additionally hashes exact Node ID. expected_basis_hash is the
original content/reuse-basis hash before that operation, never rewritten.
result_basis_hash is post-create audit only. review.target_hash hashes the full
confirmed target snapshot, and can differ across immutable reconfirmation rows.
Rebase confirmations explicitly carry both old/new target IDs; receipt identity
does not change and Course Intent points to the newer confirmation.

Target dependencies v1 include all incident semantic relations of the target
(prerequisite/part_of/supports, both directions), endpoint IDs and snapshot
content; also transitively traverse prerequisite/part_of in both directions.
Do not recursively expand supports. Exclude version_transition/carry-forward.
Sort Nodes/relations by IDs; a visited set handles cycles; traversal exceeding
1000 Nodes fails closed as proposal_dependency_limit, never truncates. Unrelated
siblings without one of these relations are excluded. Status is checked live.

Required owner ports additionally include Course.contextExistenceBatch (bounded,
tenant-scoped IDs, trusted cleanup; existence only), AI.assertPublishableIntents
(trusted Course publisher, exact accepted revision and confirmation IDs, current
actor; returns content-free lineage after source/context/target validation), and
Learning.targetSnapshot. Course never queries ai_* directly; AI never queries
Course/Learning tables. Publish source_snapshot includes proposal/revision IDs,
payload hash and exact context/target confirmation IDs/hashes for AI origin.
That immutable provenance survives mutable Intent deletion; it carries no excerpts.
The Course adapter supplies it and Learning owner validates/persists it.

### Verification and disposition

P1-1..P1-6 are addressed by this revised design, not verified implementation.
Required tests: V1 manual+AI Intents -> inherited V2 + new Node -> explicit rebase
-> next Course publish; failed rebase restores every original Intent/selection;
title correction and human successor call provider zero times; two-connection
same-UUID Generate has one run/hold, different input conflicts, zero-item replay;
human/system cancellation audit; stable prompt hash; source loss never resurrects
content; target/context confirmation lineage survives publish and Intent deletion.

P2 disposition: the revised design is included in the current Frozen scope;
hash definitions above are canonical; seal checks exact count; promotion carries
lineage; owner ports/dependencies/actors are specified; region citation uses its
typed locator, never an invented page. ADR/inventories are updated together.
HMAC for retained hashes is deferred, not silently adopted: unkeyed hashes are
not anonymization and remain tenant-restricted provenance under retention policy.

Physical DDL and trigger-concurrency checks have local evidence; this is not
an independent review PASS or certification of provider quality, frontend UI,
GitHub CI or live database apply. The scoped Step 7 pre-migration review waiver
is recorded above; no Step 4/5 waiver is inherited or expanded.


## Restricted decision inheritance — P1-N1, approved 2026-09-15

This exception applies ONLY to an exact previously human-accepted Step 7 revision,
through the explicit human-successor command. It does not authorize ordinary
stale reads, old Media evidence reads, Knowledge chunks or Vision interpretations.
Media Read policy and code remain unchanged.

All conditions must pass for EVERY predecessor source before any old decision
content is returned, and again before committing the successor:

1. The copying actor is currently authorized for the tenant/Course. Invoke
   MediaRead.currentRevision() with that actor and the original owner/usage/
   content selector (explicit current locale/profile when changed) for each source.
   Its returned media_file_id and source_fingerprint must equal the sealed original.
   Usage must still be active and unambiguous; file must not be tombstoned.
   Only processing_version or locale revision may differ. Changed fingerprint,
   different file ID, detached/missing usage or any denied/unready source refuses
   the ENTIRE inherited projection, not a partial copy.
2. Predecessor is not deletion_pending/deleted and revision payload.erased_at is
   NULL with payload present. Verify the exact historical accept decision under
   predecessor lock. No trusting permissions of its original creator/reviewer.
3. Server creates an allowlisted projection: title/body, kind, Node selection,
   mapping role and weight only. It never serializes the complete old payload.
   Drop rationale, source references/citations, excerpts, confidence, model
   explanations and every unknown/nested auxiliary field BEFORE response/logging.
   Even a human-written old rationale is not inherited. New rationale may be
   empty or freshly entered against current sources. References start empty and
   must be newly selected/validated from current Media output; no old locator,
   offset, crop or URL is transplanted. Type/branch validation still applies.
   Human-successor validation permits confidence=NULL and rationale="" (no model
   confidence is invented). Source references are empty in copy preview, but
   fresh references matching the new positive source seal are required before
   accept. Generated output validation remains unchanged.
4. Expose the projection only as an inherited, unapproved draft. Store exact
   predecessor revision, successor_reason=source_revision_changed and
   inherited_decision_draft=true on the new proposal. Display both the flag and
   reason to the next reviewer. Hash these inputs into the request command.
   Persist new source anchors/seal from current reads and require fresh human
   accept; do not call provider or claim a new AI generation.
5. If predecessor propose_new has an applied create_node, resolve its exact
   result through Learning under current authority. Require the Node to exist,
   have matching tenant/Framework/Definition and remain eligible (active Node,
   active Definition; draft/published eligibility checked for the next operation).
   Normalize successor to reuse_existing with those exact IDs, never create
   again. Invalid/missing result or a propose_new without an applied creation
   receipt returns proposal_successor_node_conflict for this inheritance path.
   It does not fall back to automatic approve_node or reuse a merely similar Node.
   Learning exposes only validation of that exact predecessor-result Node to
   the authorized copying actor, including its draft result when applicable;
   this is not permission to enumerate drafts or edit/publish Learning content.
6. Zero currently authorized sources cannot produce a successor: source_count
   stays positive. An independently authored new proposal needs valid current
   sources; manual Course/Learning authoring is the fallback when none exist.
   Access loss during preview-to-submit invalidates the preview and denies copy.

Old source fingerprints/versions remain immutable audit, not evidence to read.
No old excerpt/rationale is included in errors, decision-copy response, request
metadata, review snapshot or diagnostic logs. title/body are retained as the
human-approved authored decision per Owner scope, not certified fresh evidence.

## P2 recovery and receipt coherence — 2026-09-15

Applied receipts never advance confirmation pointers. Before apply use receipt;
after apply use Course Intent, including reconfirm_context/reconfirm_target/
reject_target via Course.updateProposalConfirmations. Missing/deleted Intent
returns proposal_intent_missing and never recreates it. Expected-pointer compare
and request UUID reject lost updates; AI appends its decision and Course updates
its state in one transaction. Rebase uses the same owner boundary.

For pending generation requests, an explicit authorized retry may claim the SAME
request/run UUID after revalidation; an active admin may instead cancel pending
as failed/proposal_generation_cancelled_before_execution. Both serialize under
Template -> request locks; cancellation only wins while pending and before any
provider claim. running is not assumed abandoned from a timeout. Controlled
recovery requires old worker stopped/no outstanding execution, checks the same
Model Run, and preserves actual usage. No retained provider-output store exists:
if run completed but no atomic item outcome was committed and live worker output
is lost, fail request as proposal_generation_output_unavailable after controlled
recovery. Never regenerate to reconstruct it or refund usage without evidence.
Human-successor persistence is one transaction and never has external execution.

Required tests: unchanged bytes with OCR/locale revision bump permits only the
decision projection and zero calls; one changed fingerprint among many sources
denies all; revoked copying actor/detach/deleted/erased denies; rationale/reference
canaries never leak; fresh sources and renewed accept required; stale receipt
cancellation; applied receipt pointer immutable; Course confirmation atomicity;
retired/inactive exclusion preview; map/remove_explicit/cancel_rebase rollback;
pending claim versus cancellation race; lost output named failure/no re-execution.
