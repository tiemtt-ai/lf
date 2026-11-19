# Table: core_course_template_activities

Version: 1.9

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-11-19

Document Path: database/course/core_course_template_activities.md

---

# Purpose

Lưu các hoạt động học tập thuộc Template Lesson.

Template Activity là blueprint của nội dung học tập cụ thể trong bài học.

Một Lesson có thể có nhiều Activity như:

* Video
* Audio
* Document
* Quiz
* Assignment
* Live Class
* Text Content

Template Activity là working draft để giáo viên chỉnh sửa.

Khi publish Template, Activity được snapshot sang
`core_course_template_version_activities`; Student không học trực tiếp working Activity.

---

# Relationships

core_course_templates

1

↓

N

core_course_template_activities

---

core_course_template_lessons

1

↓

N

core_course_template_activities

---

Media / Assessment / LiveClass

1

↓

N

core_course_template_activities

---

# Business Rules

* Mọi Template Activity phải thuộc customer_id.
* Mọi Template Activity phải thuộc một Template.
* Mọi Template Activity phải thuộc một Template Lesson.
* Activity không lưu progress học tập.
* Activity không lưu tracking logs.
* Activity chỉ tham chiếu nội dung từ Domain tương ứng.
* Video, Audio, Document thuộc Media Domain.
* Quiz, Assignment thuộc Assessment Domain.
* LiveClass thuộc LiveClass Domain.
* Thời lượng Activity nên được tự động tính từ nội dung gốc nếu có.
* Thứ tự Activity trong Lesson được xác định bằng sort_order.
* Product/Enrollment/Progress chỉ sử dụng Version Activity.
* Sửa working Template Activity không ảnh hưởng Version Activity đã publish.
* Trong authoring tree, mỗi Activity là một row phẳng trực tiếp dưới Lesson.
* Activity row hiển thị icon, title text, View, Edit và Delete.
* Title là text thuần, không phải link. View và Edit là hai action riêng.
* Media Activity có active same-tenant Media usage mở signed URL trong tab mới.
* External-link Activity chỉ mở HTTP(S) URL hợp lệ trong tab mới.
* Activity khác dùng tenant-scoped readonly detail route; detail không update
  dữ liệu và không render editable form controls.
* Khi Media/external target không hợp lệ hoặc không tồn tại, View mở readonly
  Activity detail.
* Không hiển thị Activity status hoặc type/status badge trong authoring tree.
* Empty state chỉ hiển thị `Chưa có hoạt động.`.
* Mỗi Activity chọn `anytime`, hoặc một hay nhiều thời điểm
  `before_session`, `during_session`, `after_session`.
* `anytime` không được kết hợp với các thời điểm theo buổi học.
* Activity `live_class` không được chọn `anytime`; chọn một hay nhiều thời điểm
  theo buổi học, mặc định `during_session` khi không khai báo.
* Amendment 2026-11-17 (Owner approved, đã triển khai 2026-11-17): Activity `live_class` luôn và
  chỉ là `during_session`; backend từ chối giá trị khác khi tạo, sửa và trong Publish
  readiness. Không thêm ràng buộc database. Chi tiết ở
  [LF-Core-Course](../../core/LF-Core-Course.md) § Amendment 2026-11-17.
* Activity hiện có mặc định `anytime`.
* Amendment 2026-11-01 (Approved,
  [ADR-0022](../../adr/ADR-0022-Course-Type-On-Template.md)): lựa chọn hợp lệ bị
  giới hạn theo `core_course_templates.course_type`:
  * `self_paced`: chỉ `anytime`; server tự gán, request gửi thời điểm theo buổi
    bị từ chối; không cho `activity_type = live_class`.
  * `scheduled`: chỉ `before_session` / `during_session` / `after_session`
    (≥ 1); `anytime` bị từ chối; mặc định `during_session` khi không khai báo.
  * `blended`: cả 4 lựa chọn theo các quy tắc ở trên.
  * Amendment 2026-11-09 (ADR-0022 Amendment 1.1): `scheduled` không còn được
    chọn; giá trị cũ được xử lý như `blended` (Theo lớp).
  Backend kiểm tra khi tạo/sửa Activity, khi đổi loại Template và trong
  Publish readiness.
* Không tham chiếu Live Class Activity; Cohort Session thực tế gắn Lesson là
  mốc thời gian ở runtime.
* Các lựa chọn này độc lập với sort order, completion, unlock và Progress.

---

# Fields

## Identity

### id

BIGINT UNSIGNED

Primary key.

### customer_id

BIGINT UNSIGNED

Tenant sở hữu dữ liệu.

### template_id

BIGINT UNSIGNED

Course Template chứa Activity.

### template_lesson_id

BIGINT UNSIGNED

Template Lesson chứa Activity.

---

## Basic Information

### title

VARCHAR(255)

Tên Activity.

### description

TEXT NULL

Mô tả Activity.

### sort_order

INT DEFAULT 0

Thứ tự hiển thị trong Lesson.

---

## Activity Type

### activity_type

VARCHAR(50)

Loại Activity.

Giá trị:

* video
* embedded_video
* audio
* document
* quiz
* live_class
* external_link — "Link nền tảng ngoài", Amendment 2026-11-19
  ([ADR-0035](../../adr/ADR-0035-External-Link-Activity.md)).

Amendment 2026-11-09 (Owner approved): `live_class` hiển thị là "Buổi học trực
tiếp". Mỗi Lesson có tối đa một Activity `live_class`; luật nằm ở backend (tạo,
sửa, Publish readiness), không có ràng buộc database. Lesson không có Activity
này là buổi tự học.

---

## Type-specific Content

### external_video_url

VARCHAR(1000) NULL

URL ngoài hệ thống.

Dùng khi activity_type = embedded_video. Chỉ canonical HTTPS YouTube/Vimeo URL
được normalize bởi `TrustedVideoUrlService`.

### external_link_url

> Amendment 2026-11-19 (Owner duyệt nghiệp vụ và thiết kế dữ liệu) — migration
> `2026_11_19_000100_add_external_link_to_course_activities`. Nghiệp vụ:
> [ADR-0035](../../adr/ADR-0035-External-Link-Activity.md).

VARCHAR(1000) NULL, đặt sau `external_video_url`. Không CHECK, không index, không backfill.

* Dùng khi `activity_type = external_link` ("Link nền tảng ngoài"): bắt buộc, chỉ `https://`,
  không chứa tên đăng nhập hay mật khẩu trong URL; server kiểm.
* Mọi loại khác: `NULL`. Đổi loại khỏi `external_link` thì về `NULL`.
* `completion_rule` của loại này là `view` (khóa khác Theo lớp có thêm `manual`).
* Xuất bản sao vào `core_course_template_version_activities.external_link_url_snapshot`;
  nhân bản Template sao nguyên giá trị.

### live_class_url

VARCHAR(1000) NULL

Dùng khi activity_type = live_class. Chỉ URL HTTPS. Không bắt buộc (amendment
2026-10-31): đây chỉ là **link mặc định** gợi ý cho Session. Link tham gia thực
tế thuộc về từng Cohort Session (`meeting_url_snapshot`) vì mỗi lớp có phòng
riêng và URL thường chưa có khi soạn Template. Thiếu URL không chặn lưu Activity
và không chặn publish.

Amendment 2026-11-09 (Owner approved): **ngừng nhận giá trị mới**. Form không còn
ô này và server bỏ qua giá trị gửi lên. Giá trị đã lưu được giữ khi Activity vẫn
là `live_class`, về `NULL` khi đổi loại. Publish vẫn đóng băng giá trị đã lưu vào
`live_class_url_snapshot` để buổi học của lớp tự điền link. Cột chưa bị xóa; xóa
sau khi lớp học có link phòng họp mặc định. Không đổi schema.

### assessment_quiz_id

BIGINT UNSIGNED NULL

Provisional positive integer Assessment Quiz reference khi
`activity_type = quiz`. Có thể lưu ở working Activity, nhưng Template chứa Quiz
bị chặn publish cho đến khi Assessment Phase 2 định nghĩa ownership và
immutable binding.

Quiz Picker Amendment (Owner 2026-11-10): `assessment_quiz_id` được chọn qua popup
lọc / chọn đề (giống chọn Media), không nhập số. Chỉ nhận đề cùng tenant, `status =
published` (có phiên bản, chưa lưu trữ); giáo viên chỉ chọn được đề do mình tạo, admin
chọn được mọi đề của tenant. Đề đã gắn trước đó mà nay không còn hợp lệ vẫn giữ khi
lưu lại không đổi. Template chứa Quiz vẫn bị chặn publish; đóng băng phiên bản đề vào
Version Activity thuộc phần Course Integration làm sau.

Amendment 2026-11-11 (Approved, [ADR-0032](../../adr/ADR-0032-Course-Quiz-Activity.md)):
publish nhận Quiz khi đề cùng tenant, `published`, có `current_version_id` (không
`archived`), và chốt phiên bản đó vào Version Activity. Thêm khóa ngoại
`FOREIGN KEY fk_ccta_quiz (assessment_quiz_id, customer_id) REFERENCES
core_assessment_quizzes (id, customer_id) ON DELETE RESTRICT`. Đề chỉ xóa được khi
chưa từng xuất bản và picker chỉ nhận đề đã xuất bản, nên khóa ngoại không chặn luồng
xóa nào đang có.

---

## Learning Metadata

### available_anytime

TINYINT(1) DEFAULT 1

Có thể học bất kỳ lúc nào. Khi bằng `1`, ba field theo buổi học phải bằng `0`.

### available_before_session

TINYINT(1) DEFAULT 0

Có thể học trước Cohort Session thực tế gắn với Lesson.

### available_during_session

TINYINT(1) DEFAULT 0

Có thể học trong Cohort Session thực tế gắn với Lesson.

### available_after_session

TINYINT(1) DEFAULT 0

Có thể học sau Cohort Session thực tế gắn với Lesson.

Ít nhất một trong bốn field availability phải bằng `1`.

> Khóa Theo lớp ([ADR-0022 Amendment 1.3](../../adr/ADR-0022-Course-Type-On-Template.md),
> 2026-11-18; không đổi schema): Activity của buổi có người phụ trách (`lesson_type`
> `regular`, `practice`) có đúng một trong ba field theo buổi bằng `1` và
> `available_anytime = 0`; Activity của buổi `self_study` có `available_anytime = 1`.
> `completion_rule`: `video`, `audio` dùng `watch_percent` (mặc định 80); `document`,
> `embedded_video` dùng `view`; `quiz` dùng `submit` hoặc `pass`; `live_class` dùng `join`;
> không có `manual`. Server luôn ghi `is_preview = 0`, `unlock_rule = none`,
> `estimated_duration_seconds = NULL`.

### duration_seconds

INT UNSIGNED DEFAULT 0

Thời lượng Activity.

System generated nếu Activity tham chiếu Media.

### estimated_duration_seconds

INT UNSIGNED NULL. Estimated learner completion time. UI whole minutes are
stored as `minutes * 60`; `NULL` means unknown. Lesson `duration_seconds` is the
sum of non-null Activity estimates. The current non-null Lesson column uses `0`
when every estimate is unknown.


### is_required

TINYINT(1) DEFAULT 1

Activity bắt buộc để hoàn thành Lesson.

### counts_for_attendance

> Amendment 2026-11-18 (Owner duyệt) — migration
> `2026_11_18_000100_add_counts_for_attendance_to_course_activities`.
> Nghiệp vụ: [ADR-0022 Amendment 1.3, Đợt 3](../../adr/ADR-0022-Course-Type-On-Template.md#đợt-3--tính-vào-điểm-danh-và-học-bù).

TINYINT(1) NOT NULL DEFAULT 0, đặt sau `is_required`.

"Tính vào điểm danh": học viên phải đạt tiêu chí hoàn thành của Activity này thì mới được
tính điểm danh (phần Trong buổi học) hoặc học bù (phần Sau buổi học).

* Chỉ nhận 1 khi Template là Theo lớp, Activity không phải `live_class`, và hoặc thuộc phần
  Trong hoặc Sau của buổi có người phụ trách, hoặc thuộc buổi tự học (Amendment 2026-11-18,
  Đợt 4, Bước 3). Server lưu 0 ở mọi trường hợp khác.
* Đưa về 0 khi Activity chuyển sang phần Trước và khi Template đổi sang Tự học. Đổi bài sang
  Tự học giữ nguyên giá trị.
* Dòng đang có nhận 0 khi migration chạy; không cần backfill.

```sql
CONSTRAINT chk_ccta_counts_for_attendance CHECK (
    counts_for_attendance = 0
    OR (activity_type <> 'live_class' AND available_before_session = 0)
)
```

Ràng buộc này không kiểm loại khóa và loại buổi (nằm ở bảng khác); phần đó do server và
kiểm tra xuất bản đảm nhận. Migration `2026_11_18_000300_allow_attendance_tick_on_self_study_activities`
bỏ vế `available_anytime = 0` của bản đầu để Activity của buổi tự học tick được.

### completion_rule

VARCHAR(50) DEFAULT 'view'

Giá trị:

* view
* watch_percent
* submit
* pass
* join
* manual

`live_class` hỗ trợ `join` (đã tham gia lớp học) và `manual`. Rule `join` là
contract hoàn thành dựa trên bằng chứng Attendance của LiveClass; thao tác mở
URL lớp học không tự thân được xem là đã hoàn thành.

Uploaded `video`, `audio` và `document` media được publish validation bằng
canonical `MediaService` type/MIME/extension policy. `media_files.status =
ready` là source of truth; publish không gọi storage HEAD để kiểm tra lại object
vật lý.

### completion_threshold

INT UNSIGNED NULL

Ngưỡng hoàn thành.

Khi có giá trị, threshold là integer từ `1` đến `100`.

Ví dụ:

* video watch_percent = 80
* quiz pass score = 70

---

## Preview / Access

### is_preview

TINYINT(1) DEFAULT 0

Cho phép xem thử Activity.

### unlock_rule

VARCHAR(50) DEFAULT 'none'

Giá trị:

* none
* previous_activity_completed

### unlock_after_activity_id

BIGINT UNSIGNED NULL

Activity cần hoàn thành trước.

### unlock_at

DATETIME NULL

Legacy compatibility column. Current Activity authoring and publish readiness
require this field to remain `NULL`; date-based unlock belongs outside the
current Activity runtime contract.
Kiểu `DATETIME` từ ADR-0028 cho đồng bộ với Lesson.

---

## Audit

### created_by

BIGINT UNSIGNED NULL

User tạo Activity.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Indexes

(customer_id)

(customer_id, template_id)

(customer_id, template_lesson_id)

(customer_id, activity_type)

(customer_id, template_lesson_id, sort_order)

(customer_id, created_by)

---

# Unique Constraints

Không cần unique mặc định.

Một Lesson có thể chứa nhiều Activity cùng loại và cùng nội dung nếu nghiệp vụ cho phép.

---

# Sample Data

## Video Activity

id = 1

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Video - Korean Alphabet Introduction

activity_type = video

external_video_url = null

duration_seconds = 1200

is_required = 1

completion_rule = watch_percent

completion_threshold = 80

sort_order = 1

---

## Quiz Activity

id = 2

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Quiz - Korean Alphabet Practice

activity_type = quiz

assessment_quiz_id = 88

duration_seconds = 600

is_required = 1

completion_rule = pass

completion_threshold = 70

sort_order = 2

---

## Embedded Video Activity

id = 3

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Extra Practice Video

activity_type = embedded_video

external_video_url = https://www.youtube.com/watch?v=example

duration_seconds = 300

is_required = 0

completion_rule = view

sort_order = 3

---

# Notes

Activity là nơi liên kết Course Domain với:

* Media Domain
* Assessment Domain
* LiveClass Domain
* External Content

Activity không lưu:

* Progress
* Watch Logs
* Attempts
* Scores

Các dữ liệu đó thuộc:

```text
track_*
core_assessment_*
```

---

End of Document
