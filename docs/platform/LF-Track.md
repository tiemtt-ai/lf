# LF-Track.md

Version: 1.0

Status: Foundation Approved

Last Updated: 2026-06

Document Path: platform/LF-Track.md

---

# LF Track Architecture

Track là Learning Intelligence Domain của LearnForge.

Track ghi nhận hành vi học tập dưới dạng event append-only và tạo các read
model phục vụ Analytics, Dashboard và AI. Track không phải technical logging,
không phải Course, Assessment, LiveClass, Media hoặc AI Domain.

---

# Mission

Track trả lời:

```text
Learner đã tương tác như thế nào?

Behavior thay đổi theo thời gian ra sao?

Signal nào có thể hỗ trợ Analytics và AI?
```

Track không trả lời thay Owner Domain:

```text
Course đã completed chưa?

Assessment result là gì?

Attendance có hợp lệ không?

Media processing đã hoàn tất chưa?
```

---

# Domain Responsibility

Track sở hữu:

* Append-only learning behavior events.
* Learning sessions có ý nghĩa phân tích.
* Activity và daily behavior summaries.
* AI-ready feature records.
* Historical feature snapshots.
* Observed learning journey/path records.

Track không sở hữu hoặc quyết định:

* Course Progress hoặc Completion.
* Assessment Result hoặc final grade.
* LiveClass Attendance.
* Media Processing State.
* Certificate Eligibility hoặc issuance.
* AI Recommendation.
* SaaS Usage hoặc Billing state.

---

# Source Of Truth

`track_events` là Source of Truth của observation history do Track tiếp nhận.

Track Event là quan sát về behavior, không phải Source of Truth của business
state trong Domain phát event. `event_code` được snapshot để bảo toàn lịch sử,
nhưng không thay thế source record.

```text
Source Domain Record

↓ emits event

Track Event

↓ projects

Summary / AI-ready Feature / Observed Path
```

Summary, feature và observed path là derived read models. Chúng có thể
recalculate hoặc rebuild từ event history.

---

# Event Sources

Track nhận event từ:

* Course.
* LiveClass.
* Assessment.
* Media.
* Certificate, AI hoặc SaaS khi event taxonomy được approved.

Source Domain tiếp tục sở hữu business record:

| Event Example | Source Of Truth |
| --- | --- |
| `lesson_completed` | Course Progress |
| `live_joined` | LiveClass Attendance/Session |
| `assessment_submitted` | Assessment Attempt |
| `media_stream_started` | Media access/stream context |
| `certificate_downloaded` | Certificate record |

Track không suy diễn hoặc ghi ngược business state vào source Domain.

---

# Append-Only Principle

Track Event là append-only:

```text
Observed Event

↓ append

Correction Event

↓ append

Rebuild Read Models
```

Không update hoặc delete event cũ để thay đổi lịch sử. Correction phải là event
mới. Purge chỉ được thực hiện theo retention/privacy policy đã approved.

`occurred_at` là event time; `received_at` là ingestion time. Hai thời điểm
không được dùng thay thế nhau.

---

# Event Reliability

Mỗi event có `event_uuid` bất biến và `idempotency_key` tenant-scoped.

```text
Retry / Offline Sync

↓ same customer_id + idempotency_key

Ingest Once
```

Duplicate được bỏ qua hoặc merge trước persistence theo ingestion policy.
Event đã persist không bị rewrite.

`correlation_id` nhóm nhiều events trong cùng business flow.
`causation_id` chỉ ra event trực tiếp sinh event hiện tại.

Correction flow:

```text
Incorrect Event

↓ append

Correction Event

↓ corrected_event_id + correction_reason

Rebuild Affected Projections
```

Correction không update hoặc delete event cũ.

---

# Learning Session

Track Learning Session là một phiên học tập có ý nghĩa cho Analytics/AI, không
phải authentication/login session và không thay thế Enrollment.

```text
User + Optional Enrollment Context

↓

Learning Session

↓ groups

Track Events
```

Session có thể active, ended hoặc expired. Duration là read-model value từ
events và không quyết định Course Progress.

---

# Read Models

Track tạo:

* Activity Summary theo user/enrollment/version activity.
* Daily Summary theo user/enrollment/product.
* Current AI-ready Feature.
* Historical Feature Snapshot.
* Observed Learning Path.

Mỗi read model phải có source, recalculation rule và allowed consumer rõ ràng.
Không read model nào được trở thành authority cho Progress, Attendance,
Assessment Result, Certificate hoặc Billing.

---

# Projection Versioning

Track tách event history khỏi derived projection:

```text
Event Store

↓ project with projection_version

Projection

↓ derive

AI-ready Feature
```

Event Store không rebuild hoặc rewrite. Activity Summary, Daily Summary,
AI-ready Feature và Observed Learning Path có `projection_version`.

Khi công thức thay đổi, Track tạo/rebuild versioned projection rows. Version cũ
được giữ để compare và rollback; event history không thay đổi.

---

# AI Integration

AI nên đọc Track summaries và AI-ready signals thay vì tự gom raw data trực
tiếp từ mọi Domain khi Track đã cung cấp projection phù hợp.

```text
Course / LiveClass / Assessment / Media Events

↓

Track

↓ summaries and AI-ready signals

AI

↓

Recommendation / Prediction / Assistant Output
```

Track sở hữu feature records; AI sở hữu Recommendation/Prediction/Insight của
AI. Track không quyết định hoặc thực thi AI recommendation.

---

# Analytics And Dashboard

Track cung cấp behavior data cho:

* Engagement analytics.
* Learning-time analysis.
* Replay and interaction trends.
* Journey observations.
* AI feature generation.

Analytics phải tenant-scoped. Aggregate hoặc dashboard không được expose dữ
liệu cross-tenant và không được dùng như canonical business state.

---

# Tenant Isolation

1. Mọi Track business record phải tenant-scoped bằng `customer_id`.
2. Cross-domain context phải thuộc cùng tenant.

---

# Privacy

Track lưu behavior cần thiết cho mục đích Analytics và AI đã xác định. Track
không bắt buộc lưu mãi mọi technical metadata.

```text
IP Address

User Agent

Device Metadata

↓ retention/privacy policy

Anonymize / Hash / Purge
```

Retention policy quyết định thời hạn và transformation. Privacy operation
không được rewrite business meaning của event; khi policy yêu cầu purge
technical metadata, event identity và non-sensitive observation history được
xử lý theo contract đã approved.

Consent, tenant isolation và data-subject policy vẫn áp dụng.

---

# Database Namespace

```text
track_*
```

Foundation tables:

```text
track_event_types
track_events
track_learning_sessions
track_activity_summaries
track_daily_summaries
track_ai_features
track_learning_paths
track_feature_snapshots
```

Table documentation:
[docs/database/track](../database/track/).

---

# Principles Applied

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Evidence Principle.
* Append Only Principle.
* Read Model Principle.
* Tenant Isolation Principle.
* AI Consumer Principle.
* Backward Compatibility Principle.
* Simplicity Principle.

---

# Future Considerations

Các policy sau có thể phát triển mà không thay đổi Foundation:

* Event taxonomy governance.
* Learning session boundary.
* Summary rebuild strategy.
* AI feature lifecycle.
* Offline/mobile sync.
* High-volume partitioning.

Thay đổi Domain Boundary, table foundation hoặc schema contract phải có ADR
Amendment hoặc ADR mới.

---

# Architecture Decision

Track Foundation được approved và freeze tại:

[ADR-0005 — Track Foundation](../adr/ADR-0005-Track-Foundation.md)

ADR-0005 là canonical decision cho Learning Intelligence ownership,
append-only Event Store, event reliability, projection versioning, privacy và
cross-domain integration.

---

# Final Statement

Track là Learning Intelligence Domain, không phải technical logging hoặc
learning-state authority.

Track giữ event history và các derived behavior projections. Course giữ
Progress/Completion; Assessment giữ Result; LiveClass giữ Attendance; Media
giữ Processing State; Certificate giữ Eligibility; AI giữ Recommendation.

```text
Foundation Approved

Version 1.0
```
