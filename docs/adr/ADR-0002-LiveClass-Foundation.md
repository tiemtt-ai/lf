# ADR-0002

LiveClass Foundation

---

## Status

Approved

---

## Date

2026-06-27

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

Template Version

↓

Version Activity

↓

LiveClass Room

↓

LiveClass Session

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
