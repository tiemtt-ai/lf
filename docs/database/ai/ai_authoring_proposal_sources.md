# Table: ai_authoring_proposal_sources

Version: 0.4

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-09-15

Document Path: database/ai/ai_authoring_proposal_sources.md

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

Immutable Media anchors supporting a generated Proposal; multiple units and files
are supported. Anchors confer no access rights. Media Read is rechecked.

Restricted inherited drafts use newly read current anchors only. For each old
source the copying actor must pass currentRevision with active usage and identical
media_file_id/source_fingerprint; only processing/locale revision may differ.
Old locators/excerpts/references are not copied. Missing/changed/denied source
refuses the entire inherited projection; no zero-source exception is introduced.

BEFORE INSERT locks the parent and requires pending_review plus an unset source
seal. Initial transaction inserts all anchors then seals the parent. No later
append is allowed, even before acceptance. Updates never change anchors and
DELETE stays forbidden; content-only erasure follows the existing exception.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id, customer_id, proposal_id, media_file_id | BIGINT UNSIGNED NOT NULL | Identity/tenant/parent/Media |
| source_ordinal | INT UNSIGNED NOT NULL | Stable anchor order |
| usage_type, content_type | VARCHAR(32) NOT NULL | Pair from Media Read contract |
| locale | VARCHAR(20) NULL | Exact unit locale, NULL is meaningful |
| source_fingerprint | CHAR(64) NOT NULL | Source bytes identity |
| processing_version | VARCHAR(100) NOT NULL | Exact extraction version |
| locator | JSON NOT NULL | Typed Media locator, including page/time where applicable |
| anchor_hash | CHAR(64) NOT NULL | Canonical full anchor identity incl NULL locale |
| excerpt | TEXT NULL | Optional bounded source snippet; never an authorization cache |
| created_at | DATETIME(6) NOT NULL | UTC snapshot |
| erased_at | DATETIME(6) NULL | Optional snippet erased |

## Indexes and constraints candidate

UNIQUE(customer_id, proposal_id, source_ordinal);
UNIQUE(customer_id, proposal_id, anchor_hash);
INDEX(customer_id, media_file_id, proposal_id).
Parent FK composite RESTRICT; Media FK(media_file_id, customer_id) ->
media_files(id, customer_id) RESTRICT. Exact content/usage pair CHECK and
locator schema follow Media Read §3/§5: extracted_text page/sheet, transcript
timespan, region/formula region, table region/sheet, video_frame_text timespan
with frame/bbox structure. Consume only fields actually returned by Media Read;
never invent page from region order or query Media tables to fill it.
Do not dedupe by nullable locale columns; use canonical anchor identity.
A snippet may be absent from creation; erased_at implies excerpt IS NULL.

Optional knowledge/vector citation extensions are outside this v0.1 shape:
do not write a chunk ID into locator and claim validated relational provenance.
The actual selected Media anchor remains required.

## Sample

Source anchor identity and locator are immutable. Apply the contract's trigger
rule: only excerpt-to-NULL and erased_at may change under a deletion_pending
parent, without disabling triggers. Locator must contain no source text or URL.
## Constraint detail — proposed supplement

CHECK (source_ordinal >= 1);
CHECK (erased_at IS NULL OR excerpt IS NULL);
CHECK (
 (usage_type = 'document' AND content_type IN ('extracted_text','region','table','formula'))
 OR (usage_type IN ('audio','video') AND content_type = 'transcript')
 OR (usage_type = 'video' AND content_type = 'video_frame_text')
);

All usage/content fields NOT NULL. This packet supports these observed sources
only; other Media content types need a later amendment. Source locator JSON is
a typed citation, not raw external URLs. Anchor hash includes media_file_id,
usage/content, locale (including NULL), fingerprint, processing_version and
canonical locator. Matching hashes with different anchors are conflicts,
not silently reused rows.

FK (media_file_id, customer_id) -> media_files(id, customer_id) RESTRICT.
FK (proposal_id, customer_id) -> ai_authoring_proposals(id, customer_id) RESTRICT.


Proposal 10 cites a typed document region locator and transcript interval in a second
file. Deleting either source blocks application until a fresh proposal/review;
old hashes remain for audit, snippets are erased under the approved policy.
