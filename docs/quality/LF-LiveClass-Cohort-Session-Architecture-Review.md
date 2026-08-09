# LiveClass Cohort-Centered Session Architecture Review

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Review Date: 2026-07-25

Document Path: quality/LF-LiveClass-Cohort-Session-Architecture-Review.md

## Approved Contract

- [x] Session belongs directly to same-tenant Cohort.
- [x] Cohort immutable Version and Session Version must match.
- [x] Every Session maps one Version Lesson.
- [x] Version Activity is conditional and must be same-Lesson `live_class`.
- [x] Operational events without Version Activity cannot create Course
  Activity Completion Evidence.
- [x] Room is optional reusable delivery configuration and not a learning
  authority.
- [x] Session snapshots online/offline/hybrid delivery history.
- [x] Cohort and Session teacher assignments have separate ownership.
- [x] Attendance, Recording and Replay belong to Session.
- [x] Schedule changes are append-only audit rows.
- [x] LiveClass produces evidence; Course alone owns Progress and Completion.
- [x] Every business record is tenant-scoped and cross-domain references are
  validated fail-closed.

## Review Result

```text
PASS — Foundation Ready; migration and implementation authorized
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-25
Decision: Approved and Frozen
```
