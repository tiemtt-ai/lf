# Table: ai_authoring_proposals

Version: 0.5

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-15

Document Path: database/ai/ai_authoring_proposals.md

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

AI-owned review aggregate for one generated or human-successor item. One Model Run may produce many
proposals; each has many immutable revisions, sources, reviews and applications.
Canonical Learning content is never owned here.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id, customer_id | BIGINT UNSIGNED NOT NULL | Identity and tenant |
| proposal_uuid, generation_request_uuid | CHAR(36) NOT NULL | Public identity and generation attempt |
| item_ordinal | INT UNSIGNED NOT NULL | Position in bounded generated batch |
| template_id, activity_id | BIGINT UNSIGNED NOT NULL | Working context, never canonical source |
| model_run_id | BIGINT UNSIGNED NOT NULL | Completed originating AI run; inherited lineage, not a new call, for human successor |
| creation_mode | VARCHAR(32) NOT NULL | generated or human_successor |
| framework_id, framework_version_id | BIGINT UNSIGNED NULL | Explicit optional basis; paired |
| basis_hash | CHAR(64) NULL | Original Learning content hash; present exactly when Framework basis is present |
| kind | VARCHAR(32) NOT NULL | summary/concept/learning_objective/competency/node_mapping |
| context_schema_version | VARCHAR(50) NOT NULL | Canonical hash contract |
| context_hash | CHAR(64) NOT NULL | Original combined context fingerprint |
| course_context_hash | CHAR(64) NOT NULL | Original Course-only DTO hash, separately reconfirmable |
| sources_sealed_at | DATETIME(6) NULL | One-way source-set seal before generation commit |
| source_count | INT UNSIGNED NULL | Count captured on seal, positive |
| source_set_hash | CHAR(64) NULL | Canonical ordered source-set SHA-256 captured on seal |
| supersedes_proposal_id | BIGINT UNSIGNED NULL | Optional correction lineage, same tenant |
| successor_reason | VARCHAR(64) NULL | Human successor reason; source_revision_changed for restricted inheritance |
| inherited_decision_draft | BOOLEAN NOT NULL DEFAULT FALSE | Unapproved inherited decision with changed source revision; must be exposed to reviewer |
| predecessor_revision_id | BIGINT UNSIGNED NULL | Exact previously accepted revision for human successor |
| status | VARCHAR(32) NOT NULL | pending_review/accepted/rejected/stale/deletion_pending/deleted |
| lock_version | INT UNSIGNED NOT NULL DEFAULT 1 | Optimistic request version under row lock |
| created_by | BIGINT UNSIGNED NOT NULL | Generating actor |
| created_at, updated_at | DATETIME(6) NOT NULL | Explicit UTC timestamps |
| deletion_requested_at, deleted_at | DATETIME(6) NULL | Erasure lifecycle |

## Indexes and constraints candidate

UNIQUE(customer_id, proposal_uuid); UNIQUE(customer_id, generation_request_uuid,
item_ordinal); INDEX(customer_id, template_id, status, id).
FK(customer_id, generation_request_uuid) ->
ai_authoring_generation_requests(customer_id, request_uuid) RESTRICT.
creation_mode must match request.mode under the locked completion transaction.
Model Run FK (model_run_id, customer_id) -> ai_model_runs(id, customer_id)
RESTRICT. Actor FK is tenant-aware. Framework Version FK includes framework_id.
Framework fields both NULL or both present; competency/node_mapping require both.
item_ordinal and lock_version >=1; status/kind CHECKs use only listed values.

No current_revision_id pointer is stored. Current revision is the highest
revision_no within this proposal and tenant, read under parent lock for writes.
Create parent, revision 1 and anchors in one transaction; a parent without a
revision is never exposed or accepted. Completeness is service-enforced, not a
cross-table CHECK. supersedes_proposal_id has a same-tenant self FK, RESTRICT;
set it only at creation to an existing earlier proposal and never mutate it.
Working Course IDs use the historical-reference policy in the contract.

## Constraint detail — proposed supplement

CHECK (inherited_decision_draft IN (0,1));
CHECK ((creation_mode = 'generated' AND successor_reason IS NULL AND inherited_decision_draft = 0)
 OR (creation_mode = 'human_successor' AND successor_reason IS NOT NULL
 AND successor_reason IN ('source_revision_changed','context_changed','target_changed','intent_removed','human_correction')));
CHECK ((successor_reason = 'source_revision_changed' AND inherited_decision_draft = 1)
 OR ((successor_reason IS NULL OR successor_reason <> 'source_revision_changed') AND inherited_decision_draft = 0));
CHECK (creation_mode IN ('generated','human_successor'));
CHECK ((creation_mode = 'human_successor' AND supersedes_proposal_id IS NOT NULL AND predecessor_revision_id IS NOT NULL)
 OR (creation_mode = 'generated' AND predecessor_revision_id IS NULL));
FK(predecessor_revision_id, customer_id, supersedes_proposal_id) ->
ai_authoring_proposal_revisions(id, customer_id, proposal_id) RESTRICT.
Add this nullable backward-lineage FK after both tables exist. It never points
to this proposal's own revision: service/insert guard verifies an older parent's
revision and its historical accept decision. No current-revision cycle is added.
CHECK (item_ordinal BETWEEN 1 AND 100);
CHECK (lock_version >= 1);
CHECK ((sources_sealed_at IS NULL AND source_count IS NULL AND source_set_hash IS NULL)
 OR (sources_sealed_at IS NOT NULL AND source_count IS NOT NULL AND source_count > 0 AND source_set_hash IS NOT NULL));
CHECK (status = 'pending_review' OR sources_sealed_at IS NOT NULL);
CHECK (kind IN ('summary','concept','learning_objective','competency','node_mapping'));
CHECK (status IN ('pending_review','accepted','rejected','stale','deletion_pending','deleted'));
CHECK ((framework_id IS NULL AND framework_version_id IS NULL)
 OR (framework_id IS NOT NULL AND framework_version_id IS NOT NULL));
CHECK ((framework_id IS NULL AND basis_hash IS NULL)
 OR (framework_id IS NOT NULL AND basis_hash IS NOT NULL));
CHECK (kind NOT IN ('competency','node_mapping')
 OR (framework_id IS NOT NULL AND framework_version_id IS NOT NULL));
CHECK ((status IN ('deletion_pending','deleted') AND deletion_requested_at IS NOT NULL)
 OR (status NOT IN ('deletion_pending','deleted') AND deletion_requested_at IS NULL));
CHECK ((status = 'deleted' AND deleted_at IS NOT NULL)
 OR (status <> 'deleted' AND deleted_at IS NULL));

Working template/activity references follow the historical-reference proposal
in the contract; no new Course RESTRICT key is silently assumed.


## Lifecycle and sample

Unsealed parent is a transaction-internal construction state, never readable or
reviewable. Parent/revision/sources/seal commit together. Source insertion locks
the parent and is physically rejected after seal; seal fields cannot be reset or
changed. Trigger requires COUNT(same-tenant sources) = NEW.source_count AND NEW.source_count > 0 when sealing; service computes/verifies
set hash. Tests must cover rollback and insert-after-seal while pending_review.

Edit appends revision while pending_review. Accept/reject locks and records exact
revision; subsequent corrections use a successor proposal. Stale is not accepted
work waiting for retry. Deleted is terminal. Payload lives only in revisions,
not a duplicate mutable JSON on this aggregate.

Example: tenant 1, activity 40, kind node_mapping, revision 2 accepted by its
teacher; application awaits administrator approval. No canonical Node exists yet.

## Restricted inheritance audit — 2026-09-15

These new fields are immutable parent provenance. source_revision_changed means
only processing/locale revision changed with every original media_file_id and
source_fingerprint unchanged, usage active, and currentRevision authorized for
the copying actor. It never labels changed bytes as the same source. Other
human corrections use their explicit reason and do not acquire this exception.

The successor command returns only the contract's decision-field allowlist,
not old rationale/references/confidence. New sources/seal and fresh acceptance
are mandatory; no deleted/erased predecessor content is exposed. create_node
result must be valid before normalizing propose_new to reuse_existing, otherwise
conflict. No inherited creation of a second Node. No change to Media policy.
