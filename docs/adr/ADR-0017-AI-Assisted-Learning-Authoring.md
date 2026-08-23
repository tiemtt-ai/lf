# ADR-0017 — AI-Assisted Learning Authoring

Version: 1.0

Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-08-22

Approval Date: 2026-08-16

Approved By: LearnForge Architecture Owner

Proposal Date: 2026-08-16

Document Path: adr/ADR-0017-AI-Assisted-Learning-Authoring.md

Related ADRs:

* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md)
* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md)
* [ADR-0016 — Learning Foundation](ADR-0016-Learning-Foundation.md)

---

# Context

Course authoring attaches documents, video and audio to an Activity. Media is
the approved owner of OCR, transcript and caption output, and this flow requires
that derived content to be ready before AI analysis begins. The processing
tables/jobs/workers are not implemented yet; this statement defines the required
input boundary, not a claim that uploaded material is currently machine-readable.

`LearningFrameworkAuthoringService` now creates a Framework, a draft Version, a
Node Definition and a Node, and publishes the Version for authorized internal
callers. That closes the owner-service write gap, but it is not yet a usable
manual authoring surface: the repository has request contracts and the internal
service, with no Learning route, controller or UI. The required manual fallback
must call this same owner service and use the same Framework → Framework Version
→ Stable Node Definition → Versioned Node foundation; it is not a separate data
path. For a catalogue of any size, manual entry is not the intended default and
would also waste derived Media output.

The opposite extreme is worse. Letting AI write Nodes or Mappings directly
crosses the domain boundary ADR-0006 draws — AI is a Consumer Domain and owns no
business state — and it assumes a file determines pedagogical meaning, which it
does not. The same video is a teaching asset in one course, practice material in
another and an assessment stimulus in a third. Only the Activity supplies that
purpose.

This ADR decides the boundary and the flow. It decides no table, migration, API,
prompt or screen.

---

# Decision

AI-assisted authoring is the default authoring flow. A manual Node/Mapping
surface is required as fallback and as the Framework administration tool, not
as the normal path. It must use the same Learning owner services and canonical
Framework/Definition/Node foundation. This requirement is approved policy; the
external manual surface is not implemented yet.

AI produces an **Authoring Proposal** and nothing else. A Proposal is not a
Node, a Framework Version, a Mapping, Evidence or Mastery. A human accepts,
edits or rejects it, and only then does the owning Domain's own service write
anything.

```text
Working Course Activity + authorized Media usage
  -> Media processing: OCR / transcript / caption
  -> AI Authoring Proposal (reuse-ranked, with confidence and rationale)
  -> human review: accept / edit / reject, in bulk
  -> Learning owner service writes Node into a draft_snapshot Version
  -> Course publish + Framework Version publish
  -> canonical Mapping materialized against exact published identities
```

---

# Domain Responsibility

| Domain | Owns |
| --- | --- |
| Media | File, processing state, OCR, transcript, caption, Usage |
| Course | Working Activity, pedagogical purpose, published Version Activity |
| AI | Proposal, confidence, rationale, prompt and Model Run provenance |
| Learning | Framework, Node Definition, Version Node, canonical Mapping |
| Human reviewer | Accept, edit, reject; the decision to publish |

No Domain writes into another Domain's tables to shorten this path.

---

# Canonical Decisions

## A1 — Media is input, never learning authority

A Media file does not determine a Node. AI must read the Media output *and* the
Activity title, instructions and type, the Course audience and level where
present, the Activity's position in the authoring tree, and the selected
Framework.

Without a selected Framework, AI may only extract, summarize and list topics. It
may not propose a Node or a Mapping, because it has nothing to reuse against.

## A2 — No direct Media-to-Node mapping

Canonical lineage stays:

```text
media_files -> media_file_usages -> Working Activity
  -> published Course Version Activity -> core_learning_node_mappings -> Node
```

`core_learning_node_mappings.source_type` already restricts the source to
`course_version_lesson` or `course_version_activity`, so a Media identifier can
never be the canonical source. A direct Media-to-Node link may exist as AI
retrieval metadata; it is not business state.

## A3 — Suggestion-only AI

AI may automate: transcript and OCR, summary, concept and topic extraction,
candidate matching against existing Definitions and Nodes, proposing a new Node
where nothing matches, and proposing criteria, mapping role, weight and
confidence.

AI may not: publish a Framework Version or Course Version, write a Node or
Mapping outside the owning service, write Evidence, Calculation or Profile, or
change Progress, Result, Attendance or Enrollment.

Automation confidence degrades down this list, and the document should not
pretend otherwise:

| Output | Automatable |
| --- | --- |
| Transcript, OCR | Yes |
| Summary | Yes |
| Concepts, topics | Yes, with review |
| Learning objective | Needs Course and Activity context |
| Competency | Needs the Framework and its assessment criteria |
| Mapping role and weight | Proposed only; a human decides |
| Publish | Never |

Competency is the sharp case. A document can discuss a topic without the course
intending learners to become competent in it. Inferring competency from content
alone produces a Framework that describes the material rather than the goal.

## A4 — Reuse before create

AI must search and rank existing Definitions and Nodes in the same tenant and
Framework before proposing a new one, and must tell the reviewer whether a
candidate is `reuse_existing` or `propose_new`, with its match evidence.

This is the rule that protects the Framework. `core_learning_node_definitions`
is unique on `(customer_id, framework_id, code)`, which stops a duplicate
*code* and does nothing about a duplicate *meaning*. Without reuse ranking, every
uploaded file grows the taxonomy, synonyms accumulate under different codes, and
Mastery fragments across Nodes that mean the same thing. No constraint will
catch that later.

AI must not merge two Definitions, and must not create a semantic Relation, on
similarity score alone.

## A5 — Draft and published boundary

A Proposal may reference a Working Activity. That reference is authoring context
and must never reach `core_learning_node_mappings`.

A canonical Mapping requires both identities to exist and be immutable: a
published Course Version Lesson or Activity, and a Node in a published Framework
Version. No `latest` resolution, no Working Template identifier stored as a
learning object, no silent rebinding when either side publishes a new version.

Consequence to plan for rather than discover: because rebinding is forbidden,
each new Course Version needs its own promotion pass, and Mappings for the
previous Version remain valid for that Version. This is deliberate — historical
Evidence must keep resolving against what was actually taught — but it is
ongoing work, not a one-time migration.

## A6 — One review surface

Review must be a single surface supporting accept, edit and reject, bulk review
per Activity or Course, source excerpt with confidence and rationale, duplicate
and ambiguity warnings, tenant and role scoping, and an audit of reviewer, time
and decision.

The manual Node/Mapping fallback is required for: Media that cannot be
processed, declaring competencies before content exists, Framework and taxonomy
administration, authoring deep assessment criteria, and splitting or merging
Nodes across Versions. It is the same Learning authoring path with human-entered
input, not a parallel schema or alternate Source of Truth. At this ADR version,
the external form/controller/route is still unimplemented.

## A7 — Provenance

Every Proposal carries tenant, source Activity draft context, authorized Media
reference, source fingerprint and processing version, Framework context, prompt
template version and hash, provider, model and Model Run reference, candidate
payload with confidence and rationale, review status and reviewer, and a
correlation key.

If the source fingerprint, transcript, Activity context or prompt contract
changes, the Proposal becomes stale or a new revision is created. Provenance is
never silently overwritten.

## A8 — Owner-service promotion

On acceptance: Course writes only Course working state; Learning reuses an
existing Definition or Node, or creates a Node inside a `draft_snapshot`
Framework Version through its own service; accepted Mapping intent waits for
both published identities; promotion then materializes the Mapping.

Promotion idempotency must key on the Mapping's own uniqueness —
`(customer_id, source_type, source_id, source_discriminator, learning_node_id,
mapping_role)` — not on a Proposal identifier alone. Retry must not create a
duplicate, and failure must not leave a partial Node or Mapping graph.

Accepting a Proposal is a review state. It is not a publish.

## A9 — Correcting a wrong Mapping

`core_learning_node_mappings` carries `invalidated_at`, `invalidated_by` and
`invalidation_reason`, and its audited one-way invalidation lifecycle is already
approved. A Mapping produced from a Proposal that later proves wrong is
invalidated through that lifecycle. It is not deleted and not edited, and the
Proposal that produced it keeps its provenance.

## A10 — Confidence is not weight

`mapping_role` is one of `teaches`, `practices`, `assesses`; `weight` is a
pedagogical contribution in `[0, 1]`. Model confidence measures how sure the
model is that its proposal is right. These are different quantities and must
never be written from the same number. A Proposal carries both, separately, and
a reviewer sets the weight.

## A11 — No Evidence or Mastery side effect

Proposals and Mappings describe content structure. They are not Evidence and
they create no Mastery. Evidence arises only from sources and qualification
policy approved separately by Learning Foundation.

---

# Enforcement Allocation

Most of this ADR is application-owned, and the document is explicit about it so
that a later reviewer does not assume the engine is helping.

Database-enforced today: Mapping source type vocabulary, mapping role
vocabulary, weight range, Node-to-Framework-Version membership, Framework
Version lifecycle, Node immutability after publish, tenant-safe composite keys,
and Mapping invalidation coherence.

Application-enforced, with no database backstop: that `source_id` refers to a
published Version object rather than a Working identifier — the column is a bare
`BIGINT` with no foreign key, and the CHECK constrains only `source_type`; that
a Framework was selected before a Node Proposal is produced; reuse-before-create;
that confidence never becomes weight; and every review and promotion authorization
rule.

---

# Deferred Decisions

Not decided here: physical Proposal persistence, indexes and migration; API,
event and job contracts; prompt and payload schema; retrieval and embedding
strategy; confidence thresholds and duplicate resolution policy; the permission
matrix, including who may approve a new Node; review UI; reconciliation for
already published Courses and Frameworks; rollout, cost and evaluation.

---

# Implementation Gate

Before any migration or code:

1. Proposal persistence and API contract documented and reviewed.
2. Course publish and Learning promotion transaction and idempotency design.
3. Tenant, role and authorization matrix, including Node approval authority.
4. Prompt and model provenance, stale and reprocessing policy.
5. Review UI covering bulk accept, edit, reject and manual fallback.
6. HIGH regression audit, tests and migration verification.

Learning Foundation is deployed on the development database with runtime
services and no external surface; its own external-surface and production gates
still apply, and this ADR does not lift them.

---

# Status Note

The Architecture Owner approved this ADR on 2026-08-16. It is `Approved` rather
than `Frozen` deliberately: the logic is settled enough to design against, but
no authoring runtime exists yet, and the first implementation is the thing most
likely to test these assumptions. Freezing is a later decision, taken once a
Proposal workflow has actually run.

Approval covers the logic and the boundary. It grants no migration, API, prompt
or UI authorization — those remain behind the Implementation Gate above.

---

# Course Template Mapping Intent Amendment — 2026-08-23

Approved by the Architecture Owner for implementation planning. A working
Course Template may store a selected, explicitly identified published Framework
Version and manual Mapping Intents for its working Lessons/Activities. Those
Intents are not canonical Mappings and create no Evidence or Mastery.

Course publish must snapshot the selected Framework Version and promote each
Intent in the same transaction to `core_learning_node_mappings`, substituting
only the newly-created published Version Lesson/Activity identities. Promotion
must be idempotent on the Mapping unique key; a Product remains bound to its
exact published Course Version and is never silently rebound.

Manual Mapping Intent may be confirmed directly by an authorised Course author.
AI-originated Intent still requires human accept/edit/reject. Implementation
review is optional assurance, not a mandatory gate: Owner attestation plus
recorded HIGH test evidence may close an implementation gate.

---

End of ADR
