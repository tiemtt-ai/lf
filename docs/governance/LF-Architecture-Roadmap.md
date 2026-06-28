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
| Track | [ADR-0005](../adr/ADR-0005-Track-Foundation.md) | 1.0 | Completed — Foundation Approved |
| AI | [ADR-0006](../adr/ADR-0006-AI-Foundation.md) | 1.0 | Completed — Foundation Approved and Frozen |
| Tenant | [ADR-0007](../adr/ADR-0007-SaaS-Tenant-Foundation.md) | 1.0 | Completed — Foundation Approved and Frozen |
| Commercial | [ADR-0008](../adr/ADR-0008-SaaS-Commercial-Foundation.md) | 1.0 | Completed — Foundation Approved and Frozen |
| Usage | [ADR-0009](../adr/ADR-0009-SaaS-Usage-Foundation.md) | 1.0 | Completed — Foundation Approved and Frozen |
| Billing | [ADR-0010](../adr/ADR-0010-SaaS-Billing-Foundation.md) | 1.0 | Completed — Foundation Approved and Frozen |

Các Foundation đã hoàn thành là architecture baseline. Thay đổi làm ảnh hưởng
Domain boundary, ownership hoặc Source of Truth phải đi qua ADR mới.

---

## 3. Current Phase

### Certificate Foundation

Current focus:

| Domain | Status | Architecture Role | Primary Dependency |
| --- | --- | --- | --- |
| Certificate | Foundation Planned | Quyết định eligibility, issuance và verification | Course Completion, Assessment Evidence và Track Summary khi policy yêu cầu |

Track Foundation đã completed và cung cấp behavior summaries theo ADR-0005.
Certificate vẫn tự quyết định eligibility/issuance; Course, Assessment và Track
chỉ cung cấp state hoặc Evidence thuộc ownership của mình.

```text
Course Completion

+

Assessment Evidence

+

Track Summary when required

↓

Certificate
```

AI Foundation đã completed theo ADR-0006 và không thay đổi ownership của
Certificate hoặc source Domains.

Tenant Foundation đã completed theo ADR-0007 và cung cấp Customer identity,
TenantContext cùng isolation boundary cho mọi Domain mà không sở hữu business
state của các Domain đó.

SaaS Commercial Foundation đã completed theo ADR-0008 và cung cấp Plan,
Subscription cùng effective Entitlement mà không sở hữu Usage hoặc Billing
state.

SaaS Usage Foundation đã completed theo ADR-0009 và cung cấp append-only
measurement cùng rebuildable projections mà không sở hữu Commercial, Billing,
Track hoặc AI state.

SaaS Billing Foundation đã completed theo ADR-0010 và cung cấp immutable
Financial Evidence cho Invoice, Payment và Credit Note mà không sở hữu
Commercial hoặc Usage state.

---

## 4. SaaS Expansion

Status: Planned.

| Domain | Architecture Focus |
| --- | --- |
| Tenant | Foundation Approved and Frozen — Version 1.0; identity, domain, membership and isolation boundary |
| Commercial | Foundation Approved and Frozen — Version 1.0; Plan, Subscription and Entitlement; “Can Use?” |
| Billing | Foundation Approved and Frozen — Version 1.0; Invoice, Payment and Credit Note/Refund; “Pay” |
| Usage | Foundation Approved and Frozen — Version 1.0; Usage Event, Counter and Summary; “Used.” |
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

Status: Completed for Course, LiveClass, Assessment, Media, Track, AI, Tenant,
SaaS Commercial, SaaS Usage and SaaS Billing.

Outcome:

* Core, Learning Intelligence and Decision Support Domain boundaries approved.
* Learning, operational, evaluation, digital asset và behavior ownership
  separated.
* Foundation ADRs approved.

### Milestone 2 — Platform

Status: Current / Planned.

Outcome:

* Certificate foundation approved.
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
