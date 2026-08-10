# Table: core_liveclass_session_teachers

Version: 1.4

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-10

Document Path: database/liveclass/core_liveclass_session_teachers.md

## Purpose

Stores the complete teaching team for one Session.

## Fields And Rules

Required: `customer_id`, `session_id`, `teacher_id`, `role`
(`primary_teacher|teacher|assistant|substitute`), `created_by`, timestamps.
Optional assignment window: `assigned_from`, `assigned_to`.

Session and Teacher must share tenant; Teacher must be an active teacher.
A Teacher may teach any number of Sessions. The unique key represents canonical
storage only: repeated input for the same Teacher and Session is normalized to
one assignment and is not a business validation error.

## Cohort Session Team Policy — 2026-08-04

A Session may have multiple `teacher` and `assistant` rows. Repeated references
to the same Teacher in one request are normalized to one canonical row; the
same Teacher may appear in any number of different Sessions. New Cohort Session operations do not create a
`primary_teacher` row: a Cohort `primary_teacher` selected for a Session is
stored as `teacher`, while a Cohort `assistant` remains `assistant`.

Every row must resolve through an active same-tenant
`core_course_cohort_teachers` assignment. Every assignment, including the
Cohort primary, must cover the complete Session scheduled interval according to the canonical availability rule in
`core_liveclass_sessions.md`. Create, edit and reschedule must validate the
complete team transactionally. New writes set
`core_liveclass_sessions.primary_teacher_id` to `NULL`; non-null values are
legacy compatibility data only.

## Cohort Assignment Deactivation — 2026-08-10

Approved: 2026-08-10 bởi LearnForge Architecture Owner.

Quy tắc "mọi dòng phải resolve qua một assignment `core_course_cohort_teachers`
đang `active`" chỉ ràng buộc **Session còn ở phía trước và chưa có evidence**.
Với Session đã diễn ra hoặc đã có Attendance/Recording/Replay, dòng assignment
tại đây là bằng chứng lịch sử về người đã dạy: nó được giữ nguyên vĩnh viễn kể
cả khi assignment cấp Cohort tương ứng đã chuyển sang `inactive`.

Vì vậy việc vô hiệu hóa một assignment cấp Cohort **không** cascade xuống bảng
này. Thao tác đó bị chặn fail-closed khi còn Session tương lai chưa có evidence
đang gán giáo viên. Hợp đồng đầy đủ và điều kiện chặn nằm tại
[core_course_cohort_teachers](../course/core_course_cohort_teachers.md)
§ Assignment Deactivation Policy — 2026-08-10.
