# Table: core_liveclass_schedule_exclusions

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/liveclass/core_liveclass_schedule_exclusions.md

## Purpose

Stores a local calendar date excluded from a LiveClass Schedule preview.
Exclusion is planning data and has no effect on an existing Session.

## Fields

| Field | Type | Null | Meaning |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED` | no | Primary key. |
| `customer_id` | `BIGINT UNSIGNED` | no | Tenant owner. |
| `schedule_id` | `BIGINT UNSIGNED` | no | Parent Schedule. |
| `excluded_on` | `DATE` | no | Local date removed from preview. |
| `reason` | `VARCHAR(500)` | yes | Optional human-readable exclusion reason. |
| `created_by` | `BIGINT UNSIGNED` | yes | Same-tenant actor that created the exclusion. |
| `created_at` | `TIMESTAMP` | no | Creation timestamp. |
| `updated_at` | `TIMESTAMP` | no | Last update timestamp. |

## Business Rules

* Exclusion, Schedule, Cohort and actor must resolve to the same
  `customer_id`.
* `excluded_on` must be within the inclusive
  `schedule.starts_on..schedule.ends_on` range.
* One Schedule may contain at most one row for one `excluded_on` date.
* Exclusion removes every projected Slot occurrence on that local date.
* Exclusion does not create, update, cancel, reschedule or delete a Session.
* A date that has no matching Slot produces no preview difference; this does
  not turn Exclusion into Session or holiday-calendar data.

## Keys And Indexes

```text
PRIMARY KEY (id)

FOREIGN KEY (customer_id) REFERENCES saas_customers(id) ON DELETE RESTRICT
FOREIGN KEY (schedule_id) REFERENCES core_liveclass_schedules(id)
    ON DELETE RESTRICT
FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT

UNIQUE uk_lcsexclusion_date
(customer_id, schedule_id, excluded_on)

INDEX idx_lcsexclusion_schedule
(customer_id, schedule_id, excluded_on)
```

Shared holiday calendars and automatic holiday import are deferred.
