# LF-Core-LiveClass.md

Version: 2.15

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-08

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
* Schedule deletion chưa được approved; implementation không được hard-delete
  hoặc tự thêm soft delete/status để giả lập lifecycle.

Automatic generation, synchronization, shared holiday calendars and
Schedule-driven bulk rescheduling remain deferred. Explicit confirmed creation
from selected occurrences is governed by the immutable Origin policy below.
Schedule mutation has no side effect on any Session or Origin.

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
  evidence such as Attendance or Recording until a teacher is assigned;
* Owner decision 2026-11-04: in a `draft` Cohort, a `scheduled` Session whose
  start has passed, with no teacher and no evidence, may still receive teachers
  through a dedicated action. Other fields and the times stay unchanged, and
  each teacher must still be available for the Session's dates;
* title, type, curriculum binding and delivery fields may be edited only while
  the Session has not started, remains in a pre-runtime state and has no
  Attendance, Recording, Replay, Progress evidence or other operational child;
* schedule changes use the dedicated reschedule workflow and append audit
  history rather than silently replacing history;
* active Session time ranges in the same Cohort must not overlap, regardless
  of whether a Session originates from a Schedule, is created off-schedule, or
  is rescheduled; boundary-touching ranges are allowed, while cancelled and
  no-show Sessions no longer reserve the time range;
* a live, completed, cancelled or no-show Session keeps its type and
  binding immutable;
* completed or archived Cohorts expose historical Sessions read-only and do
  not accept new Sessions;
* cancelled and no-show Sessions remain visible and are never hard deleted.

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
là chuẩn nghiệm thu. Không có QR, không có tài khoản tạm.

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
| `curriculum` | Media `ready` của các Version Activity trong Version Lesson của Session có `available_during_session = 1`, theo thứ tự của lớp (`CourseCohortContentOrder`); chỉ đọc |
| `operational` | Trống |

* Cờ `available_during_session` là điều kiện hiển thị trong giờ học. Luật mở khoá
  bài của học viên (`VersionActivityAccessService`) không áp dụng cho phần giáo viên
  chiếu; việc xem trên bảng không phải là truy cập Activity và không tính Progress.
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
| Học viên | Trang khoá học, khi buổi đang `live`: "Xem bảng" | `/my-courses/...`, `role:student` |

Trang khoá học của học viên chưa có (`/my-courses` mới là trang giữ chỗ); lối vào
của học viên làm cùng trang đó. Bảng mở trong cửa sổ riêng, có nút toàn màn hình.

## Giao diện

Bố cục và chức năng theo AllVia; màu, icon và tooltip theo UI Kit của LF (Owner chốt
2026-11-07). Mọi nút chỉ có icon có tooltip i18n và `aria-label` (`data-tooltip`);
trên màn hình cảm ứng, chạm giữ để xem tooltip. Mọi chữ trên bảng (thông báo, trạng
thái kết nối, câu hỏi mở lại trang cũ, lỗi) dùng key `LF_liveclass_whiteboard_*` đủ
`vi` và `en`. Dùng được bằng bàn phím; hiệu ứng tôn trọng `prefers-reduced-motion`.

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
