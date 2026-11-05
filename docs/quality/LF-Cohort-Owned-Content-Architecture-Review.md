# Cohort Owned Content Architecture Review

Version: 1.0

Document Status: Draft

Implementation Status: Not Applicable

Last Updated: 2026-11-05

Review Date: 2026-11-05

Document Path: quality/LF-Cohort-Owned-Content-Architecture-Review.md

Related ADR: [ADR-0024 — Cohort Owned Content](../adr/ADR-0024-Cohort-Owned-Content.md)

---

# Đã rút

Owner rút ADR-0024 ngày 2026-11-05 và chọn không sinh bản chép. Không sửa Guardrails, không có migration. Phần dưới chỉ còn giá trị lưu vết.

# Kết luận

**Pass with changes.** Hướng làm khả thi. Chưa được Freeze hoặc tạo migration cho tới khi Owner duyệt phần sửa Guardrails ở mục 1.

Rà soát do một agent độc lập thực hiện, chỉ đọc code và tài liệu, trên `learnforge_db` chỉ chạy SHOW và SELECT.

# 1. Chặn Freeze: mâu thuẫn với Guardrails

`LF-Architecture-Guardrails.md` có ưu tiên cao hơn ADR. Phần Course Guardrails định nghĩa Version là snapshot bất biến, có đúng bốn trạng thái, và ghi "Template Version snapshots không phải Runtime Course". ADR-0024 đặt bản sửa được của lớp vào cùng bảng, nên phải sửa Guardrails trước.

Đề xuất thêm vào Guardrails, chờ Owner duyệt, chưa áp dụng:

```text
Bản nội dung của lớp (status = cohort, ADR-0024) nằm trong bảng Version nhưng không phải
Version đã xuất bản: không thuộc lifecycle trên, sửa được, và do đúng một Cohort sở hữu.
Quy tắc bất biến áp dụng cho draft_snapshot đã đóng, published, deprecated và archived.
Bản này là nội dung vận hành của lớp; Rule 8 vẫn cấm tạo lại các bảng core_courses*.
```

# 2. Phát hiện mức cao

| # | Vấn đề | Bằng chứng | Cách xử lý |
| --- | --- | --- | --- |
| 1 | Bản lớp lọt vào màn hình Template | `CourseTemplateController::versionsQuery`, `showVersion`, `CourseTemplateVersionMediaAuthorizer` không lọc trạng thái | Loại `cohort` ở cả ba |
| 2 | Xóa cứng lớp nháp thất bại vì FK restrict | `CourseCohortLifecycleService` chỉ xóa lớp | Xóa theo thứ tự đã ghi ở `core_course_cohorts.md` |
| 3 | Check quá lỏng, khóa chưa đủ cho tenant | Bản nháp đầu của tài liệu Database | Đã sửa tài liệu: check đầy đủ, `uk_cctv_id_customer`, FK kép, unique |
| 4 | Xử lý Media chỉ phục vụ `course_activity` | `MediaService`, `MediaProcessingOrchestrator`, `CourseTemplateController` (preview) | Sửa hợp đồng Media để `course_version_activity` của bản lớp được xử lý; khi chép không đặt `processing_locale` |
| 5 | Session vẫn gắn `version_id` | `CourseCohortOperationController`, `CourseCohortController`, `CourseCohortContentReorder` | Session gắn bản lớp; Enrollment và kiểm tra lớp vận hành giữ `version_id` |

# 3. Phát hiện mức trung bình và mặc định đã chọn

* Chặn xóa còn phải tính ghi chú, bookmark và tiến độ, vì các FK đó restrict.
* Quyền học viên (`VersionActivityAccessService`, `VersionLessonAccessService`) gắn với `enrollment.version_id`. Phải có dịch vụ chọn nội dung trước khi làm màn hình học.
* Thư viện Media (`MediaFileController::collapseLogicalUsages`) cần nhánh riêng cho bản lớp.
* Quyền sửa: mặc định chỉ quản trị viên ở bước đầu. Giáo viên của Template không được đi vào bản lớp qua `CourseMediaOwnerContextAuthorizer`.
* Lớp có `content_version_id = NULL`: đọc qua một helper duy nhất, không sửa và không kéo được. Bỏ bảng thứ tự của ADR-0023 sẽ đưa các lớp này về `sort_order`.

# 4. Dùng lại giao diện Template

Giữ gần như nguyên các file Blade, không tham số hóa các controller Template.

1. `CourseCohortContentViewModel` đổi tên cột `*_snapshot` và `version_*` sang tên view đang đọc.
2. Bộ route `course-cohorts.content.*`, truyền vào các biến tiền tố route đã có.
3. Controller mỏng cho Section, Lesson, Activity, dùng chung bộ quy tắc kiểm tra được tách ra. `Rule::exists` giới hạn trong bản lớp.
4. Loại khóa học đọc từ `course_type_snapshot`; khóa trạng thái trình duyệt trong `list.blade.php` thêm tiền tố để id lớp không trùng id Template.
5. `list.blade.php` thêm một include tùy chọn cho ô ngày và giáo viên. Đây là ngoại lệ nhỏ của mục 3 trong ADR-0024.

# 5. Đã kiểm chứng

Chạy trên MariaDB 11.4.12 tạm, cấu trúc rút gọn đúng các khóa đề xuất, không phải migration thật:

* Chấp nhận: bản lớp cho hai lớp cùng Template không đụng số Version; nhiều mục cùng `source` là `NULL`.
* Từ chối: `published` thiếu số, `published` có chủ, `cohort` thiếu chủ, `cohort` có số, `cohort` là `is_current`, bản thứ hai của một lớp, chủ khác tenant, gắn bản khác tenant, hai lớp dùng chung bản, xóa lớp còn gắn bản, xóa bản còn nội dung.
* Thứ tự xóa đầy đủ chạy được với FK vòng.

Agent cũng xác nhận an toàn, chưa chạy kiểm thử: `CourseCohortVersionResolver`, `CourseProductVersionSummaryPresenter`, bộ kiểm tra của `CourseProductController`, `CourseEnrollmentLifecycleService`, `CourseTemplateVersionDuplicatingService`, `CourseTemplatePublishingService`, đồng bộ tri thức trong `MediaReadService`.

# 6. Chưa kiểm chứng

* Migration thật và `schema:drift --fresh`.
* FK kép và `change()` nullable trên SQLite dùng cho test.
* Toàn bộ nơi gọi `TeacherJudgmentService` và `LearningCourseCompetencyReadService`.
