# Table: core_course_cohort_teachers

Version: 1.3

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-15

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

## Assignment Deactivation Policy — 2026-08-10

Approved: 2026-08-10 bởi LearnForge Architecture Owner. Amendment này lấp
`DOC-CONFLICT-0004`: trước đây không tài liệu nào quy định điều gì xảy ra với
`core_liveclass_session_teachers` khi một assignment cấp Cohort bị vô hiệu hóa.

Gỡ giáo viên khỏi Cohort là chuyển `status` sang `inactive`. Không xóa dòng
assignment và không xóa lịch sử.

Thao tác này **fail-closed**. Nó phải bị từ chối khi giáo viên còn được phân
công ở bất kỳ Session nào của Cohort thỏa **đồng thời** ba điều kiện:

```text
status ∉ (cancelled, no_show)

scheduled_end_at còn ở tương lai theo quy ước thời gian canonical của Session

Session chưa có Attendance, Recording, Replay hoặc operational evidence khác
```

Thông báo lỗi phải liệt kê đầy đủ các Session chặn thao tác để người dùng thay
giáo viên trước, thay vì chỉ báo một lỗi chung.

Assignment cấp Session của các Session **đã diễn ra hoặc đã có evidence** là
bằng chứng lịch sử về người đã dạy. Chúng không bị xóa, không bị sửa và không
chặn việc gỡ assignment cấp Cohort. Một giáo viên chỉ còn liên quan tới Session
quá khứ vì vậy vẫn gỡ được bình thường và không bị khóa vĩnh viễn.

Không dùng cascade delete cho assignment cấp Session. Việc âm thầm biến nhiều
Session thành chưa phân công sẽ đẩy lỗi ra xa nguyên nhân — Cohort activation
sau đó thất bại với thông báo "Session chưa có giáo viên" ở một màn hình khác —
và là thao tác phá hủy khó đảo ngược. Nguyên tắc này nhất quán với
[ADR-0001](../../adr/ADR-0001-Course-Foundation.md): activation không được âm
thầm loại bỏ membership không hợp lệ.

Kiểm tra chạy trong cùng transaction với thao tác gỡ, sau khi lock dòng Cohort.
Hợp đồng phía Session nằm tại
[core_liveclass_session_teachers](../liveclass/core_liveclass_session_teachers.md).

## Indexes

Indexes cover tenant/cohort/status, tenant/teacher and date range. Foreign keys
to tenant, Cohort and User use `RESTRICT`.

### Phase 4E Tenant Parent-Key Prerequisite — Planned

Implementation Status for this index: Not Implemented.

```sql
UNIQUE uk_core_course_cohort_teachers_id_customer
(id, customer_id);
```

This candidate key does not change assignment lifecycle or ownership. It is
reserved for the future tenant-safe composite foreign key from the
LiveClass-owned Teacher Judgment source. Migration, read-only real-schema
preflight and physical contract activation remain separately gated by the
[Course Parent-Key Prerequisite Review](../../quality/LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md).
