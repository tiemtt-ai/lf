# Table: core_liveclass_schedules

Version: 1.0

Status: Approved

Last Updated: 2026-08-05

Document Path: database/liveclass/core_liveclass_schedules.md

## Purpose

Stores one recurring planning configuration owned by LiveClass and belonging
directly to one Cohort. A Schedule is setup data, not a concrete Session and
not operational or learning evidence.

```text
core_course_cohorts 1 → N core_liveclass_schedules
core_liveclass_schedules 1 → N core_liveclass_schedule_slots
core_liveclass_schedules 1 → N core_liveclass_schedule_exclusions
```

Schedule is not Course content, is not published into a Template Version and
must not be stored in Session, Cohort metadata, Course metadata, Room or JSON
recurrence data.

## Fields

| Field | Type | Null | Meaning |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED` | no | Primary key. |
| `customer_id` | `BIGINT UNSIGNED` | no | Tenant owner. |
| `cohort_id` | `BIGINT UNSIGNED` | no | Same-tenant Cohort being planned. |
| `name` | `VARCHAR(255)` | no | Human-readable Schedule name within Cohort context. |
| `starts_on` | `DATE` | no | Inclusive first date used by preview. |
| `ends_on` | `DATE` | no | Inclusive last date used by preview. |
| `timezone` | `VARCHAR(64)` | no | Valid IANA timezone used for preview calculation. |
| `created_by` | `BIGINT UNSIGNED` | yes | Same-tenant actor that created the Schedule. |
| `created_at` | `TIMESTAMP` | no | Creation timestamp. |
| `updated_at` | `TIMESTAMP` | no | Last update timestamp. |

There is no persisted `status`. Presentation derives:

* upcoming — current date in Schedule timezone is before `starts_on`;
* current — current date is within the inclusive range;
* ended — current date is after `ends_on`.

No soft-delete column is approved. Delete behavior remains deferred.

## Business Rules

* Schedule, Cohort and actor must belong to the same `customer_id`.
* Cohort must exist and be `draft|active` for create/update.
  `completed|archived` Schedule data is read-only.
* `starts_on <= ends_on`.
* The complete Schedule range must be contained in the Cohort operating range
  `cohort.start_date..cohort.end_date`.
* Cohort must therefore have both operating dates before Schedule create.
* Both Cohort and Schedule boundaries are inclusive; equality at either end is
  valid. A Cohort may contain multiple Schedules with independent subranges.
* Schedule dates never define, expand or update the Cohort operating period.
  A Cohort operating-period update must be rejected when it would leave any
  existing Schedule outside the proposed range; no Schedule may be silently
  truncated, updated or deleted.
* `timezone` must be a recognized IANA timezone.
* A Schedule must contain at least one valid Slot. Create writes Schedule and
  its Slots in one transaction; update cannot remove the final Slot.
* Schedule create/update never activates Cohort and never creates, updates,
  cancels or deletes Session, Origin, Attendance, Recording, Replay or Progress
  data.
* Explicit Session creation from selected preview occurrences is a separate
  confirmation transaction governed by
  `core_liveclass_session_schedule_origins`.
* A Slot referenced by an Origin has stable identity and cannot be hard-deleted.
  Schedule update must not delete/recreate referenced Slot rows.
* Schedule names are not globally or Cohort-unique; identity is the primary
  key. Presentation may warn about similar names but persistence must not infer
  recurrence identity from a name.

## Preview Contract

Backend calculation is canonical:

```text
Schedule + Slots + Exclusions + timezone
→ projected occurrence start/end timestamps
```

Preview includes both range endpoints, removes exclusion dates, stores no
occurrence rows, creates no Session and grants no Session ID. UI must state:
“Các Buổi học thực tế chưa được tạo”.

## Keys And Indexes

```text
PRIMARY KEY (id)

FOREIGN KEY (customer_id) REFERENCES saas_customers(id) ON DELETE RESTRICT
FOREIGN KEY (cohort_id) REFERENCES core_course_cohorts(id) ON DELETE RESTRICT
FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT

INDEX idx_lcschedule_cohort
(customer_id, cohort_id, starts_on, ends_on)
```

Application validation remains authoritative for same-tenant ownership and
the Cohort lifecycle/date invariants.

## Explicitly Deferred

`default_teacher_id`, `default_room_id`, default delivery configuration,
Schedule deletion, automatic generation and synchronization are not part of
this table contract. Explicit confirmation, provenance and idempotency are
owned by `core_liveclass_session_schedule_origins` and its application service.
