# LF-Core-LiveClass.md

Version: 2.38

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-19

Document Path: core/LF-Core-LiveClass.md

---


> Amendment 2026-11-05 (Approved, [ADR-0023](../adr/ADR-0023-Cohort-Content-Order.md)):
> mỗi lớp có thể có thứ tự Lesson và Activity riêng, lưu ở `core_course_cohort_lesson_orders`
> và `core_course_cohort_activity_orders`; không có dòng nào nghĩa là dùng thứ tự của Version.
> Màn hình trong ngữ cảnh lớp đọc qua `CourseCohortContentOrder`, không đọc trực tiếp `sort_order`
> của Version. Kéo bài thì cả box đi theo nội dung (tên, link, hình thức, phòng, địa chỉ, giáo viên), ngày giờ đứng yên theo vị trí;
> buổi đã qua bị khóa. Owner chốt 2026-11-05: form sửa buổi thuộc chương trình chỉ hiện các trường
> của Template (kể cả tên); chỉ sửa ngày giờ, giáo viên, hình thức, link tham gia, phòng, địa chỉ (link theo
> § Session Meeting URL: mở từ dòng link của Template, chỉ áp dụng cho buổi đó, để trống thì dùng lại link Template). Buổi sắp tới
> chưa gán giáo viên hiện giáo viên của lớp theo ngày đó và mở form với họ được chọn sẵn.
> Từ 2026-11-10: lần kéo hoặc lần hủy-dời phải lưu buổi mới vào một ngày giao với buổi khác của lớp
> (hai Lịch học trùng giờ) thì bị từ chối cả lần, như kiểm tra giao giờ của form buổi học.

# LF-Core LiveClass Architecture

LiveClass Domain quản lý hoạt động học đồng bộ và hybrid:

* LiveClass Rooms
* LiveClass Schedules
* LiveClass Sessions
* Attendance
* Recording references
* Replay summaries
* Chat logs

LiveClass sinh dữ liệu vận hành. Nó không kế thừa Course, không tạo Runtime
Course và không sở hữu learning completion.

---

# Mission

Cho phép giáo viên và học viên tương tác trực tiếp, đồng thời tạo operational
evidence phục vụ Course Progress, Tracking, Analytics và AI.

---

# Architecture Flow

```text
Course Template

↓

Template Activity (`activity_type = live_class`)

↓ publish

Course Template Version

↓

Version Activity (immutable)

↓

LiveClass Schedule / Room / Session

↓

Attendance / Recording / Replay / Chat

↓

Course Activity Progress
```

Working Template chỉ định nghĩa activity. Khi publish, Version Activity đóng
băng learning context, completion rule và threshold. LiveClass operational data
chỉ gắn với Course thông qua published Version context.

---

# Core Architecture Rules

## Cohort-Centered Session Policy

The 2026-08-03 curriculum/operational amendment supersedes the Session binding
rules in the earlier Room-owned and 2026-07-25 descriptions.

`core_liveclass_sessions` belongs directly to one `core_course_cohorts` row.
Every Session freezes the Cohort's `template_version_id` and declares
`session_type = curriculum|operational`. A curriculum Session requires one
same-tenant Version Lesson and one `live_class` Version Activity in that Lesson
and locked Version. An operational Session requires both bindings to be
`NULL` and cannot produce Activity Completion Evidence.

Room is an optional reusable delivery resource. Session snapshots its online,
offline or hybrid delivery values. Session Teachers, Attendance, Recording and
Replay are children of Session. Course remains the only owner of Progress and
Completion.

### Cohort Draft Setup Boundary — 2026-08-02

A `draft` Cohort may prepare LiveClass Schedules, Sessions and teacher
assignments as setup data. Schedule is the recurring planning entity; Session
is a concrete class meeting. Creating or editing either setup entity does not
activate the Cohort and must not produce Attendance, Replay evidence or another
runtime operation.
Attendance and other operational evidence may be created only when the Cohort
is `active` and the existing Session, Enrollment, authorization and tenant
requirements are satisfied. Historical data remains readable according to the
applicable authorization policy after completion or archival.

### Cohort Primary Teacher Assignment Policy — 2026-08-04

Một Cohort chỉ được có tối đa một assignment `primary_teacher` đang hoạt động.
Giáo viên chính chịu trách nhiệm toàn bộ vòng đời vận hành của lớp, vì vậy
`assigned_from` và `assigned_to` bắt buộc lần lượt bằng
`core_course_cohorts.start_date` và `core_course_cohorts.end_date`. Cohort phải
có đủ hai mốc thời gian trước khi phân công giáo viên chính.

Khi thay giáo viên chính, assignment chính hiện tại phải được chuyển về vai trò
`teacher` trước khi assignment mới trở thành `primary_teacher`; không xóa lịch
sử assignment. Khi một Cohort còn được phép sửa thời gian vận hành, mọi
assignment `primary_teacher` đang hoạt động phải được đồng bộ trong cùng
transaction với ngày mới của Cohort.

Các vai trò `teacher` và `assistant` được phép có khoảng phụ trách ngắn hơn,
nhưng khoảng đó phải nằm hoàn toàn trong thời gian vận hành của Cohort. Chính
sách Cohort teacher không tự tạo hoặc thay đổi Session teacher assignment;
giáo viên của từng Session vẫn là cấu hình vận hành riêng.

Trên form Session không có khái niệm giáo viên chính và không tự động chọn
người phụ trách. Tất cả assignment đang hoạt động của Cohort được trình bày
trong cùng một nhóm checkbox khi toàn bộ khoảng `scheduled_start_at` đến
`scheduled_end_at` của Session nằm
trong khoảng phụ trách hiệu lực của assignment. Khoảng `NULL` ở một đầu được
hiểu là không giới hạn thêm ở đầu đó nhưng vẫn chịu boundary thời gian Cohort.
UI phải lọc theo hai mốc Session và backend phải kiểm tra lại cùng invariant;
request trực tiếp không được vượt qua availability này.

Một Session có thể có nhiều assignment `teacher`/`assistant`. Một giáo viên
được phép phụ trách nhiều Session trong cùng Cohort hoặc giữa các Cohort; việc
lặp lại giáo viên giữa các buổi không phải xung đột nghiệp vụ. Trong một
Session, nhiều giá trị đầu vào cùng tham chiếu một Teacher phải được tự động
chuẩn hóa thành một assignment canonical và không được trả lỗi validation cho
người dùng. Đội ngũ canonical nằm tại
`core_liveclass_session_teachers`; implementation mới phải ghi
`core_liveclass_sessions.primary_teacher_id = NULL`. Cột này chỉ được giữ để
đọc dữ liệu legacy và không đại diện cho policy hiện hành.
Đổi lịch Session phải tái kiểm tra availability của tất cả giáo viên trước khi
ghi schedule change.

### Giáo viên chính tự vận hành lớp — 2026-11-17

[ADR-0033](../adr/ADR-0033-Teacher-Permissions-And-Tenant-Owner.md) D7: giáo viên có quyền
`class.manage` tạo lớp và vận hành lớp mà mình là `primary_teacher` đang hoạt động (sửa
thông tin, lịch học, buổi học, thứ tự nội dung, kích hoạt, kết thúc); `class.delete` xoá
lớp nháp chưa có học viên trong cùng phạm vi. Người tạo lớp tự thành giáo viên chính, nên
tạo lớp bắt buộc có ngày bắt đầu và kết thúc. Ghi danh, phân công giáo viên của lớp, bộ quy
tắc điểm danh và lưu trữ vẫn chỉ quản trị làm. Mọi thao tác này kiểm tra phạm vi ở máy chủ.

2026-11-19 (Owner duyệt và triển khai; chi tiết ở
[ADR-0022](../adr/ADR-0022-Course-Type-On-Template.md) Amendment 1.4, mục "Lớp tự kết thúc
theo ngày"): lớp `active` tự kết thúc sau 23:59 ngày kết thúc (lệnh
`course:complete-ended-cohorts`); kết thúc sớm chỉ quản trị bấm, giáo viên chính không còn
nút "Kết thúc lớp" nhưng vẫn sửa được ngày kết thúc (lớp đang chạy: không trước hôm nay).

Khoảng lịch của Session phải nằm hoàn toàn trong thời gian vận hành Cohort.
Ngày bắt đầu tối thiểu là ngày lớn hơn giữa `cohort.start_date` và ngày hiện
tại; ngày kết thúc tối đa là cuối ngày `cohort.end_date`. Cùng boundary phải
được áp dụng ở date picker và backend cho cả tạo, sửa và đổi lịch.

## LiveClass Operational Data Principle

LiveClass là Operational Domain.

LiveClass chỉ sinh:

```text
Room

Schedule

Schedule Slot

Schedule Exclusion

Session

Attendance

Recording

Replay

Chat
```

LiveClass không quyết định Course Completion, Course Progress hoặc Certificate.
Course Domain sở hữu:

```text
core_course_activity_progress

core_course_progress

core_course_completions

certificate eligibility
```

LiveClass data chỉ là evidence/input cho Course Progress recalculation.

## Domain Responsibility Principle

LiveClass chỉ sở hữu operational data và rules của LiveClass.

Khi LiveClass cần ảnh hưởng Course Domain, LiveClass sinh Attendance/Replay
Evidence, Event hoặc Request. Course Domain tự áp dụng completion rule và tự
quyết định Progress, Completion hoặc Certificate eligibility.

LiveClass không được cập nhật trực tiếp Course business state hoặc ghi đè
Course source of truth.

## Rule 1 — No Course Inheritance

LiveClass không kế thừa Course và không tạo lại:

```text
core_courses

core_course_sections

core_course_lessons

core_course_activities
```

## Rule 2 — Version Activity Binding

Course-linked operational records phải tham chiếu:

```text
template_version_id

version_activity_id
```

`version_activity_id` là link chính giữa LiveClass và Course Domain. Version
Activity phải có:

```text
activity_type = live_class
```

Không dùng working `template_activity_id` làm learning/runtime reference.

## Rule 3 — Enrollment Binding

User-specific operational records phải tham chiếu:

```text
enrollment_id

user_id
```

Áp dụng cho:

* Attendance
* Replay

Enrollment phải `active` khi tạo record. `user_id` phải khớp `student_id` của
Enrollment. Record được giữ lại sau khi Enrollment hết active để bảo toàn
learning-cycle history.

## Rule 4 — Progress Ownership

LiveClass không tự quyết định completion.

Canonical activity completion record là:

```text
core_course_activity_progress
```

Attendance và Replay chỉ là operational evidence. Course Domain đánh giá
evidence theo frozen Version Activity completion rule rồi cập nhật/recalculate
Course Activity Progress.

## Rule 5 — Media Ownership

File recording thuộc Media Domain.

LiveClass Recording chỉ giữ:

```text
media_file_id

provider_recording_id

provider/source metadata
```

`media_file_id` là canonical khi file đã được import vào Media. LiveClass không
lưu binary file, không sở hữu storage lifecycle và không mặc định public media
URL.

## Rule 6 — Tenant Isolation

Mọi LiveClass business data phải có `customer_id`. Tất cả cross-domain
references phải thuộc cùng tenant.

---

# Database Namespace

```text
core_liveclass_*
```

Core tables:

```text
core_liveclass_rooms

core_liveclass_schedules

core_liveclass_schedule_slots

core_liveclass_schedule_exclusions

core_liveclass_sessions

core_liveclass_attendances

core_liveclass_recordings

core_liveclass_replays

core_liveclass_chat_logs
```

---

# LiveClass Rooms

`core_liveclass_rooms` là delivery resource online/offline/hybrid có thể tái sử
dụng cho nhiều Session và không chứa Course learning context.

Course context:

```text
customer_id

product_id

template_version_id

version_activity_id

teacher_id
```

Room chứa provider/location configuration nhưng không chứa progress và không
giới hạn cardinality theo Version Activity.

Supported provider examples:

```text
Zoom

Google Meet

Microsoft Teams

Custom RTMP

Future WebRTC
```

Provider credentials và signing secrets không thuộc Room record.

---

# LiveClass Schedules

`core_liveclass_schedules` là source of truth cho kế hoạch lặp của một Cohort.
Schedule thuộc trực tiếp một same-tenant Cohort và không thuộc Course Template,
Template Version hoặc Room.

```text
Cohort 1 → N Schedules
Schedule 1 → N Slots
Schedule 1 → N Exclusions
```

Schedule là setup/planning data, không phải một buổi học cụ thể và không phải
operational evidence. Schedule không được publish vào immutable Version, không
tạo quyền học và không sở hữu Progress, Completion, Attendance, Recording hoặc
Replay.

## Schedule Source Of Truth

Canonical persistence:

```text
core_liveclass_schedules
core_liveclass_schedule_slots
core_liveclass_schedule_exclusions
```

Không lưu recurring Schedule trong:

```text
core_liveclass_sessions
core_course_cohorts.metadata
Course Template / Template Version
LiveClass Room
JSON recurrence metadata
```

Một Schedule có inclusive date range, IANA timezone và ít nhất một Slot. Mỗi
Slot biểu diễn riêng một ISO weekday và một same-day time interval; các ngày có
thể dùng giờ khác nhau. Exact duplicate và interval overlap trên cùng weekday
trong cùng Schedule bị từ chối. Exclusion là một ngày duy nhất trong range và
chỉ loại occurrence dự kiến khỏi preview.

## Cohort Operating Period And Schedule Application Range

`core_course_cohorts.start_date` và `core_course_cohorts.end_date` là inclusive
operating period do Cohort sở hữu. `Schedule.starts_on` và `Schedule.ends_on`
chỉ là inclusive application range của riêng Schedule đó. Một Cohort có thể có
nhiều Schedule với các khoảng con khác nhau, nhưng mọi Schedule phải thỏa:

```text
cohort.start_date <= schedule.starts_on <= schedule.ends_on <= cohort.end_date
```

Không suy ra operating period từ Schedule, không dùng Schedule đầu/cuối để
backfill Cohort và không cho Schedule tự mở rộng operating period. Khi sửa
operating period, request phải bị từ chối nếu bất kỳ Schedule hiện có nào không
còn nằm trọn trong khoảng mới; không tự cắt ngắn, sửa hoặc xóa Schedule.
Cohort legacy thiếu một hoặc cả hai ngày vẫn đọc được, nhưng không thể tạo, sửa
hoặc preview Schedule cho tới khi có đủ operating period. Database giữ hai ngày
nullable chỉ để tương thích legacy; Cohort mới bắt buộc đủ hai ngày. IANA
timezone tiếp tục thuộc Schedule và là authority của preview.

Lưu Cohort hoặc Schedule không tạo, sửa, xóa hay đồng bộ Session và không tạo
Attendance, Recording, Replay, Progress hoặc Completion.

## Schedule Preview

Preview là read model do backend tính canonical:

```text
Schedule + Slots + Exclusions + timezone
→ danh sách ngày giờ dự kiến
```

Preview bao gồm `starts_on` và `ends_on`, loại mọi `excluded_on`, không lưu từng
occurrence, không cấp Session ID và không tạo `core_liveclass_sessions`. UI phải
ghi rõ “Các Buổi học thực tế chưa được tạo”. Client-side preview chỉ được dùng
để hỗ trợ presentation; request/backend vẫn là calculation authority.

## Schedule Lifecycle

* Cohort `draft`: authorized actor được tạo và sửa Schedule.
* Cohort `active`: authorized actor được tạo và sửa Schedule.
* Cohort `completed|archived`: Schedule chỉ đọc.
* Tạo/sửa Schedule không activate Cohort và không tạo runtime data.
* Persisted Schedule status không tồn tại; presentation suy ra `upcoming`,
  `current` hoặc `ended` từ ngày hiện tại trong timezone của Schedule.
* Xóa Schedule theo [ADR-0002 § Schedule Deletion Amendment](../adr/ADR-0002-LiveClass-Foundation.md#schedule-deletion-amendment--2026-11-19)
  (Owner duyệt 2026-11-19, đã triển khai): chỉ khi mọi
  Session của nó chưa diễn ra, chưa hủy, chưa dời giờ và chưa có dữ liệu; Session,
  Origin và phân công giáo viên đi theo. Không thêm soft delete hoặc status để giả lập
  lifecycle.

Automatic generation, synchronization, shared holiday calendars and
Schedule-driven bulk rescheduling remain deferred. Explicit confirmed creation
from selected occurrences is governed by the immutable Origin policy below.
Schedule mutation has no side effect on any Session or Origin.

## Lịch học trùng giờ và dời buổi đã có dữ liệu — 2026-11-19 (Owner duyệt, đã triển khai)

Owner duyệt hai quy tắc ngày 2026-11-19. Không thêm cột, không thêm bảng.

**Hai Schedule của cùng Cohort không được trùng giờ.** Trước đây hai Schedule
được phép giao nhau, và bước tạo buổi tự động bỏ qua ngày giao nhau mà không báo,
nên lớp có thể thiếu buổi. Nay lưu Schedule (tạo hoặc sửa) bị từ chối, không lưu
gì, khi một occurrence của nó (sau khi bỏ ngày Exclusion) giao với một occurrence
của Schedule khác cùng Cohort. Hai khoảng chỉ chạm mốc (giờ kết thúc bằng giờ bắt
đầu) không tính là trùng. Thông báo nêu ngày, khung giờ đầu tiên bị trùng và tên
Schedule kia. Kiểm tra chạy trong cùng transaction lưu, sau khi lock Cohort. Quy
tắc cũ vẫn giữ: các Slot trong một Schedule không chồng nhau, và Session đang hiệu
lực trong Cohort không chồng nhau. Schedule cũ đã trùng nhau thì vẫn đọc được; lần
sửa tiếp theo của một trong hai phải gỡ chỗ trùng trước.

**Session đã có dữ liệu không được dời giờ.** Ngoài các điều kiện dời giờ hiện
có (`scheduled`, chưa bắt đầu, giờ bắt đầu dự kiến chưa tới), Session còn phải
không có Attendance hoặc Recording, giống điều kiện sửa Session. Điều này chặn
trường hợp học viên điểm danh sớm (trong cửa sổ mở trước giờ học) rồi buổi bị dời.
Áp dụng cho form dời buổi trên tab Buổi học và cho mọi đường dời giờ khác dùng cùng
policy; kéo thả thứ tự hoạt động đã khóa vị trí khi có dữ liệu nên không đổi.

Triển khai: `LiveClassScheduleService` kiểm trùng giờ khi tạo và sửa (thông báo
`LF_course_cohort_schedule_overlaps_other`, lỗi ở trường `slots`);
`LiveClassSessionPolicy::canReschedule` nhận thêm cờ có dữ liệu, form dời buổi trả
422 với `LF_course_cohort_session_reschedule_has_evidence` và nút dời ẩn trên tab
Buổi học. Test: `CourseCohortManagementTest`.

Xóa Schedule: xem § Schedule Lifecycle.

## Schedule Occurrence To Session Origin

`core_liveclass_session_schedule_origins` is the only source of truth for a
Session created from a projected Schedule occurrence. Lineage is not stored in
Session metadata or inferred from matching dates.

```text
Schedule 1 → N Origins
Schedule Slot 1 → N Origins
Session 1 → 0..1 Origin
```

Occurrence identity is immutable and tenant-scoped:

```text
customer_id + schedule_id + schedule_slot_id + source_local_date
```

One identity may create at most one Session for all history. Cancelled and
no-show Sessions keep the Origin. There is no replacement or reuse workflow.
The same Session is rescheduled through the existing append-only audit flow,
or a separate manual Session is created outside the Schedule.

Origin freezes:

```text
source_local_date
source_local_start_time
source_local_end_time
source_timezone
source_start_at
source_end_at
```

The local tuple is interpreted in the IANA `source_timezone` and is never
changed. Absolute source timestamps are UTC instants stored as UTC `DATETIME`.
Session classification compares its planned interval, interpreted through the
Session timezone, with the normalized Origin instants. It must not compare
against the current mutable Schedule.

An Origin and all parents share `customer_id`. Session, Schedule and Slot
foreign keys use `RESTRICT`. A referenced Slot must retain its identity and
cannot be hard-deleted. Schedule edit therefore updates child rows by stable
identity/diff and must not delete-and-recreate referenced Slots. Updating
Schedule/Slot changes only future projected occurrences; it never changes an
existing Origin or Session.

Saving a Schedule creates the Sessions itself (ADR-0002 § Automatic Session
Generation Amendment): free future occurrences go, in programme order, to
`live_class` Activities without a delivering Session, with the Lesson's title,
online, the Activity's link and the Cohort's teachers of that date, in the
transaction saving the Schedule. Saving is refused when the free dates do not
reach every such Activity; extra dates stay unused. Changes are made on the
Sessions tab.

Client occurrence timestamps, timezone and tenant metadata are never trusted.
Uniqueness on the occurrence identity provides the final double-submit guard.
This flow creates no Attendance, Recording, Replay, Progress or Completion.

A date can still lack a Session, for example after a cancellation or when a
Schedule predates generation. Such programme dates are a read-only presentation
on the Cohort Sessions tab. Free future occurrences are paired, in programme order, with `live_class` Activities
that have no delivering Session (`scheduled`, `live`, `completed`). The tab shows
such a date like a Session, with the Cohort's teachers, but it persists nothing:
teachers, learners, Attendance and lifecycle actions do not see it. It follows
the Schedule when that changes, and becomes a Session only when an admin saves it
in the Session form. An occurrence whose start has
passed, or that another Session occupies, is not shown. A Session cannot be moved
onto a date shown on another lesson.

A Session form may change the date and time of a Session. The change is applied
only when the form declares it, passes the same Cohort window, overlap and
teacher checks as a reschedule, and writes the same audit row. When creating
from a proposal with a different time, the Origin keeps the original occurrence
and the audit row records the move. The Sessions tab marks a `rescheduled` Session
as edited, and its form can restore the Origin's date; saving that is a reschedule
like any other, with its audit row. Once a Session's start has passed it can no
longer be moved or edited.

Presentation labels are derived from immutable lineage:

* `on_schedule`: Origin exists and normalized current Session times equal it.
* `rescheduled`: Origin exists but normalized Session times differ.
* `off_schedule`: a newly created manual Session has no Origin.
* `source_unknown`: a legacy Session has no Origin and is not backfilled.
* `planned_occurrence`: projected occurrence has no Origin/Session.

The manual/legacy boundary uses one immutable server-configured rollout
instant. A no-Origin Session created before that cutover is `source_unknown`;
a no-Origin Session created by the manual workflow at or after it is
`off_schedule`. Client input cannot set or override this classification. The
cutover must remain fixed after deployment.

Rescheduling retains Origin, modifies only Session planned times and appends
the existing schedule-change audit. Schedule, Slot and Origin are unchanged.

---

# LiveClass Sessions

`core_liveclass_sessions` đại diện cho một lần học cụ thể của Cohort.

```text
Cohort 1 → N Sessions
Room 1 → 0..N Sessions
```

## Session Types And Authority

### Course Type And Delivery Mode — Amendment 2026-11-01 (Approved)

Approved theo [ADR-0022](../adr/ADR-0022-Course-Type-On-Template.md). Chỉ
Cohort của Product loại Theo lịch (`scheduled`) hoặc Kết hợp (`blended`) có
Session. Online / offline / hybrid là `delivery_mode` của từng Session, không
phải loại khóa học; lớp "hybrid" là lớp có cả buổi online và buổi offline.
Nguyên tắc này chưa thay đổi code.

### Hình thức buổi học trên giao diện — 2026-11-09 (Approved)

Owner duyệt 2026-11-09. Không đổi dữ liệu: `delivery_mode` giữ ba giá trị, chỉ đổi cách
chọn và tên hiển thị.

| Giao diện | `delivery_mode` |
| --- | --- |
| **Tại lớp** | `offline` |
| **Tại lớp** + "Có người tham gia từ xa" | `hybrid` (hiển thị "Tại lớp + từ xa") |
| **Trực tuyến** | `online` |

* Form buổi học (popup Sửa bài học và buổi ngoài chương trình) chọn Tại lớp hoặc Trực
  tuyến. Tại lớp: Phòng học bắt buộc, Địa chỉ **không bắt buộc**, ô "Có người tham gia
  từ xa". Trực tuyến hoặc có người từ xa: Link phòng họp. Popup Sửa bài học sửa được link
  (trước đây link chỉ sửa ở popup Sửa hoạt động, nay đã bỏ).
* Dòng bài học trong tab Buổi học có nhãn hình thức cạnh ngày giờ.
* Buổi học chỉ sửa ở **dòng bài học** (mỗi bài một Buổi học trực tiếp). Dòng hoạt động
  "Buổi học trực tiếp" không còn nút sửa; vẫn có nút xem (bắt đầu, kết thúc buổi), nút
  đổi giờ khi buổi đã khóa sửa và nút thêm giáo viên muộn.
* Giáo viên (danh sách buổi): nhãn hình thức; nút "Mở bảng" và "Vào phòng họp" (buổi có
  link `http`/`https`).
* Học viên ("Buổi học đang diễn ra"): Tại lớp chỉ hiện hướng dẫn quét mã QR trên màn hình
  lớp, không có nút xem bảng; Tại lớp + từ xa cho chọn "Tôi ở lớp" (hướng dẫn quét QR) hay
  "Tôi học từ xa" ("Xem bảng", "Vào phòng họp"); Trực tuyến có "Xem bảng" và "Vào phòng họp".
* Chưa có: buổi tự học (bài không có Buổi học trực tiếp) và link phòng họp mặc định của
  lớp; xem đề xuất ở [Buổi tự học và link mặc định của lớp](#buổi-tự-học-và-link-mặc-định-của-lớp--đề-xuất-2026-11-09).

### Buổi tự học và link mặc định của lớp — Đề xuất 2026-11-09

Trạng thái: Đợt B **Approved** (Owner duyệt 2026-11-09, đã có code); Đợt C: Owner duyệt
nghiệp vụ ngày 2026-11-18 trong
[ADR-0022 Amendment 1.3, Đợt 4](../adr/ADR-0022-Course-Type-On-Template.md#đợt-4--buổi-thực-hành-và-buổi-tự-học-có-lịch)
(Bước 2 và 3), bản đó thay các điểm khác dưới đây: buổi tự học là bài có loại `self_study`
(không suy từ việc thiếu `live_class`) và **có** điểm danh. Bước 2 (buổi tự học có ngày trên
lịch) và Bước 3 (mở nội dung và điểm danh) đã triển khai 2026-11-18; xem mục Buổi tự học có
lịch.

**Đợt B — Link phòng họp mặc định của lớp.**

* Thêm `core_course_cohorts.default_meeting_url` (VARCHAR(2000) NULL, chỉ `http`/`https`),
  nhập ở popup sửa lớp. Thay mục `meeting_url` đang để "deferred" trong tài liệu Cohort.
* Buổi học tự điền link theo thứ tự: link riêng của buổi (`meeting_url_snapshot`) → link
  mặc định của lớp → `live_class_url_snapshot` của Version (chỉ cho dữ liệu cũ).
* Lưu link mặc định của lớp **không** sửa link của buổi đã tạo; chỉ dùng khi tạo buổi mới
  hoặc khi buổi để trống link.
* Đã làm: cột `default_meeting_url`, ô "Link phòng họp mặc định" trong popup sửa lớp;
  tạo buổi (form, Lưu lịch, kéo thả) chép link; "Vào phòng họp" của giáo viên, học viên
  và trên bảng, cùng ô Phòng họp ở chi tiết buổi, đọc link của lớp khi buổi để trống.
* Sau khi chạy ổn: bỏ tự điền từ `live_class_url_snapshot`, rồi xóa
  `core_course_template_activities.live_class_url` (migration riêng, cần duyệt riêng).

**Đợt C — Buổi tự học có lịch.** Đây là thay đổi Foundation: phải sửa ADR-0002 (Session
binding) và ADR-0023 (ngày theo vị trí) trước.

* Thêm `session_type = self_study`: có `version_lesson_id`, `version_activity_id = NULL`,
  bài đó không có Activity `live_class`. Không có hình thức, phòng, link; không có bảng,
  không điểm danh, không sinh Activity Completion Evidence.
* `delivery_mode` thành nullable, bắt buộc với `curriculum` và `operational`, phải `NULL`
  với `self_study` (CHECK cập nhật tương ứng).
* Giờ: buổi tự học **giữ giờ bắt đầu và kết thúc như buổi thường** (Owner chốt
  2026-11-09), để kéo thả theo vị trí, cửa sổ Trước / Trong / Sau buổi học và mục "Buổi học
  đang diễn ra" dùng chung một cách tính.
* Lịch học xếp ngày cho **mọi bài** theo thứ tự chương trình, không chỉ bài có Buổi học
  trực tiếp. Lớp đã có buổi: bấm Lưu lịch lần nữa để tạo buổi tự học cho các bài còn thiếu
  ngày; buổi đã có giữ nguyên.
* Giao diện: dòng bài tự học có nhãn "Tự học"; popup chỉ có ngày giờ (không giáo viên bắt
  buộc, không hình thức, phòng, link). Giáo viên thấy buổi tự học trong danh sách nhưng
  không có nút.
* Ngoài phạm vi: trang tự học của học viên có dấu hoàn thành (Đợt D, cần trang học và ghi
  tiến độ, chưa có trong LF).

### Buổi thực hành, ngoại khóa — 2026-11-18 (Approved, đã triển khai)

[ADR-0022 Amendment 1.3, Đợt 4, Bước 1](../adr/ADR-0022-Course-Type-On-Template.md#đợt-4--buổi-thực-hành-và-buổi-tự-học-có-lịch). Buổi của lớp gắn với bài có loại
`practice`:

* Tổ chức tại phòng học hoặc một địa điểm: `delivery_mode = offline`, không link phòng họp,
  `attendance_tool = button`. Server đặt các giá trị này ở mọi nơi ghi buổi (form quản trị,
  Lưu lịch, kéo thả), kể cả khi request gửi khác.
* Form buổi chỉ có ô bắt buộc "Phòng học hoặc địa điểm", địa chỉ, ngày giờ và giáo viên.
  Buổi tạo tự động để trống địa điểm cho đến khi người xếp lịch nhập.
* Không có bảng điện tử cho bất kỳ ai: không có lối mở, và địa chỉ bảng của buổi trả 404.
  Giáo viên bắt đầu và kết thúc buổi như buổi thường.
* Học viên điểm danh bằng nút Điểm danh, theo các mốc của bộ quy tắc; bộ quy tắc đặt "giáo
  viên ghi tay" cho buổi tại lớp thì giáo viên ghi tay.

### Buổi tự học có lịch — 2026-11-18 (Approved, đã triển khai)

[ADR-0022 Amendment 1.3, Đợt 4, Bước 2](../adr/ADR-0022-Course-Type-On-Template.md#đợt-4--buổi-thực-hành-và-buổi-tự-học-có-lịch) và
[ADR-0023 mục 10](../adr/ADR-0023-Cohort-Content-Order.md). Thay quy tắc "chỉ Activity
`live_class` được xếp lịch" của mục Curriculum Session cho bài có loại `self_study`.

* `session_type = self_study`: gắn `version_lesson_id` của một bài `self_study`,
  `version_activity_id = NULL`. Không có `delivery_mode`, link, phòng, địa chỉ, công cụ điểm
  danh; không có bảng điện tử; không bắt buộc giáo viên.
* Có `scheduled_start_at` và `scheduled_end_at` như buổi thường. Lịch học xếp ngày cho mọi
  vị trí theo thứ tự chương trình: mỗi Activity `live_class` và mỗi bài tự học. Lịch không đủ
  ngày thì không lưu được.
* Kéo thả và hủy buổi áp dụng cho bài tự học như mọi vị trí; dòng Session ở một vị trí nhận
  loại buổi của nội dung mới.
* Trạng thái: `scheduled → completed` khi hết giờ kết thúc theo lịch (lệnh
  `liveclass:complete-overdue`), hoặc `scheduled → cancelled`. Không có `live` và `no_show`.
* Quản trị và giáo viên chính của lớp đổi lịch hoặc hủy buổi ở dòng bài trong tab Buổi học;
  không có form sửa chung. Ngày chưa lưu của bài tự học được lưu ở lần Lưu Lịch học kế tiếp.
* **Mở nội dung** (Bước 3): mọi Activity của bài tự học mở lúc buổi bắt đầu theo lịch; bài chưa
  có buổi thì khóa, "Chưa có lịch". Buổi tự học được tính là "buổi liền trước" của bài kế tiếp.
* **Điểm danh** (Bước 3), chỉ cho kết quả do hệ thống ghi:
  - Bài có Activity được tick "Tính vào điểm danh": xong mọi Activity đó trước giờ kết thúc
    thì ghi `present`, `attendance_source = system`, `joined_at` là lúc xong Activity cuối.
    Xong sau giờ kết thúc, trong hạn học bù của bộ quy tắc và khi lớp còn `active`, thì ghi
    `present` với `attendance_source = makeup`.
  - Bài không tick Activity nào: học viên bấm nút Điểm danh trong giờ của buổi thì ghi
    `present`; không có học bù.
  - Không có `late`. `attendance_mode` và `version_activity_id` để trống.
  - Tự ghi vắng sau thời gian chờ của bộ quy tắc, không cần giáo viên và không phụ thuộc thiết
    lập "giáo viên ghi tay".
  - Quản trị và giáo viên của lớp ghi tay được; kết quả ghi tay là kết quả sau cùng.

### Curriculum Session

`session_type = curriculum` requires:

```text
Cohort → locked Template Version → Version Lesson
       → Live Class Version Activity → Session
```

The Lesson and Activity must share tenant and Version with the Cohort, and the
Activity must belong to the selected Lesson with `activity_type = live_class`.
A Lesson does not imply exactly one Session. Only Live Class Activities may be
scheduled, and one Activity may have multiple Sessions.

#### Session Meeting URL — amendment 2026-10-31

The join URL belongs to the Session (`meeting_url_snapshot`), not to the
Course definition, because each Cohort uses its own room and the URL is often
unknown when the Template is authored. For a curriculum Session the Version
Activity `live_class_url_snapshot`, when present, is only prefilled as a
default; the administrator may enter, replace or leave the URL empty for
later, on create and edit. A submitted empty URL falls
back to that default. `online_provider` is derived from the final URL for
curriculum Sessions. A Session without a URL shows "not available" to
learners and teachers until one is added.

### Operational Session

`session_type = operational` is outside published Course content. It is used
for orientation, class meetings, supplementary support, workshops,
non-replacement make-up meetings, closing events and similar operations.
`version_lesson_id` and `version_activity_id` must both be `NULL`. The Session
must be labelled as outside the curriculum and cannot create Activity Progress
or complete a Lesson or Course.

Session type is explicit and must not be inferred from title or metadata.
Create and edit share the same server-side validation. Client-side switching
to operational clears stale Lesson and Activity IDs, and the server must reject
or canonicalize any remaining curriculum binding.

For curriculum Sessions the UI may suggest, but never lock, a title derived
from the Version Lesson and Live Class Activity. The suggestion may change only
until the user manually edits the title. Operational Session title is required
and entered by the user.

List and detail presentation must show Session type. Curriculum Sessions show
the locked Version, Version Lesson and Live Class Activity. Operational
Sessions show “Outside curriculum” and must not render an empty Lesson cell
that implies missing data.

Session tách:

```text
scheduled_start_at / scheduled_end_at

actual_start_at / actual_end_at
```

Allowed status:

```text
scheduled

live

completed

cancelled

no_show
```

`draft` không phải Session status. Session được chuẩn bị trong Cohort `draft`
vẫn được tạo ở trạng thái `scheduled`; trạng thái setup thuộc Cohort lifecycle,
không thuộc Session status.

Session bị cancel hoặc no-show vẫn được giữ để bảo toàn lịch sử. `completed`
không đồng nghĩa Activity đã completed.

### Session Status Vocabulary Amendment — 2026-08-10

Approved: 2026-08-10 bởi LearnForge Architecture Owner.

Amendment này thay thế giá trị `ended` bằng `completed` và loại `draft` khỏi
tập status của Session. Lý do: `completed` nằm trong danh sách status canonical
tại [LF Development Standards](../LF-Development-Standards.md) § Status Fields
và đồng nhất với từ vựng Cohort/Enrollment/Membership, trong khi `ended` là
ngoại lệ duy nhất trong toàn hệ thống; `draft` chưa từng được bất kỳ policy nào
định nghĩa ý nghĩa nghiệp vụ, điều kiện vào hay transition ra.

Amendment giải quyết `DOC-CONFLICT-0001` tại
[LF Documentation Conflict Register](../quality/LF-Documentation-Conflicts.md).
Hợp đồng canonical đầy đủ cùng lifecycle transition nằm tại
[core_liveclass_sessions](../database/liveclass/core_liveclass_sessions.md)
§ Session Status And Time Convention Amendment — 2026-08-10.

### Session Time Convention — 2026-08-10

Approved: 2026-08-10 bởi LearnForge Architecture Owner.

`scheduled_start_at`, `scheduled_end_at`, `actual_start_at` và `actual_end_at`
lưu **giờ địa phương (wall-clock)** được diễn giải theo cột `timezone` của chính
Session đó. Chúng không phải UTC instant và không so sánh được trực tiếp giữa
các Session ở dạng giá trị thô.

Mọi phép so sánh phải parse giá trị theo `core_liveclass_sessions.timezone`,
chuẩn hóa về UTC, rồi mới so sánh instant. Quy tắc này áp dụng cho:

```text
overlap detection

Cohort operating-period boundary

edit / reschedule eligibility

runtime eligibility

Origin classification
```

Session xác nhận từ Schedule occurrence kế thừa timezone của Schedule. Session
tạo thủ công lưu timezone tại thời điểm nhập khoảng thời gian dự kiến. Vì vậy
hai Session trong cùng một Cohort có thể mang hai timezone khác nhau; so sánh
chuỗi hoặc so sánh naive giữa chúng là sai.

Browser timezone và server default timezone không bao giờ là calculation
authority.

Amendment giải quyết `DOC-CONFLICT-0002` và thay thế phát biểu cũ trong
[core_liveclass_sessions](../database/liveclass/core_liveclass_sessions.md)
§ Design Notes rằng thời gian Session "nên được lưu theo UTC".

Binding fields are immutable after Attendance, Recording, Replay, Progress
evidence or other operational history exists. Such Sessions are never hard
deleted. Rescheduling must retain the schedule-change audit trail. Draft and
active Cohorts may prepare schedule setup; runtime evidence still requires an
active Cohort and an eligible Session under the existing lifecycle policy.

Session setup follows these mutation boundaries and remains independent from
recurring Schedule persistence:

* a `draft` or `active` Cohort may create Sessions;
* a Session may be saved without a teacher during setup and is shown as
  unassigned, but every non-cancelled/non-no-show Session must have at least
  one assigned teacher before Cohort activation;
* an unassigned Session in an already active Cohort cannot accept runtime
  evidence such as Attendance or Recording until a teacher is assigned; this
  covers QR check-in too: the board offers no code and a scan records nothing;
* Owner decision 2026-11-04, widened 2026-11-10: in a `draft` or `active`
  Cohort, a `scheduled` Session whose start has passed, with no teacher and no
  evidence, may still receive teachers through a dedicated action. Other fields
  and the times stay unchanged, and each teacher must still be available for
  the Session's dates;
* an unassigned Session cannot move to `live`; it may still be cancelled or
  marked no-show;
* title, type, curriculum binding and delivery fields may be edited only while
  the Session has not started, remains in a pre-runtime state and has no
  Attendance, Recording, Replay, Progress evidence or other operational child;
* schedule changes use the dedicated reschedule workflow and append audit
  history rather than silently replacing history; a Session with Attendance or
  Recording cannot be rescheduled (2026-11-19);
* active Session time ranges in the same Cohort must not overlap, regardless
  of whether a Session originates from a Schedule, is created off-schedule, or
  is rescheduled; boundary-touching ranges are allowed, while cancelled and
  no-show Sessions no longer reserve the time range;
* a live, completed, cancelled or no-show Session keeps its type and
  binding immutable;
* completed or archived Cohorts expose historical Sessions read-only and do
  not accept new Sessions;
* cancelled and no-show Sessions remain visible and are never hard deleted.
* 2026-11-09 ([ADR-0023 §9](../adr/ADR-0023-Cohort-Content-Order.md)): cancelling a programme
  Session that has not started moves its lesson and every later live Activity one date down
  (no time changes); the cancelled Session stays on its Activity with
  `superseded_by_session_id` set to the Session now carrying that lesson. In the Sessions tab
  it shows under "Buổi đã hủy", and the lesson date carries a "Buổi bù" badge.
* 2026-11-19 (Owner duyệt và triển khai; chi tiết ở
  [ADR-0022](../adr/ADR-0022-Course-Type-On-Template.md) Amendment 1.4, mục "Dọn buổi khi hoàn
  thành lớp"): khi lớp chuyển sang `completed`, buổi chưa tới giờ bắt đầu thành `cancelled`
  (không dời lịch), buổi đang `live` và buổi tự học đang trong giờ thành `completed` tại thời
  điểm đó (đã qua giờ kết thúc thì ở giờ kết thúc theo lịch), buổi đã tới giờ mà chưa được bắt
  đầu giữ nguyên (`LiveClassSessionLifecycleService::settleForCohortCompletion()`).

---

## Giáo viên sửa phần vận hành của buổi — 2026-11-17 (Approved, đã triển khai)

Owner duyệt ngày 2026-11-17. Nguồn chi tiết: ADR-0002 § Teacher Session Operations
Amendment. Không thêm bảng, cột hay migration.

* **Ai:** giáo viên và trợ giảng được phân công vào chính buổi đó. Quản trị trung tâm vẫn
  sửa được mọi buổi.
* **Giáo viên sửa được:** link phòng họp, phòng học, địa chỉ, công cụ điểm danh.
* **Chỉ quản trị sửa:** hình thức, ngày giờ, giáo viên của buổi, huỷ buổi.
* **Khi nào:** theo quy tắc sửa buổi hiện có: buổi `scheduled`, chưa bắt đầu, chưa có
  điểm danh hay bản ghi, và chưa tới giờ bắt đầu dự kiến.
* **Riêng link phòng họp:** sửa được đến giờ kết thúc dự kiến, kể cả khi buổi đang
  `live`, cho cả quản trị và giáo viên của buổi.
* **Ở đâu:** khu giáo viên, trang "Buổi học trực tiếp", nút "Sửa" trên dòng của buổi.
* **Ghi vết:** mỗi lần sửa, của giáo viên hay quản trị, ghi một dòng vào
  `saas_audit_logs` kèm giá trị trước và sau.

---

# Attendance

`core_liveclass_attendances` ghi nhận sự tham gia của một Enrollment trong một
Session.

Allowed status:

```text
registered

present

late

absent

excused
```

Attendance sources:

```text
provider

manual

system
```

Một Enrollment có tối đa một Attendance record cho một Session. Attendance có
thể làm evidence cho `core_course_activity_progress`, nhưng không thay thế
progress và không bị Replay tự động ghi đè.

Raw join/leave events chi tiết có thể thuộc Track Domain; Attendance giữ
operational summary đã chuẩn hóa.

## Điểm danh bằng mã QR — 2026-11-09 (Approved)

Theo Đặc tả nghiệp vụ "Buổi học của Lớp học" v1.0 (Owner chốt 2026-11-09). Đặc tả thay
các quy tắc cũ trái với nó. Nguồn chi tiết: ADR-0002 § Attendance Check-In Amendment.
Không thêm bảng, cột hay migration. Mọi phần dưới đây chỉ chạy khi bật
`LIVECLASS_ATTENDANCE_ENABLED`.

* **Áp dụng cho:** buổi `offline` và `hybrid`. Buổi `online` điểm danh bằng lần bấm "Vào
  phòng họp", là đợt sau.
* **Trên bảng:** giáo viên được phân công hoặc quản trị bấm "Điểm danh" để hiện mã QR,
  kèm số học viên đã điểm danh. Mã đổi mỗi 30 giây, mỗi mã dùng được tối đa 60 giây (cộng
  5 giây cho mạng chậm), nên ảnh chụp gửi đi không dùng được. Mã được ký bằng khoá của ứng
  dụng, không lưu ở đâu cả. Trong mã là một link ngắn (`/qr/{session}/{mã}`, khoảng 50 ký
  tự) để mã ít ô và ô to. Nút "Phóng to" cho mã chiếm cả màn hình, để học viên ngồi cuối
  lớp quét được từ chỗ ngồi bằng app Camera của điện thoại.
  Từ 2026-11-09 nút trên thanh công cụ tên "Mã QR điểm danh": bấm lần đầu mã hiện to giữa
  màn hình, bấm lần nữa (hoặc "Thu vào góc") mã thu nhỏ ở góc dưới bên phải và vẫn tự đổi
  mã, bấm vào mã ở góc thì to lại, × thì đóng.
* **Học viên:** quét bằng điện thoại, đăng nhập bằng tài khoản thật của mình. Chưa đăng
  nhập thì đăng nhập rồi quay lại trang điểm danh, và giờ vào vẫn là lúc quét. Chỉ người
  được xem bảng mới được ghi nhận: còn trong lớp, tài khoản đang hoạt động, Enrollment
  `active` và trong thời hạn học.
* **Khi nào:** buổi đang `live`, từ 15 phút trước giờ bắt đầu dự kiến đến giờ kết thúc dự
  kiến. Quét trước giờ bắt đầu tính là đúng giờ.
* **Hệ thống ghi:**
  - `present`; `late` nếu quét sau 10 phút tính từ giờ bắt đầu, lấy mốc muộn hơn giữa giờ
    dự kiến và giờ giáo viên bắt đầu thật (`actual_start_at`). Số phút đặt ở
    `liveclass.late_after_minutes` cho tới khi cài đặt theo trung tâm được duyệt.
  - `joined_at` là giờ server nhận mã.
  - `attendance_mode = offline` (tại lớp), `attendance_source = system`.
* **Chỉ tính lần đầu.** Bản ghi đã có `joined_at`, hoặc đã được giáo viên hay quản trị
  ghi tay, thì không bị lần quét sau đổi.
* **Điểm danh tay vẫn dùng được và được ưu tiên.** Lưu tab Điểm danh chỉ ghi cho Enrollment
  `active` (đóng DOC-CONFLICT-0011). Dòng không đổi thì giữ nguyên, kể cả giờ vào và nguồn
  QR. Dòng có đổi thì thành `manual`. Tab hiện giờ vào của từng học viên.
* **Đợt sau:** ghi lần bấm "Vào phòng họp", tự ghi vắng 30 phút sau giờ kết thúc, lưu lịch
  sử sửa, số phút muộn theo trung tâm (cần duyệt Database docs), màn hình điểm danh cho
  giáo viên, và buổi học trực tiếp tự hoàn thành theo điểm danh.

## Bộ quy tắc điểm danh và các cách ghi còn lại — 2026-11-17 (Approved, đã triển khai)

Owner duyệt đề xuất nghiệp vụ ngày 2026-11-17. Nguồn chi tiết: ADR-0002 § Attendance
Policy And Recording Amendment. Đã triển khai ngày 2026-11-17: bộ quy tắc điểm danh, màn
quản lý `/admin/attendance-policies` (mục "Quy tắc điểm danh" trong nhóm menu "Lớp học"), ô
chọn bộ quy tắc ở popup sửa lớp, mã QR đọc mốc từ bộ của lớp, nút Điểm danh, vào phòng
họp, mở bảng, điểm danh tay của giáo viên (`/teacher/cohorts/{cohort}/attendance`, mở từ
"Lớp của tôi"), lịch sử sửa và tự ghi vắng (`liveclass:mark-absent`, mỗi phút). Tất cả vẫn
sau công tắc `LIVECLASS_ATTENDANCE_ENABLED`; mặc định bật từ 2026-11-17 (Owner quyết định 11), đặt
`false` để tắt.
Phần này thay danh sách "Đợt sau" của mục mã QR ở trên, trừ "buổi học trực tiếp tự hoàn
thành theo điểm danh" đã có theo ADR-0030.

Tự ghi vắng chỉ xét các buổi kết thúc trong 7 ngày gần nhất và không trước
`liveclass.auto_absent_from` (UTC, mặc định `2026-11-18 00:00:00`). Buổi `hybrid` chỉ bị
bỏ qua khi bộ quy tắc đặt ghi tay cho cả buổi tại lớp lẫn buổi trực tuyến.

Owner muốn quy tắc điểm danh theo sát tài liệu nguồn (MK Live). Khác biệt còn lại: LF
không có Klass và mã QR đổi mỗi 30 giây. Quy tắc "một thiết bị một học viên" đã có từ
2026-11-18 (mục "Một thiết bị một học viên" bên dưới).
Nội dung vẫn nằm ở Template; lớp chỉ đọc.

**Bộ quy tắc điểm danh** (`core_liveclass_attendance_policies`)

* Mỗi trung tâm có các bộ có tên và đúng một bộ mặc định. Lớp chọn một bộ ở
  `core_course_cohorts.attendance_policy_id`; để trống là dùng bộ mặc định.
* Thiết lập và mặc định: mở trước giờ học 15 phút; muộn sau 10 phút; vắng sau 30 phút
  hoặc không giới hạn; tự ghi vắng 30 phút sau buổi; tính từ lúc giáo viên thực sự bắt
  đầu (bật); cách ghi giờ vào lớp cho buổi tại lớp và buổi trực tuyến (hệ thống tự ghi
  hoặc giáo viên ghi tay); học bù (bật, không giới hạn); hạn sửa của giáo viên 7 ngày.
* Quản trị trung tâm tạo, sửa, xoá và gán cho lớp. Giáo viên chỉ xem. Bộ mặc định và bộ
  đang gán cho lớp không xoá được.
* Gán cho lớp ở form lớp, hoặc ở cửa sổ "Áp dụng cho lớp" của từng bộ: tìm theo mã hoặc
  tên, tick chọn, xem tóm tắt thay đổi rồi xác nhận. Bỏ tick là đưa lớp về bộ mặc định.
* Kết quả theo mốc: vào đến hết mốc muộn là `present`; sau mốc muộn đến hết mốc vắng là
  `late`; sau mốc vắng thì tuỳ cách ghi (mã QR bị từ chối, các cách khác ghi `absent`).
* Sửa bộ hoặc đổi bộ của lớp chỉ áp dụng cho kết quả ghi sau đó. Kết quả đã ghi không
  bị tính lại.
* Mã QR lấy mốc mở sớm và mốc muộn từ bộ của lớp. Sau mốc vắng, mã ẩn và lần quét bị từ
  chối; giáo viên ghi tay. Bộ đặt "giáo viên ghi tay" cho buổi tại lớp thì bảng không
  hiện mã. Mã vẫn đổi mỗi 30 giây.
* Thiết lập học bù có hiệu lực từ 2026-11-18 (mục Điểm danh theo nội dung và học bù); form
  hiện lại hai ô "Cho học bù" và "Hạn học bù".
* Danh sách bộ quy tắc gắn nhãn "Bộ mặc định" cho bộ mặc định thay vì chỉ ẩn nút xoá.
* Tab Điểm danh của lớp mở ở buổi gần nhất đã đến giờ bắt đầu (chưa có buổi nào thì buổi
  đầu tiên). Với giáo viên, tab này chỉ xem và có nút "Điểm danh" dẫn sang trang ghi tay của
  đúng buổi đang xem (2026-11-18).

**Vào phòng họp** (buổi `online`, và người ở xa của buổi `hybrid`)

* Trang lớp của học viên có nút "Vào phòng họp" thay cho link. Bấm là ghi giờ vào lớp
  theo giờ server rồi mở phòng họp.
* Bấm được từ mốc mở sớm đến giờ kết thúc dự kiến, khi buổi chưa bị huỷ. Không cần giáo
  viên bấm "Bắt đầu" trước. Điều kiện về học viên giống mã QR.
* Ghi `attendance_mode = online`, `attendance_source = system`, `joined_at`, và
  `present`, `late` hoặc `absent` theo các mốc. Vào sau mốc vắng vẫn vào được, kết quả là
  `absent`, giờ vào được giữ, trang báo trước cho học viên.
* Chỉ tính lần đầu. Kết quả ghi tay không bị đổi. Bộ đặt "giáo viên ghi tay" cho buổi
  trực tuyến thì nút chỉ mở phòng họp.
* Hệ thống chỉ biết học viên đã bấm nút, không biết họ có ở trong phòng họp hay không.

**Mở bảng điện tử trong tài khoản học viên**

Ở LF học viên xem bảng ngay trong tài khoản của mình, không cần qua link phòng họp.
Server không biết học viên ngồi ở đâu (người ở lớp cũng có thể mở bảng trên laptop), nên
quy tắc dựa vào loại buổi mà trung tâm đã đặt (Owner quyết 2026-11-17):

| Loại buổi | Mở bảng | Cách được điểm danh |
| --- | --- | --- |
| `offline` | Không tính, với bất kỳ ai | Quét mã QR hoặc bấm nút Điểm danh |
| `online` | Ghi `online`, như bấm "Vào phòng họp"; lần nào trước thì tính lần đó | Mở bảng hoặc "Vào phòng họp" |
| `hybrid` | Không tính, với bất kỳ ai | Người ở xa: "Vào phòng họp". Người ở lớp: mã QR hoặc nút Điểm danh |

* Buổi `hybrid`: học viên đã được ghi `online` (qua "Vào phòng họp") rồi mới quét mã hoặc
  bấm nút Điểm danh thì giữ giờ vào sớm nhất và trạng thái đã ghi, chỉ đổi hình thức sang
  `offline`.
* Bảng chỉ mở được khi buổi đang `live`. Học viên vào sớm đi qua nút "Vào phòng họp".
* Bộ quy tắc đặt "giáo viên ghi tay" cho buổi trực tuyến thì mở bảng không ghi gì.
* Quy tắc "mở hoặc xem bảng không phải là điểm danh" của mục mã QR vẫn áp dụng cho buổi
  `offline` và `hybrid`.

**Nút Điểm danh** (buổi tại lớp)

* Mỗi buổi tại lớp dùng một công cụ điểm danh, lưu ở
  `core_liveclass_sessions.attendance_tool`: `board` (mã QR trên bảng điện tử, mặc định)
  hoặc `button`. Chọn ở form buổi học của lớp.
* Với `button`, học viên tự bấm "Điểm danh" trên trang lớp; giáo viên không cần mở gì và
  bảng không hiện mã QR.
* Đã có kết quả (kể cả đang chờ nội dung) thì nút Điểm danh ẩn đi, chỉ còn dòng kết quả
  (Owner 2026-11-19).
* Quy tắc giống "Vào phòng họp": cùng khoảng thời gian, cùng điều kiện về học viên, chỉ
  tính lần đầu, không cần giáo viên bấm "Bắt đầu", bấm sau mốc vắng vẫn được nhưng ghi
  `absent` kèm giờ vào và có dòng báo trước. Khác ở chỗ ghi `attendance_mode = offline`
  và luôn tính mốc từ giờ trên lịch.
* Buổi `hybrid`: công cụ áp dụng cho người ở lớp; người ở xa bấm "Vào phòng họp".
* Bộ quy tắc đặt "giáo viên ghi tay" cho buổi tại lớp thì không có mã QR và không có nút.
* Hệ thống không biết học viên có thật sự ở trong phòng học hay không. Quy tắc "một
  thiết bị một học viên" bên dưới hạn chế việc này.

**Một thiết bị một học viên** (Owner duyệt 2026-11-18, theo "Nghiệp vụ luồng học tập lớp MK"
mục 2.4; thay câu "được hoãn" của ADR-0002 § Attendance Policy And Recording Amendment)

* Trong một buổi, một thiết bị chỉ điểm danh cho một học viên. Áp dụng cho quét mã QR và
  nút Điểm danh của buổi tại lớp. Không áp dụng cho "Vào phòng họp" và buổi tự học (học ở
  nhà, một gia đình có thể dùng chung máy).
* Thiết bị đã điểm danh cho học viên A mà học viên B dùng để điểm danh cùng buổi: hệ thống
  từ chối, không ghi gì, và báo "Thiết bị này đã điểm danh cho học viên khác trong buổi học
  này. Hãy báo giáo viên để được ghi nhận." Giáo viên ghi tay nếu B có mặt.
* Thiết bị chỉ bị gắn với học viên khi lần điểm danh đó được ghi (`recorded` hoặc
  `mode_changed`). Chính học viên đó điểm danh lại, hoặc thiết bị đó dùng ở buổi khác, không
  bị từ chối.
* Nhận ra thiết bị bằng mã ngẫu nhiên trong cookie `lf_attendance_device` của trình duyệt
  (một năm). Thông tin "thiết bị nào đã điểm danh cho ai" chỉ nằm trong cache, tự mất sau
  48 giờ; không có cột hay bảng. Đây là rào cản, không phải bằng chứng: trình duyệt khác,
  chế độ ẩn danh hoặc xóa cookie là một thiết bị mới.
* Code: `LiveClassAttendanceDevice::guard()`.

**Tên học viên vừa quét trên bảng** (Owner duyệt 2026-11-18, MK 2.4)

* Dưới mã QR, bảng của người trình chiếu hiện 5 lần điểm danh tại lớp mới nhất do hệ thống
  ghi: tên, giờ vào, kết quả (Có mặt, Muộn hoặc "Chờ nội dung"). Bảng hỏi lại 5 giây một lần.
* Chỉ người được trình chiếu (giáo viên của buổi, quản trị) nhận dữ liệu này.

**Điểm danh tay**

* Quản trị trung tâm ở mọi lớp. Giáo viên đang được phân công vào lớp, ở màn Điểm danh
  của khu giáo viên.
* Ghi `present`, `late`, `absent`, `excused` kèm ghi chú. `excused` chỉ ghi bằng tay.
* Giáo viên ghi và sửa từ giờ bắt đầu buổi đến hết hạn sửa của bộ quy tắc. Quản trị
  không giới hạn.
* Lớp `completed` (Owner duyệt và triển khai 2026-11-19; chi tiết ở ADR-0022 Amendment 1.4,
  mục Bổ sung 2026-11-19): quản trị vẫn sửa không giới hạn, giáo viên vẫn sửa đến hết hạn sửa;
  lớp `archived` chỉ xem. Màn hình điểm danh của lớp `completed` nhắc lối vào lớp, mã QR, nút
  Điểm danh và học bù đã đóng. Kết quả sửa tính lại hoàn thành của Buổi học trực tiếp và tiến
  độ.
* Kết quả ghi tay là kết quả sau cùng.
* Màn hình hiện tên bộ quy tắc, các mốc chính, giờ vào, hình thức và nguồn. Học viên
  chưa có kết quả và chưa hết thời gian chờ hiện "Chưa điểm danh".
* Trang Điểm danh của giáo viên có nút "Xem đầy đủ" mở toàn bộ bộ quy tắc của lớp, chỉ
  đọc (Owner quyết 2026-11-17). Giáo viên không có trang quản lý bộ quy tắc.

**Lịch sử sửa** (`core_liveclass_attendance_changes`)

* Mỗi lần một kết quả đã có bị đổi, và mỗi lần ghi tay, lưu một dòng chỉ-thêm: ai, lúc
  nào, từ trạng thái và hình thức nào sang trạng thái và hình thức nào, ghi chú.
* Lần ghi đầu tiên của hệ thống không tạo dòng lịch sử.

**Tự ghi vắng**

* Lệnh định kỳ ghi `absent`, nguồn `system`, cho học viên đang trong lớp, Enrollment
  `active`, chưa có kết quả, khi đã qua giờ kết thúc dự kiến cộng thời gian chờ.
* Bỏ qua buổi bị huỷ, học viên đã có kết quả, và buổi mà bộ quy tắc đặt ghi tay cho hình
  thức đó.
* Trong lúc chờ học viên thấy "Chờ ghi nhận điểm danh".

**Bổ sung khi rà soát** (Owner xác nhận cả bảy điểm ngày 2026-11-17)

1. Giáo viên bấm "Bắt đầu" trễ hơn lịch thì kết quả hệ thống đã ghi trước lúc đó được xét
   lại một lần theo mốc mới. Kết quả ghi tay không bị xét lại.
2. Quy tắc "Mở bảng điện tử trong tài khoản học viên" ở trên: chỉ tính ở buổi `online`.
   Owner xác nhận lúc đầu cho cả `hybrid`, rồi thu hẹp trong cùng ngày. Buổi `hybrid`: quét
   mã hoặc bấm nút Điểm danh sau khi đã được ghi `online` chỉ đổi hình thức sang `offline`.
3. Điểm danh tay chỉ có bốn giá trị; không đưa được kết quả về `registered`.
4. Hạn sửa của giáo viên tính tròn N × 24 giờ từ giờ kết thúc dự kiến của buổi.
5. Giáo viên kết thúc buổi sớm thì mọi cách ghi giờ vào lớp đóng từ lúc đó.
6. Lần ghi đầu tiên của hệ thống không tạo dòng lịch sử; giáo viên của lớp xem được
   lịch sử.
7. Tự ghi vắng bỏ qua buổi kết thúc trước `liveclass.auto_absent_from` và học viên có
   `joined_at` của lớp sau giờ kết thúc dự kiến của buổi.

Thẻ buổi học trên trang học viên hiện từ mốc mở sớm, không chỉ khi buổi `live`.

**Điều hệ thống không biết**

* "Vào phòng họp": chỉ biết học viên đã bấm nút, không biết họ có vào phòng họp hay ở lại
  bao lâu.
* Mở bảng điện tử: chỉ biết học viên đã mở bảng, không biết họ ngồi ở đâu. Vì vậy chỉ
  tính ở buổi `online`, nơi không ai ở lớp.
* Nút Điểm danh: không biết học viên có ở trong phòng học.
* Mã QR: người ở xa quét được nếu giáo viên chia sẻ màn hình có mã qua phòng họp; giáo
  viên cần thu mã vào góc hoặc đóng mã khi chia sẻ màn hình.

**Chữ hiển thị trạng thái** (Owner quyết 2026-11-17, theo tài liệu nguồn): `registered` là
"Chưa điểm danh", `present` "Có mặt", `late` "Muộn", `absent` "Vắng", `excused` "Có phép".
Trước đó giao diện dùng "Dự kiến", "Đi muộn", "Vắng có phép". Giá trị lưu trong database
không đổi.

## Điểm danh theo nội dung và học bù — 2026-11-18 (Approved, đã triển khai)

Nghiệp vụ và lý do: [ADR-0022 Amendment 1.3, Đợt 3](../adr/ADR-0022-Course-Type-On-Template.md#đợt-3--tính-vào-điểm-danh-và-học-bù).
Quy tắc chỉ áp dụng cho kết quả do hệ thống ghi; kết quả ghi tay là kết quả sau cùng.

* Activity được tick "Tính vào điểm danh" thuộc phần Trong hoặc Sau của buổi có người phụ
  trách. Buổi không có Activity nào được tick thì điểm danh như trước.
* **Phần Trong.** Lần vào lớp chỉ cho ra Có mặt hoặc Muộn khi học viên đã xong mọi Activity
  được tick trước giờ kết thúc trên lịch. Chưa xong thì hệ thống giữ giờ vào và hình thức,
  kết quả chờ (`registered` có `joined_at`); xong trong giờ thì kết quả tính theo giờ vào đã
  giữ. Vào sau mốc vắng vẫn ghi Vắng ngay.
* **Học bù.** Sau giờ kết thúc trên lịch, học viên xong mọi Activity phần Sau được tick thì
  được ghi `present` với `attendance_source = makeup`, khi bộ quy tắc bật học bù, lớp đang
  `active` và nội dung được làm xong trong hạn (hết 23:59 ngày thứ N sau buổi; không giới
  hạn là đến khi lớp kết thúc). Áp dụng cho học viên chưa có kết quả, kết quả đang chờ và
  Vắng do hệ thống ghi. Không đè kết quả ghi tay, Có phép, Có mặt hoặc Muộn. Buổi kết thúc
  trước ngày học viên vào lớp không có học bù. Mỗi lần học bù ghi một dòng lịch sử do hệ thống.
* **Thời điểm làm xong.** Quiz tính từ giờ nộp (tiêu chí `pass`: lần nộp có điểm đạt, chấm muộn
  vẫn tính); Activity khác tính từ thời điểm hoàn thành.
* Tự ghi vắng áp dụng cả cho kết quả đang chờ và giữ giờ vào. Khi giáo viên bắt đầu muộn và
  kết quả trực tuyến được tính lại, kết quả hết Vắng nhưng chưa xong nội dung trở về chờ.
* `live_class` hoàn thành theo kết quả sau cùng, kể cả Có mặt do học bù.
* Hiển thị: học viên thấy "Chờ ghi nhận điểm danh" và "Có mặt · Học bù"; bảng điểm danh hiện
  "Vào lớp lúc HH:mm, chờ hoàn thành nội dung" và nhãn "Học bù".

**Trang của học viên** (Owner duyệt 2026-11-18, theo "Nghiệp vụ luồng học tập lớp MK" mục 3.1)

* Khối "Buổi học sắp bắt đầu / đang diễn ra" tự làm mới mỗi phút khi tab đang mở, không tải
  lại trang, ở bốn trang: Khóa học của tôi, Kết quả học tập, trang bài học, trang lớp. Khối
  chỉ đổi khi dữ liệu đổi. Nguồn: `GET /my-courses/live-sessions/now` (`?cohort=` để lọc một
  lớp), trả về đúng phần HTML của khối.
* Trang lớp: dưới kết quả điểm danh của buổi hiện giờ vào lớp và hình thức, ví dụ
  "Vào lớp 19:28 · Trực tuyến". Ghi chú của giáo viên không hiện cho học viên.
* "Chờ ghi nhận điểm danh" hiện cho buổi đã diễn ra (`live` hoặc `completed`), đã qua giờ kết
  thúc theo lịch mà học viên chưa có kết quả, cho đến khi hệ thống ghi vắng hoặc giáo viên
  ghi tay. Buổi không ai bắt đầu (vẫn `scheduled`) không hiện nhãn này. Buổi đã kết thúc quá
  7 ngày (hết khoảng tự ghi vắng nhìn lại) cũng không hiện: học viên thấy "Chưa điểm danh".
* Buổi tự học không có nút "Bắt đầu" nên giữ `scheduled` đến khi kết thúc. Trong giờ của buổi,
  trang lớp của học viên hiện "Đang diễn ra", khớp với khối buổi học phía trên (2026-11-19).

---

# Recording

`core_liveclass_recordings` giữ operational reference của recording phát sinh
từ Session.

```text
LiveClass Session

↓

LiveClass Recording Reference

↓

Media File / Media Processing

↓

Replay
```

Allowed status:

```text
processing

ready

failed

archived

deleted
```

Provider `recording_url` chỉ là source URL. Khi có `media_file_id`, Media File
là canonical file reference.

---

# Replay

`core_liveclass_replays` lưu operational replay summary theo Enrollment và
Recording.

```text
Recording

↓

Enrollment Replay

↓ evidence

Course Activity Progress
```

Supported behavior:

* Resume watching
* Watch duration
* Progress percentage
* Operational replay completion

Raw play/pause/seek events thuộc Track Domain. `completed` ở Replay không phải
Course completion; Course Domain vẫn phải áp dụng frozen completion rule.

---

# Live Chat

`core_liveclass_chat_logs` lưu message trong Session để phục vụ audit, replay,
teacher review và AI summary.

Allowed message types:

```text
text

question

answer

system

file
```

Chat không dùng để tính completion trực tiếp. File đính kèm phải qua Media
Domain. Chat phải tuân theo tenant retention, privacy và consent policy.

---

# Whiteboard

Amendment 2026-11-07 (Approved, [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md)
§ LiveClass Whiteboard Amendment; ADR là nguồn chi tiết, mục này tóm tắt).

Trạng thái code (2026-11-08): giai đoạn 1 (bảng giáo viên, bắt đầu và kết thúc buổi, nút Mở
bảng của quản trị) và giai đoạn 2 (học viên xem và đồng bộ) đã có; Media đợt 1–3 và bản
video faststart đã có (§ Âm thanh, video và chế độ nghe; § Tài liệu Office trên bảng). Đợt 4
(S3) hoãn đến sau production; Live Quiz cần ADR riêng.

**Bảng điện tử** là màn hình giảng của một Session đang `live`: giáo viên chiếu tài
liệu của bài học lên màn hình cảm ứng trong lớp và viết, vẽ lên đó; học viên có thể
mở cùng bảng trên máy mình và xem theo giáo viên. Nghiệp vụ theo bảng điện tử của
AllVia; bảng chức năng trong
[LF-LiveClass-Whiteboard-Proposal](../quality/LF-LiveClass-Whiteboard-Proposal.md)
là chuẩn nghiệm thu. Không có QR mời vào bảng, không có tài khoản tạm; mã QR điểm danh
hiện trên bảng là việc của Attendance (§ Attendance, Điểm danh bằng mã QR).

## Quyền sở hữu

* Bảng thuộc LiveClass, gắn với đúng một Session cùng tenant. Bảng không có bảng
  database, không có trạng thái riêng, **không cần migration**.
* Bảng không sinh Attendance, Replay, Progress hay Completion. Mở bảng, xem bảng
  hay vẽ trên bảng đều không phải evidence.
* Nét vẽ, ghi chú, trang và độ phóng chỉ lưu trong trình duyệt của người vẽ, theo
  tenant, người dùng, Session và tài liệu; bị xoá khi đăng xuất vì màn hình trong lớp
  là máy dùng chung. Chúng không phải Media, Recording hay Chat.
* Trạng thái đang chiếu giữ tạm trong cache theo `customer_id` và Session, hết hạn
  sau giờ kết thúc dự kiến cộng một khoảng đệm. Cache không phải nguồn sự thật.

## Tài liệu trên bảng

| Session | Ngăn tài liệu |
| --- | --- |
| `curriculum` | Media `ready` của mọi Version Activity học liệu trong Version Lesson của Session, không phân biệt phần Trước / Trong / Sau (ADR-0022 Amendment 1.3), theo thứ tự của lớp (`CourseCohortContentOrder`); chỉ đọc |
| `operational` | Trống |

* Từ 2026-11-18, ADR-0022 Amendment 1.3 thay điều kiện "cờ `available_during_session`" của
  ADR-0002 LiveClass Whiteboard Amendment (văn bản Frozen giữ nguyên): mỗi Activity chỉ thuộc
  một phần, nên slide đặt ở "Trước buổi học" vẫn phải trình chiếu được. Luật mở khoá bài của
  học viên (`VersionActivityAccessService`) không áp dụng cho phần giáo viên chiếu; việc xem
  trên bảng không phải là truy cập Activity và không tính Progress.
* Giáo viên có thể mở thêm một PDF từ máy mình. Tệp chỉ nằm trong trình duyệt, không
  tải lên, không gửi cho học viên; học viên chỉ thấy nét vẽ và một dòng báo tài liệu
  đang ở trên máy giáo viên.
* PDF hiển thị trong bảng bằng pdf.js; video tải lên, video nhúng tin cậy và âm thanh
  tải lên phát trong bảng, theo [LF-Media](../platform/LF-Media.md) § Whiteboard
  Rendering Exception và § Âm thanh, video và chế độ nghe bên dưới. Tài liệu Office có
  bản PDF của Media thì hiện như PDF (§ Tài liệu Office trên bảng); Office chưa có bản
  PDF và loại khác vẫn mở tab mới.
* Mức 100% của một trang tài liệu là cả trang nằm trọn trong bảng (nhỏ hơn giữa vừa
  chiều cao và vừa chiều rộng). Màn hình rộng vẫn vừa chiều cao như trước; điện thoại
  dựng đứng thì vừa chiều rộng. Máy xem theo độ phóng của người trình bày tính trên mức
  này, nên ở 100% màn hình nào cũng thấy trọn trang.
* Link có chữ ký do một hàm của Media cấp theo ngữ cảnh LiveClass đã kiểm quyền.
  LiveClass không đọc storage trực tiếp.

## Tài liệu Office trên bảng — Amendment 2026-11-08 (Approved)

Theo [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) § Whiteboard Office Preview
Amendment. Không thêm bảng, cột hay migration.

* Media chuyển Word, PowerPoint, Excel sang PDF khi tài liệu được gắn vào Activity, không
  phải khi bảng mở. LiveClass chỉ đọc trạng thái và xin URL qua Media.
* Mỗi tài liệu Office trong ngăn tài liệu có một trạng thái: **PDF sẵn sàng** (mở trên
  bảng như PDF), **đang chuyển** hoặc **không chuyển được** (cả hai mở tệp gốc ở tab mới).
  Ngăn tài liệu tải lại danh sách mỗi lần mở; máy xem chưa thấy bản PDF thì tải lại danh
  sách một lần trước khi mở theo người trình chiếu.
* Sự kiện đồng bộ vẫn chỉ mang định danh Version Activity. Nét vẽ và ghi chú riêng của bản
  PDF dùng chung khoá `activity:{id}` với tài liệu đó.
* Admin xem trạng thái và yêu cầu chuyển lại trên Activity của khoá học mẫu; giáo viên
  không sửa được tài liệu từ bảng.

## Ai được mở

Server kiểm tra ở mỗi lần mở trang, đăng ký kênh, xin link tài liệu và gửi thao tác:

* cùng `customer_id` theo `TenantContext`; Cohort `active`; Session `live`;
* **người trình chiếu**: giáo viên đang hoạt động có dòng trong
  `core_liveclass_session_teachers` của Session (mọi vai trò), khung
  `assigned_from`/`assigned_to` (nếu có) bao thời điểm hiện tại; hoặc
  `customer_admin` của tenant;
* **người xem**: học viên có dòng `core_course_cohort_students` `active`, `left_at`
  trống, trong Cohort của Session; Enrollment `active` và còn trong thời hạn học
  (`allowsLearningAccessAt`).

## Một người trình chiếu tại một thời điểm

Nhiều người có quyền trình chiếu, nhưng chỉ **một người đang trình chiếu** được gửi
thao tác, giữ bằng khoá theo Session trong cache. Người mở bảng trước giữ khoá; người
khác thấy bảng ở chế độ xem và có thể bấm "Nhận trình chiếu"; người cũ được báo và
chuyển sang chế độ xem. Server từ chối thao tác của người không giữ khoá.

## Bắt đầu và kết thúc buổi

Giáo viên được phân công tự bắt đầu (`scheduled → live`) và kết thúc
(`live → completed`) buổi của mình qua `LiveClassSessionLifecycleService`, theo bảng
chuyển trạng thái hiện có. Server từ chối bắt đầu ngoài ngày dự kiến của buổi theo
múi giờ đã lưu của buổi. Huỷ buổi và đánh dấu vắng (`no_show`) vẫn là việc của admin.

**Giờ bắt đầu và kết thúc của giáo viên (Owner 2026-11-18).** Trong ngày dự kiến, giáo viên
chỉ bắt đầu được từ mốc mở điểm danh của lớp: giờ bắt đầu dự kiến trừ `opens_before_minutes`
của bộ quy tắc lớp đang dùng (mặc định 15 phút). Trước mốc đó nút "Bắt đầu buổi học" không
hiện, dòng buổi ghi "Bắt đầu được từ HH:mm" và server từ chối. Buổi `live` không kết thúc
được trước giờ bắt đầu dự kiến. Lý do: buổi bắt đầu sớm hơn mốc mở thì đã `live` nhưng học
viên chưa được ghi giờ vào, và buổi kết thúc sớm vẫn giữ nguyên khung giờ dự kiến. Quy tắc
này áp dụng cho giáo viên (`LiveClassWhiteboardService::startForTeacher`,
`completeForTeacher`); thao tác của admin ở tab Buổi học không đổi.

**Danh sách buổi của giáo viên** (`/teacher/live-sessions`, 2026-11-18) có ba tab: "Sắp tới"
(buổi `scheduled` từ hôm nay và buổi `live`, nơi bắt đầu, mở bảng, kết thúc), "Cần xử lý"
(buổi `scheduled` của ngày đã qua mà chưa dạy; giáo viên chính có `class.manage` có lối sang
tab Buổi học của lớp để huỷ và xếp buổi bù, người khác được nhắc báo quản trị) và "Đã dạy"
(buổi `completed`, mới nhất trước, 10 buổi một trang, kèm lối vào điểm danh). Trang chỉ đọc thêm, không đổi trạng
thái buổi.

**Hết giờ là kết thúc (Owner 2026-11-11).** Buổi `live` đã qua `scheduled_end_at` (theo múi
giờ của buổi) được hệ thống tự chuyển sang `completed` bằng lệnh
`liveclass:complete-overdue` chạy mỗi phút, đi qua cùng lifecycle service; `actual_end_at`
ghi bằng giờ kết thúc dự kiến (không sớm hơn `actual_start_at`). Chỉ áp dụng cho Cohort
`active`. Buổi còn `scheduled` mà đã qua giờ không bị đổi: huỷ và `no_show` vẫn là việc
của admin.

Mọi lần Session rời `live` (kết thúc, huỷ, vắng) hoặc Cohort rời `active` đều đi qua
một điểm chung trong lifecycle service: xoá cache và khoá trình chiếu, phát sự kiện
"bảng đã đóng"; máy đang mở bảng tự đóng. Học viên bị rút khỏi lớp cũng bị ngắt như vậy.

## Đồng bộ

* Mỗi Session một kênh private, xác thực ở `routes/channels.php` theo điều kiện trên.
* **Chỉ người đang trình chiếu gửi**, qua một endpoint có xác thực; server kiểm tra
  quyền, loại và kích thước, đánh số thứ tự nguyên tử theo Session, cập nhật cache rồi
  phát ngay (`ShouldBroadcastNow`).
* **Tắt client event.** Reverb mặc định cho thành viên kênh gửi `client-*` từ mọi
  origin; `config/reverb.php` phải đặt `accept_client_events_from = none`, giới hạn
  `allowed_origins` theo domain tenant và bật rate limiting.
* Học viên chỉ nhận. Nét vẽ riêng của học viên ở lại trên máy học viên.
* Người vào sau: đăng ký kênh, giữ tạm thao tác đến, tải trạng thái trong cache, rồi
  áp các thao tác có số thứ tự lớn hơn. Thấy hụt số thứ tự thì tải lại toàn bộ.
* Mỗi thao tác tối đa 8 KB (Reverb giới hạn 10.000 byte); nét dài được chia nhỏ. Con
  trỏ và vị trí cuộn không lưu vào cache.
* **Nét vẽ theo bút** (2026-11-09). Nét được gửi ngay khi đang vẽ, không chờ nhấc bút:
  `stroke.add` lúc đặt bút, rồi `stroke.append` với các điểm mới. Hàng đợi gộp các đoạn
  chưa gửi của cùng một nét thành một thao tác, và mỗi lần gửi cách nhau ít nhất 100 ms
  (tối đa 10 lần/giây, dưới giới hạn 900 lần/phút của route gửi thao tác). Nét bị huỷ
  giữa chừng (hai ngón tay, đổi công cụ, đổi trang, `pointercancel`) gửi `stroke.remove`;
  nếu chưa gửi phần nào thì bỏ luôn, không gửi gì. Snapshot có cả nét đang vẽ. Máy xem
  nhận lại điểm đã có (từ snapshot hoặc thao tác phát lại) thì bỏ qua phần trùng; hụt
  điểm thì tải lại toàn bộ. Không đổi loại thao tác của ADR-0002.
* **Mục tiêu độ trễ nét vẽ** (2026-11-09). Tính từ lúc bút di chuyển trên máy người
  trình chiếu đến lúc đoạn nét hiện trên máy học viên: **p95 ≤ 300 ms**, khi mỗi máy có
  ping tới server ≤ 100 ms. Ngân sách: chờ gom gói tối đa 100 ms, gửi lên server
  30–80 ms, Laravel và Reverb 30–100 ms, tới học viên và vẽ 20–50 ms. Lời nói qua Zoom
  thường trễ 200–400 ms, nên trong mục tiêu này nét vẽ không chậm hơn lời nói. Đo trên
  local (2026-11-09, nét 1,6 giây): đoạn đầu ~160 ms sau khi đặt bút, mỗi đoạn ~135 ms,
  đoạn cuối ~50 ms sau khi nhấc bút. Cách đo: giáo viên và học viên mở trên cùng một máy
  (chung đồng hồ), ghi thời điểm có điểm mới bên vẽ và thời điểm bên xem nhận được điểm
  đó; đo lại trên staging trước khi chạy thật. Vượt mục tiêu thì tìm chỗ chậm ở mạng hoặc
  server (khoá cache, broadcast), không giảm khoảng cách 100 ms giữa hai lần gửi, vì sẽ
  vượt giới hạn 900 lần/phút.
* **Chịu tải khi vẽ liền** (2026-11-09). Snapshot (tới 1 MB) nằm ở khoá cache riêng
  (`…:snapshot`, mang số thứ tự của nó), tách khỏi nhật ký thao tác (`…:state`). Mỗi
  lần gửi thao tác chỉ đọc và ghi nhật ký: đo local với bảng 1 MB, ~66 ms giảm còn
  ~1 ms mỗi lần. Người vào sau đọc nhật ký trước rồi tới snapshot; snapshot mới hơn
  nhật ký thì bỏ các thao tác nó đã gồm, snapshot cũ hơn điểm bắt đầu nhật ký thì không
  dùng. Snapshot hết hạn trước nhật ký thì server xin bản mới. Nhật ký cũ còn mang
  snapshot bên trong vẫn đọc được và được chuyển sang khoá riêng ở lần gửi kế tiếp.
  Mỗi route của bảng (presence, events, snapshot, release) có bộ đếm giới hạn riêng;
  trước đây các route `throttle:N,1` dùng chung một bộ đếm theo người dùng, nên vẽ liền
  hơn 6 giây làm presence (60/phút) và snapshot (120/phút) bị chặn 429. Đã thử nét
  liền 30 giây trên trình duyệt: 300 lần gửi, không lỗi, học viên vào muộn giữa nét
  vẫn nhận đủ.

## Âm thanh, video và chế độ nghe — Amendment 2026-11-08 (Approved)

Theo [LF-LiveClass-Whiteboard-Media-Evaluation](../quality/LF-LiveClass-Whiteboard-Media-Evaluation.md)
đợt 1 (B1–B6), Owner duyệt 2026-11-08. Không thêm bảng, cột hay migration.

* **Một Media một lúc.** Bảng mở nhiều nhất một video, video nhúng hoặc âm thanh. Âm
  thanh không đóng khi giáo viên đổi trang hay đổi PDF; mở video khác hoặc âm thanh khác
  thì thay thế. Sự kiện chỉ mang định danh Version Activity, không mang URL.
* **Sự kiện.** `video.play`, `video.pause`, `video.seek`, `video.close` (có số thứ tự,
  lưu cache) và `video.sync` (nhịp vị trí, không số thứ tự, không lưu cache) dùng cho
  cả video và âm thanh; trường `kind` là `video`, `embed` hoặc `audio`. Server đóng dấu
  giờ máy chủ `at` (mili giây) lên mọi sự kiện `video.*` và lên phần Media của snapshot;
  `at` do trình duyệt gửi bị bỏ. Trạng thái cache trả kèm giờ máy chủ `now`.
* **Chống lệch.** Khi Media đang phát, máy giáo viên gửi `video.sync` mỗi 5 giây. Máy
  người xem tính vị trí đích = `time` + (đang phát ? thời gian đã trôi từ `at` theo giờ
  máy chủ : 0) và chỉ tua khi lệch quá 1 giây. Người vào muộn tính cùng cách từ
  snapshot và các sự kiện sau nó.
* **Video nhúng (Amendment 2026-11-08, đợt 2).** YouTube và Vimeo đồng bộ phát, dừng,
  tua và vị trí như video tải lên, qua `postMessage` với iframe của nhà cung cấp theo
  [LF-Media](../platform/LF-Media.md) § Whiteboard Rendering Exception. Mở video nhúng
  thì máy người xem mở theo ở trạng thái dừng; nhịp `video.sync` của video nhúng gửi cả
  khi đang dừng, vì YouTube không báo sự kiện tua. Trình phát của nhà cung cấp không
  phản hồi (bị chặn, mạng chậm) thì video vẫn hiện, chỉ không đồng bộ. Trên iPhone, học
  viên có thể phải chạm vào chính trình phát để có tiếng.
* **Ghi chú riêng của học viên** (đợt 2, C2) đã có từ giai đoạn 2: máy xem có lớp nét vẽ
  và ghi chú riêng theo từng tài liệu, lưu trong trình duyệt (khoá có hậu tố `:viewer`),
  không gửi đi. Đây không phải nộp bài.
* **Xem trước riêng / Chiếu cho lớp.** Người đang trình chiếu có thể mở video hoặc âm
  thanh chỉ trên máy mình: khi đó không gửi sự kiện nào, snapshot không có Media, và
  Media đang chiếu cho lớp (nếu có) được đóng trước. "Chiếu cho lớp" gửi trạng thái hiện
  tại (vị trí, đang phát hay dừng) để lớp mở theo.
* **Chế độ nghe** chỉ áp dụng cho máy đang ở chế độ xem: "Ở lớp" phát không tiếng, "Từ
  xa" phát có tiếng. Máy đang trình chiếu luôn có tiếng. Mặc định theo `delivery_mode`
  của Session: `offline` → Ở lớp, `online` → Từ xa, `hybrid` → hỏi khi vào bảng (trong
  lúc chưa trả lời: Ở lớp). Lựa chọn lưu trong trình duyệt theo tenant, người dùng và
  Session, nên chỉ nhớ cho buổi đó; đổi được bất cứ lúc nào. Ai có quyền xem đều mở được
  bảng, ở trong lớp hay ở xa; mở bảng không bắt buộc và không phải điểm danh.
* **Bật tiếng.** Máy xem bị trình duyệt chặn tự phát có tiếng thì phát không tiếng (giữ
  đúng vị trí) và hiện nút "Bật tiếng". Tiếng chỉ được bật trong thao tác bấm của người
  dùng (nút Bật tiếng, nút chế độ nghe, câu trả lời câu hỏi buổi hybrid); thao tác đó
  cũng mở khoá âm thanh cho iPhone/Safari. Người xem tự bật hoặc tắt tiếng bằng điều
  khiển của trình phát thì chế độ nghe đổi theo.
* **Nhắc Zoom.** Buổi `online` hoặc `hybrid`: khi Media đang chiếu cho lớp, bảng của
  giáo viên nhắc không chia sẻ âm thanh máy tính qua Zoom/Meet, để học viên không nghe
  hai lần.

## Lối vào

Mỗi vai trò có nhóm route riêng với bộ middleware của vai trò đó, không có nhóm trộn
vai trò:

| Vai trò | Lối vào | Route của bảng |
| --- | --- | --- |
| Giáo viên | Danh sách buổi học của giáo viên: "Bắt đầu buổi học", "Mở bảng" | `/teacher/...` |
| Admin | Chi tiết buổi đang `live` trong tab Buổi học của Lớp học: "Mở bảng" | `/admin/...` |
| Học viên | Mục "Buổi học đang diễn ra" trên `/my-courses`, khi buổi đang `live`: "Xem bảng" | `/my-courses/...`, `role:student` |

Bảng mở trong cửa sổ riêng, có nút toàn màn hình.

**Vào phòng họp** (2026-11-09). Bảng không mang giọng nói hay hình (ADR-0002 § Hybrid
delivery); nói chuyện hai chiều đi qua phòng họp của Session. Buổi `online` hoặc `hybrid`
có `meeting_url_snapshot` dạng `http`/`https` thì có nút "Vào phòng họp", mở tab mới, ở:
danh sách buổi học của giáo viên, mục "Buổi học đang diễn ra" của học viên và thanh trạng
thái đồng bộ trên bảng (mọi vai trò). Buổi `offline`, không có link hoặc link không phải `http`/`https`
thì không có nút. Link chỉ hiện cho người đã qua kiểm tra quyền mở bảng của Session đó.

## Giao diện

Bố cục và chức năng theo AllVia; màu, icon và tooltip theo UI Kit của LF (Owner chốt
2026-11-07). Mọi nút chỉ có icon có tooltip i18n và `aria-label` (`data-tooltip`);
trên màn hình cảm ứng, chạm giữ để xem tooltip. Mọi chữ trên bảng (thông báo, trạng
thái kết nối, câu hỏi mở lại trang cũ, lỗi) dùng key `LF_liveclass_whiteboard_*` đủ
`vi` và `en`. Dùng được bằng bàn phím; hiệu ứng tôn trọng `prefers-reduced-motion`.

Trên điện thoại (rộng ≤ 600px) thanh trên cùng giữ một hàng: ẩn ô tên lớp, nút phóng
to, thu nhỏ và nút đưa về khung ban đầu (chụm hai ngón để phóng to; bấm số % để đưa về
khung ban đầu); nút còn 36px.

## Ngoài phạm vi

Âm thanh, video của giáo viên (WebRTC); điểm danh khi mở bảng; học viên gửi bài lên
bảng; Live Quiz, hoạt động quiz trên bảng và bảng thông minh AI (cần ADR riêng); chuyển
tài liệu Office sang PDF; lưu, xuất hoặc ghi lại bảng trên server.

---

# Relationship With Course Domain

```text
Course Product + Enrollment

↓ locked

Template Version + Version Activity

↓

LiveClass Room / Session

↓

Attendance / Replay Evidence

↓

core_course_activity_progress
```

Responsibilities:

* Course Version Activity sở hữu immutable learning context và completion rule.
* LiveClass sở hữu Room, Session và operational evidence.
* Course Activity Progress sở hữu trạng thái completion cấp Activity.
* Enrollment phân biệt từng learning cycle.
* Product và Template Version context phải nhất quán xuyên suốt.

---

# Relationship With Media Domain

```text
LiveClass Session

↓

LiveClass Recording Reference

↓ media_file_id

media_files
```

Media Domain sở hữu file, storage, processing, signed delivery, transcript và
file retention. LiveClass chỉ giữ reference cùng metadata vận hành.

---

# Relationship With Track Domain

LiveClass sinh operational summaries:

```text
Attendance

Replay Progress

Chat
```

Track Domain ghi append-only behavior events và rebuildable summaries:

```text
track_events

track_activity_summaries
```

Track không thay thế LiveClass source records và không sở hữu Course
completion.

---

# Relationship With AI Domain

LiveClass có thể cung cấp:

* Transcript references
* Attendance evidence
* Replay behavior
* Questions and participation

AI có thể tạo teacher analytics, summaries và learning insights, nhưng AI
output không ghi đè operational source records hoặc tự quyết định completion.

---

# Hybrid Learning

LiveClass hỗ trợ:

```text
Online

Offline

Hybrid
```

Schedule/Room/Session boundaries cho phép recurring planning và hybrid
delivery mà không thay đổi Course Foundation binding. Schedule CRUD/Preview và
explicit atomic creation từ selected occurrence với immutable Origin đã được
Foundation approved. Tạo Session khi lưu Schedule đã được duyệt (ADR-0002
§ Automatic Session Generation Amendment, 2026-11-05); synchronization vẫn thuộc
phase sau.

Trong buổi `hybrid`, người ngồi trong lớp nhìn bảng điện tử trên màn hình cảm ứng;
người học từ xa mở "Xem bảng" trên máy mình và nghe giảng qua `meeting_url_snapshot`
của Session. Bảng không truyền âm thanh hay video (§ Whiteboard).

## Vận hành buổi hybrid (hướng dẫn, 2026-11-09)

| Ai | Làm gì |
| --- | --- |
| Lớp học | Màn hình cảm ứng chạy bảng, và một máy chạy Zoom/Meet có micro (có thể là cùng máy). |
| Giáo viên | Bấm "Vào phòng họp", **bật micro**. **Không bật "chia sẻ âm thanh máy tính"** (Share sound) khi chiếu video hoặc âm thanh trên bảng; bảng tự nhắc (§ Whiteboard, Nhắc Zoom). |
| Học viên ở lớp | Nhìn màn hình của lớp; nếu mở bảng trên máy mình thì chọn "Ở lớp" (máy không phát tiếng). |
| Học viên ở xa | Mở "Xem bảng" và "Vào phòng họp"; chọn "Từ xa". Nghe giáo viên qua Zoom/Meet, nhìn bảng LF. |

* **Tiếng vọng.** Loa trong lớp phát video có thể lọt vào micro rồi sang Zoom. Nếu học
  viên ở xa nghe vọng: tạm tắt micro lúc chiếu video, hoặc dùng micro cài áo hay tai nghe.
* **Dự phòng: share màn hình bảng qua Zoom/Meet** chỉ khi học viên không mở được bảng
  (máy yếu, mất kết nối đồng bộ, Reverb lỗi). Khi share: không bật chia sẻ âm thanh máy
  tính nếu có video trên bảng, và báo học viên chỉ nhìn một nguồn (bảng LF hoặc màn hình
  share), không nhìn cả hai.
* **Điểm danh.** Mở bảng không phải điểm danh. Học viên ở lớp quét mã QR trên bảng, bản ghi
  có hình thức "tại lớp" (§ Attendance, Điểm danh bằng mã QR). Ghi lần bấm "Vào phòng
  họp" cho học viên ở xa là đợt sau; tới lúc đó admin điểm danh tay cho họ.

---

# Current Scope

```text
Rooms

Schedules

Schedule Slots

Schedule Exclusions

Sessions

Attendance

Recording references

Replay summaries

Chat logs

Whiteboard (approved 2026-11-07; giai đoạn 1–2 và Media đợt 1–3 đã có code)
```

---

# Future Scope

```text
Whiteboard: lưu, xuất, ghi lại bảng; Live Quiz và bảng thông minh AI

Breakout Rooms

Calendar Sync

Schedule → Session synchronization (tạo khi lưu đã duyệt 2026-11-05)

Shared Holiday Calendar

Transcript Pipeline

AI Teacher Analytics

AI Learning Insights
```

Future features phải giữ nguyên Version Activity, Enrollment, Media ownership
và Course Progress boundaries.

---

# Architecture Decision

LiveClass Foundation được phê duyệt tại:

[ADR-0002 — LiveClass Foundation](../adr/ADR-0002-LiveClass-Foundation.md)

ADR này là source quyết định cho LiveClass operational ownership, Course
integration, Media integration và cross-domain responsibility boundaries.

---

# Final Statement

LiveClass không kế thừa Course.

Course Version Activity giữ learning context bất biến. LiveClass sinh dữ liệu
vận hành. Media sở hữu file recording. Track sở hữu behavior events.
`core_course_activity_progress` là nơi tổng hợp trạng thái completion cấp
Activity.

---

End of LF-Core-LiveClass
