# Table: core_liveclass_session_schedule_origins

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/liveclass/core_liveclass_session_schedule_origins.md

## Purpose

Stores immutable lineage for a concrete Session explicitly confirmed from one
projected Cohort Schedule occurrence. This table is the only authority for
Schedule-origin classification; Session metadata and timestamp coincidence are
not lineage.

```text
Schedule 1 → N Origins
Schedule Slot 1 → N Origins
Session 1 → 0..1 Origin
```

## Fields

| Field | Type | Null | Meaning |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED` | no | Primary key. |
| `customer_id` | `BIGINT UNSIGNED` | no | Tenant owner shared by all referenced rows. |
| `session_id` | `BIGINT UNSIGNED` | no | Concrete Session created by confirmation. |
| `schedule_id` | `BIGINT UNSIGNED` | no | Source Schedule. |
| `schedule_slot_id` | `BIGINT UNSIGNED` | no | Stable source Slot identity. |
| `source_local_date` | `DATE` | no | Local occurrence date in source timezone. |
| `source_local_start_time` | `TIME` | no | Source Slot start wall time. |
| `source_local_end_time` | `TIME` | no | Source Slot end wall time. |
| `source_timezone` | `VARCHAR(64)` | no | IANA timezone frozen from Schedule. |
| `source_start_at` | `DATETIME` | no | Absolute source start instant normalized to UTC. |
| `source_end_at` | `DATETIME` | no | Absolute source end instant normalized to UTC. |
| `created_by` | `BIGINT UNSIGNED` | no | Same-tenant actor confirming the batch. |
| `created_at` | `TIMESTAMP` | no | Origin creation time. |

There is no `updated_at`, status, metadata or soft-delete column. Origin rows
are immutable and are never reused.

## Occurrence Identity And Uniqueness

Canonical identity:

```text
customer_id + schedule_id + schedule_slot_id + source_local_date
```

Required uniqueness:

```text
UNIQUE uk_lcsso_session
(customer_id, session_id)

UNIQUE uk_lcsso_occurrence
(customer_id, schedule_id, schedule_slot_id, source_local_date)
```

The occurrence constraint applies for all history. Cancelled/no-show Sessions
retain their Origin and continue to consume the identity. Double-submit is
resolved by transaction locking plus this database constraint.

## Time Convention

The local tuple is authoritative for the original Schedule rule:

```text
source_local_date
+ source_local_start_time / source_local_end_time
+ source_timezone
```

`source_start_at` and `source_end_at` are the corresponding absolute instants,
converted with `source_timezone` and stored as timezone-free UTC `DATETIME`.
They must satisfy `source_end_at > source_start_at`. Browser timezone and
server default timezone are not calculation authorities.

A created Session records the Schedule timezone. To classify it, parse its
planned interval using the Session timezone, normalize it to UTC and compare
with Origin absolute instants. Current Schedule values are never used for
historical classification.

## Keys, Constraints And Indexes

```text
PRIMARY KEY (id)

FOREIGN KEY (customer_id) REFERENCES saas_customers(id) ON DELETE RESTRICT
FOREIGN KEY (session_id) REFERENCES core_liveclass_sessions(id) ON DELETE RESTRICT
FOREIGN KEY (schedule_id) REFERENCES core_liveclass_schedules(id) ON DELETE RESTRICT
FOREIGN KEY (schedule_slot_id) REFERENCES core_liveclass_schedule_slots(id) ON DELETE RESTRICT
FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT

UNIQUE uk_lcsso_session
(customer_id, session_id)

UNIQUE uk_lcsso_occurrence
(customer_id, schedule_id, schedule_slot_id, source_local_date)

INDEX idx_lcsso_schedule_date
(customer_id, schedule_id, source_local_date)

CHECK (source_local_end_time > source_local_start_time)
CHECK (source_end_at > source_start_at)
```

Application validation remains authoritative for same-tenant ownership and
for verifying that Slot belongs to Schedule.

## Write And Transaction Policy

Preview writes nothing. On confirmation, one atomic transaction must:

1. resolve and lock the same-tenant Cohort, Schedule and selected Slots;
2. recalculate occurrences with canonical Schedule preview rules;
3. reject excluded/out-of-range/already-consumed rows and all invalid Session
   bindings;
4. create every selected Session and explicit Session Teacher assignment;
5. create one Origin for each new Session.

Any failure rolls back the complete batch. No partial success is permitted.
The request cannot supply authoritative occurrence timestamps, timezone,
customer, Cohort or Schedule metadata.

## Mutation And Delete Rules

Origin is append-only and immutable. Reschedule keeps the Origin unchanged and
uses `core_liveclass_session_schedule_changes` for audit.

All Origin relationships use `RESTRICT`. Schedule deletion is not available.
A referenced Slot cannot be hard-deleted; Schedule updates must preserve Slot
identity and may not delete/recreate referenced Slots. Schedule or Slot edits
never update Origin snapshots or Sessions.

## Legacy Strategy

Existing Sessions receive no Origin backfill. A legacy Session without Origin
is classified `source_unknown`; a newly created manual Session without Origin
is classified `off_schedule`. The distinction uses one immutable
server-configured feature rollout instant against Session `created_at`. The
cutover is not request data and must never be advanced after rollout.
Coincident times are never used to infer origin.

## Domain Boundaries

Confirmation creates no Attendance, Recording, Replay, Progress or Completion.
Curriculum/operational binding, Cohort lifecycle, tenant authorization,
delivery and Session Teacher policies remain governed by existing contracts.
