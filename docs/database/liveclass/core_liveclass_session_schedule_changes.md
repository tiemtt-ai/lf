# Table: core_liveclass_session_schedule_changes

Version: 1.1

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-11-19

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

## Evidence Guard — 2026-11-19 (Owner duyệt, đã triển khai)

Không ghi row dời giờ cho Session đã có Attendance hoặc Recording: reschedule bị
từ chối trước khi đổi Session. Không đổi schema. Xem
[LF-Core-LiveClass](../../core/LF-Core-LiveClass.md#lịch-học-trùng-giờ-và-dời-buổi-đã-có-dữ-liệu--2026-11-19-owner-duyệt-đã-triển-khai).
