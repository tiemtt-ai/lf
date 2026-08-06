# ADR-0002

LiveClass Foundation

---

## Status

Approved

---

## Date

2026-06-27

## Schedule-To-Session Origin Amendment

Approved: 2026-08-05

This amendment authorizes explicit, immutable provenance when an authorized
actor creates one or more concrete Sessions from selected projected Schedule
occurrences. It supersedes only the clauses that deferred Schedule-to-Session
provenance, confirmed generation and idempotency. Automatic generation,
Schedule-driven synchronization, replacement Sessions and Schedule deletion
remain unapproved.

Canonical ownership is:

```text
Schedule + Slot + local date
→ projected occurrence
→ confirmed Session
→ immutable Session Schedule Origin
```

Lineage is stored only in
`core_liveclass_session_schedule_origins`. No lineage column is added to
`core_liveclass_sessions`. Every Origin belongs to exactly one same-tenant
Session, Schedule and Schedule Slot. A Session has zero or one Origin. Absence
of an Origin means either an explicitly manual Session or a legacy Session;
the application distinguishes those cases by the immutable feature rollout
cutover: no-Origin Sessions created before the cutover are legacy; no-Origin
Sessions created through the manual workflow at or after the cutover are
manual. The cutover instant is deployment configuration, not client input, and
must not move after rollout. The application must not infer or backfill
historical lineage from coincident timestamps.

Canonical occurrence identity is:

```text
customer_id + schedule_id + schedule_slot_id + source_local_date
```

This tuple is unique for all history. A cancelled or no-show Session retains
its Origin and continues to consume the occurrence identity. Replacement and
superseded occurrence reuse are not authorized. A make-up Session must use the
existing reschedule workflow or be created manually outside the Schedule.

Origin snapshots are immutable and include the source local date, local start
and end times, IANA timezone and absolute start/end instants. The local tuple
is interpreted in `source_timezone`. `source_start_at` and `source_end_at` are
the resulting UTC instants stored as timezone-free UTC `DATETIME` values. A
Session created from an occurrence receives the Schedule timezone and the
corresponding planned interval under the existing Session time convention.
Presentation and comparison must parse the Session interval in its recorded
timezone and compare normalized UTC instants; browser or server timezone is
never an authority.

Origin foreign keys to Session, Schedule and Schedule Slot use `RESTRICT`.
Schedule deletion remains unavailable. A Slot referenced by an Origin cannot
be hard-deleted; Schedule mutation must preserve stable Slot identity and may
not implement child replacement by deleting referenced Slots. Editing a
Schedule or Slot never updates an existing Origin snapshot or Session.

Creating Sessions from occurrences is an explicit preview, selection and
confirmation workflow. Preview creates no row. Confirmation runs in one
atomic transaction, locks the relevant same-tenant Cohort, Schedule, Slots and
occurrence identities, recalculates every occurrence with the canonical
Schedule preview rules and trusts no client-provided occurrence timestamp,
timezone or metadata. If any selected row is invalid, excluded, outside the
Schedule/Cohort window, already consumed or fails Session binding validation,
the whole batch fails and creates no Session or Origin. Database uniqueness is
the final double-submit guard.

Session curriculum/operational binding, teacher eligibility, delivery,
lifecycle and authorization remain unchanged. Confirmation creates only
Session, Session Teacher assignments when explicitly selected, and Origin. It
must not create Attendance, Recording, Replay, Progress or Completion.

Canonical relationship labels are:

* Origin exists and the normalized current Session interval equals its source
  snapshot: `on_schedule` / “Theo lịch”.
* Origin exists and the normalized interval differs: `rescheduled` / “Đã điều chỉnh”.
* A newly created manual Session without Origin: `off_schedule` / “Ngoài lịch”.
* A legacy Session without Origin: `source_unknown` / “Không có dữ liệu nguồn”.
* A projected occurrence without Session/Origin: `planned_occurrence` / “Ngày dự kiến”.

Rescheduling retains the Origin, updates only the Session planned interval and
appends `core_liveclass_session_schedule_changes`. It never mutates Schedule,
Slot or Origin.

## Cohort Schedule Foundation Amendment

Approved: 2026-08-05

This amendment separates recurring Cohort planning from concrete LiveClass
Sessions. LiveClass owns both concepts, but they have different sources of
truth and lifecycles:

```text
Cohort 1 → N LiveClass Schedules
Schedule 1 → N Schedule Slots
Schedule 1 → N Schedule Exclusions

Cohort 1 → N LiveClass Sessions
```

A Schedule belongs directly to one same-tenant Cohort. The Cohort supplies
tenant, Product, locked Template Version and lifecycle context. A Schedule is
LiveClass setup/planning data; it is not Course content, is not published into
an immutable Template Version and does not own Progress, Completion,
Attendance, Recording or Replay.

The Cohort owns its inclusive operating period through the existing canonical
`core_course_cohorts.start_date` and `core_course_cohorts.end_date` fields. Each
Schedule owns only its own inclusive application range. A Cohort may have many
Schedules, and every Schedule must satisfy:

```text
cohort.start_date <= schedule.starts_on
schedule.starts_on <= schedule.ends_on
schedule.ends_on <= cohort.end_date
```

The Cohort operating period is never inferred from a Schedule, and Schedule
create/update never expands or changes it. Updating the Cohort operating period
must fail when the proposed range no longer contains every existing Schedule;
the application must not truncate, update or delete those Schedules. Both
operating dates remain nullable at database level only for legacy compatibility.
New Cohorts must provide both dates, while a legacy Cohort without a complete
operating period remains readable but cannot create or preview a Schedule until
the period is completed. Schedule remains the timezone authority for preview;
this amendment does not move timezone ownership to Cohort.

Canonical Schedule persistence is normalized across:

```text
core_liveclass_schedules
core_liveclass_schedule_slots
core_liveclass_schedule_exclusions
```

Schedule data must not be stored in `core_liveclass_sessions`, Cohort metadata,
Course Template/Version, Room or a JSON recurrence payload. A Schedule has one
inclusive application range and IANA timezone. Its Slots store individual ISO
weekdays and same-day time intervals; a Schedule must retain at least one Slot,
exact duplicates and overlaps on the same weekday are rejected, and
`end_time > start_time`. Exclusions store unique dates within the Schedule
range and remove those dates from preview only.

The canonical preview is a backend-calculated read model:

```text
Schedule + Slots + Exclusions + timezone
→ projected occurrence timestamps
```

Preview includes both range endpoints, excludes configured dates, stores no
occurrence rows, creates no Session and displays that concrete Sessions have
not yet been created. Client-side calculation may assist presentation but is
never authoritative.

Schedule lifecycle follows its Cohort: `draft` and `active` Cohorts permit
authorized create/update; `completed` and `archived` Cohorts are read-only.
Schedule status is derived from the current date in its timezone and is not a
persisted column. Schedule mutation never activates a Cohort and never creates
or mutates runtime or learning evidence.

The canonical Cohort detail navigation is now:

```text
Overview | Students | Teachers | Schedules | Sessions | Attendance |
Recordings / Replay
```

Schedules and Sessions are independent tabs. The Sessions tab retains the
current Session Foundation and behavior without redesign in this amendment.
Cohort Attendance and Recordings/Replay remain aggregate views over Sessions
that belong to the Cohort.

Schedule deletion, automatic generation, future-Session synchronization,
shared holiday calendars and Schedule-driven bulk rescheduling remain
deferred. Explicit preview/selection/confirmation and immutable provenance are
authorized only by the Schedule-To-Session Origin Amendment above. Creating or
updating a Schedule still must not create, update, cancel or delete any Session.

## Curriculum And Operational Session Amendment

Approved: 2026-08-03

This amendment supersedes the requirement in the 2026-07-25 amendment that
every Session must reference a Version Lesson. Every Session still belongs to
one same-tenant Cohort and freezes the Cohort's immutable
`template_version_id`, but it now declares exactly one canonical
`session_type`:

```text
curriculum
operational
```

A `curriculum` Session realizes one published Live Class Activity and requires
the complete authority chain:

```text
Cohort
→ locked Template Version
→ Version Lesson
→ Version Activity (activity_type = live_class)
→ Session
```

Its `version_lesson_id` and `version_activity_id` are both required. The Lesson
must belong to the Cohort Version. The Activity must belong to that Lesson and
Version, must share `customer_id`, and must have `activity_type = live_class`.
One Version Lesson may contain zero, one or many Live Class Activities, and one
Live Class Activity may be realized by zero, one or many Sessions. Non-live
Activities never create or require Sessions automatically.

An `operational` Session represents orientation, class administration,
supplementary support, workshops, non-replacement make-up meetings, closing
events or another event outside published learning content. Both
`version_lesson_id` and `version_activity_id` must be `NULL`. It may retain the
Cohort's locked `template_version_id` as immutable context, but it cannot
produce Activity Progress, Lesson Completion or Course Completion evidence.
Attendance and other operational history may still be recorded when the
applicable Cohort and Session lifecycle permits it.

Session type and curriculum binding are canonical columns, not inferred from
title, nullable metadata or Working Template identifiers. Switching to
`operational` must clear both Version IDs on the client and be canonicalized or
rejected by the server. Create and edit must share the same validation policy.

Session title remains editable. For a curriculum Session, the UI may suggest a
title from the selected Version Lesson and Live Class Activity only while the
user has not manually edited the title. Operational Sessions require a
user-provided title.

Cancelled and no-show Sessions are retained. A Session with Attendance,
Recording, Replay, Progress evidence or other operational history must not be
hard deleted. Binding fields become immutable once evidence exists; scheduling
changes follow the append-only schedule-change policy.

This amendment does not change the Cohort Version binding, published Version
content, Enrollment, Product policy or Course ownership of Progress and
Completion.

## Cohort-Centered Session Amendment

Approved: 2026-07-25

This amendment supersedes every conflicting Room-owned Session clause below.

The operational root for a scheduled class meeting is now:

```text
Course Product
└── Cohort
    └── LiveClass Session
        ├── Published Version Lesson
        ├── Published Live Class Activity (conditional)
        ├── Session Teachers
        ├── Optional Delivery Resource
        ├── Attendance
        └── Recording / Replay
```

Every Session requires same-tenant `cohort_id`, `template_version_id` and
`version_lesson_id`. The Version must equal the immutable Version locked by the
Cohort, and the Lesson must belong to that Version.

`version_activity_id` is required when the Session operationalizes a published
Live Class Activity and may produce Course Activity evidence. It may be `NULL`
for operational events outside Course Activity completion. When present, it
must belong to the Session Lesson and Version, have `activity_type =
live_class`, and share `customer_id`.

Session belongs directly to Cohort. `room_id` is nullable. Room is a reusable
delivery resource only and is no longer the Session owner or learning-context
authority. Online, offline and hybrid Sessions snapshot the delivery details
needed for historical reproducibility.

Session teachers use an assignment table and support `primary_teacher`,
`teacher`, `assistant` and `substitute`. A nullable `primary_teacher_id` on
Session is a convenience value; assignments are the complete authority.

Rescheduling updates the planned times while appending a schedule-change audit
record. A replacement Session uses `superseded_by_session_id`; the old Session
is cancelled and historical evidence remains attached to the old Session.

Attendance and Replay may provide Course Activity Completion Evidence only
when `version_activity_id` is present. LiveClass never updates Course Progress
directly.

---

## Context

LearnForge cần một Domain độc lập để quản lý hoạt động học đồng bộ và hybrid:

* Live Room
* Recurring Cohort Schedule
* Session
* Attendance
* Replay
* Recording
* Chat

Course Domain vẫn phải là nơi định nghĩa Learning Structure, published
immutable content, Enrollment learning cycle và Learning Progress.

Nếu LiveClass kế thừa Course hoặc tự sở hữu completion, hệ thống sẽ tạo hai
nguồn sự thật cho cùng một learning activity. Nếu LiveClass sở hữu binary
recording, ranh giới với Media Domain cũng bị phá vỡ. Foundation vì vậy cần
quyết định rõ ownership và integration boundaries trước implementation.

---

## Decision

LiveClass được thiết kế là:

```text
Operational Domain
```

LiveClass không phải Learning Domain và không phải Course Domain.

LiveClass quản lý dữ liệu vận hành của hoạt động live. Course Domain quản lý
learning structure và tự quyết định progress/completion từ evidence phù hợp.

Mọi LiveClass business record phải tenant-scoped bằng `customer_id`.

---

## Final Architecture

```text
Course Template

↓

Template Activity

↓ publish

Template Version / Version Activity

↓ locked context

Cohort

├── LiveClass Schedule → read-only occurrence preview
└── LiveClass Session → optional Room / delivery resource

↓

Attendance

↓

Replay

↓ evidence

Course Activity Progress
```

Version Lesson và conditional Version Activity là published immutable learning
context. Session is the Cohort-owned operational instance; Room is optional.

---

## Operational Data Decision

LiveClass chỉ sở hữu:

* Rooms
* Cohort Schedules, Slots and Exclusions
* Sessions
* Attendance
* Replay
* Recording references
* Chat

LiveClass không sở hữu:

* Progress
* Completion
* Certificate

Attendance percentage, replay percentage, watch position và operational status
chỉ là evidence/read models. Chúng không phải source of truth của learning
completion.

---

## Media Integration

```text
Recording

↓

Media File
```

Media Domain sở hữu:

* Binary
* Storage
* Delivery
* Subtitle
* Transcript

LiveClass chỉ lưu Media references và provider/source metadata cần thiết cho
vận hành. AI summary hoặc transcript processing không được ghi trực tiếp vào
LiveClass Recording.

---

## Course Integration

```text
Version Activity

↓

LiveClass

↓

Attendance Evidence

↓

Replay Evidence

↓

Course Activity Progress
```

`version_activity_id` là liên kết chính với Course Domain.

Attendance và Replay của người học phải tham chiếu `enrollment_id` để giữ đúng
learning cycle. Course Domain đọc evidence, áp dụng frozen completion rule và
tự cập nhật/recalculate Course Progress.

LiveClass không được cập nhật trực tiếp Course Progress, Completion hoặc
Certificate eligibility.

---

## Future Integration

```text
Track Domain

↓

Raw Events

↓

Attendance Summary

Replay Summary
```

Track Domain có thể ghi raw join/leave và playback events. LiveClass giữ
operational summaries; Course Domain quyết định learning state.

```text
AI Domain

↓

Transcript

↓

Summary

↓

Recommendations
```

AI output là insight/recommendation, không ghi đè operational source data và
không tự chuyển Course business state.

---

## Foundation Decisions

* LiveClass là Operational Domain.
* Schedule là LiveClass-owned recurring planning data thuộc trực tiếp Cohort.
* Schedule và Session có source of truth riêng; preview không tạo Session.
* Room không kế thừa Course và không sở hữu Session.
* Không tạo Runtime Course tables cho LiveClass.
* Working Template Activity chỉ định nghĩa `activity_type = live_class`.
* Published Version Activity đóng băng learning context và completion rule.
* `cohort_id`, `template_version_id` và `version_lesson_id` là Session
  invariants.
* `version_activity_id` là conditional Course Activity evidence link.
* Room và downstream records luôn tenant-scoped bằng `customer_id`.
* Room visibility không thay thế authorization.
* Session thuộc Cohort, có Room/Delivery Resource tùy chọn và tách thời gian
  scheduled khỏi actual.
* Session status không quyết định learning completion.
* Attendance tham chiếu Enrollment learning cycle.
* Attendance source và attendance method là hai khái niệm riêng.
* Attendance percentage là read-model, không quyết định completion.
* Replay tham chiếu Enrollment và Recording.
* Replay percentage và last position phục vụ vận hành/resume UX, không quyết
  định completion.
* Attendance và Replay chỉ là evidence cho Course Progress recalculation.
* `core_course_activity_progress` là source of truth cho Activity completion.
* Course progress, completion và certificate eligibility thuộc Course Domain.
* Recording binary, storage và delivery thuộc Media Domain.
* Transcript và subtitle files thuộc Media Domain; LiveClass chỉ lưu
  references.
* Chat là Operational Data và không tính completion trực tiếp.
* Chat soft-hide không đồng nghĩa hard-delete; retention/legal hold vẫn được
  tôn trọng.
* Track Domain sở hữu raw behavior events khi cần.
* AI Domain sở hữu summaries, insights và recommendations.
* Snapshot learning content nằm ở Course Template Version/Version Activity,
  không nằm trong LiveClass.

---

## Future Considerations

Các hạng mục sau là hướng mở rộng, không phải lỗi của Foundation:

* Provider abstraction và provider sync contracts
* Breakout Rooms
* Webinar Mode
* Live Poll
* Whiteboard
* Live Quiz
* AI Live Assistant
* Calendar synchronization
* Advanced recording consent and retention policies
* Multi-product Room sharing policy

Mọi mở rộng tương lai phải giữ Domain Responsibility Principle và các ownership
boundaries trong ADR này.

---

## Consequences

### Positive

* Course, LiveClass, Media, Track và AI có source of truth rõ ràng.
* Không tạo completion source song song.
* Published learning context vẫn immutable và Enrollment-cycle safe.
* Provider và Media implementation có thể phát triển mà không tạo Runtime
  Course.

### Trade-offs

* Cần integration/service boundary để chuyển Evidence, Event hoặc Request giữa
  các Domain.
* Course Progress cần cơ chế recalculation idempotent.
* Provider sync, threshold, retention, privacy và Room sharing policy vẫn cần
  được chốt trước implementation.

---

## Applied Principles

See:

[LF-Architecture-Principles.md](../governance/LF-Architecture-Principles.md)

* Domain Responsibility Principle
* Source Of Truth Principle
* Immutable Principle
* Snapshot Principle
* Versioning Principle
* Evidence Principle
* Operational Data Principle
* Tenant Isolation Principle
* Read Model Principle
* Append Only Principle
* AI Consumer Principle
* Backward Compatibility Principle
* ADR Principle

---

## Result

```text
Foundation Ready

YES
```
