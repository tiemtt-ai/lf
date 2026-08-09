# Table: core_liveclass_session_schedule_changes

Version: 1.0

Status: Approved

Last Updated: 2026-07-25

Document Path: database/liveclass/core_liveclass_session_schedule_changes.md

## Purpose

Append-only audit evidence for Session schedule changes.

## Fields And Rules

Required: `customer_id`, `session_id`, previous/new start and end timestamps,
`changed_by`, `created_at`. Optional `reason`.

Rows are append-only. A normal reschedule keeps Session status `scheduled`.
When a replacement Session is created, the old Session becomes `cancelled` and
references the replacement via `superseded_by_session_id`.

## Schedule Origin Amendment — 2026-08-05

For an Origin-backed Session, reschedule retains the same immutable
`core_liveclass_session_schedule_origins` row. This audit table records only
the Session interval transition and never updates Schedule, Slot or Origin.
Occurrence replacement/superseded reuse is not authorized by the current
Schedule-to-Session contract; cancelled/no-show Sessions continue to consume
their original occurrence identity.
