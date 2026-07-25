# Table: core_course_cohort_teachers

Version: 1.0

Status: Approved

Last Updated: 2026-07-25

## Purpose

Stores the complete teacher team assigned to one Cohort. Template teacher
assignments remain authoring permissions; Session teacher assignments identify
who teaches a specific Session.

## Fields

`id`, required tenant `customer_id`, required `cohort_id`, required
`teacher_id`, `role` (`primary_teacher|teacher|assistant`), nullable
`assigned_from`, nullable `assigned_to`, `status` (`active|inactive`),
`created_by`, timestamps.

## Rules

Teacher and Cohort must belong to the same tenant. Teacher must have role
`teacher` and be active. Date range must be valid. One active assignment per
Cohort/Teacher is allowed. Cohort teacher assignment does not automatically
create Session teacher assignments.

## Indexes

Indexes cover tenant/cohort/status, tenant/teacher and date range. Foreign keys
to tenant, Cohort and User use `RESTRICT`.
