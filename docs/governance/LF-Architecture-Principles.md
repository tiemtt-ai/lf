# LearnForge Architecture Principles

Version: 1.0

Status: Official Governance — Approved

Last Updated: 2026-06

---

## Purpose

Đây là tập nguyên tắc kiến trúc nền tảng và là single source of truth cho
LearnForge.

Mọi Domain, ADR, Database Design, Development Standard và Code Review phải tuân
theo tài liệu này.

Domain docs và standards có thể mô tả cách áp dụng cụ thể, nhưng không được tạo
định nghĩa nguyên tắc cạnh tranh. Guardrails chuyển các principle thành
constraint bắt buộc. Nếu phát hiện xung đột:

```text
STOP

Report

Review

Approve

Then Change
```

---

# Principle 1

## Domain Responsibility Principle

Một Domain chỉ sở hữu:

* Dữ liệu của Domain đó.
* Business rules của Domain đó.

Không Domain nào được:

* Cập nhật business state của Domain khác.
* Quyết định completion của Domain khác.
* Ghi đè source of truth của Domain khác.

Nếu cần ảnh hưởng Domain khác:

```text
Source Domain

↓

Evidence / Event / Request

↓

Target Domain

↓

Target Domain tự quyết định
```

---

# Principle 2

## Source Of Truth Principle

Mỗi Business State chỉ có một Source Of Truth.

```text
Course Completion → Course Domain

Assessment Result → Assessment Domain

Media Metadata → Media Domain

Attendance → LiveClass Domain

AI Recommendation → AI Domain
```

Không duplicate ownership. Read models hoặc snapshots không được trở thành
business-state authority song song.

---

# Principle 3

## Immutable Principle

Published Business Object là read-only.

Không update hoặc delete nội dung đã publish nếu việc đó làm thay đổi historical
experience. Muốn thay đổi phải tạo object/version mới.

Áp dụng:

* Course Template Version
* Quiz/Quiz Question Snapshot
* Media Binary
* Historical Attempt/Grading/Certificate records

---

# Principle 4

## Snapshot Principle

Historical Data phải đọc Snapshot, không đọc mutable Authoring Source.

Examples:

```text
Question → Quiz Question Snapshot

Rubric → Grading Rubric Snapshot

Course Template → Template Version
```

Snapshot chỉ tạo tại lifecycle boundary được phê duyệt, ví dụ Publish, Attempt
start hoặc Evaluation start.

---

# Principle 5

## Versioning Principle

```text
Working Object

↓ publish

Immutable Version

↓

Runtime / Enrollment / Historical Consumer
```

Không sửa Runtime Version. Thay đổi authoring không silent-update historical
consumer.

---

# Principle 6

## Evidence Principle

Một Domain không update business state của Domain khác; nó chỉ sinh Evidence,
Event hoặc Request.

```text
Attendance → Evidence

Score → Evidence

Replay → Evidence

Course Domain → đọc Evidence → tự quyết định Completion
```

Evidence không tự trở thành state transition của consumer Domain.

---

# Principle 7

## Platform Domain Principle

Platform Domain cung cấp shared capability/infrastructure và không chứa business
logic của consumer Domain.

Ví dụ Media chỉ cung cấp:

* Storage
* Metadata
* Processing
* Digital Asset delivery

Media không quyết định Learning, Evaluation, Attendance, Certificate hoặc AI
Result.

---

# Principle 8

## Operational Data Principle

Operational Domain chỉ ghi nhận dữ liệu vận hành.

```text
LiveClass

↓

Room / Session / Attendance / Replay / Recording / Chat
```

Operational data có thể là Evidence nhưng không tự quyết định Course Progress
hoặc Completion.

---

# Principle 9

## Evaluation Domain Principle

Assessment là Evaluation Domain và sinh:

* Attempt
* Answer
* Score
* Pass/Fail
* Rubric Result
* Grading Result
* Feedback

Assessment không quyết định Course Completion, Certificate Eligibility,
Promotion hoặc Learning State.

---

# Principle 10

## Generic Reference Principle

Cross-domain association cần giảm coupling bằng Generic Mapping khi hard
relationship không thuộc responsibility của Platform Domain.

Example:

```text
Media Usage

↓

owner_type

owner_id
```

Generic reference không miễn tenant validation, owner existence validation hoặc
authorization. Không dùng Generic Mapping khi một hard relationship là
invariant nội bộ của cùng Domain.

---

# Principle 11

## Tenant Isolation Principle

Mọi business data phải thuộc `customer_id`.

```text
Request

↓

Tenant Context

↓

Tenant-scoped Query / Write
```

Không cross tenant. Relationship, snapshot, event, evidence, cache và read model
đều phải bảo toàn tenant ownership.

---

# Principle 12

## Read Model Principle

Read Model có thể denormalize hoặc duplicate dữ liệu phục vụ:

* Dashboard
* Reporting
* Search
* Analytics
* Performance
* Historical display

Source Of Truth không được duplicate.

Mỗi read-model field phải có:

```text
Purpose

Source Of Truth

Update / Recalculation Rule

Allowed Consumers
```

---

# Principle 13

## Append Only Principle

Event Data phải append-only khi có thể.

Không update/delete event history để biến đổi kết quả. Summary/read model được
rebuild từ event source.

Áp dụng mặc định cho Track Domain và audit/event streams. Retention/privacy
policy có thể purge theo governance được phê duyệt.

---

# Principle 14

## AI Consumer Principle

AI không phải Source Of Truth cho business state.

AI đọc Course, Track, Assessment và Media để sinh:

* Recommendation
* Suggestion
* Prediction
* Insight

AI không được:

* Complete Course.
* Quyết định final grade.
* Issue Certificate.
* Update business state trực tiếp.

Human hoặc owning Domain/policy được phê duyệt tự quyết định.

---

# Principle 15

## Backward Compatibility Principle

Published Architecture không được phá vỡ âm thầm.

Thay đổi phải:

* Compatible hoặc có migration/transition plan rõ.
* Bảo toàn existing Enrollment/historical records.
* Được review trước khi merge.
* Không rename/remove stable contract mà không có approval.

---

# Principle 16

## ADR Principle

Mọi thay đổi kiến trúc phải có ADR.

Không update Foundation âm thầm.

```text
Architecture Change

↓

New ADR or Approved ADR Amendment
```

ADR phải liệt kê Applied Principles và consequences/trade-offs.

---

# Principle 17

## Simplicity Principle

```text
Simple UX

↓

Rich Data

↓

Deep Tracking

↓

AI Ready
```

Ưu tiên solution đơn giản, monolith-first và không over-engineering; đồng thời
giữ đủ structured data cho audit, analytics, AI và enterprise reporting.

---

# References

* [ADR-0001 — Course Foundation](../adr/ADR-0001-Course-Foundation.md)
* [ADR-0002 — LiveClass Foundation](../adr/ADR-0002-LiveClass-Foundation.md)
* [ADR-0003 — Assessment Foundation](../adr/ADR-0003-Assessment-Foundation.md)
* [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)
* [ADR-0005 — Track Foundation](../adr/ADR-0005-Track-Foundation.md)
* [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)
* [ADR-0007 — SaaS Tenant Foundation](../adr/ADR-0007-SaaS-Tenant-Foundation.md)

---

# Final Status

```text
Official Governance

Approved

Version 1.0
```
