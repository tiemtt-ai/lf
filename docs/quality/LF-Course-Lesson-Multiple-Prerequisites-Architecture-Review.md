# Course Lesson Multiple Prerequisites Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-07-26

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Domain Doc | [LF-Core-Course](../core/LF-Core-Course.md) |
| Decision ADR | [ADR-0015](../adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md) |
| Database Docs | Working and Version Lesson prerequisite relationship tables |
| Review Version | 0.1 |

# Review Scope

This review covers the proposed authoring, snapshot, runtime access,
duplicate-to-draft, migration compatibility and tenant-isolation contract. It
does not authorize migration or application implementation.

# A — Domain Boundary

- [x] Course owns Lesson prerequisite authoring and immutable snapshots.
- [x] Progress remains the evidence source for completed Version Lessons.
- [x] No Runtime Course aggregate or cross-domain write is introduced.

# B — Data Ownership

- [x] Both relationship tables require `customer_id`.
- [x] Same-tenant Template/Version ownership is explicit.
- [x] Runtime access retains the Enrollment/Product/Version/student boundary.

# C — Versioning

- [x] Working prerequisites are mutable authoring data.
- [x] Publish freezes explicit effective prerequisite edges.
- [x] Published edges are immutable and independent from later draft reorder.
- [x] Duplicate-to-draft maps Version identities to new working identities.

# D — Business Rules

- [x] `all`, `any`, empty-set and unknown-rule semantics are explicit.
- [x] Canonical “previous” ordering is deterministic.
- [x] Cross-lane, self, future and duplicate edges fail validation.
- [x] The first Lesson cannot select an ineffective all-previous rule.

# E — Database

- [x] Canonical relationships are normalized rather than stored in JSON.
- [x] Keys, uniqueness, indexes and restrictive deletion are documented.
- [x] Additive compatibility and legacy backfill are defined.
- [x] Additive migration and rollback strategy are approved.

# F — Architecture

- [x] Template → Version and immutable snapshot principles are preserved.
- [x] Tenant isolation and fail-closed runtime behavior are preserved.
- [x] ADR-0015 extends rather than rewrites approved ADR history.
- [x] ADR-0015 is approved by the Architecture Owner.

# G — Documentation

- [x] Proposed ADR exists.
- [x] Proposed Domain and database contracts are linked.
- [x] LF-INDEX and ADR catalog list the proposal.
- [x] Review artifact is stored in `docs/quality`.

# H — Ready For Code

- [x] Migration design is documented at field and compatibility level.
- [x] Laravel implementation boundaries are identifiable.
- [x] Architecture Review passed.
- [x] Foundation Freeze is confirmed.

# Review Result

Decision:

```text
PASS — Foundation Ready; migration and implementation are authorized.
```

Required owner decisions: complete.

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-26
Decision: Approved; Foundation Freeze confirmed.
```

# Implementation Verification

Verified on 2026-07-26:

* additive migration creates working and immutable Version prerequisite
  relationship tables and backfills the legacy single prerequisite;
* Lesson authoring supports all-previous and selected `all|any` rules;
* publish freezes the effective prerequisite set;
* duplicate-to-draft reconstructs normalized working edges;
* runtime checks completed Lesson Progress in the exact tenant, Enrollment,
  Product, Version and student context;
* targeted Course regression: `71 passed`, `844 assertions`;
* full regression: `564 passed`, `1 skipped`; two unrelated existing backend
  layout contract failures remain outside ADR-0015 scope.

---

End of Review
