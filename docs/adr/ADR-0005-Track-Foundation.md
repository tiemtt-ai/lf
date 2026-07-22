# ADR-0005

Track Foundation

---

## Status

Frozen

---

## Version

1.0

---

## Date

2026-06-27

---

## Related ADRs

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md)
* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md)
* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)

---

## Context

LearnForge cần một Domain trung tâm để thu thập và chuẩn hóa Learning Behavior.

Các Domain hiện có:

* Course.
* LiveClass.
* Assessment.
* Media.

đều tạo ra dữ liệu học tập nhưng không nên tự xây dựng Analytics projection
hoặc AI Feature Data riêng.

Do đó LearnForge cần Track: một Learning Intelligence Domain độc lập, nhận
event từ các Domain nguồn và cung cấp behavior data có cấu trúc cho Analytics,
Dashboard và AI.

---

## Problem Statement

Nếu AI và Analytics đọc trực tiếp:

* Course.
* Assessment.
* LiveClass.
* Media.

thì:

* Coupling cao.
* Logic tổng hợp bị duplicate.
* Khó thay đổi source schema.
* Khó rebuild và version projection.
* Khó quản trị event reliability, privacy và historical behavior.

LearnForge cần một Learning Intelligence Domain có event contract, tenant
boundary và projection lifecycle riêng mà không chiếm business ownership của
Domain nguồn.

---

## Decision

Track được xác định là:

```text
Learning Intelligence Domain
```

Track chỉ sở hữu:

* Learning Events.
* Learning Sessions.
* Behavior Summaries.
* Daily Summaries.
* AI Feature Data.
* Historical Feature Snapshots.
* Learning Journey Observations.

Track không sở hữu:

* Course Progress hoặc Completion.
* LiveClass Attendance.
* Assessment Result.
* Media Processing State.
* Certificate Eligibility hoặc issued Certificate.
* AI Recommendation, Prediction hoặc Insight.

---

## Applied Principles

Reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

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

## Applied Patterns

Reference:
[LF-Architecture-Patterns](../governance/LF-Architecture-Patterns.md).

* Append Only Pattern.
* Read Model Pattern.
* Generic Mapping Pattern.
* AI Consumer Pattern.
* Shared Infrastructure Pattern.

Generic source references giảm coupling giữa Track và emitting Domain.
Shared capability chỉ cung cấp behavior data; consumer vẫn giữ business
ownership.

---

## Domain Responsibility

Track chịu trách nhiệm:

* Event collection và reliable ingestion identity.
* Learning Session grouping.
* Behavior Analytics Data.
* AI Feature Store.
* Historical Feature Snapshot.
* Observed Learning Journey projection.

Track không quyết định:

* Course Completion.
* Assessment Result.
* Live Attendance.
* Media Processing State.
* Certificate Eligibility.
* AI Recommendation.

Track không cập nhật trực tiếp business state của Domain khác.

---

## Event Store Principle

```text
track_events

=

Append Only Event Store
```

Business Rules:

* Không update event history.
* Không rewrite history.
* Correction dùng Correction Event mới với reference và reason.
* Duplicate được xử lý bằng tenant-scoped idempotency.
* `event_uuid` là event identity bất biến.
* Correlation và causation được lưu riêng.
* `occurred_at` và `received_at` giữ event-time/ingestion-time semantics riêng.
* Retention/privacy purge chỉ thực hiện theo approved policy.

---

## Projection Principle

Summary và feature tables:

* `track_activity_summaries`.
* `track_daily_summaries`.
* `track_ai_features`.
* `track_learning_paths`.

là Read Models.

Chúng có thể:

* Rebuild.
* Recalculate.
* Version projection.
* Compare.
* Roll back sang projection version trước.

Projection không phải Source Of Truth và không được sửa event history. Daily
summary snapshot timezone thay vì assume UTC.

---

## AI Principle

AI không đọc trực tiếp raw events khi không cần.

AI ưu tiên:

* Behavior summaries.
* AI Feature Store.
* Feature snapshots.

```text
Track provides behavior data

↓

AI consumes data

↓

AI owns Recommendation / Prediction / Insight
```

AI không sở hữu Track và Track không sở hữu AI output.

---

## Privacy Principle

Track có thể lưu:

* IP Address.
* Device Metadata.
* Browser.
* User Agent.

Retention/privacy policy có quyền:

* Anonymize.
* Hash.
* Purge.

Track không yêu cầu giữ raw technical metadata vĩnh viễn. Privacy processing
phải giữ tenant isolation và không được âm thầm đổi business meaning của event.

---

## Relationships

Track nhận Event từ:

* Course.
* LiveClass.
* Assessment.
* Media.

Track cung cấp dữ liệu cho:

* Analytics.
* Dashboard.
* AI.
* Recommendation.
* Reporting.

Arrows biểu diễn event/data consumption, không chuyển ownership. Không Domain
nào được cập nhật Track business rules hoặc viết lại Track event history.

---

## Database Foundation

Official Tables:

* `track_event_types`.
* `track_events`.
* `track_learning_sessions`.
* `track_activity_summaries`.
* `track_daily_summaries`.
* `track_ai_features`.
* `track_learning_paths`.
* `track_feature_snapshots`.

Canonical table documentation:
[docs/database/track](../database/track/).

---

## Foundation Freeze

Track Foundation Version 1.0 được freeze bởi ADR này.

Không thay đổi Domain Boundary, table foundation hoặc schema contract đã
approved nếu không có:

* ADR Amendment được approved; hoặc
* ADR mới.

Implementation detail không được tạo Source Of Truth hoặc business ownership
cạnh tranh.

---

## Consequences

### Benefits

* AI-ready.
* Analytics-ready.
* Append-only architecture.
* Decoupled source Domains.
* Reliable retry/offline ingestion identity.
* Versioned, rebuildable projections.
* High scalability path.
* Historical behavior preserved.

### Trade-offs

* Event volume lớn.
* Projection cần rebuild/recalculation policy.
* Event taxonomy cần governance.
* Storage tăng theo thời gian.
* Partitioning, retention và offline ordering cần operational policy.

---

## Future Considerations

Các chủ đề tương lai không thay đổi Foundation:

* Event taxonomy governance.
* Learning Session boundary refinement.
* Summary rebuild orchestration.
* AI Feature lifecycle.
* Offline synchronization.
* Event streaming.
* Kafka/Event Bus.
* High-volume partitioning.
* Long-term retention.
* Data warehouse export.

Các thay đổi làm ảnh hưởng Domain Boundary hoặc Foundation schema phải đi qua
ADR Amendment hoặc ADR mới.

---

## Result

```text
Track Foundation

Frozen

Version

1.0

Architecture Status

Foundation Frozen

Ready for Migration

YES

Ready for Implementation

YES

Governance Version

1.0
```
