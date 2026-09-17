# Table: ai_authoring_proposal_revisions

Version: 0.4

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-15

Document Path: database/ai/ai_authoring_proposal_revisions.md

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

Immutable AI original and human-edited versions belonging to one Proposal.
Human edits preserve original Model Run lineage through the parent.

Restricted source-revision successors copy only accepted decision fields through
the actor-authorized projection, never rationale, citations or confidence.
Human confidence is NULL; rationale may be empty or newly authored. Fresh source
references matching the new seal are required at accept. The predecessor payload
is not returned whole or modified. Creation must pass every v0.8 inheritance
condition; erasure blocks copying. No new provider execution is implied.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id, customer_id, proposal_id | BIGINT UNSIGNED NOT NULL | Identity, tenant and parent |
| revision_no | INT UNSIGNED NOT NULL | Monotonic within parent |
| origin | VARCHAR(32) NOT NULL | generated, human_successor or human |
| payload_schema_version | INT UNSIGNED NOT NULL | Validated kind-specific format |
| payload | JSON NULL | Review content; NULL only after controlled erasure |
| payload_hash | CHAR(64) NOT NULL | Immutable canonical payload SHA-256 |
| created_by | BIGINT UNSIGNED NOT NULL | Actual generating/editing actor |
| created_at | DATETIME(6) NOT NULL | UTC creation |
| erased_at | DATETIME(6) NULL | Erasure marker, not a normal edit |

## Indexes and constraints candidate

UNIQUE(customer_id, proposal_id, revision_no);
UNIQUE(id, customer_id, proposal_id) for same-parent references.
FK(proposal_id, customer_id) -> ai_authoring_proposals(id, customer_id) RESTRICT;
tenant-aware actor FK. CHECK revision_no>=1, payload_schema_version>=1, origin
vocabulary, and either payload present/erased_at NULL or payload NULL/erased_at
present. Semantic schema/hash matching is application-enforced.

Allocate revisions under parent lock. No ordinary update/delete API. Apply the
contract's BEFORE UPDATE/DELETE protection: only payload-to-NULL and erased_at
under a deletion_pending parent may change; every other column is immutable.
Test physical enforcement on both target engines; JSON validity is insufficient.

## Sample
## Constraint detail — proposed supplement

CHECK (revision_no >= 1 AND payload_schema_version >= 1);
CHECK (origin IN ('generated','human_successor','human'));
CHECK ((payload IS NOT NULL AND erased_at IS NULL)
 OR (payload IS NULL AND erased_at IS NOT NULL));

FK (proposal_id, customer_id) -> ai_authoring_proposals(id, customer_id);
FK (created_by, customer_id) -> users(id, customer_id); both RESTRICT.
Generated/human_successor is revision 1; edits use human at >1. Insert guard
also checks origin matches parent.creation_mode for revision 1. Enforced by
CHECK ((origin IN ('generated','human_successor') AND revision_no = 1)
 OR (origin = 'human' AND revision_no > 1)).
Content hash and identity stay immutable during payload erasure. No cascade
deletion from the aggregate. Parent has no reverse current-revision FK; highest
revision_no resolves current content. Parent and revision 1 commit atomically;
a parent missing its revision fails closed on every read or mutation.


Proposal 10 revision 1 generated; revision 2 human-edited. Accept references
revision 2. Neither provider output nor revision 1 is overwritten.
