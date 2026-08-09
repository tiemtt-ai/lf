# LiveClass Schedule-To-Session Origin Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-08-05

Document Path: quality/LF-LiveClass-Schedule-Session-Origin-Architecture-Review.md

## Review Information

| Field | Value |
| --- | --- |
| Domain | LiveClass |
| Domain Doc | `docs/core/LF-Core-LiveClass.md` |
| ADR | ADR-0002 Schedule-To-Session Origin Amendment |
| Database Doc | `core_liveclass_session_schedule_origins` |
| Change Class | Foundation Amendment / Existing-Feature Architecture Change |

## Domain And Source Of Truth

- [x] Schedule remains recurring planning authority.
- [x] Projected occurrence remains a non-persisted read model before confirmation.
- [x] Session remains the concrete operational meeting authority.
- [x] Origin is the only Schedule-to-Session lineage authority.
- [x] No lineage column or metadata is added to Session.
- [x] Current mutable Schedule data is not used to classify historical Sessions.

## Identity, Snapshot And Time

- [x] Occurrence identity is
      `(customer_id, schedule_id, schedule_slot_id, source_local_date)`.
- [x] One occurrence can create at most one Session for all history.
- [x] Local date/time tuple and IANA timezone are immutable snapshots.
- [x] Absolute source timestamps are normalized UTC `DATETIME` values.
- [x] Browser/server timezone is not authoritative.
- [x] Session relationship classification compares normalized instants.

## Data Ownership And Tenant Isolation

- [x] Origin has required `customer_id`.
- [x] Session, Schedule, Slot, actor and Origin are same-tenant.
- [x] All foreign keys use `RESTRICT`.
- [x] All query/unique indexes are tenant-first.
- [x] Application validation verifies the complete ownership chain fail-closed.

## Lifecycle And Historical Integrity

- [x] Cancelled/no-show Session retains Origin and occurrence identity.
- [x] Reschedule retains Origin and appends existing audit history.
- [x] Schedule/Slot mutation does not update Origin or Session.
- [x] Referenced Slot identity is stable and cannot be hard-deleted.
- [x] Schedule deletion and replacement Session remain unapproved.
- [x] Existing Sessions receive no inferred/backfilled lineage.

## Confirmation And Idempotency

- [x] Preview persists nothing.
- [x] User explicitly selects occurrences and confirms creation.
- [x] Backend recalculates occurrences from locked canonical Schedule data.
- [x] Client occurrence timestamps/timezone/tenant metadata are untrusted.
- [x] Complete batch is atomic; any invalid row rolls back all writes.
- [x] Transaction locking plus database uniqueness prevents double-submit.
- [x] Curriculum/operational, teacher, delivery and lifecycle policies remain
      authoritative per Session row.

## Domain Boundaries And Backward Compatibility

- [x] Confirmation creates no Attendance, Recording, Replay, Progress or Completion.
- [x] Product/Version, Enrollment, Students and Cohort Teachers are unchanged.
- [x] Manual Session remains valid outside Schedule.
- [x] Legacy Session without Origin is visible as source unknown.
- [x] Manual/legacy classification uses an immutable server-side rollout
      cutover and never client metadata or timestamp coincidence.
- [x] No current Session or schedule-change audit row is deleted or rewritten.

## Implementation Gate

- [x] ADR amendment approved by Architecture Owner.
- [x] Domain invariants documented.
- [x] Database contract and migration shape documented.
- [x] Additive migration required; historical migrations remain unchanged.
- [x] Regression and tenant-isolation test matrix is defined below.

## Required Implementation Test Matrix

Checkpoint 2 may report PASS only with automated evidence for all applicable
rows:

1. additive migration creates Origin keys, tenant-first indexes, both unique
   constraints and `RESTRICT` foreign keys; rollback is verified in an
   isolated test database;
2. migration preserves every existing Session and schedule-change audit row
   and performs no inferred Origin backfill;
3. preview is read-only, timezone/DST-correct and excludes out-of-range or
   excluded dates;
4. confirmation ignores client occurrence time/timezone/tenant metadata and
   recalculates the canonical occurrence from locked Schedule and Slot rows;
5. one valid selection creates the expected Session, optional explicit
   Session Teacher assignments and exactly one immutable Origin;
6. a mixed valid/invalid batch creates no Session, teacher assignment or
   Origin;
7. duplicate selection and concurrent/double-submit create at most one
   Session for the occurrence and return a controlled validation/conflict
   result;
8. cross-tenant Cohort, Schedule, Slot, Session, teacher or actor identifiers
   fail closed without data disclosure;
9. curriculum and operational Session binding policies remain enforced;
10. cancelled/no-show Session continues to consume occurrence identity;
11. reschedule retains Origin, changes only Session planned time and appends
    exactly one schedule-change audit row;
12. Schedule/Slot edit preserves referenced Slot identity and never changes
    Origin snapshot or Session;
13. labels cover `on_schedule`, `rescheduled`, `off_schedule`,
    `source_unknown` and `planned_occurrence`, including immutable rollout
    cutover behavior;
14. confirmation creates no Attendance, Recording, Replay, Progress or
    Completion;
15. Cohort Sessions-tab preview/confirm/list UI preserves authorization,
    responsive/list standards, validation state and the distinction between
    projected occurrences and concrete Sessions.

## Review Result

| Section | Result |
| --- | --- |
| Domain Boundary | PASS |
| Data Ownership | PASS |
| Historical Integrity | PASS |
| Time And Identity | PASS |
| Database Design | PASS |
| Guardrails / Tenant Isolation | PASS |
| Backward Compatibility | PASS |
| Ready For Additive Implementation | PASS |

```text
PASS — Foundation Ready

Explicit preview/selection/atomic confirmation with immutable Origin may
proceed. Automatic generation, synchronization, replacement and Schedule
deletion remain blocked.
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-08-05
Decision: Approved and Frozen
```
