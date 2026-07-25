# Table: core_liveclass_session_teachers

Version: 1.0

Status: Approved

Last Updated: 2026-07-25

## Purpose

Stores the complete teaching team for one Session.

## Fields And Rules

Required: `customer_id`, `session_id`, `teacher_id`, `role`
(`primary_teacher|teacher|assistant|substitute`), `created_by`, timestamps.
Optional assignment window: `assigned_from`, `assigned_to`.

Session and Teacher must share tenant; Teacher must be an active teacher.
One Teacher has one assignment per Session. At most one
`primary_teacher` is allowed by service validation. `primary_teacher_id` on
Session is a convenience projection and must match the primary assignment.
