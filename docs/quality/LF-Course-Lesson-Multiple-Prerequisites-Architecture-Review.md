# Course Lesson Multiple Prerequisites Architecture Review

Version: 1.1

Status: Approved — Post-Implementation Verified

Review Date: 2026-07-26

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Domain Doc | [LF-Core-Course](../core/LF-Core-Course.md) |
| Decision ADR | [ADR-0015](../adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md) |
| Database Docs | Working and Version Lesson prerequisite relationship tables |
| Review Version | 1.1 |

# Review Scope

This review covers authoring, snapshot, runtime access, duplicate-to-draft,
migration compatibility and tenant isolation. The original review authorized
implementation. Version 1.1 also records the completed post-implementation
audit and remediation verification.

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
* rollback restores only a lossless legacy single prerequisite and refuses
  unsupported graphs before changing data or schema;
* Lesson authoring supports all-previous and selected `all|any` rules;
* prerequisite validation uses prospective request ordering for direct and
  sectioned lanes;
* publish freezes the effective prerequisite set;
* duplicate-to-draft reconstructs selected working edges, derives
  all-previous edges again from working order, and rejects invalid snapshot
  ownership, lane, order, rule or match semantics before replacing a draft;
* runtime checks completed Lesson Progress in the exact tenant, Enrollment,
  Product, Version and student context;
* HTTP authoring, request tampering, tenant/role authorization, old input,
  field-level errors, publish snapshots, duplicate/re-publish, migration
  backfill/rollback, uniqueness and repeated update behavior have regression
  coverage;
* prerequisite array and wildcard validation errors render adjacent to the
  accessible fieldset, and the `all|any` policy is visibly required;
* the four audited PHP implementation files pass Pint;
* production Vite build and `git diff --check` pass;
* full regression: `579 passed`, `1 skipped`; two pre-existing backend layout
  contract failures remain outside ADR-0015 scope.

# Post-Implementation Audit Closure

The post-implementation audit initially found one BLOCKER, one HIGH, three
MEDIUM and two LOW issues. The approved remediation sequence completed:

1. lossless-or-refused migration rollback;
2. correct duplicate behavior for `all_previous_lessons_completed`;
3. prospective-order authoring validation;
4. fail-closed duplicate snapshot graph validation;
5. missing regression coverage;
6. audited PHP coding style;
7. field-level UI validation and required-state accessibility.

ADR-0015 is approved for deployment subject to the normal environment
migration, backup and release procedures. The two unrelated backend layout
test failures are not waived by this review and remain tracked outside the
Course prerequisite change.

---

End of Review
