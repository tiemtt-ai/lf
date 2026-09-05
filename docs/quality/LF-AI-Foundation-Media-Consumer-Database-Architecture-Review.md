# AI Foundation Media-Consumer Database Architecture Review

Version: 1.2

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-09-05

Review Date: 2026-08-25

Document Path: quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md

---

# Retrieval-policy amendment review — 2026-09-05

Owner-approved ADR-0006 v1.0.1 assigns ranking exclusively to AI and leaves
Media evidence/order immutable. The policy is implementable from existing Media
Read fields and needs no Media schema or migration. It explicitly preserves
Jamo paragraph evidence, formula-region fallback, table `undetermined`, image
crop and per-unit citations.

**Scoped verdict: PASS for contract boundary.** Overall database verdict below
remains `CHANGES REQUIRED`; this amendment does not authorize AI migrations,
embedding population or production retrieval before F-1–F-7 are resolved.

Database-doc follow-up records that F-1–F-5 already have Owner-approved
amendments dated 2026-08-25. The 2026-09-05 amendment additionally aligns
`ai_knowledge_sources.content_type` with `region|table|formula`, aligns chunk
locator vocabulary with `region|sheet`, requires one Media unit per chunk, and
adds immutable snapshots `source_role`, `source_quality_status`,
`language_evidence`. This closes the schema-shape gap introduced by the newer
Media Read contract without weakening tenant or citation identity.

Migration remains unauthorized for two independent reasons: this document is
still not an independent rerun, and F-6/F-7 still require an approved retention
deletion mechanism plus a vector-store/adapter decision. Lexical-only fallback
or a specific vector product must not be invented inside a migration.

---

# F-6/F-7 closure — Owner decision 2026-09-05

F-6 closed by the relational-first `deletion_pending` state machine, exact UUID
+ tenant-filter remote delete, acknowledgment, retry/reconciliation and parent
purge barrier. F-7 closed by Qdrant self-hosted >=1.11 inside the LF-managed
boundary; MariaDB 11.4 remains relational only. Point payload excludes raw text,
PII and signed URLs; every vector hit is post-validated against relational and
Media authorization/revision state.

These decisions close the two architecture questions. **Migration remains NO**
until an independent reviewer reruns the full revised database packet and gives
a PASS; Owner approval cannot substitute for that separate gate.

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | AI × Media |
| Parent ADR | [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md) v1.0.1 — Approved, Frozen |
| Constraining ADR | [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved |
| Consumer Contract | [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) v1.19 |
| Producer Contract | [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) |
| Review Scope | 4 tables on the Media→AI consumer path: `ai_knowledge_sources`, `ai_knowledge_chunks`, `ai_embeddings`, `ai_model_runs` |
| Out Of Scope | `ai_assistant_sessions`, `ai_conversations`, `ai_messages`, `ai_prompt_templates`, `ai_feedback`, `ai_insights`, `ai_recommendations` |

Reason for the subset: LF-Media-Read-Contract § 7 makes AI a registered
consumer of derived content units. These four tables are the entire path from a
Read Service unit to a retrievable embedding. The remaining seven `ai_*` tables
serve conversation and authoring flows that no implemented code reaches today.

Review provenance: this is an **author self-assessment** produced in the same
stream as the Media Read runtime, not an independent Architecture Review. The
checkboxes are a review packet for the next reviewer; they do not by themselves
produce an Approved verdict.

Amendment boundary: [ADR-0006 Amendment Version 1.1](../adr/ADR-0006-AI-Foundation.md)
is **Proposed — pending Architecture Owner approval**. Everything below reviews
the Version 1.0 database shape only; v1.0.1 adds no table or column. The Learning Mastery Profile provenance rule marked
*"Proposed, chưa có hiệu lực"* in `ai_model_runs.md`, `ai_insights.md` and
`ai_recommendations.md` must not be encoded by any migration produced from this
review.

---

# A — Domain Boundary

- [x] AI owns registration, chunking, embedding and run provenance; it owns none
      of the source content.
- [x] `ai_knowledge_sources` registers a reference, never a copy of Course,
      Assessment, Media, Track or LiveClass ownership.
- [x] Chunks and embeddings are declared derived and rebuildable; neither is a
      Source Of Truth.
- [x] **Media→AI stale propagation assigned to AI.** Closed by approved
      `ai_knowledge_sources` amendment: Media exposes revision state; AI marks
      source/chunk/embedding stale and rebuilds.

# B — Tenant Isolation

- [x] Every reviewed table carries `customer_id NOT NULL`.
- [x] Every documented unique key and index is `customer_id`-leading.
- [x] `UNIQUE (id, customer_id)` and composite child FKs are documented by the
      approved 2026-08-25 amendments. F-2 closed.

# C — Read Contract Conformance

- [x] `ai_knowledge_sources` stores fingerprint/version and current textual
      content types. F-1 closed.
- [x] Chunk locator uses Media vocabulary and one unit per Media chunk. F-3
      closed; the 2026-09-05 amendment adds `region|sheet`.
- [x] AI is consumer-only: no reviewed table grants a write path into `media_*`.

# D — Lifecycle And Revision

- [x] Source/chunk/embedding status vocabularies each include a stale state.
- [x] Media revision archiving now exists in runtime
      (`ProcessMediaProcessingJob::archiveSupersededRevisions`), so a detection
      point for staleness is available.
- [x] Contract assigns stale detection/rebuild to AI. Runtime remains pending
      AI Foundation implementation; this is no longer a schema ambiguity.

# E — Retention, Deletion And PII

- [x] No reviewed table stores a provider credential or BYOK secret.
- [x] Qdrant deletion synchronization is frozen by ADR-0006 v1.0.2 and
      ADR-0018 v1.1. F-6 closed; implementation evidence remains future work.

# F — Findings

## F-1 — CLOSED 2026-08-25 — `ai_knowledge_sources` derived content identity

LF-Media-Read-Contract § 7:

> `ai_knowledge_sources` đăng ký theo derived content unit, không theo Media
> File, và lưu `source_fingerprint` cùng `processing_version` của unit đã đọc.

The documented table has neither column. Two mismatches follow:

1. **No stale detection input.** `content_hash` and `source_version` are
   AI-authored fields, not the Media values. Mapping them onto
   `source_fingerprint` / `processing_version` is an undocumented guess, and a
   guess here silently breaks the only mechanism that tells AI its chunks are
   built from a superseded revision.
2. **The `source_type` vocabulary cannot name three of the four content
   types.** Allowed values include `media_file` and `media_transcript`; the Read
   Service returns `extracted_text`, `transcript`, `caption_asset` and
   `variant`. `media_file` also contradicts the contract's explicit "không theo
   Media File".

Required before migration: amend `ai_knowledge_sources.md` to carry the unit
identity — at minimum `source_fingerprint` and `processing_version`, and a
`source_type` vocabulary aligned with the four Read Contract content types.

## F-2 — CLOSED 2026-08-25 — Tenant composite identity and foreign keys

The two most recent Foundation migrations
(`2026_08_24_000000_create_media_processing_substrate`,
`2026_08_23_120000_add_course_template_learning_mapping_intents`) both give each
table `UNIQUE (id, customer_id)` and reference parents by
`(parent_id, customer_id)`, so a cross-tenant reference is rejected by the
database rather than by application code.

The four AI docs declare single-column relationships only
(`knowledge_source_id`, `knowledge_chunk_id`, `media_file_id`, `user_id`,
`prompt_template_id`) and no `UNIQUE (id, customer_id)`. Implemented as
documented, a chunk in tenant A can reference a source in tenant B and nothing
at the schema level prevents it. Guardrails § "Mọi dữ liệu nghiệp vụ phải thuộc
một tenant" makes this an isolation defect, not a style preference.

Required before migration: an explicit decision recorded in the four table
docs — adopt the composite pattern, or document why AI Foundation departs from
it.

## F-3 — CLOSED 2026-09-05 — Chunk locator contract

LF-Media-Processing-Contract § 4 freezes one locator shape for every output:

```text
locator := { locator_type, locator_value }   // page | timespan, value always text
```

`ai_knowledge_chunks.source_locator` is free-form JSON and the doc's own sample
is `{"start_second":0,"end_second":45}` — a third shape, numeric, incompatible
with both `page` and `timespan`. A citation stored this way cannot be joined
back to the `media_extracted_texts` / `media_transcripts` row it came from, which
is the entire purpose of the locator contract.

Required before migration: constrain `source_locator` to the frozen locator
contract, or state in the doc why a chunk-level locator is a distinct vocabulary
and how it maps back.

## F-4 — CLOSED 2026-08-25 — Stale propagation ownership

The Read Contract assigns the split correctly — *"Media báo trạng thái, AI quyết
định"* — but no document says how AI observes the state change. Nothing sets
`ai_knowledge_sources.status = 'stale'`.

The runtime now has the detection point: when a new revision reaches `ready`,
the prior revision flips to `archived`. A registered source holding the old
`processing_version` is detectably stale from that moment. This needs to be
written down as a contract obligation on one side before either side implements
a poll or a signal.

## F-5 — CLOSED 2026-08-25 — Deferred `ai_model_runs` foreign keys

`assistant_session_id` and `prompt_template_id` reference two tables outside
this subset and not implemented. A subset migration must either leave them
nullable and unconstrained — departing from the repo's FK discipline — or defer
`ai_model_runs` until `ai_assistant_sessions` and `ai_prompt_templates` land.

Recommendation: implement `ai_model_runs` with the columns documented but add
the two foreign keys in the migration that creates their targets, and record
that deferral in the table doc so it is not read as an omission.

## F-6 — CLOSED 2026-09-05 — Embedding deletion synchronization

Resolved by ADR-0006 v1.0.2, ADR-0018 v1.1 and the `ai_embeddings` amendment:
MariaDB status moves to `deletion_pending` before remote work; retrieval accepts
only `ready`; exact UUID + tenant-filter deletion must be acknowledged before
`deleted`; failure retries/reconciles and blocks parent hard purge.

## F-7 — CLOSED 2026-09-05 — Vector adapter selection

Resolved by ADR-0006 v1.0.2 and LF-Tech-Stack v1.3: Qdrant self-hosted >=1.11
inside the LF-managed boundary, shared collections partitioned by indexed
`customer_id`, and mandatory tenant filter on every operation. MariaDB 11.4
remains relational only; Qdrant Cloud and external stores remain unapproved.

---

# G — Verdict

```text
AI Foundation — Media Consumer Subset

Database Architecture Review

Status

CHANGES REQUIRED

Migration authorized

NO
```

F-1–F-7 are closed in approved ADR/database/tech contracts. Migration remains
unauthorized because this packet still requires an independent architecture
rerun before Freeze under `AGENTS.md`.

# H — Owner Actions

Status 2026-09-05: actions 1–5 and F-6/F-7 are Owner-approved. Action 6—the
independent rerun—remains open; no migration is authorized.

1. Amend `ai_knowledge_sources.md`: add `source_fingerprint` and
   `processing_version`; align `source_type` with the four Read Contract content
   types; remove or justify `media_file_id` against "không theo Media File".
2. Decide the tenant composite identity/FK question for AI Foundation and record
   it in all four table docs.
3. Constrain `ai_knowledge_chunks.source_locator` to the frozen locator
   contract, or document the mapping back to it.
4. Assign the stale-propagation obligation to Media or AI in writing.
5. Decide whether `ai_model_runs` ships in this subset with deferred foreign
   keys, or waits for its reference tables.
6. Confirm this review is re-run as an independent review before Freeze; the
   present document is an author self-assessment.

---

## Owner

Architecture Team
