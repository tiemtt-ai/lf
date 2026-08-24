# Course Template Learning Mapping Intent Architecture Review

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

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
- [x] Template selection columns and Intent table are deployed on MariaDB development.

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
- [x] Owner Approval recorded — 2026-08-23.

# Review Result

```text
HIGH IMPLEMENTATION PASS — Owner-attested evidence recorded on 2026-08-23:
MariaDB promotion suite 12 passed / 44 assertions; CI MariaDB job 77 passed /
348 assertions; default PHPUnit 734 passed / 8260 assertions / 1 skipped;
`schema:drift`, `docs:lint`, Pint and `git diff --check` passed. The migration,
manual Intent surface and atomic canonical Mapping promotion are implemented.
```

# Required Future Reviews

* AI Proposal persistence, provenance and review workflow.
* Course-derived Evidence source authorization.
* A unified Course Template authoring lifecycle/status gate. Mapping Intent has
  no Phase 1 status gate because it has no pre-publish effect; re-review this
  decision before any component consumes Intent before promotion.
* Add retired-Node promotion coverage when a valid fixture path exists; a
  published Node is immutable today, so this state cannot be externally built.
* Add HTTP/browser-shape coverage for the Mapping Intent controller, routes and
  form. The current MariaDB suite verifies service and database boundaries.
* Sequencing note: the Intent authoring surface builds on the Learning
  Framework authoring surface, whose Gate 2 is still open.
