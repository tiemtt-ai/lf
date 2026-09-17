# Table: ai_authoring_generation_requests

Version: 0.2

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-15

Document Path: database/ai/ai_authoring_generation_requests.md

## Schema implementation — 2026-09-15

The v0.8 migration packet is implemented and reconstructed on temporary
MariaDB 11.4.12 and 10.4.21 databases. This status describes schema only,
not the authoring services, live database apply, provider activation or Step 7
completion. Owner's scoped pre-migration review waiver is recorded in the
Authoring Proposal Contract; no independent PASS is claimed. Earlier
Not Implemented/no-DDL statements below are historical and superseded for
schema status. Physical metadata is harvested into LF-SCHEMA-CONTRACT.json.

## Authority and purpose

### Owner design closure — 2026-09-15

Owner decision: "chốt tài liệu, ko cần reivew quá nhiều". The current Step 7
contract v0.8, six AI table designs and their Course/Learning/ADR design
extensions are approved and Frozen. No additional design-review round is
scheduled by this closure. Earlier Review/pending-Freeze statements below are
historical and superseded for design status only. Implementation remains Not
Implemented. This records Owner approval, NOT reviewer PASS, physical DDL
verification, migration execution or live database/provider authorization.


Owner approved the P1-3 durable-request direction on 2026-09-14. This new table
is part of the revised [Step 7 contract](../../platform/LF-AI-Authoring-Proposal-Contract.md),
not the earlier five-table Freeze. No migration, physical verification or review
PASS is claimed. AI owns command identity/outcome, not quota or Course authority.

One request precedes zero or more proposals and at most one originating Model
Run. Human-successor requests create one item without invoking a provider.
Keeping zero-item and unresolved outcomes prevents duplicate paid executions.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id, customer_id | BIGINT UNSIGNED NOT NULL | Identity and tenant |
| request_uuid | CHAR(36) NOT NULL | Client command identity, scoped to tenant |
| command_hash | CHAR(64) NOT NULL | Immutable canonical inputs including actor/mode |
| mode | VARCHAR(32) NOT NULL | generated or human_successor |
| actor_id | BIGINT UNSIGNED NOT NULL | Actual requesting human |
| template_id, activity_id | BIGINT UNSIGNED NOT NULL | Historical Course owner IDs, validated through owner port |
| run_uuid | CHAR(36) NULL | Stable preallocated provider run identity, generated mode only |
| model_run_id | BIGINT UNSIGNED NULL | Exact bound run; NULL until gate creates it, always NULL for human successor |
| prompt_contract_id | VARCHAR(100) NULL | Code registry identity; generated mode only |
| prompt_version | INT UNSIGNED NULL | Pinned code template version |
| prompt_hash | CHAR(64) NULL | Canonical template/schema SHA-256, without sha256 prefix |
| status | VARCHAR(32) NOT NULL | pending/running/completed/failed |
| item_count | INT UNSIGNED NULL | Committed outcome count, including zero |
| error_code | VARCHAR(100) NULL | Sanitized local failure code |
| created_at, updated_at | DATETIME(6) NOT NULL | Explicit UTC timestamps |
| completed_at | DATETIME(6) NULL | Terminal completed/failed timestamp |

## Indexes and constraints

PRIMARY KEY(id); UNIQUE(id, customer_id); UNIQUE(customer_id, request_uuid);
UNIQUE(customer_id, run_uuid); INDEX(customer_id, status, id).
FK(customer_id) -> saas_customers(id), RESTRICT, matching the tenant target
used by the existing SaaS quota migration.
FK(actor_id, customer_id) -> users(id, customer_id), RESTRICT.
FK(model_run_id, customer_id) -> ai_model_runs(id, customer_id), RESTRICT.
Course IDs use the contract's historical-reference exception, not access rights.

CHECK (mode IN ('generated','human_successor'));
CHECK (status IN ('pending','running','completed','failed'));
CHECK ((mode = 'generated' AND run_uuid IS NOT NULL AND prompt_contract_id IS NOT NULL
 AND prompt_version IS NOT NULL AND prompt_version >= 1 AND prompt_hash IS NOT NULL)
 OR (mode = 'human_successor' AND run_uuid IS NULL AND model_run_id IS NULL
 AND prompt_contract_id IS NULL AND prompt_version IS NULL AND prompt_hash IS NULL));
CHECK ((status = 'completed' AND item_count IS NOT NULL AND item_count <= 100)
 OR (status <> 'completed' AND item_count IS NULL));
CHECK (mode <> 'human_successor' OR status <> 'completed' OR item_count = 1);
CHECK (mode <> 'generated' OR status <> 'completed' OR model_run_id IS NOT NULL);
CHECK ((status = 'failed' AND error_code IS NOT NULL)
 OR (status <> 'failed' AND error_code IS NULL));
CHECK ((status IN ('completed','failed') AND completed_at IS NOT NULL)
 OR (status IN ('pending','running') AND completed_at IS NULL));

All nullable CHECK branches are explicit; service verifies referenced run UUID,
prompt/version/hash and completed provider status, not merely FK existence.

## Transactions, immutability and recovery

Authorized caller inserts pending row and commits before provider gate. Losing
UNIQUE insert reads the winner under tenant scope, compares command_hash and
returns its current outcome without execution. Conditional locked pending ->
running claim admits one worker. The stable run_uuid is passed into the existing
gate. No network call is inside the claim transaction. running -> completed or
failed only; pending -> failed for safe pre-execution failure. Terminal outcomes
never reset, including completed zero-item results. A new attempt needs new UUID.

Request completion and every generated proposal/revision/source seal are one
transaction; count must equal committed items for this request. A trigger/service
completion check verifies count and sealed parents; success is never inferred
from Model Run status alone. There is no durable provider-output store. If a completed run has no committed
item outcome and live output is lost, controlled recovery marks the request
failed with proposal_generation_output_unavailable; never call provider again.
Unknown execution remains running until controlled recovery establishes outcome;
elapsed time is not proof of no usage. Quota settlement remains Commercial-owned.

Identity, actor, mode, historical Course IDs, prompt and run_uuid are immutable.
model_run_id binds once from NULL to its matching run. Only lifecycle/count/error/
completion timestamps may advance under state rules. BEFORE UPDATE/DELETE
protection blocks identity mutation, terminal reset and deletion. No payload,
source excerpt, rendered prompt or raw error is stored here, so normal Proposal
erasure preserves this content-free idempotency record. No automatic TTL deletion.

Human-successor pending claim, item creation/seal and completed count=1 share
one transaction. Its proposal inherits the predecessor's model_run_id; the
request model_run_id stays NULL to prove this command did not execute a model.

## Verification and sample

Two connections with the same UUID yield one request and one originating run;
changed requested kinds conflict before run completion. A completed request with
item_count=0 replays zero proposals, not a new provider call. All replay paths
recheck current Course/Media authority before returning any item content.
Test wrong-tenant run, mismatched prompt, crash before/after claim, atomic item
rollback, immutable identity and terminal reset rejection on both MariaDB engines.

The parent request key is (customer_id, request_uuid); proposal FK uses that same
order. This request table is created before proposals. Backward successor revision FKs
are added after revisions exist. down() preflights the entire six-table packet
and affected Course references before removing ANY FK/trigger/table; rows refuse
rollback. No physical DDL has been run for this design.

## Pending and successor recovery — 2026-09-15

A pending request can be explicitly resumed by its currently authorized actor
using the same UUID/hash, or cancelled before claim by active tenant admin with
proposal_generation_cancelled_before_execution. Template -> request lock order
serializes claim/cancel. Claim authorization is checked again, not inherited
from the first request. If already running, cancellation must not assume no call.

All multi-owner work follows Templates -> request -> proposals including
predecessor -> applications -> Learning Versions -> Framework -> Nodes, sorted
within each collection. Initial request registration holds no other locks and
commits separately; successor's claim/parent/source seal/outcome share one
transaction in that order. No request-first-then-Template path is permitted.

Restricted inheritance inputs include predecessor revision, source_revision_changed
reason, inherited-decision flag, exact original/current file fingerprints and
revision selectors, decision projection hash and newly selected current anchors.
Replay rechecks copying actor and every source before returning draft content.
No old rationale, references or excerpts are stored in request metadata.
