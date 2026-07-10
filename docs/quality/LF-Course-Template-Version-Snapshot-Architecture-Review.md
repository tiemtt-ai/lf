# Course Template Published Version Snapshot Architecture Review

Version: 1.1

Status: Approved Review

Review Date: 2026-07-03

Nested Section Capability Review Date: 2026-07-10

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Domain Doc | [LF-Core-Course](../core/LF-Core-Course.md) |
| Parent ADR | [ADR-0001 — Course Foundation](../adr/ADR-0001-Course-Foundation.md) |
| Decision ADR | [ADR-0012 — Published Version Snapshot](../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md) |
| Database Docs | Four `core_course_template_version_*` table documents |
| Review Version | 1.1 |

The 2026-07-10 amendment approves unlimited Section hierarchy and the required
`allows_lessons` field in working and Version Sections. Publish must preserve
both fields and reject a Lesson mapping to a Section that disallows Lessons.

The compact authoring-tree presentation hides Section/Lesson/Activity status,
uses flat Activity rows and omits disallowed Lesson blocks. This is a UI-only
read model and does not remove or mutate snapshot fields or publish behavior.

# Review Scope

This review verifies the documentation and schema contract for immutable
published Course Template Version snapshots. It does not authorize or review
application code, migrations, rollback, duplicate, Enrollment binding or
published Course consumption.

# A — Domain Boundary

- [x] Course owns Course authoring and published Version state.
- [x] Snapshot tables do not claim Media, Assessment or LiveClass ownership.
- [x] Editable Template and published Version sources of truth are separated.
- [x] No Runtime Course tables are introduced.

# B — Data Ownership

- [x] All four tables require `customer_id`.
- [x] Parent, child, source and prerequisite tenant compatibility is explicit.
- [x] `TenantContext::customerId()` is required for all future reads/writes.
- [x] Generic Activity references retain tenant and owner validation.

# C — Versioning

- [x] Publish is the approved snapshot boundary.
- [x] `version_number` starts at 1 and increments per Template.
- [x] Published snapshot payload is immutable.
- [x] Current designation is separated from immutable content.
- [x] Flat and Sectioned Lesson structures are preserved.

# D — Business Rules

- [x] Publish is atomic and prevents partial graphs.
- [x] Old Versions are retained.
- [x] Editable draft content is not modified by snapshot creation.
- [x] Lifecycle and allowed envelope updates are explicit.
- [x] Rollback and duplicate are explicitly deferred.

# E — Database

- [x] Primary keys, relationships and required/nullability rules are defined.
- [x] Snapshot fields mirror the current editable Course definition.
- [x] Ordering and prerequisite mappings are documented.
- [x] Suggested indexes and unique constraints use short explicit names.
- [x] Delete behavior uses `RESTRICT` or logical lineage; no destructive
  cascade is allowed.
- [x] Metadata cannot replace canonical fields.

# F — Architecture

- [x] Template → Version, Snapshot and Immutable Publishing patterns apply.
- [x] Architecture Principles and Guardrails are satisfied.
- [x] ADR-0012 extends rather than rewrites ADR-0001 history.
- [x] Course Edit remains the parent workflow; no new navigation module exists.

# G — Documentation

- [x] Four canonical database documents are complete.
- [x] ADR-0012 records context, decision, consequences and future work.
- [x] Documentation catalogs link the new ADR and review.
- [x] No application source code or migration is included.

# H — Ready For Next Gate

- [x] Field-level migration design is documented.
- [x] Tenant, immutability and transaction requirements are implementation-ready.
- [x] Architecture review passed for the documented snapshot scope.
- [x] Documentation is approved for later migration planning.

# Review Result

Score:

```text
100 / 100
```

Decision:

```text
Foundation Ready — Snapshot Documentation Scope
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-03
Decision: Approved
```

# Required Future Reviews

Separate review remains required before implementing:

* Media/Assessment/LiveClass immutable Activity-reference contracts;
* rollback or restore;
* duplicate;
* Product/Enrollment Version binding;
* published Course consumption.

---

End of Review
