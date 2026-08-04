# Table: core_liveclass_session_teachers

Version: 1.2

Status: Approved

Last Updated: 2026-07-25

## Purpose

Stores the complete teaching team for one Session.

## Fields And Rules

Required: `customer_id`, `session_id`, `teacher_id`, `role`
(`primary_teacher|teacher|assistant|substitute`), `created_by`, timestamps.
Optional assignment window: `assigned_from`, `assigned_to`.

Session and Teacher must share tenant; Teacher must be an active teacher.
One Teacher has one assignment per Session.

## Cohort Session Team Policy — 2026-08-04

A Session may have multiple `teacher` and `assistant` rows. A Teacher may occur
only once in the Session team. New Cohort Session operations do not create a
`primary_teacher` row: a Cohort `primary_teacher` selected for a Session is
stored as `teacher`, while a Cohort `assistant` remains `assistant`.

Every row must resolve through an active same-tenant
`core_course_cohort_teachers` assignment. Every assignment, including the
Cohort primary, must cover the complete Session scheduled interval according to the canonical availability rule in
`core_liveclass_sessions.md`. Create, edit and reschedule must validate the
complete team transactionally. New writes set
`core_liveclass_sessions.primary_teacher_id` to `NULL`; non-null values are
legacy compatibility data only.
