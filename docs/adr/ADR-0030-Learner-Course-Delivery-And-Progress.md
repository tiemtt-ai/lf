# ADR-0030 — Learner Course Delivery And Progress Recording

Version: 1.0

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-10

Proposal Date: 2026-11-10

Approval Date: 2026-11-10

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0030-Learner-Course-Delivery-And-Progress.md

Related ADRs:

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md): Rule 4 (Enrollment Freeze), Rule 5 (Versioned Progress)
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md): Attendance thuộc LiveClass; trang khóa học của học viên "chưa có"
* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md): signed delivery
* [ADR-0005 — Track Foundation](ADR-0005-Track-Foundation.md): Track không sở hữu Course Progress
* [ADR-0015 — Course Lesson Multiple Prerequisites](ADR-0015-Course-Lesson-Multiple-Prerequisites.md): điều kiện mở bài đọc Lesson Progress
* [ADR-0022 — Course Type On Template](ADR-0022-Course-Type-On-Template.md): cửa sổ Trước / Trong / Sau buổi học vẫn là câu hỏi mở
* [ADR-0023 — Cohort Content Order](ADR-0023-Cohort-Content-Order.md): học viên thấy thứ tự của lớp mình

---

# Context

Đợt 1 (2026-11-10) đã có các trang chỉ đọc cho học viên: `/my-courses` (Khóa học,
Lớp học, Bài thi), chi tiết khóa và chi tiết lớp
([LF-Navigation § My Courses Read Pages](../business/LF-Navigation.md)).

Còn thiếu ba việc, và chưa ADR nào cho phép làm:

1. **Mở nội dung bài học.** Học viên chưa xem được video, âm thanh hay tài liệu của
   Version Activity. Hiện chỉ admin (xem trước Version) và bảng điện tử của buổi học
   đang diễn ra được cấp signed URL cho Media của Activity.
2. **Ghi tiến độ.** Ba bảng `core_course_progress`, `core_course_lesson_progress`,
   `core_course_activity_progress` đã có schema và tài liệu, nhưng chưa có code nào ghi.
   Vì vậy điều kiện mở bài `previous_lesson_completed`, `selected_lessons_completed` và
   `all_previous_lessons_completed` hiện khóa vĩnh viễn mọi bài phụ thuộc.
3. **Quan tâm (Favorite).** Bảng `core_course_favorites` đã có, chưa có nút.

Ràng buộc đã có hiệu lực:

* Progress luôn gắn `enrollment_id`, `version_lesson_id` và `version_activity_id`; không
  dùng id của Template đang sửa (Guardrails Rule 5, ADR-0001 Rule 5).
* `product_id` và `version_id` lấy từ Enrollment, không nhận từ request (DB docs § Runtime
  Invariants của ba bảng progress).
* Track không sở hữu Course Progress (ADR-0005). Track chưa triển khai.
* `media_access_logs` không được dùng để tính Course Progress hay Attendance (LF-Media § Access Audit).
* Tài liệu (PDF, Office) mở bằng signed URL trong tab mới. Chỉ bảng điện tử được hiện PDF
  trong trang (LF-Media § Whiteboard Rendering Exception).
* Activity Quiz chưa xuất bản được (LF-Core-Course § Activity Learning Availability):
  chưa có Version nào chứa Quiz Activity.
* Hệ thống chưa có môi trường production.

---

# Decision

## D1. Trang bài học của học viên

* Route mới, trong nhóm middleware học viên hiện có (`tenant`, `auth`, `verified`,
  `tenant.user`, `role:student`):

  ```text
  GET  /my-courses/{enrollment}/lessons/{versionLesson}
  ```

* Trang hiện các Activity của Lesson theo thứ tự hiển thị. Khóa học theo lớp dùng thứ tự
  của lớp (ADR-0023, `CourseCohortContentOrder`); khóa tự học dùng thứ tự của Version.
  Mục lục ở trang chi tiết khóa (Đợt 1) cũng đổi sang thứ tự của lớp với khóa theo lớp.
* Mỗi Activity hiện quyết định của `VersionActivityAccessService` (mở / khóa và lý do).
  Lesson bị `VersionLessonAccessService` khóa thì trả 403 với lý do, không lộ nội dung Activity.
* Enrollment không phải của học viên, khác tenant, hoặc `cancelled`: 404, như Đợt 1.
* Nút "Học tiếp" ở trang chi tiết khóa mở `last_version_lesson_id` của Course Progress;
  chưa có thì mở bài đầu tiên đang mở.

## D2. Mở nội dung từng loại Activity

| Loại | Cách mở |
| --- | --- |
| `video` | Trình phát trong trang, signed URL ngắn hạn |
| `audio` | Trình phát trong trang, signed URL ngắn hạn |
| `embedded_video` | iframe từ `TrustedVideoUrlService::embedUrl` (YouTube, Vimeo), như bảng điện tử |
| `document` | Tab mới bằng signed URL (giữ luật LF-Media). Xem câu hỏi Q3 |
| `live_class` | Không có nội dung. Hiện buổi học của lớp gắn Activity này, nút vào bảng hoặc phòng họp khi buổi đang diễn ra (dùng lại `learnerLiveSessions`) |
| `quiz` | Ngoài phạm vi. Hiện "Chưa hỗ trợ" nếu gặp; không có Version nào chứa loại này |

* MediaService có thêm `generateLearnerActivitySignedUrl(enrollmentId, versionActivityId)`.
  Hàm chỉ cấp URL khi tất cả điều kiện sau đúng, trong cùng một truy vấn:
  * `VersionActivityAccessService` cho phép;
  * `VersionLessonAccessService` cho phép Lesson chứa Activity (bổ sung 2026-11-11: trước đó
    Activity mở trong Lesson bị khóa vẫn lấy được URL);
  * Activity thuộc `enrollment.version_id`;
  * có Usage `course_version_activity` đang active, đúng `usage_type`;
  * Media `ready`, cùng tenant.
* Hạn signed URL theo `media.signed_url_ttl_minutes`; trình phát xin URL mới khi hết hạn,
  không kéo dài thời hạn.
* Không lưu signed URL. Media vẫn ghi `media_access_logs` như hiện hành, nhưng log đó không
  dùng để tính tiến độ.

## D3. Ghi tiến độ

Course là owner của tiến độ (ADR-0005). Không ghi qua Track.

### Endpoint

```text
POST /my-courses/{enrollment}/activities/{versionActivity}/progress
     event = opened | position | completed
     position_seconds (chỉ cho event = position)
```

* Server tự lấy `product_id`, `version_id`, `version_section_id`, `version_lesson_id`,
  `activity_type`, `is_required`, `completion_rule`, `completion_threshold` từ Enrollment và
  Version Activity. Request chỉ mang `event` và vị trí.
* Chỉ ghi khi Enrollment `active`, đang trong hạn học hoặc ôn tập, và
  `VersionActivityAccessService` cho phép. Ngoài điều kiện đó: 403, không ghi gì.
* Ghi trong một transaction, khóa dòng `core_course_progress` của Enrollment trước rồi mới
  tạo hoặc sửa dòng Lesson và Activity, để hai tab cùng gửi không tạo trùng.
  Dòng Course Progress được tạo khi học viên mở bài đầu tiên. Unique key sẵn có của ba bảng
  là lớp chặn cuối.

### Quy tắc hoàn thành Activity

Version Activity dùng từ vựng của Template; bảng progress dùng từ vựng riêng. Ánh xạ:

| Version `completion_rule` | Progress `completion_rule` | Hoàn thành khi |
| --- | --- | --- |
| `view` | `viewed` | Học viên mở Activity (event `opened`; với `document` trang gửi `opened` sau khi mở tab, xem Implementation record) |
| `watch_percent` (video) | `watch_percentage` | Vị trí xem cao nhất đã ghi nhận ≥ `completion_threshold`% thời lượng. Xem Q1 |
| `watch_percent` (audio) | `listen_percentage` | Như trên |
| `manual` | `manual` | Học viên bấm "Đánh dấu đã học" (event `completed`) |
| `join` (live_class) | `attended` | LiveClass ghi `present` hoặc `late` cho Enrollment ở một buổi gắn Activity này, buổi không `cancelled` / `no_show` |
| `submit`, `pass` (quiz) | — | Ngoài phạm vi |

* Với `watch_percentage` / `listen_percentage`, server không tin vị trí do trình duyệt gửi
  một cách tuyệt đối: vị trí cao nhất chỉ được tăng tối đa bằng thời gian thực trôi qua kể
  từ lần ghi trước, nhân 1,5 (cho phép tua nhanh nhẹ), cộng 30 giây.
* `last_position_seconds` lưu vị trí cuối để phát tiếp; `total_learning_seconds` cộng
  dồn theo cùng giới hạn trên.
* Activity đã `completed` không quay lại trạng thái chưa hoàn thành, trừ `attended`: trạng
  thái này đi theo điểm danh, nên giáo viên sửa thành vắng thì Activity quay lại chưa hoàn thành.
* Activity `attended` được tính lại khi điểm danh của buổi đó thay đổi: LiveClass gọi một
  hàm của Course sau khi ghi điểm danh (QR qua `LiveClassAttendanceCheckIn`, điểm danh tay
  ở trang lớp qua `CourseCohortOperationController`). Course không đọc
  `media_access_logs` và LiveClass không ghi bảng progress.

### Lesson và Course

* Lesson `completed` khi mọi Activity bắt buộc đã hoàn thành. Lesson không có Activity bắt
  buộc thì `completed` khi mọi Activity đã hoàn thành. Lesson không có Activity nào thì
  `completed` khi học viên mở bài.
* Lesson `in_progress` khi có ít nhất một Activity đã bắt đầu.
* Course Progress: `progress_percentage` = Activity bắt buộc đã hoàn thành / tổng Activity
  bắt buộc của Version (bỏ Activity `quiz`). Nếu Version không có Activity bắt buộc thì
  tính theo Lesson. Các cột đếm (`completed_lessons`, `total_lessons`, ...) và
  `last_version_lesson_id`, `last_version_activity_id`, `last_accessed_at` cập nhật
  cùng transaction.
* Course Progress `completed` khi mọi Lesson đã `completed`. Xem Q2 về Enrollment.
* Progress giữ nguyên khi Enrollment hết hạn; không ghi thêm sau đó.
* Lesson Progress `completed` là đầu vào của điều kiện mở bài hiện có (ADR-0015). Không đổi
  cách `VersionLessonAccessService` đọc.

## D4. Quan tâm (Favorite) — hoãn

Owner chọn Q4 = B: chưa làm trong Đợt 2. Thiết kế dưới đây giữ để làm sau, khi trang
danh sách khóa học công khai dùng dữ liệu thật:

```text
POST   /my-courses/favorites/{product}
DELETE /my-courses/favorites/{product}
```

* Chỉ Product `active` của tenant. Unique `(customer_id, student_id, product_id)` sẵn có;
  bấm hai lần không lỗi.
* Favorite không đồng nghĩa Enrollment (Guardrails Rule 6). Tab "Quan tâm" của "Khóa học
  của tôi" liệt kê Product đã đánh dấu, có hoặc chưa ghi danh.

## D5. Không đổi

* Không tạo bảng, cột hay migration. Ba bảng progress và bảng favorites dùng như schema đã
  duyệt; `metadata` của Activity Progress chỉ chứa thông tin kỹ thuật (lần ghi cuối dùng
  cho giới hạn ở D3).
* Không đổi cách tính quyền mở bài, mở Activity và hạn học.
* Không tính cửa sổ Trước / Trong / Sau buổi học (vẫn là câu hỏi mở của ADR-0022).
* Không làm Kết quả học tập, Certificate, Notes, Bookmarks, Reviews.

---

# Questions for the Owner

| # | Câu hỏi | Lựa chọn | Đề xuất |
| --- | --- | --- | --- |
| Q1 | Video và âm thanh "xem đủ X%" tính thế nào? | A. Vị trí xem cao nhất, server giới hạn tốc độ tăng (D3). B. Đếm từng đoạn đã xem thật, cần lưu danh sách đoạn trong `metadata` | A: đủ chống bấm tua thẳng tới cuối, đơn giản hơn nhiều |
| Q2 | Học xong mọi bài thì Enrollment có tự chuyển `completed` không? | A. Chỉ Course Progress `completed`; Enrollment giữ `active` đến khi có quy tắc Completion và Certificate. B. Tự chuyển Enrollment sang `completed` | A: `completed` của Enrollment là lifecycle, đang do admin đổi; tab "Đã hoàn thành" của Đợt 1 sẽ đọc thêm Course Progress |
| Q3 | Tài liệu PDF trong bài học mở ở đâu? | A. Tab mới (giữ luật LF-Media). B. Hiện trong trang bằng pdf.js như bảng điện tử, cần mở rộng Whiteboard Rendering Exception | A cho Đợt 2; B làm sau nếu cần |
| Q4 | Làm "Quan tâm" trong Đợt 2? | A. Làm (D4). B. Để khi trang danh sách khóa học công khai dùng dữ liệu thật | B: trang `/courses` công khai hiện còn dữ liệu giả, chỉ đánh dấu được khóa đã ghi danh thì ít giá trị |
| Q5 | Trong thời gian ôn tập có ghi tiến độ không? | A. Có, như thời gian học. B. Không, chỉ cho xem | A |

---

# Alternatives rejected

* **Ghi tiến độ qua Track rồi tính lại.** Track chưa triển khai và không sở hữu Course
  Progress (ADR-0005).
* **Tính tiến độ từ `media_access_logs`.** LF-Media cấm; log chỉ để audit.
* **Tin vị trí xem do trình duyệt gửi.** Học viên tua thẳng tới cuối là hoàn thành.
* **Cấp signed URL dài hạn cho trình phát.** Trái Signed Delivery Principle.
* **Gộp Quiz Activity vào đợt này.** Quiz Activity chưa xuất bản được; cần ADR riêng về
  ràng buộc Quiz với Version và với ghi danh đề (ADR-0025).

---

# Consequences

* Điều kiện mở bài theo bài trước bắt đầu có tác dụng thật.
* Trang "Khóa học của tôi" và chi tiết khóa hiện tiến độ thật.
* Thêm một điểm ghi dữ liệu do học viên kích hoạt: cần throttle cho endpoint progress
  (đề xuất 60 lần/phút/người) và test đồng thời trên MariaDB như các luồng ghi khác.
* LiveClass gọi sang Course sau khi ghi điểm danh: thêm một phụ thuộc một chiều
  LiveClass → Course service, không chạm bảng của nhau.

---

# Implementation outline (sau khi duyệt)

1. Domain docs: LF-Core-Course § Learning Progress (quy tắc D3), LF-Media § Domain
   Integrations / Course (D2), DB docs ba bảng progress (ánh xạ `completion_rule`).
2. Review kiến trúc theo docs/governance/LF-Architecture-Review-Checklist.md.
3. Code: trang bài học, trình phát, MediaService, `CourseProgressRecorder`, hook điểm danh,
   mục lục theo thứ tự lớp.
4. Test SQLite và MariaDB (ghi đồng thời, giới hạn tốc độ, khóa bài phụ thuộc, 404/403).

# Approval

Owner duyệt 2026-11-10 ("Duyệt theo đề xuất"), chọn đúng các đề xuất:

* Q1 = A: vị trí xem cao nhất, server giới hạn tốc độ tăng.
* Q2 = A: chỉ Course Progress `completed`; Enrollment không tự đổi trạng thái. Tab "Đã
  hoàn thành" của "Khóa học của tôi" đọc thêm Course Progress `completed`.
* Q3 = A: tài liệu mở tab mới; không mở rộng Whiteboard Rendering Exception.
* Q4 = B: Quan tâm (D4) hoãn.
* Q5 = A: thời gian ôn tập vẫn ghi tiến độ như thời gian học.

# Implementation record — 2026-11-10

* Trang bài học `GET /my-courses/{enrollment}/lessons/{lesson}`; bài bị khóa trả 403, bài của
  Version khác hoặc của người khác trả 404. Mở bài ghi `first_accessed_at` của Lesson Progress.
* `GET /my-courses/{enrollment}/activities/{activity}/media` trả signed URL ngắn hạn
  (`MediaService::generateLearnerActivitySignedUrl`), chỉ khi Activity mở và Media đang gắn
  (`media_file_usages` active, `usage_type` = loại Activity). Video ưu tiên bản faststart.
  Lệnh đọc này **không ghi tiến độ**: tài liệu gửi `opened` riêng sau khi mở tab, để một
  lần lấy lại URL khi hết hạn không bị tính là mở.
* `CourseProgressRecorder` là nơi ghi duy nhất. Mọi transaction đọc Enrollment bằng khóa
  chia sẻ trước tiên: trên MariaDB (REPEATABLE READ) lệnh đọc thường đầu tiên chốt snapshot,
  nên đọc thường trước khi chờ khóa Course Progress làm sự kiện thứ hai không thấy dòng
  Lesson vừa tạo và lỗi `Duplicate entry` (test MariaDB đã bắt lỗi này trước khi sửa).
* Điểm danh: gọi `attendanceChanged` sau QR (`LiveClassAttendanceCheckIn::record`), sau điểm
  danh tay (`CourseCohortOperationController::saveAttendance`) và khi buổi chuyển sang
  `cancelled` / `no_show` (`LiveClassSessionLifecycleService`, sau commit). Điểm danh đi
  thẳng sang `completed` nên không ghi `started_at`; sửa thành vắng thì Activity quay về
  `not_started` nếu học viên chưa từng mở.
* Thứ tự lớp (ADR-0023) dùng cho mục lục, trang bài học và bài trước / bài tiếp.
* Test: `tests/Feature/LearnerMyLearningTest.php`, `tests/Feature/LiveClassAttendanceCheckInTest.php`
  (`test_a_join_activity_follows_attendance_both_ways`), `tests/js/learner-lesson.test.mjs`,
  `tests/Integration/CourseProgressRecorderMariaDbTest.php` (bốn sự kiện đồng thời, chạy trên
  MariaDB 11.4 tạm).
