# LF-Core-LiveClass.md

Version: 2.1

Status: Official Foundation

Last Updated: 2026-06

---

# LF-Core LiveClass Architecture

LiveClass Domain quản lý hoạt động học đồng bộ và hybrid:

* LiveClass Rooms
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

LiveClass Room / Session

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

The 2026-07-25 approved policy supersedes the earlier Room-owned Session
description in this document.

`core_liveclass_sessions` belongs directly to one `core_course_cohorts` row.
Every Session freezes the Cohort's `template_version_id` and one
`version_lesson_id`. `version_activity_id` is conditionally required only when
the Session realizes a Version Activity of type `live_class`; operational
events may leave it `NULL` and cannot produce Activity Completion Evidence.

Room is an optional reusable delivery resource. Session snapshots its online,
offline or hybrid delivery values. Session Teachers, Attendance, Recording and
Replay are children of Session. Course remains the only owner of Progress and
Completion.

### Cohort Draft Setup Boundary — 2026-08-02

A `draft` Cohort may prepare Sessions, schedules and teacher assignments as
setup data. Creating or editing this setup does not activate the Cohort and
must not produce Attendance, Replay evidence or another runtime operation.
Attendance and other operational evidence may be created only when the Cohort
is `active` and the existing Session, Enrollment, authorization and tenant
requirements are satisfied. Historical data remains readable according to the
applicable authorization policy after completion or archival.

## LiveClass Operational Data Principle

LiveClass là Operational Domain.

LiveClass chỉ sinh:

```text
Room

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

# LiveClass Sessions

`core_liveclass_sessions` đại diện cho một lần học cụ thể của Cohort.

```text
Cohort 1 → N Sessions
Room 1 → 0..N Sessions
```

Session tách:

```text
scheduled_start_at / scheduled_end_at

actual_start_at / actual_end_at
```

Allowed status:

```text
scheduled

live

ended

cancelled

no_show
```

Session bị cancel hoặc no-show vẫn được giữ để bảo toàn lịch sử. `ended` không
đồng nghĩa Activity đã completed.

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

Room/Session model cho phép lịch đơn, lịch lặp hoặc hybrid delivery trong các
phase sau mà không thay đổi Course Foundation binding.

---

# Current Scope

```text
Rooms

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
