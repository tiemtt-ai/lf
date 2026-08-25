# AI Foundation Media-Consumer Database Architecture Review

Version: 1.0

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-25

Review Date: 2026-08-25

Document Path: quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | AI × Media |
| Parent ADR | [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md) v1.0 — Approved, Frozen |
| Constraining ADR | [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved |
| Consumer Contract | [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) v1.4 |
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
Version 1.0 only. The Learning Mastery Profile provenance rule marked
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
- [ ] **Media→AI stale propagation has no documented mechanism.** See F-4.

# B — Tenant Isolation

- [x] Every reviewed table carries `customer_id NOT NULL`.
- [x] Every documented unique key and index is `customer_id`-leading.
- [ ] **No table declares `UNIQUE (id, customer_id)`, and every documented
      relationship is single-column.** See F-2.

# C — Read Contract Conformance

- [ ] **`ai_knowledge_sources` cannot store the two values the Read Contract
      requires per registered unit.** See F-1.
- [ ] **`ai_knowledge_chunks.source_locator` re-encodes the locator in a third,
      incompatible shape.** See F-3.
- [x] AI is consumer-only: no reviewed table grants a write path into `media_*`.

# D — Lifecycle And Revision

- [x] Source/chunk/embedding status vocabularies each include a stale state.
- [x] Media revision archiving now exists in runtime
      (`ProcessMediaProcessingJob::archiveSupersededRevisions`), so a detection
      point for staleness is available.
- [ ] Nothing sets `ai_knowledge_sources.status = 'stale'`. See F-4.

# E — Retention, Deletion And PII

- [x] No reviewed table stores a provider credential or BYOK secret.
- [ ] **`ai_embeddings` deletion synchronization is an open Foundation question**
      while ADR-0018 requires retention/deletion coverage across the provenance
      chain including AI-derived chunk/embedding. See F-6.

# F — Findings

## F-1 — BLOCKER — `ai_knowledge_sources` cannot express a derived content unit

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

## F-2 — BLOCKER — No tenant composite identity or composite foreign key

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

## F-3 — HIGH — Locator contract divergence in `ai_knowledge_chunks`

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

## F-4 — HIGH — Stale propagation has no trigger

The Read Contract assigns the split correctly — *"Media báo trạng thái, AI quyết
định"* — but no document says how AI observes the state change. Nothing sets
`ai_knowledge_sources.status = 'stale'`.

The runtime now has the detection point: when a new revision reaches `ready`,
the prior revision flips to `archived`. A registered source holding the old
`processing_version` is detectably stale from that moment. This needs to be
written down as a contract obligation on one side before either side implements
a poll or a signal.

## F-5 — MEDIUM — `ai_model_runs` subset has unresolvable foreign keys

`assistant_session_id` and `prompt_template_id` reference two tables outside
this subset and not implemented. A subset migration must either leave them
nullable and unconstrained — departing from the repo's FK discipline — or defer
`ai_model_runs` until `ai_assistant_sessions` and `ai_prompt_templates` land.

Recommendation: implement `ai_model_runs` with the columns documented but add
the two foreign keys in the migration that creates their targets, and record
that deferral in the table doc so it is not read as an omission.

## F-6 — MEDIUM — `ai_embeddings` collides with the ADR-0018 production gate

`ai_embeddings.md` Design Notes: *"Vector-store selection, data residency and
deletion synchronization remain open Foundation questions."* ADR-0018 requires
retention/deletion to cover the provenance chain **including** AI-derived
chunk/embedding, and names the missing implementation as the reason
production/real-tenant rollout stays gated (Read Contract risk B1).

Creating the table is not blocked by this. Populating it in a real tenant is.

## F-7 — MEDIUM — Vector store implies a platform the stack does not run

`ai_embeddings.md` sample data uses `vector_store=pgvector`, a PostgreSQL
extension; LF runs MySQL/MariaDB. `vector_index` is described as a "tenant-safe
logical index" with tenant-scoped retrieval over a possibly shared index, which
is an adapter contract that does not exist.

The table stores only references, so the schema itself is platform-neutral and
implementable. Actually embedding anything requires a vector-store decision of
the same class as a Tech Stack amendment. This must not be discovered after the
migration ships.

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

Two blockers (F-1, F-2) are schema-shaping and must be closed **in the database
documents** before any migration is written, per the Database Rule in
`AGENTS.md`. F-3 and F-4 are contract obligations that decide column shape and
should be closed in the same pass. F-5 through F-7 are recorded constraints that
do not block table creation.

# H — Owner Actions

Status 2026-08-25: drafts for actions 1–5 are written into the four table docs as
`## Amendment — Proposed 2026-08-25` sections. They are **proposals pending Owner
approval**; no migration may be written from them until that approval and an
independent review exist.

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
