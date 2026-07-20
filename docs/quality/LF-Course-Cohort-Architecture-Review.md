# Course Cohort Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-07-20

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
- [x] Only active Cohorts accept membership operations.
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
