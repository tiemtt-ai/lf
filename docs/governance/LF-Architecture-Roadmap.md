# LearnForge Architecture Roadmap

Version: 1.0

Status: Official Governance

Last Updated: 2026-06

---

# Purpose

Tài liệu này định nghĩa roadmap kiến trúc dài hạn của LearnForge.

Đây là Architecture Roadmap, không phải Product Roadmap. Tài liệu xác định thứ
tự xây dựng foundation, dependency và architecture milestone; không cam kết
feature, ngày phát hành hoặc commercial priority.

Mọi trạng thái Planned trong tài liệu này chỉ thể hiện hướng đi. Planned không
đồng nghĩa với Approved và không cấp quyền implementation trước ADR,
documentation và foundation review.

---

## 1. Vision

LearnForge hướng tới:

```text
Enterprise AI Learning Platform
```

Kiến trúc phát triển theo từng tầng:

```text
Foundation

↓

Platform

↓

Enterprise

↓

AI Native

↓

Open Ecosystem
```

Không bỏ qua Foundation. Mỗi tầng phải có Domain boundary, Source of Truth,
tenant isolation và contract ổn định trước khi tầng phụ thuộc được mở rộng.

---

## 2. Completed Foundations

| Domain | ADR | Version | Status |
| --- | --- | --- | --- |
| Course | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) | 1.0 | Completed — Foundation Approved |
| LiveClass | [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) | 1.0 | Completed — Foundation Approved |
| Assessment | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) | 1.0 | Completed — Foundation Approved |
| Media | [ADR-0004](../adr/ADR-0004-Media-Foundation.md) | 1.0 | Completed — Foundation Approved |

Các Foundation đã hoàn thành là architecture baseline. Thay đổi làm ảnh hưởng
Domain boundary, ownership hoặc Source of Truth phải đi qua ADR mới.

---

## 3. Current Phase

### Platform Completion

Các Domain tiếp theo:

| Domain | Status | Architecture Role | Primary Dependency |
| --- | --- | --- | --- |
| Track | Planned | Thu nhận Track Event append-only và tạo behavioral summary | Events từ Course, LiveClass, Assessment và Media |
| Certificate | Foundation Planned | Quyết định eligibility, issuance và verification | Course Completion, Assessment Evidence và Track Summary khi policy yêu cầu |
| AI | Planned / Strategic Architecture | Tạo Recommendation, Prediction và Assistant output | Track signals, Course context, Assessment Evidence và Media transcript |

Dependency chính:

```text
Track

↓ signals and summaries

AI
```

```text
Course Completion

+

Assessment Evidence

+

Track Summary when required

↓

Certificate
```

Track phải có event ownership và tenant boundary ổn định trước khi AI phụ thuộc
quy mô lớn vào behavioral data. Certificate tự quyết định issuance; Course,
Assessment và Track chỉ cung cấp state hoặc Evidence thuộc ownership của mình.

---

## 4. SaaS Expansion

Status: Planned.

| Domain | Architecture Focus |
| --- | --- |
| Tenant | Tenant identity, context, configuration và isolation boundary |
| Billing | Invoice calculation, charge và billing outcome |
| Usage | Tenant resource-consumption measurement |
| Subscription | Plan entitlement, quota, renewal và subscription lifecycle |
| Marketplace | Offering discovery, distribution và ecosystem ownership boundary |

SaaS expansion phải phục vụ tất cả Domain qua contract tenant-aware, không đưa
learning business state vào SaaS Domain.

---

## 5. Enterprise Expansion

Status: Planned.

* Organization
* Department
* Competency
* Skill Graph
* HR Integration
* SSO
* SCIM
* Audit

Enterprise foundation phải xác định rõ ownership giữa Tenant, User, Course và
các integration boundary. SSO và SCIM không được làm suy yếu authentication,
authorization hoặc tenant isolation.

---

## 6. AI Expansion

Status: Planned.

* Recommendation
* Tutor
* Adaptive Learning
* Learning Coach
* AI Authoring
* AI Review
* AI Analytics

Mọi AI capability áp dụng AI Consumer Pattern: AI đọc dữ liệu được phép và tạo
output hỗ trợ; Owner Domain hoặc user vẫn tự quyết định business action.

---

## 7. Future Platform

Status: Future.

* Agentic AI
* Workflow
* Automation
* Plugin SDK
* External APIs
* Event Bus
* Webhooks

Các capability này chỉ được mở rộng sau khi public contract, permission,
tenant boundary, audit và backward compatibility được xác định.

---

## 8. Dependency Graph

Arrows biểu diễn dependency hoặc data consumption, không biểu diễn ownership
transfer và không cho phép Domain nguồn cập nhật business state của Domain
đích.

```text
Course

↓ learning context

LiveClass

↓ operational evidence

Assessment

↓ evaluation evidence

Track

↓ behavioral signals

AI

↓ decision support

Certificate
```

Certificate đồng thời phụ thuộc trực tiếp vào Course Completion và Assessment
Evidence; AI không phải dependency bắt buộc để issue Certificate.

```text
Media

↓

Shared Platform for Course, LiveClass, Assessment and AI
```

```text
SaaS

↓

Tenant Context, Entitlement and Usage for All Domains
```

---

## 9. Architecture Milestones

### Milestone 1 — Foundation

Status: Completed for Course, LiveClass, Assessment and Media.

Outcome:

* Core Domain boundaries approved.
* Learning, operational, evaluation và digital asset ownership separated.
* Foundation ADRs approved.

### Milestone 2 — Platform

Status: Current / Planned.

Outcome:

* Track foundation approved.
* Certificate foundation approved.
* AI foundation and consumer contracts approved.
* Cross-domain event and evidence contracts stabilized.

### Milestone 3 — Enterprise

Status: Planned.

Outcome:

* Enterprise organization and identity boundaries approved.
* Competency and Skill Graph ownership approved.
* SSO, SCIM, HR Integration and Audit contracts stabilized.

### Milestone 4 — AI Native

Status: Planned.

Outcome:

* Governed AI data consumption.
* Recommendation, Tutor, Coach, Authoring, Review and Analytics capabilities.
* Explainable, auditable AI output without business-state ownership leakage.

### Milestone 5 — Open Ecosystem

Status: Future.

Outcome:

* Stable external APIs and webhooks.
* Plugin SDK and Marketplace boundaries.
* Event-driven workflow and automation platform.

Milestones không có date commitment trong Architecture Roadmap. Chuyển
milestone dựa trên foundation readiness, không dựa trên feature pressure.

---

## 10. Governance Rule

Mọi Domain mới phải:

* Tuân thủ
  [Architecture Principles](LF-Architecture-Principles.md).
* Áp dụng Pattern phù hợp từ
  [Architecture Patterns](LF-Architecture-Patterns.md).
* Không vi phạm
  [Architecture Guardrails](LF-Architecture-Guardrails.md).
* Có ADR được review và approved.
* Có Domain Doc.
* Có Database Docs.
* Có entry trong
  [Domain Map](LF-Domain-Map.md) và
  [Documentation Index](../LF-INDEX.md).
* Vượt qua
  [Architecture Review Checklist](LF-Architecture-Review-Checklist.md).
* Được Foundation Freeze trước khi triển khai code lớn.

Flow:

```text
Domain Proposal

↓

Boundary and Source Of Truth

↓

Domain and Database Documentation

↓

ADR Approval

↓

Architecture Review

↓

Foundation Freeze

↓

Implementation
```

Nếu đề xuất vi phạm Guardrails:

```text
STOP

Report

Review

Approve

Then Implement
```
