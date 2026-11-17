# Table: core_course_cohort_teachers

Version: 1.6

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-17

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

**Live Session Amendment (Owner 2026-11-10):** Session đang `live` luôn chặn thao
tác, kể cả khi đã có Attendance hoặc đã quá `scheduled_end_at`, vì giáo viên đang
dạy buổi đó. Session đã kết thúc có evidence vẫn không chặn.

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

## Assignment Edit Rule — 2026-11-05

Assignment đang hoạt động được sửa `role`, `assigned_from`, `assigned_to` khi
Cohort còn cho phép sửa; không đổi `teacher_id`. Các luật về `primary_teacher`
và khoảng phụ trách ở trên giữ nguyên.

Thao tác sửa không thay đổi `core_liveclass_session_teachers`. Với vai trò
`teacher` hoặc `assistant`, thao tác bị từ chối (fail-closed) nếu khoảng mới làm
một Session thuộc nhóm chặn của Assignment Deactivation Policy rơi ra ngoài
khoảng phụ trách, và phải liệt kê đầy đủ các Session đó. Kiểm tra chạy trong
cùng transaction, sau khi lock dòng Cohort.

## Value Constraint Amendment — 2026-11-17

Approved: 2026-11-17 bởi LearnForge Architecture Owner, cả ba ràng buộc. Owner
xác nhận không cần ADR: amendment chỉ ép vocabulary và luật đã Approved, không
đổi Foundation.

Implementation Status: Deployed on the development database `learnforge_db`
(migration `2026_11_17_000100_add_value_checks_to_course_cohort_teachers`);
production not authorized.

### Lý do

Ngày 2026-11-17, một dòng dữ liệu demo trên `learnforge_db` được ghi tay với
`role = 'primary'` (vocabulary của `core_course_template_teachers`, không phải
của bảng này). Cột `role` là `varchar(50)` không có ràng buộc nên dòng đó được
chấp nhận. Hậu quả: tab Giáo viên của Lớp học hiện khóa ngôn ngữ thô, Cohort
không được coi là có giáo viên chính, và `assigned_to` bị bỏ trống. Validation
ở tầng HTTP (`Rule::in`) chỉ bảo vệ đường ghi qua form; seed, lệnh và ghi trực
tiếp vào DB đi vòng qua nó.

### Ràng buộc

Ba ràng buộc CHECK. Chúng chỉ ép các giá trị và luật **đã Approved** ở mục
Fields và Rules; không thêm, bớt hay đổi nghĩa giá trị nào.

```sql
ALTER TABLE core_course_cohort_teachers
    ADD CONSTRAINT chk_core_course_cohort_teachers_role
        CHECK (role IN ('primary_teacher', 'teacher', 'assistant')),
    ADD CONSTRAINT chk_core_course_cohort_teachers_status
        CHECK (status IN ('active', 'inactive')),
    ADD CONSTRAINT chk_core_course_cohort_teachers_period
        CHECK (assigned_from IS NULL OR assigned_to IS NULL OR assigned_from <= assigned_to);
```

Ngoài phạm vi, vẫn do tầng ứng dụng bảo đảm trong transaction như hiện nay:

- tối đa một `primary_teacher` đang `active` mỗi Cohort;
- khoảng phụ trách của `primary_teacher` bằng khoảng vận hành của Cohort;
- khoảng phụ trách không vượt ngoài khoảng vận hành của Cohort.

Ba luật này cần so với dòng khác hoặc bảng khác nên CHECK không biểu diễn được.

### Điều kiện trước migration

Migration phải dừng (fail-closed) nếu còn dòng vi phạm; không tự sửa dữ liệu.
Câu kiểm tra phải trả về 0 dòng trên mọi môi trường trước khi chạy:

```sql
SELECT id, customer_id, cohort_id, role, status, assigned_from, assigned_to
FROM core_course_cohort_teachers
WHERE role NOT IN ('primary_teacher', 'teacher', 'assistant')
   OR status NOT IN ('active', 'inactive')
   OR (assigned_from IS NOT NULL AND assigned_to IS NOT NULL AND assigned_from > assigned_to);
```

Trạng thái `learnforge_db` ngày 2026-11-17, sau khi sửa dòng demo nói trên:
0 dòng vi phạm (3 dòng, đều `primary_teacher` / `active`).

### Triển khai và kiểm chứng

- Migration chỉ thêm ràng buộc khi driver là `mysql`/`mariadb`, theo cách các
  migration CHECK hiện có; `down()` gỡ đúng ba ràng buộc này.
- SQLite bỏ qua CHECK, nên phải kiểm trên một instance MariaDB 11.4 tạm bằng
  test `tests/Integration/*MariaDb*`: ghi `role = 'primary'`, `status` lạ và
  khoảng ngày ngược đều bị từ chối; ba giá trị hợp lệ được chấp nhận.
- Chạy `php artisan schema:drift --docs-only` và
  `php artisan schema:drift --connection=mysql`.
- Sao lưu `learnforge_db` trước khi migrate.

Kết quả 2026-11-17 trên MariaDB 11.4.12 tạm:
`tests/Integration/CourseCohortTeacherValueChecksMariaDbTest.php` PASS; `down()`
gỡ đủ ba ràng buộc và `up()` thêm lại được; `up()` dừng khi còn một dòng
`role = 'primary'` và không thêm ràng buộc nào. Ba biểu thức đã ghi vào
`LF-SCHEMA-CONTRACT.json`; `schema:drift --connection=mysql` PASS trên instance
tạm và trên `learnforge_db`.

## Indexes

Indexes cover tenant/cohort/status, tenant/teacher and date range. Foreign keys
to tenant, Cohort and User use `RESTRICT`.

### Phase 4E Tenant Parent-Key Prerequisite

Implementation Status for this index: Deployed on the development database
`learnforge_db`; production not authorized.

```sql
UNIQUE uk_core_course_cohort_teachers_id_customer
(id, customer_id);
```

This candidate key does not change assignment lifecycle or ownership. It is
reserved for the future tenant-safe composite foreign key from the
LiveClass-owned Teacher Judgment source. Migration, physical contract and
development deployment passed; production remains separately gated by the
[Course Parent-Key Prerequisite Review](../../quality/LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md).
