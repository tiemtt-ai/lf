# LiveClass Cohort Schedule Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-08-05

## Review Information

| Field | Value |
| --- | --- |
| Domain | LiveClass |
| Domain Doc | `docs/core/LF-Core-LiveClass.md` v2.4 |
| ADR | ADR-0002 Cohort Schedule Foundation Amendment |
| Database Docs | `core_liveclass_schedules`, `core_liveclass_schedule_slots`, `core_liveclass_schedule_exclusions` |
| Change Class | Foundation Amendment / Existing-Feature Architecture Change |

## Domain And Source Of Truth

- [x] LiveClass owns recurring Cohort Schedule planning.
- [x] Schedule belongs directly to one same-tenant Cohort.
- [x] Course owns Product/Version context but does not own Schedule.
- [x] Schedule is not Course content and is not published into an immutable
      Version.
- [x] Schedule and Session use separate normalized sources of truth.
- [x] Schedule is not stored in Session, Cohort metadata, Room or JSON.
- [x] Attendance, Recording, Replay, Progress and Completion ownership is
      unchanged.

## Data Ownership And Tenant Isolation

- [x] Every Schedule, Slot and Exclusion has required `customer_id`.
- [x] Schedule references a same-tenant Cohort with `RESTRICT` delete behavior.
- [x] Slot and Exclusion reference a same-tenant Schedule.
- [x] Actor references are same-tenant and nullable only according to existing
      LiveClass audit convention.
- [x] All recommended indexes begin with `customer_id`.
- [x] Application validation remains authoritative for same-tenant chains.

## Lifecycle And Mutation

- [x] `draft|active` Cohorts permit authorized Schedule create/update.
- [x] `completed|archived` Cohorts expose Schedule read-only.
- [x] Schedule status is derived from dates and timezone, not persisted.
- [x] No soft delete or delete workflow is approved.
- [x] Schedule mutation does not activate Cohort.
- [x] Schedule mutation has no Session or downstream-data side effect.
- [x] Create/update is transactional and preserves at least one valid Slot.

## Recurrence And Preview

- [x] ISO weekday and same-day `TIME` Slots are normalized rows.
- [x] Exact duplicate and overlapping same-weekday intervals are rejected.
- [x] Different weekdays may use different intervals.
- [x] Exclusion dates are unique and remain inside the Schedule range.
- [x] Preview includes both range endpoints and applies Schedule timezone.
- [x] Backend is the canonical preview calculation authority.
- [x] Preview persists no occurrence and creates no Session ID.
- [x] UI contract states “Các Buổi học thực tế chưa được tạo”.

## Navigation And Backward Compatibility

- [x] Canonical Cohort tabs separate Schedules from Sessions.
- [x] Existing Sessions tab and curriculum/operational binding are unchanged.
- [x] Attendance and Recordings/Replay remain Cohort aggregates over Sessions.
- [x] No existing table, route, public API, runtime evidence or historical data
      is changed by this documentation amendment.

## Deferred Boundary

- [x] Schedule deletion.
- [x] Schedule-to-Session provenance and occurrence identity.
- [x] Bulk Session generation and idempotency.
- [x] Future Session synchronization and individually edited Session policy.
- [x] Shared holiday calendar.
- [x] Schedule-driven Session reschedule audit.
- [x] Default teacher, Room and delivery configuration.

Each item above requires a separate approved amendment before implementation.

## Architecture Review Result

| Section | Result |
| --- | --- |
| Domain Boundary | PASS |
| Data Ownership | PASS |
| Versioning / Course Immutability | PASS |
| Business Rules | PASS |
| Database Design | PASS |
| Guardrails / Tenant Isolation | PASS |
| Documentation Consistency | PASS |
| Ready For Additive Implementation | PASS |

```text
PASS — Foundation Ready

The three normalized Schedule tables and Schedule CRUD/Preview may proceed in
a later implementation task using forward-only additive migrations.
Schedule-to-Session generation/synchronization remains blocked.
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-08-05
Decision: Approved and Frozen for Schedule CRUD/Preview only
```
