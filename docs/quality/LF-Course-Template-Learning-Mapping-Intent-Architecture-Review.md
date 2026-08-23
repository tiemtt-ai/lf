# Course Template Learning Mapping Intent Architecture Review

Version: 1.0

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-23

Review Date: 2026-08-23

Document Path: quality/LF-Course-Template-Learning-Mapping-Intent-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Course × Learning |
| Domain Docs | [LF-Core-Course](../core/LF-Core-Course.md), [LF-Core-Learning](../core/LF-Core-Learning.md) |
| ADR | [ADR-0017](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md) |
| Database Docs | `core_course_templates`, `core_course_template_learning_mapping_intents`, `core_learning_node_mappings` |
| Review Scope | Draft intent and atomic publish promotion design only |

# A — Domain Boundary

- [x] Course owns Template selection and draft Mapping Intent.
- [x] Learning owns canonical Mapping, Node, Evidence and Mastery.
- [x] Intent is not Evidence, Mastery or a canonical Mapping.

# B — Data Ownership

- [x] Every relation is tenant-scoped.
- [x] Template selection and Intent→selection containment use composite keys.
- [x] Intent→Node→Framework Version containment is physically enforceable.

# C — Versioning

- [x] Template stores one explicit published Framework Version; no `latest`.
- [x] Publish replaces working source IDs with immutable Version Lesson/Activity IDs.
- [x] Product remains bound to its exact published Course Version.

# D — Business Rules

- [x] Phase 1 is manual-only; AI Proposal persistence is deferred.
- [x] Promotion revalidates published Version, active Node, source and tenant.
- [x] Missing/orphan source fails publish closed and rolls back the snapshot.
- [x] Wrong canonical Mapping is invalidated, never edited/deleted.

# E — Database

- [x] Required fields, composite FK tuples, unique keys, CHECKs and indexes are documented.
- [x] Canonical Mapping `source_snapshot` and decimal `source_discriminator` are required.
- [x] Template selection columns and Intent table remain Not Implemented pending migration authorization.

# F — Architecture

- [x] ADR-0017 A2/A5/A8 boundaries are preserved.
- [x] No cross-domain direct write bypasses the Learning owner service.
- [x] Course-side Intent reads of Framework, Version and Node go through the
      authorised Learning read service. Owner decision G2-N1 of 2026-08-22
      forbids a controller querying `core_learning_*` directly, and the
      Framework/Version/Node picker and orphan-Intent list are exactly the
      surfaces that would.
- [x] Publish promotion is one transaction and idempotent on Mapping uniqueness.

# G — Documentation

- [x] Course and Intent contracts have routed metadata and manifest records.
- [x] `docs:lint` passes.
- [x] Deferred AI/staleness and orphan-surface requirements are explicit.

# H — Ready For Next Gate

- [x] Migration shape is documented.
- [x] HIGH test requirements include MariaDB promotion, rollback, tenant and Product-version retention.
- [ ] Owner Approval recorded.

# Review Result

```text
PASS WITH OWNER APPROVAL PENDING — Database/Architecture contract is ready for
Owner approval. No migration or implementation is authorized by this review.
```

# Required Future Reviews

* Owner approval, then Database Document status transition to Approved.
* HIGH implementation audit before migration/service/UI.
* AI Proposal persistence, provenance and review workflow.
* Course-derived Evidence source authorization.
* Sequencing note: the Intent authoring surface builds on the Learning
  Framework authoring surface, whose Gate 2 is still open.
