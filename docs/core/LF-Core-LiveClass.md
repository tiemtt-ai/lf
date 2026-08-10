# LF-Core-LiveClass.md

Version: 2.6

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-10

Document Path: core/LF-Core-LiveClass.md

---

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

Creation is an explicit four-stage operation:

1. Select one Schedule and a range within both Schedule and Cohort windows.
2. Recalculate projected occurrences with canonical Slots, exclusions and
   Schedule timezone; preview persists nothing.
3. Select occurrences and explicitly assign valid curriculum/operational
   Session data. Schedule never guesses Lesson or Activity.
4. Confirm one atomic batch. Backend recalculates and locks canonical data,
   rejects the whole batch on any invalid row and writes Sessions plus Origins.

Client occurrence timestamps, timezone and tenant metadata are never trusted.
Uniqueness on the occurrence identity provides the final double-submit guard.
This flow creates no Attendance, Recording, Replay, Progress or Completion.

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
Foundation approved. Automatic generation và synchronization vẫn thuộc phase
sau.

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
```

---

# Future Scope

```text
Whiteboard

Breakout Rooms

Calendar Sync

Automatic Schedule → Session generation and synchronization

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
