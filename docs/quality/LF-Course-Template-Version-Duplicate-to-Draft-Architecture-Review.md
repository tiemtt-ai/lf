# Course Template Version Duplicate to Draft Architecture Review

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
| Parent ADRs | [ADR-0001](../adr/ADR-0001-Course-Foundation.md), [ADR-0012](../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md) |
| Decision ADR | [ADR-0013 — Duplicate Version to Draft](../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md) |
| Database Docs | Editable Template and immutable Version table documents |
| Review Version | 1.1 |

The 2026-07-10 amendment approves reconstruction of unlimited Section
hierarchy and the required `allows_lessons` capability while preserving Lesson
mapping and sibling ordering.

The compact authoring-tree presentation does not change duplicate-to-draft
reconstruction. Restored status and structural data remain stored even when
Section/Lesson/Activity status is not displayed in the authoring tree.

# Review Scope

This review verifies the architecture contract for replacing the one editable
Course Template draft with content copied from one immutable published Version.

It does not review application code or authorize editing Versions, creating
Templates, publishing, Product switching, Enrollment migration or learner-state
changes.

# A — Domain Boundary

- [x] Duplicate belongs to Course Template Authoring.
- [x] No Product responsibility is introduced.
- [x] Product, Enrollment, Progress and Completion remain untouched.
- [x] Teacher assignments remain outside the Version content snapshot.
- [x] No Runtime Course concept is introduced.

# B — Source Of Truth

- [x] One existing Course Template remains the sole editable draft.
- [x] No second draft, draft Version or new Template is created.
- [x] The selected Version is read-only source data.
- [x] Published Versions remain the historical source of truth.

# C — Immutability And Versioning

- [x] No Version row or child snapshot row is updated or deleted.
- [x] No Version is created.
- [x] `version_number` is unchanged.
- [x] `is_current` is unchanged.
- [x] Version lifecycle status is unchanged.
- [x] Deprecated and archived immutable Versions may be authoring sources.
- [x] Incomplete `draft_snapshot` records are rejected.

# D — Draft Replacement

- [x] Existing working Activities, Lessons and Sections are replaced.
- [x] The existing Template row is updated rather than recreated.
- [x] Direct and Sectioned Lessons are preserved.
- [x] Section hierarchy and all documented ordering are preserved.
- [x] Lesson and Activity prerequisites are remapped to new working IDs.
- [x] Aggregate fields are reconstructed consistently.
- [x] Existing teacher assignments remain unchanged.

# E — Template State

- [x] Resulting Template status is `draft`.
- [x] `working_revision` increments from the current draft revision.
- [x] No new revision field is introduced.
- [x] `created_by` and `created_at` remain unchanged.
- [x] `updated_at` records the replacement time.
- [x] `last_version_published_at` remains unchanged.

# F — Optional References

- [x] Same-tenant Category references are restored when they still exist.
- [x] Missing optional Category references fall back to `NULL`.
- [x] Missing optional Media/reference IDs fall back without mutating history.
- [x] Snapshot text remains preserved on the immutable Version.
- [x] Required invalid data and slug conflicts fail atomically.
- [x] Cross-tenant references are never accepted.

# G — Tenant And Authorization

- [x] `TenantContext::customerId()` scopes every read and write.
- [x] Template and Version ownership are both validated.
- [x] Version ID alone is not an authorized lookup.
- [x] The operation is `customer_admin` only.
- [x] Unauthorized roles cannot reach the mutation.

# H — Transaction And Concurrency

- [x] The full replacement is one database transaction.
- [x] The Template working row is locked.
- [x] Validation occurs before destructive replacement where practical.
- [x] Failure restores the original draft through transaction rollback.
- [x] Audit recording participates in successful completion.

# I — Audit

- [x] No last-operation audit columns are added to Course Template.
- [x] Append-only tenant audit captures Template, source Version, actor and time.
- [x] Audit is not Course business state.
- [x] No snapshot content or sensitive data is stored in audit context.
- [x] No Course schema migration is required.

# J — UI And Safety

- [x] Action placement is limited to History and Version Detail.
- [x] No new navigation or Product UI is introduced.
- [x] Confirmation explicitly warns that current draft content is replaced.
- [x] State change is not performed by `GET`.
- [x] Success returns to Course Edit → Content.

# K — Implementation Readiness

- [x] Field mapping is defined.
- [x] Structural identity mapping is defined.
- [x] Reference fallback is defined.
- [x] Status and working revision behavior are defined.
- [x] Transaction, authorization and tenant requirements are defined.
- [x] Required tests can be derived directly from the ADR.

# Review Result

Score:

```text
100 / 100
```

Decision:

```text
Approved — Implementation Authorized
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-03
Decision: Approved
```

# Required Implementation Conformance

Implementation must verify:

* successful Admin replacement;
* unauthorized-role denial;
* tenant isolation;
* transactional rollback;
* direct and Sectioned structure reconstruction;
* ordering and prerequisite mapping;
* optional reference fallback;
* unchanged Version count, payload, lifecycle and current marker;
* unchanged Product and learner state;
* one remaining editable draft;
* required confirmation and redirect behavior.

---

End of Review
