# ADR-0032 — Course Quiz Activity

Version: 1.0

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-11

Proposal Date: 2026-11-11

Approval Date: 2026-11-11

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0032-Course-Quiz-Activity.md

Related ADRs:

* [ADR-0025 — Assessment Simple Administration](ADR-0025-Assessment-Simple-Administration.md): phiên bản đề bất biến, lần làm bài, luật hiển thị D12
* [ADR-0027 — Assessment Grader Assignment And Essay Files](ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md): ai chấm bài
* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md): Version Activity bất biến
* [ADR-0030 — Learner Course Delivery And Progress](ADR-0030-Learner-Course-Delivery-And-Progress.md): trang bài học, `CourseProgressRecorder`
* [ADR-0031 — Learner Learning Results](ADR-0031-Learner-Learning-Results.md): D4 để điểm bài kiểm tra trong khóa lại cho ADR này

---

# Context

Activity loại `quiz` đã có ở Template: giáo viên chọn đề qua popup (Quiz Picker
Amendment 2026-11-10), cột `assessment_quiz_id` lưu đề. Nhưng:

* Publish chặn mọi Template có Quiz (`activity_quiz_unavailable`), vì chưa có
  cách gắn đề bất biến vào Version Activity. `assessment_quiz_id_snapshot` chỉ là
  cột dự phòng, không khóa ngoại.
* `core_assessment_attempts` đã có `enrollment_id`, `product_id`,
  `template_version_id`, `version_activity_id` (nullable, chưa khóa ngoại) và
  CHECK "đúng một nguồn quyền", nhưng `AssessmentAttemptService::start` chỉ nhận
  ghi danh đề và luôn dùng phiên bản hiện hành của đề.
* `CourseProgressRecorder` bỏ qua Activity `quiz`; trang bài học hiện "chưa hỗ trợ".

Owner chốt 2026-11-11: số lượt làm theo `max_attempts` của phiên bản đề được chốt
khi xuất bản khóa; "đạt" dùng ngưỡng hoàn thành của Activity, không dùng
`pass_score_percentage` của đề.

---

# Decision

## D1. Chốt phiên bản đề khi xuất bản khóa

* Publish Template đọc đề của Activity: cùng tenant, `status = published`, có
  `current_version_id`. Đề `draft` hoặc `archived` chặn publish
  (`activity_quiz_unavailable` đổi nghĩa thành "đề không dùng được").
* Version Activity lưu `assessment_quiz_id_snapshot` và cột mới
  `assessment_quiz_version_id_snapshot` = `current_version_id` lúc publish. Phiên
  bản phải thuộc đúng đề (ứng dụng kiểm).
* Đề xuất bản lại sau đó không đổi khóa đã publish; khóa dùng phiên bản mới khi
  Template publish Version mới. Đề bị lưu trữ sau khi khóa publish không làm hỏng
  khóa: phiên bản đã chốt vẫn bất biến và vẫn làm được (lưu trữ chỉ đóng ghi danh).
* Quiz Activity không được là bài học thử (`is_preview = 0`): làm bài cần Enrollment.
* `completion_rule = pass` không đi cùng đề có `show_result_mode = hidden`
  (publish chặn), vì "Hoàn thành" sẽ lộ kết quả đạt mà học viên không được xem.

## D2. Nguồn quyền là Enrollment

* Lần làm bài trong khóa có `enrollment_id`, `product_id`,
  `template_version_id`, `version_activity_id`; `quiz_registration_id` NULL.
  `quiz_id` / `quiz_version_id` lấy từ Version Activity, không từ phiên bản hiện
  hành của đề.
* **Bắt đầu** cần: Enrollment của chính học viên, `VersionActivityAccessService`
  và `VersionLessonAccessService` cho phép (đúng các luật mở khóa, hạn học, cửa
  sổ theo buổi đang dùng cho Activity khác). Trong một transaction: khóa dòng
  Enrollment (`lockForUpdate`); có lần `in_progress` của cùng Enrollment +
  Activity thì trả lại lần đó; nếu không, đếm lần đã làm, kiểm
  `< max_attempts` của phiên bản đã chốt, tạo lần mới `attempt_no = đã làm + 1`.
* **Trả lời / nộp** lần đang làm chỉ cần đúng chủ và còn hạn (`deadline_at`,
  `assessment:close-overdue` vẫn áp dụng), để hết hạn học giữa chừng không làm mất
  bài. Enrollment `cancelled` thì không trả lời / nộp được nữa.
* Đề trong khóa không hiện ở "Bài thi của tôi" và không dùng ghi danh;
  `exhaustUsedUp` (ADR-0025 D8a) không áp dụng.

## D3. Hoàn thành Activity

`CourseProgressRecorder` là nơi ghi duy nhất (ADR-0030). Ánh xạ sang
`core_course_activity_progress.completion_rule`:

| Version Activity | Activity Progress | Hoàn thành khi |
| --- | --- | --- |
| `submit` | `submitted` | nộp một lần (học viên nộp hoặc hết giờ) |
| `pass` | `passed` | một lần `graded` có `score_percentage >= completion_threshold` của Activity |
| `manual` | `manual` | học viên bấm "Đánh dấu hoàn thành" (như Activity khác) |

* Gọi recorder khi nộp và khi chấm xong (cả chấm lại). `attempt_count`,
  `score`, `max_score`, `passed` của Activity Progress lấy từ lần `graded` có
  `score_percentage` cao nhất; `passed` so với ngưỡng của Activity.
* Đã hoàn thành thì không thu hồi khi chấm lại ra điểm thấp hơn (giống các Activity
  khác: tiến độ chỉ tăng). `pass_score_percentage` của đề vẫn chỉ quyết định cột
  `passed` của chính lần làm bài.
* `recalculate` tính cả Activity `quiz`; khóa có Quiz chỉ hoàn thành khi Quiz
  bắt buộc hoàn thành.

## D4. Học viên thấy gì

* Trang bài học: thẻ Quiz có tên đề, số câu, thời lượng, lượt đã dùng / tối đa,
  nút "Làm bài" / "Làm tiếp" / "Hết lượt", và các lần đã nộp. Điểm, đáp án, nhận
  xét theo luật D12 của **phiên bản đã chốt** (`AssessmentLearnerService::visibility`).
* Trang làm bài và trang kết quả dùng lại giao diện module Kiểm tra; nút quay lại
  về bài học. Quyền đọc lần làm bài: `user_id` là học viên đang đăng nhập.
* "Kết quả học tập" (ADR-0031): nhóm Bài thi thêm các Quiz trong khóa, mỗi dòng một
  Enrollment + Activity, ghi tên khóa; vẫn chỉ dùng điểm được xem (ADR-0031 D3).

## D5. Chấm bài

Như ADR-0027: người tạo đề, người có phạm vi chấm của đề, người được phân công,
`customer_admin`. Không mở thêm cho giáo viên của lớp. Danh sách chấm hiện nguồn
"Khóa học: tên khóa" cho lần làm trong khóa.

## D6. Cơ sở dữ liệu

Chi tiết trong Database Docs (Amendment 2026-11-11, Approved):

* `core_course_template_version_activities`: thêm `assessment_quiz_version_id_snapshot`;
  `UNIQUE (id, customer_id)`; FK tenant tới đề và phiên bản đề; CHECK Quiz có đủ
  cả hai, Activity khác không có.
* `core_course_template_activities`: FK tenant `assessment_quiz_id` → đề.
* `core_assessment_attempts`: FK tenant tới Enrollment và Version Activity, FK tới
  Product và Template Version; CHECK ngữ cảnh khóa học đủ khi có `enrollment_id`
  và rỗng khi có `quiz_registration_id`; UNIQUE số lượt và một lần `in_progress`
  cho mỗi Enrollment + Activity.
* `core_course_activity_progress`: không đổi schema (đã có `submitted`, `passed`,
  `score`, `max_score`, `attempt_count`).

Dữ liệu dev hiện không có Version Activity `quiz` và không có lần làm bài gắn
khóa học, nên migration không cần chuyển dữ liệu.

## D7. Chưa làm

Điểm Quiz vào chứng chỉ, Quiz theo buổi học của lớp có giáo viên mở / đóng, làm
lại cả khóa, đổi đề của khóa đã publish (cần Version mới).

---

# Alternatives rejected

* **Dùng phiên bản hiện hành của đề lúc học viên bắt đầu:** khóa đã publish sẽ đổi
  nội dung theo đề, trái ADR-0012.
* **Tạo ghi danh đề ẩn cho mỗi Enrollment:** hai nguồn sự thật cho quyền và lượt,
  phải đồng bộ hạn học / hủy Enrollment.
* **Ngưỡng `pass_score_percentage` của đề:** Owner chọn ngưỡng của Activity.

# Consequences

* Template có Quiz publish được; khóa đã publish giữ đúng đề đã duyệt.
* Lượt làm của khóa và của ghi danh đề tách nhau: cùng một đề có thể vừa ở "Bài thi
  của tôi" vừa ở trong khóa, mỗi nơi tính lượt riêng.
* Đề đã được khóa dùng không xóa được (đã có luật chỉ xóa đề chưa từng xuất bản;
  FK giữ thêm).

# Questions for the Owner

Owner duyệt ADR và Database Docs Amendment 2026-11-11 ("anh duyệt"), gồm ba quy định thêm ở D1 và D5. Đã chốt trước đó: lượt theo `max_attempts` của phiên bản đã chốt; "đạt" theo
ngưỡng Activity.

# Implementation outline (sau khi duyệt)

1. Migration theo D6 (kiểm trên MariaDB 11.4 tạm), cập nhật schema contract.
2. Publish: kiểm đề, chốt phiên bản, chặn `is_preview` / `pass` + `hidden`.
3. `AssessmentAttemptService::startForActivity`; quyền đọc theo `user_id`.
4. `CourseProgressRecorder::quizChanged` gọi khi nộp / chấm xong.
5. Trang bài học, trang làm bài, danh sách chấm, "Kết quả học tập".
6. Test feature + `tests/Integration/*MariaDb*` cho CHECK / UNIQUE / khóa đồng thời.

# Implementation record — 2026-11-11

* Migration `2026_11_11_000100_bind_course_quiz_activities` (D6); kiểm up / down trên MariaDB 11.4 tạm;
  `LF-SCHEMA-CONTRACT.json` thêm cột, chỉ mục, khóa ngoại, CHECK mới.
* Publish: `CourseTemplatePublishGraphValidator` (`activity_quiz_unavailable`, `activity_quiz_rules`),
  `CourseTemplatePublishingService` chốt `current_version_id` dưới shared lock.
* `AssessmentAttemptService::startForActivity`; `courseProgress` gọi
  `CourseProgressRecorder::quizChanged` sau commit (bắt đầu, nộp, chấm, chấm lại).
* `LearnerCourseQuizService` (quyền, thẻ Quiz), route `student.courses.activities.attempts.store`,
  thẻ Quiz ở trang bài học, trang kết quả quay về bài học, nguồn "Khóa học" ở danh sách chấm,
  nhóm Bài thi của "Kết quả học tập".
* Trang làm bài và trang kết quả của Quiz trong khóa có link quay về bài học và không làm sáng
  menu "Bài đánh giá". `CourseProgressRecorder::recalculate` ghi `assessment_completed` /
  `assessment_total` của `core_course_progress` = số Quiz Activity bắt buộc đã hoàn thành / tổng.
* Test: `tests/Feature/CourseQuizActivityTest.php`,
  `CourseTemplatePublishingTest::test_a_quiz_activity_publishes_with_the_quiz_version_current_at_publish`,
  `tests/Integration/CourseQuizActivityMariaDbTest.php`.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
