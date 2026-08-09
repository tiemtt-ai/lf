# Table: core_course_cohort_teachers

Version: 1.1

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/course/core_course_cohort_teachers.md

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

Mỗi Cohort có tối đa một assignment `primary_teacher` ở trạng thái `active`.
Assignment này bắt buộc có `assigned_from = Cohort.start_date` và
`assigned_to = Cohort.end_date`; không được tạo khi Cohort thiếu một trong hai
mốc. Khi thay giáo viên chính, assignment chính cũ được chuyển thành `teacher`
để bảo toàn lịch sử. Khi ngày vận hành của Cohort được cập nhật hợp lệ, khoảng
phụ trách của assignment chính đang hoạt động phải được đồng bộ trong cùng
transaction. Assignment `teacher` và `assistant` có thể dùng khoảng con nhưng
không được vượt ngoài khoảng vận hành của Cohort.

Khi chọn giáo viên phụ trách một LiveClass Session, assignment chính luôn hợp
lệ. Assignment `teacher` hoặc `assistant` chỉ hợp lệ nếu toàn bộ thời gian
Session nằm trong `assigned_from`/`assigned_to`; đầu mút `NULL` không bổ sung
giới hạn ở phía tương ứng. Validation phải được thực hiện lại ở backend trong
tenant và Cohort hiện hành.

## Indexes

Indexes cover tenant/cohort/status, tenant/teacher and date range. Foreign keys
to tenant, Cohort and User use `RESTRICT`.
