# Table: core_liveclass_schedule_slots

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/liveclass/core_liveclass_schedule_slots.md

## Purpose

Stores one weekday/time recurrence rule for a LiveClass Schedule. Slots are
normalized rows so different weekdays may use different time intervals.

## Fields

| Field | Type | Null | Meaning |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED` | no | Primary key. |
| `customer_id` | `BIGINT UNSIGNED` | no | Tenant owner. |
| `schedule_id` | `BIGINT UNSIGNED` | no | Parent Schedule. |
| `weekday` | `TINYINT UNSIGNED` | no | ISO weekday `1=Monday` through `7=Sunday`. |
| `start_time` | `TIME` | no | Local start time in parent Schedule timezone. |
| `end_time` | `TIME` | no | Local end time on the same local date. |
| `sort_order` | `INT UNSIGNED` | no | One-based stable presentation order. |
| `created_by` | `BIGINT UNSIGNED` | yes | Same-tenant actor that created the Slot. |
| `created_at` | `TIMESTAMP` | no | Creation timestamp. |
| `updated_at` | `TIMESTAMP` | no | Last update timestamp. |

## Business Rules

* Slot, Schedule, Cohort and actor must resolve to the same `customer_id`.
* `weekday` is in `1..7` using ISO weekday semantics.
* `end_time > start_time`; overnight Slots are not supported by this
  Foundation contract.
* A Schedule may have different intervals on different weekdays and multiple
  non-overlapping intervals on one weekday.
* Exact duplicate `(weekday, start_time, end_time)` rows are prohibited within
  one Schedule.
* Two intervals on the same weekday and Schedule must not overlap. Adjacent
  intervals where one `end_time` equals the next `start_time` do not overlap.
* Every Schedule must retain at least one Slot. Schedule and Slot mutation is
  transactional and locks the relevant Schedule/Slot set before authoritative
  overlap validation.
* Slot mutation changes preview only and has no Session side effect.
* A Slot referenced by `core_liveclass_session_schedule_origins` has stable
  occurrence identity and cannot be hard-deleted. Its edit changes only future
  projection; existing Origin snapshots and Sessions remain unchanged.

## Keys, Constraints And Indexes

```text
PRIMARY KEY (id)

FOREIGN KEY (customer_id) REFERENCES saas_customers(id) ON DELETE RESTRICT
FOREIGN KEY (schedule_id) REFERENCES core_liveclass_schedules(id)
    ON DELETE RESTRICT
FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT

Referenced by core_liveclass_session_schedule_origins.schedule_slot_id
    ON DELETE RESTRICT

CHECK (weekday BETWEEN 1 AND 7)
CHECK (end_time > start_time)

UNIQUE uk_lcsslot_exact
(customer_id, schedule_id, weekday, start_time, end_time)

INDEX idx_lcsslot_schedule_order
(customer_id, schedule_id, sort_order, id)

INDEX idx_lcsslot_overlap
(customer_id, schedule_id, weekday, start_time, end_time)
```

Cross-row overlap and the “at least one Slot” invariant require transactional
application validation; basic database checks and uniqueness do not replace
that validation.
