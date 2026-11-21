# ADR-0030 — Learner Course Delivery And Progress Recording

Version: 1.1

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-21

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
  *Từ [ADR-0022 Amendment 1.5](ADR-0022-Course-Type-On-Template.md#amendment-15--học-viên-lớp-chỉ-học-ở-trang-lớp-approved-2026-11-19-đã-triển-khai-2026-11-19)
  (2026-11-19), học viên lớp không dùng trang chi tiết khóa và trang bài học: cả hai chuyển tới
  trang lớp.*
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
| `external_link` | Bổ sung 2026-11-19 ([ADR-0035](ADR-0035-External-Link-Activity.md)): nút "Mở link" mở link HTTPS đã chốt ở tab mới (`rel="noopener noreferrer"`) và gửi `opened`; link chỉ có trong trang khi Activity được mở |

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

## D4. Quan tâm (Favorite) — đã làm 2026-11-11

Owner chọn Q4 = B: chưa làm trong Đợt 2, làm khi trang danh sách khóa học công khai dùng
dữ liệu thật. Điều kiện đó đạt ngày 2026-11-11 (`TenantCourseCatalogService`); Owner bảo
"làm tiếp việc còn lại" và D4 được làm đúng thiết kế dưới đây:

```text
POST   /my-courses/favorites/{product}
DELETE /my-courses/favorites/{product}
```

* Chỉ Product `active` của tenant. Unique `(customer_id, student_id, product_id)` sẵn có;
  bấm hai lần không lỗi.
* Favorite không đồng nghĩa Enrollment (Guardrails Rule 6). Tab "Quan tâm" của "Khóa học
  của tôi" liệt kê Product đã đánh dấu, có hoặc chưa ghi danh.
* Làm rõ khi triển khai: "Product `active` của tenant" nghĩa là Product đang trên catalogue
  (`public`, trong thời gian hiển thị) hoặc Product học viên đang có Enrollment khác
  `cancelled` (khóa riêng đang học vẫn đánh dấu được). Bỏ đánh dấu luôn được, kể cả khi
  Product đã rời catalogue. Tab Quan tâm chỉ hiện Product còn thỏa điều kiện này.

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

# Implementation record — 2026-11-11 (D4 Quan tâm)

* `POST` / `DELETE /my-courses/favorites/{product}` (`LearnerCourseFavoriteController`,
  chỉ học viên của tenant): `insertOrIgnore` với `source = website`, không tạo Enrollment;
  trả JSON cho script, form thường thì quay lại trang.
* `TenantCourseCatalogService::favoritable`, `favorites` (mới đánh dấu trước),
  `favoriteIds`; thẻ catalogue có `favorite`.
* Nút ♡ (`tenant/partials/favorite-button`) trên thẻ `/courses` và trang chủ, trang chi
  tiết khóa học (có chữ "Quan tâm / Bỏ quan tâm") và thẻ khóa ở "Khóa học của tôi";
  `student-site.js` gửi nền và đổi mọi nút của cùng Product. Khách và nhân viên không thấy nút.
* "Khóa học của tôi" › Khóa học có tab "Quan tâm" (`view=favorite`) hiện thẻ catalogue.
  Lớp học và Bài thi vẫn khóa "Quan tâm" và ♡ (đề thi không phải Product).
* Test: `tests/Feature/TenantWebsiteUiTest.php` (`test_students_mark_courses_quan_tam_without_enrolling`,
  `test_quan_tam_is_for_the_tenants_own_students_only`), `tests/Feature/LearnerMyLearningTest.php`;
  chạy cả trên MariaDB 11.4 tạm.

# Implementation record — 2026-11-21 (trang lớp cập nhật tại chỗ)

* Phản hồi của endpoint tiến độ có thêm `lesson_completed`: id các Activity của cùng Lesson mà
  Enrollment đã hoàn thành (`CourseProgressRecorder::completedInLesson`, lọc theo `customer_id`
  và `enrollment_id`). Hoàn thành một nội dung "Tính điểm danh" có thể hoàn thành luôn Activity
  `live_class` của bài (ADR-0022 Amendment 1.3), nên trang cần biết cả những id đó. Chỉ Activity đã
  hoàn thành mới đọc danh sách này; nhịp `position` 15 giây một lần của video đang xem dở trả `[]`, không tốn
  thêm truy vấn. Nội dung đã xong thì lần ghi nào cũng trả danh sách, nên nếu mất phản hồi của lần ghi vừa hoàn
  thành, lần ghi kế tiếp vẫn cập nhật được thẻ Link học.
* Trang lớp (`tenant/my-learning/cohort.blade.php`): mọi thẻ đang mở, trừ Quiz và Link học, đổi
  nhãn trạng thái ("Đã mở", "Đã xem", "Đã nghe") và dấu "✓ Đã hoàn thành" ngay khi máy chủ trả
  lời. Trước đây chỉ thẻ video/audio làm vậy; tài liệu và link phải tải lại trang. "Hoàn thành x/y
  nội dung bắt buộc" của từng buổi và ô "Tỷ lệ hoàn thành" đầu trang đếm theo cùng sự kiện, từ
  danh sách id máy chủ đã đếm (`LearnerMyLearningService`: `completion_ids`).
* `learner-lesson.js`: trạng thái chỉ đi lên (chưa học → đang học → hoàn thành), nên phản hồi đến
  muộn không lùi lại. Mỗi thẻ báo sự kiện `lf-learn-progress`; các thẻ khác của cùng Activity (bài
  dạy nhiều buổi) và các bộ đếm nghe sự kiện đó. Ô điểm danh của buổi (ví dụ "Vắng mặt" → "Học bù")
  vẫn chỉ đổi khi tải lại trang.
* Safari trên iPhone không cho phần tử thường của trang vào toàn màn hình, chỉ cho chính video (trình
  phát của iOS). Nút toàn màn hình của popup video ở trang lớp và trang khóa tự học dùng
  `enterFullscreen` (`learner-lesson.js`): khung phát nếu trình duyệt cho, không thì video
  (`webkitEnterFullscreen`). Video chưa nạp (đang hỏi "Tiếp tục xem") thì nút phát video như "Tiếp tục xem" rồi
  vào toàn màn hình khi video nạp xong (`playFullscreen`); nếu không phát được (link, mạng) thì lần phát sau không tự vào
  toàn màn hình. Hộp "Tiếp tục xem" của popup khóa tự học nằm ngoài khung phát, nên trên iPad và Chrome khung phát vào
  toàn màn hình với nút "Xem tiếp" của chính nó; khi video bắt đầu phát (bằng nút nào cũng vậy) hộp hỏi ẩn đi, không
  còn che video sau khi thoát toàn màn hình. Ở popup khóa tự học, nút tắt khi không làm được gì
  (`canEnterFullscreen`): trên iPhone là bài audio, bài chỉ có tài liệu và video nhúng YouTube/Vimeo (dùng nút
  toàn màn hình của chính trình phát đó). Popup khóa tự học từng mất khung phát
  trên Safari vì phần thân `flex: 1` (cơ sở 0%) bị tính cao 0; nay là `flex: 1 1 auto`.
  Trình duyệt có lệnh toàn màn hình nhưng từ chối khung phát (WebView, chính sách quyền) thì
  `enterFullscreen` chuyển sang video, không để nút im lặng; trang báo trước là không cho phần tử vào toàn màn hình
  (`fullscreenEnabled` false, ví dụ trình duyệt nhúng của ứng dụng trên iPad) thì chuyển sang video ngay trong lần bấm,
  nên video chưa nạp trả lời "không" và nút phát video trước như trên iPhone (`playFullscreen`). Thoát dùng `leaveFullscreen`, đọc cả tên
  `webkit` (Safari trước 16.4 trên iPad cũ), ở nút bấm lần hai và khi đóng popup khóa tự học. Script của popup khóa
  tự học (tìm bài, mở bài, hộp "Tiếp tục xem", chân popup, toàn màn hình) là module
  `resources/js/learner-course-popup.js` (`installCoursePopup`, gọi từ `app.js`), không còn là script inline trong
  `course.blade.php`; chữ "Đang tải" và "Không mở được" đến từ thuộc tính `data-learn-message-*` của popup.
  `tests/js/learner-course-popup.test.mjs` import thẳng module: khung được chọn, thứ tự thoát → vào → phát, nút tắt,
  hộp hỏi hiện khi video dừng giữa chừng và ẩn khi khung phát phát, bài khóa không phát, đóng popup thoát toàn màn hình
  và đọc lại số liệu.
  Test cũng đọc mọi tệp trong `resources/css` và đọc giá trị `flex` như trình duyệt: không luật nào của phần thân
  popup (đúng class `lf-cd-modal__body`, kể cả selector ghép, trong `@media`) có cơ sở 0 (`flex: 1`, `flex: 1 1`, `flex: 1 0px`, `flex-basis: 0`), và
  phải còn một luật có cơ sở `auto` (`flex: 1 1 auto`, `flex: auto`).
* Thẻ Link học (`live_class`) không có trình phát nhưng cũng nghe `lf-learn-progress`: điểm danh hoàn thành nó
  cùng nội dung khác (`lesson_completed`), nên nhãn "Bắt buộc" đổi sang "✓ Đã hoàn thành" cùng lúc với dòng của buổi.
* Test: `tests/js/learner-lesson.test.mjs`, `tests/js/learner-course-popup.test.mjs`, `LearnerMyLearningTest::test_the_class_video_popup_shows_its_resume_question_and_watched_share_like_mk`,
  `LiveClassAttendanceCheckInTest::test_ticked_content_decides_attendance_and_make_up`.
