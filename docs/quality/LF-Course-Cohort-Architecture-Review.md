# Course Cohort Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-07-20

Amendment Review Date: 2026-08-02

Document Path: quality/LF-Course-Cohort-Architecture-Review.md

## Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Parent ADR | [ADR-0001 — Course Foundation](../adr/ADR-0001-Course-Foundation.md) |
| Domain Doc | [LF-Core-Course](../core/LF-Core-Course.md) |
| Database Docs | [core_course_cohorts](../database/course/core_course_cohorts.md), [core_course_cohort_students](../database/course/core_course_cohort_students.md) |

## Approved Contract

- [x] Enrollment remains the learning-access and learning-cycle authority.
- [x] Every new Cohort binds one Product and one server-resolved published Version.
- [x] Version is resolved from exactly one valid active Product Item and is never accepted from request input.
- [x] Product and Version are frozen after Cohort creation; Product changes never migrate a Cohort or Enrollment.
- [x] Legacy nullable bindings remain nullable physically. Only deterministic membership-derived bindings may be backfilled.
- [x] `teacher_id` remains for backward compatibility and is deprecated as an authority.
- [x] Lifecycle is `draft -> active -> completed -> archived`, plus `draft -> archived`.
- [x] Draft and active Cohorts accept authorized membership setup operations;
      only active Cohorts accept runtime operations.
- [x] Only active Enrollments may be added or transferred to a draft or active
      Cohort, with tenant, Product, Version, capacity and duplicate invariants
      preserved under the existing transaction/locking contract.
- [x] Draft membership does not grant runtime access, change Enrollment status
      or activate the Cohort.
- [x] Activation remains a separate `draft -> active` action and revalidates
      all membership and applicable readiness conditions fail-closed.
- [x] Capacity is nullable or at least one and is enforced under a locked Cohort row.
- [x] Membership uses `enrollment_id`, updates the current record on transfer, and creates no history or `is_current` state.
- [x] Admin navigation exposes one Cohort entry; membership management is contextual to Cohort detail.

## LiveClass Boundary

Cohort provides tenant, Product, immutable Version and active Enrollment membership context for a future LiveClass phase. It does not own Room, Session, teacher authority, Attendance, Recording, Replay, Progress or Completion.

## Legacy and Migration Decision

Migration is additive. It must not rewrite Enrollment Version locks, guess from the first membership, overwrite legacy teacher data, or force nullable binding columns to `NOT NULL`. A later review is required before physical nullability is tightened.

## Review Result

```text
Approved — Cohort Foundation hardening and contextual membership UI authorized
```

Owner approval was supplied at the Architecture Gate on 2026-07-20.

## Cohort Draft Setup Amendment Result — 2026-08-02

```text
Approved and Frozen — draft setup operations and canonical Cohort tab UX are
authorized; runtime operations remain active-only
```

Owner approval was supplied by the LearnForge Architecture Owner on
2026-08-02. The amendment changes only the operations permitted in `draft`; it
does not change Cohort transitions, Enrollment lifecycle, tenant ownership,
Product/Version binding or historical-data ownership.
