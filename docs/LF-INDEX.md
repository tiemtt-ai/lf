# LF-INDEX.md

Version: 2.23

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-25

Document Path: LF-INDEX.md

---

# LearnForge Documentation Index

This document is the official catalog and routing guide for LearnForge
documentation.

Start at [docs/README.md](README.md) to learn how the documentation areas are
used. Then use this index to locate the documents relevant to the current task.

If the current task starts from a specific product feature (Cohort, Schedule,
Bulk Enrollment, Product, Certificate, ...) rather than a task type, go
directly to [Feature-Based Documentation Routing](#feature-based-documentation-routing)
near the end of this index.

All AI agents (Codex, ChatGPT, Claude, Gemini, Cursor, Windsurf, etc.) should
consult this catalog before making architecture, database, backend, frontend,
infrastructure, or business decisions.

[`LF-DOCUMENTATION-MANIFEST.json`](LF-DOCUMENTATION-MANIFEST.json) supports
machine-readable inventory and Vietnamese/English candidate discovery. It does
not replace this routing guide; agents must still read selected sources and
check the conflict register when inconsistency appears. Its canonical schema
and maintenance rules are defined in
[Documentation Manifest Standard](governance/LF-Documentation-Manifest.md).

Database drift phải route qua
[LearnForge Schema Drift Standard](database/LF-Schema-Drift.md) và
[`LF-SCHEMA-CONTRACT.json`](database/LF-SCHEMA-CONTRACT.json). Documentation,
migration intent, fresh reconstruction và selected read-only database luôn là
các nguồn evidence riêng biệt.

---

# Mandatory AI Agent Rules

Policy/Document Status cho biết mức độ phê duyệt và hiệu lực của tài liệu.
Implementation Status cho biết mức độ triển khai đã được xác minh trong source
code/database. Hai trạng thái độc lập và không được suy ra lẫn nhau. Schema và
quy tắc canonical nằm tại
[LF Naming Convention](governance/LF-Naming-Convention.md#canonical-documentation-metadata).

All AI agents must follow these rules before modifying LearnForge.

## Rule 1 — Follow Documentation Routing

Always start with:

docs/LF-INDEX.md

Do not read every document in the repository.

Follow the Documentation Routing Guide and load only the documents required for the current task.

---

## Rule 2 — Never Guess

If required documentation is:

- missing
- conflicting
- ambiguous
- incomplete

STOP.

Report the conflict.

Do not invent:

- architecture
- database schema
- business rules
- API behavior
- UI behavior

---

## Rule 3 — Reuse Existing Architecture

Before implementing code, inspect the existing:

- migrations
- routes
- controllers
- services
- requests
- middleware
- views
- tests

Reuse the existing implementation whenever possible.

Do not create duplicate architecture.

---

## Rule 4 — Respect Stable Foundations

Do not modify approved architecture unless explicitly requested.

Do not silently replace:

- authentication
- tenant model
- routing
- role model
- published snapshot architecture
- runtime authority

without documentation approval.

---

## Rule 5 — Route Existing-Feature Changes

Khi sửa đổi hoặc nâng cấp nghiệp vụ hiện có, đọc:

1. [LF Development Standards](LF-Development-Standards.md) để kích hoạt
   `Existing-Feature Change Safety Protocol`.
2. [LF Regression Audit](quality/LF-Regression-Audit.md) để chọn Audit Level
   `LOW`, `MEDIUM` hoặc `HIGH` và thực hiện checklist theo mức cao nhất áp dụng.
3. [Architecture Review Checklist](governance/LF-Architecture-Review-Checklist.md)
   khi impact analysis chạm source of truth, domain/lifecycle, tenant/auth,
   public contract, historical data, schema/migration hoặc backward
   compatibility.

Quy tắc định tuyến này áp dụng ngay cả khi người dùng không yêu cầu review,
audit hoặc regression test.

---

# Documentation Structure

## Directory Guides

| Area | Guide |
| --- | --- |
| Documentation entry point | [README.md](README.md) |
| Governance | [governance/README.md](governance/README.md) |
| Architecture Decision Records | [adr/README.md](adr/README.md) |
| Core Domains | [core/README.md](core/README.md) |
| Database Documentation | [database/README.md](database/README.md) |
| Prompt and Implementation Rules | [prompts/README.md](prompts/README.md) |
| Platform Domains | [platform/README.md](platform/README.md) |
| SaaS Domains | [saas/README.md](saas/README.md) |
| Quality and Regression | [quality/README.md](quality/README.md) |
| Technology | [tech/README.md](tech/README.md) |
| Business | [business/README.md](business/README.md) |

---

## Foundation Documents

Foundation context documents catalog:

| Document | Purpose |
| --- | --- |
| [LF-OS.md](LF-OS.md) | Product philosophy and design principles |
| [LF-Core-Overview.md](LF-Core-Overview.md) | LF-Core architecture overview |
| [LF-SaaS-Overview.md](LF-SaaS-Overview.md) | LF-SaaS architecture overview |

---

## Engineering Standards

Location:

```text
docs/
```

| Document | Purpose |
| --- | --- |
| [LF-Data-Modeling.md](LF-Data-Modeling.md) | Database design methodology |
| [LF-Development-Standards.md](LF-Development-Standards.md) | Implementation and development standards |
| [database/LF-Schema-Drift.md](database/LF-Schema-Drift.md) | Canonical non-destructive schema-drift gate và contract maintenance |

---

# AI Implementation Rules

Before implementing any feature:

1. Read docs/LF-INDEX.md.

2. Follow the Documentation Routing Guide.

3. Read only the required documents.

4. Inspect the existing implementation.

5. Verify there are no documentation conflicts.

6. Begin implementation.

If documentation conflicts exist:

STOP.

Report the conflict.

Verify and register confirmed documentation conflicts in
[LF Documentation Conflict Register](quality/LF-Documentation-Conflicts.md).

Do not guess.

---

## Core Modules

Location:

```text
docs/core/
```

| Document | Purpose |
| --- | --- |
| [core/LF-Core-Auth.md](core/LF-Core-Auth.md) | Authentication architecture |
| [core/LF-Core-User.md](core/LF-Core-User.md) | User management |
| [core/LF-Core-Course.md](core/LF-Core-Course.md) | Course management |
| [core/LF-Core-Assessment.md](core/LF-Core-Assessment.md) | Assessment engine |
| [core/LF-Core-LiveClass.md](core/LF-Core-LiveClass.md) | Live class engine |
| [core/LF-Core-Certificate.md](core/LF-Core-Certificate.md) | Foundation Approved and Frozen — Version 1.0; Certificate evidence and verification |
| [core/LF-Core-Learning.md](core/LF-Core-Learning.md) | Frozen Version 1.1; Learning semantics, Evidence and Mastery; database design Phase 3 complete |

---

## Platform Modules

Location:

```text
docs/platform/
```

| Document | Purpose |
| --- | --- |
| [platform/LF-Media.md](platform/LF-Media.md) | Media processing |
| [platform/LF-Media-Processing-Contract.md](platform/LF-Media-Processing-Contract.md) | Hợp đồng substrate xử lý Media: trigger, orchestration, fingerprint, locator, đo lường |
| [platform/LF-Media-Read-Contract.md](platform/LF-Media-Read-Contract.md) | Hợp đồng đọc output dẫn xuất cho AI consumer: owner context, locale, readiness, citation, mã lỗi |
| [platform/LF-Track.md](platform/LF-Track.md) | Learning analytics |
| [platform/LF-AI.md](platform/LF-AI.md) | AI intelligence |

---

## SaaS Modules

Location:

```text
docs/saas/
```

| Document | Purpose |
| --- | --- |
| [saas/LF-SaaS-Tenant.md](saas/LF-SaaS-Tenant.md) | Foundation Approved and Frozen — Version 1.0; Multi-tenant architecture |
| [saas/LF-SaaS-Commercial.md](saas/LF-SaaS-Commercial.md) | Foundation Approved and Frozen — Version 1.0; Plan, Subscription and Entitlement architecture |
| [saas/LF-SaaS-Usage.md](saas/LF-SaaS-Usage.md) | Foundation Approved and Frozen — Version 1.0; Usage measurement, counters and summaries |
| [saas/LF-SaaS-Billing.md](saas/LF-SaaS-Billing.md) | Foundation Approved and Frozen — Version 1.0; Invoice, Payment and Credit Note |

---

## Technology Documents

Location:

```text
docs/tech/
```

| Document | Purpose |
| --- | --- |
| [tech/LF-Tech-Stack.md](tech/LF-Tech-Stack.md) | Technology stack |
| [tech/LF-Tech-Architecture.md](tech/LF-Tech-Architecture.md) | System architecture |
| [tech/LF-Tech-CSS.md](tech/LF-Tech-CSS.md) | CSS architecture |
| [tech/LF-Admin-Form-Design-Standard.md](tech/LF-Admin-Form-Design-Standard.md) | Canonical presentation standard cho LF Admin Create/Edit forms và List/Index pages; kích hoạt bởi “Áp dụng thiết kế tiêu chuẩn”, “Áp dụng chuẩn danh sách” và các trigger tương đương |
| [tech/LF-Tech-AWS.md](tech/LF-Tech-AWS.md) | AWS infrastructure |
| [tech/LF-Tech-Runtime-Requirements.md](tech/LF-Tech-Runtime-Requirements.md) | Yêu cầu cài đặt runtime, extension, binary, biến môi trường, CI và gate triển khai production |

---

## Business Documents

Location:

```text
docs/business/
```

| Document | Purpose |
| --- | --- |
| [business/LF-Business-Model.md](business/LF-Business-Model.md) | Business model |
| [business/LF-Navigation.md](business/LF-Navigation.md) | Navigation and UX |

---

## Governance Documents

Location:

```text
docs/governance/
```

| Document | Purpose |
| --- | --- |
| [governance/LF-Architecture-Principles.md](governance/LF-Architecture-Principles.md) | Canonical architecture principles |
| [governance/LF-Architecture-Patterns.md](governance/LF-Architecture-Patterns.md) | Approved architecture patterns |
| [governance/LF-Architecture-Guardrails.md](governance/LF-Architecture-Guardrails.md) | Mandatory architecture constraints |
| [governance/LF-Domain-Map.md](governance/LF-Domain-Map.md) | Domain Architecture and ownership map |
| [governance/LF-Data-Flow.md](governance/LF-Data-Flow.md) | Cross-domain business data flows |
| [governance/LF-Glossary.md](governance/LF-Glossary.md) | Canonical terminology |
| [governance/LF-Naming-Convention.md](governance/LF-Naming-Convention.md) | Project-wide naming conventions |
| [governance/LF-Architecture-Roadmap.md](governance/LF-Architecture-Roadmap.md) | Architecture roadmap |
| [governance/LF-Architecture-Review-Checklist.md](governance/LF-Architecture-Review-Checklist.md) | Domain foundation review gate |
| [governance/LF-Documentation-Manifest.md](governance/LF-Documentation-Manifest.md) | Canonical manifest schema, bilingual discovery and maintenance contract |

---

## Architecture Decision Records

Location:

```text
docs/adr/
```

| Document | Purpose |
| --- | --- |
| [adr/README.md](adr/README.md) | ADR usage, naming, and change policy |
| [ADR-0001](adr/ADR-0001-Course-Foundation.md) | Course Foundation decision |
| [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) | LiveClass Foundation decision |
| [ADR-0003](adr/ADR-0003-Assessment-Foundation.md) | Assessment Foundation decision |
| [ADR-0004](adr/ADR-0004-Media-Foundation.md) | Media Foundation decision |
| [ADR-0005](adr/ADR-0005-Track-Foundation.md) | Track Foundation decision |
| [ADR-0006](adr/ADR-0006-AI-Foundation.md) | AI Foundation decision |
| [ADR-0007](adr/ADR-0007-SaaS-Tenant-Foundation.md) | SaaS Tenant Foundation decision |
| [ADR-0008](adr/ADR-0008-SaaS-Commercial-Foundation.md) | SaaS Commercial Foundation decision |
| [ADR-0009](adr/ADR-0009-SaaS-Usage-Foundation.md) | SaaS Usage Foundation decision |
| [ADR-0010](adr/ADR-0010-SaaS-Billing-Foundation.md) | SaaS Billing Foundation decision |
| [ADR-0011](adr/ADR-0011-Certificate-Foundation.md) | Certificate Foundation decision |
| [ADR-0012](adr/ADR-0012-Course-Template-Published-Version-Snapshot.md) | Course Template Published Version Snapshot decision |
| [ADR-0013](adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md) | Course Template Version Duplicate to Draft decision |
| [ADR-0014](adr/ADR-0014-Product-Offering-And-Draft-Binding.md) | Approved Product offering and Draft binding decision |
| [ADR-0015](adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md) | Approved Course Lesson multiple-prerequisite decision |
| [ADR-0016](adr/ADR-0016-Learning-Foundation.md) | Frozen Learning Foundation Version 1.1; deployed on development, production separately gated |
| [ADR-0017](adr/ADR-0017-AI-Assisted-Learning-Authoring.md) | AI proposes Learning Node/Mapping from Course Media; human review and owner services write, AI never publishes. Approved — implementation separately gated |
| [ADR-0018](adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) | Approved: PII presence không phải OCR failure; redacted derivative và external-processing eligibility là boundary riêng |
| [ADR-0019](adr/ADR-0019-Media-Structured-Extraction-Boundary.md) | Approved: mở locator sang region/table/sheet, structured extraction là content type mới; Media quan sát cấu trúc, AI diễn giải |

---

## Quality Documents

Location:

```text
docs/quality/
```

| Document | Purpose |
| --- | --- |
| [quality/README.md](quality/README.md) | Quality area usage and boundaries |
| [quality/LF-Regression-Audit.md](quality/LF-Regression-Audit.md) | Mandatory `LOW`/`MEDIUM`/`HIGH` audit for every Existing-Feature Change |
| [quality/LF-Documentation-Conflicts.md](quality/LF-Documentation-Conflicts.md) | Canonical classification, STOP rule and traceable register for verified documentation conflicts |
| [quality/LF-Course-Template-Version-Snapshot-Architecture-Review.md](quality/LF-Course-Template-Version-Snapshot-Architecture-Review.md) | Approved Course Template Version snapshot architecture review |
| [quality/LF-Course-Template-Version-Duplicate-to-Draft-Architecture-Review.md](quality/LF-Course-Template-Version-Duplicate-to-Draft-Architecture-Review.md) | Approved Course Template Version duplicate-to-draft architecture review |
| [quality/LF-Course-Template-Ordering-Architecture-Review.md](quality/LF-Course-Template-Ordering-Architecture-Review.md) | Approved and frozen Course Template tenant/category ordering review |
| [quality/LF-Course-Template-Activity-Estimated-Duration-Architecture-Review.md](quality/LF-Course-Template-Activity-Estimated-Duration-Architecture-Review.md) | Approved Course Template Activity estimated duration architecture review |
| [quality/LF-Course-Template-Lesson-Role-Architecture-Review.md](quality/LF-Course-Template-Lesson-Role-Architecture-Review.md) | Approved Course Template Lesson role architecture review |
| [quality/LF-Course-Template-Learning-Mapping-Intent-Architecture-Review.md](quality/LF-Course-Template-Learning-Mapping-Intent-Architecture-Review.md) | Course Template Learning Mapping Intent contract review; PASS with Owner approval pending |
| [quality/LF-Media-Processing-Substrate-Architecture-Review.md](quality/LF-Media-Processing-Substrate-Architecture-Review.md) | Media Processing substrate review; PII/external-processing amendment v1.15 Approved with documented implementation risks |
| [quality/LF-A0-Docling-Closure-Evidence.md](quality/LF-A0-Docling-Closure-Evidence.md) | Only surviving copy of the A0 run behind the closure decision; exploratory evidence, not a verdict |
| [quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md](quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md) | AI Foundation Media-consumer subset A–H packet; CHANGES REQUIRED, migration not authorized |
| [quality/LF-Media-Read-Contract-Architecture-Review.md](quality/LF-Media-Read-Contract-Architecture-Review.md) | Media Read A–H self-assessment packet; independent review pending |
| [quality/LF-Course-Lesson-Multiple-Prerequisites-Architecture-Review.md](quality/LF-Course-Lesson-Multiple-Prerequisites-Architecture-Review.md) | Approved Course Lesson multiple-prerequisite architecture review |
| [quality/LF-Version-Activity-Media-Snapshot-Architecture-Review.md](quality/LF-Version-Activity-Media-Snapshot-Architecture-Review.md) | Approved Version Activity media snapshot architecture review |
| [quality/LF-Course-Product-Architecture-Review.md](quality/LF-Course-Product-Architecture-Review.md) | Approved Course Product CRUD architecture review |
| [quality/LF-Course-Product-Integrated-Architecture-Review.md](quality/LF-Course-Product-Integrated-Architecture-Review.md) | Approved and frozen integrated Product v2 phase-one review |
| [quality/LF-Course-Product-Items-Architecture-Review.md](quality/LF-Course-Product-Items-Architecture-Review.md) | Superseded by the integrated Product v2 phase-one review; retained for historical context |
| [quality/LF-Course-Product-Relations-Architecture-Review.md](quality/LF-Course-Product-Relations-Architecture-Review.md) | Approved Course Product Relations architecture review |
| [quality/LF-Course-Cohort-Architecture-Review.md](quality/LF-Course-Cohort-Architecture-Review.md) | Approved Cohort binding, lifecycle, membership and legacy migration review |
| [quality/LF-LiveClass-Cohort-Session-Architecture-Review.md](quality/LF-LiveClass-Cohort-Session-Architecture-Review.md) | Approved Cohort-centered Session, optional delivery resource and evidence boundary review |
| [quality/LF-LiveClass-Cohort-Schedule-Architecture-Review.md](quality/LF-LiveClass-Cohort-Schedule-Architecture-Review.md) | Approved and frozen recurring Cohort Schedule CRUD/Preview architecture review |
| [quality/LF-LiveClass-Schedule-Session-Origin-Architecture-Review.md](quality/LF-LiveClass-Schedule-Session-Origin-Architecture-Review.md) | Approved explicit atomic Schedule occurrence confirmation and immutable Session Origin review |
| [quality/LF-Enrollment-Lifecycle-Architecture-Review.md](quality/LF-Enrollment-Lifecycle-Architecture-Review.md) | Approved and frozen single and bulk Enrollment lifecycle transition review |
| [quality/LF-Bulk-Enrollment-Architecture-Review.md](quality/LF-Bulk-Enrollment-Architecture-Review.md) | Approved and frozen Admin Bulk Enrollment creation, re-enrollment and atomic-submission idempotency review |
| [quality/LF-Learning-Foundation-Database-Architecture-Review.md](quality/LF-Learning-Foundation-Database-Architecture-Review.md) | PASS Phase 3 Learning Database/Architecture Review; Foundation Freeze recorded and migration remains separately gated |
| [quality/LF-Learning-Foundation-Phase-4C-Trigger-Specification.md](quality/LF-Learning-Foundation-Phase-4C-Trigger-Specification.md) | Review draft for 24 Learning trigger bodies; implementation remains blocked pending exact SQL and approval |
| [quality/LF-Learning-Foundation-Phase-4C-Trigger-Static-Review.md](quality/LF-Learning-Foundation-Phase-4C-Trigger-Static-Review.md) | Engine rehearsal BLOCKED: candidate `JSON_TABLE` conflicts with the allowed MariaDB 10.5 floor; database cleanup PASS |
| [quality/LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md](quality/LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md) | Phase 4E Owner-approved direction; database review must cover four released Course parent-key prerequisites before any migration authorization |
| [quality/LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md](quality/LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md) | Migration source, contract update and isolated MariaDB rehearsal PASS; real database deployment and Teacher Judgment source remain gated |
| [quality/LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md](quality/LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md) | Gate 1 independent runtime/migration code review — **PASS** after four passes; Framework authoring Gate 2 closed 2026-08-23 by recorded MariaDB HTTP/service evidence and Owner attestation |
| [quality/LF-Schema-Drift-Trigger-Identity-Regression-Audit.md](quality/LF-Schema-Drift-Trigger-Identity-Regression-Audit.md) | PASS HIGH regression audit for opt-in trigger identity enforcement in schema drift |

---

# Governance Reading Order

1. [Architecture Principles](governance/LF-Architecture-Principles.md)
2. [Architecture Patterns](governance/LF-Architecture-Patterns.md)
3. [Architecture Guardrails](governance/LF-Architecture-Guardrails.md)
4. [Domain Map](governance/LF-Domain-Map.md)
5. [Data Flow](governance/LF-Data-Flow.md)
6. [Glossary](governance/LF-Glossary.md)
7. [Naming Convention](governance/LF-Naming-Convention.md)
8. [Architecture Roadmap](governance/LF-Architecture-Roadmap.md)
9. [Architecture Review Checklist](governance/LF-Architecture-Review-Checklist.md)
10. [Architecture Decision Records](adr/README.md)

The [Regression Audit](quality/LF-Regression-Audit.md) is a Quality document
required for every `Existing-Feature Change`; Final Audit Level is the highest
applicable `LOW`, `MEDIUM` or `HIGH`. Architecture review is additionally
required only when the change affects an architecture boundary.

---

# Documentation Source Priority

Documentation has different responsibilities.

Governance Documents define mandatory architectural constraints.

Approved ADRs define official architecture decisions.

Domain Documentation defines architecture, ownership, lifecycle and business rules.

Database Documentation defines physical schema, fields, indexes and constraints.

Development Standards define implementation conventions.

Existing Stable Implementation should be reused unless documentation explicitly requires a change.

---

Priority applies only when documents describe different aspects of the system.

If two documents define the same concern differently (for example Domain Documentation and Database Documentation), AI Agents must:

STOP.

Report the conflict.

Do not choose one automatically.

Do not continue implementation until the documentation has been clarified.

---

Documentation Priority

1. Governance Documents
2. Approved ADRs
3. Domain Documentation
4. Database Documentation
5. Development Standards
6. Existing Stable Implementation

---

# Mandatory Documentation Routing Guide

Load only the documents required for the current task.

---

## Before Writing Code

Before implementation, AI Agents must complete the following steps.

1. Read the required documents.

2. Inspect the current implementation.

3. Reuse existing architecture.

4. Verify that no documentation conflicts exist.

5. Only then begin implementation.

If any conflict is found:

STOP.

Report the conflict.

Do not implement code until the conflict has been resolved.

---

## Governance / Safety Check

Read:

* governance/LF-Architecture-Principles.md
* governance/LF-Architecture-Patterns.md
* governance/LF-Architecture-Guardrails.md
* governance/LF-Domain-Map.md
* governance/LF-Data-Flow.md
* governance/LF-Glossary.md
* governance/LF-Naming-Convention.md
* governance/LF-Architecture-Roadmap.md
* governance/LF-Architecture-Review-Checklist.md
* relevant ADR in `adr/`
* quality/LF-Regression-Audit.md

Use when:

* major refactor
* auth changes
* tenant changes
* role/permission changes
* route/middleware changes
* navigation/UI changes
* i18n changes
* before large commits

---

## Database Design / Schema Changes

Read:

* LF-OS.md
* LF-Data-Modeling.md
* LF-Development-Standards.md
* prompts/LF-Implementation-Rules.md
* relevant domain document

Use when:

* creating new tables
* changing table structure
* adding fields
* designing relationships
* creating or modifying migrations

---

## Code Implementation

Read:

* LF-Development-Standards.md
* LF-OS.md
* prompts/LF-Implementation-Rules.md
* relevant domain document
* relevant tech document if needed

Use when:

* writing Laravel code
* writing Livewire code
* changing routes
* changing controllers
* changing middleware
* writing tests
* modifying existing implementation

---

## Existing-Feature Change

Read:

* LF-Development-Standards.md
* quality/LF-Regression-Audit.md
* relevant domain/ADR/database documentation
* governance/LF-Architecture-Review-Checklist.md when an architecture boundary
  is affected

Use automatically when:

* modifying, fixing, extending or upgrading an existing form, flow, module or
  business behavior
* changing existing validation, query/filter, lifecycle, role/access,
  route/middleware, data structure or integration

---

## Authentication

Read:

* LF-OS.md
* core/LF-Core-Auth.md
* core/LF-Core-User.md
* tech/LF-Tech-Architecture.md

---

## User Management

Read:

* core/LF-Core-User.md
* core/LF-Core-Auth.md
* saas/LF-SaaS-Tenant.md

---

## Course Management

Read:

* LF-Core-Overview.md
* core/LF-Core-Course.md
* core/LF-Core-User.md

---

## Assessment Engine

Read:

* LF-Core-Overview.md
* core/LF-Core-Assessment.md
* core/LF-Core-Course.md
* platform/LF-Track.md
* platform/LF-AI.md

---

## Live Class

Read:

* core/LF-Core-LiveClass.md
* platform/LF-Media.md
* platform/LF-Track.md

---

## Media Processing

Read:

* adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md
* adr/ADR-0019-Media-Structured-Extraction-Boundary.md
* platform/LF-Media.md
* platform/LF-Media-Processing-Contract.md
* platform/LF-Media-Read-Contract.md
* tech/LF-Tech-AWS.md

---

## Learning Analytics

Read:

* platform/LF-Track.md
* platform/LF-AI.md

---

## AI Features

Read:

* platform/LF-AI.md
* platform/LF-Track.md
* platform/LF-Media.md

---

## Multi-Tenant SaaS

Read:

* LF-SaaS-Overview.md
* saas/LF-SaaS-Tenant.md
* saas/LF-SaaS-Commercial.md
* saas/LF-SaaS-Usage.md
* saas/LF-SaaS-Billing.md
* tech/LF-Tech-Architecture.md

---

## Infrastructure

Read:

* tech/LF-Tech-Stack.md
* tech/LF-Tech-Architecture.md
* tech/LF-Tech-AWS.md

---

## Frontend

Read:

* tech/LF-Tech-CSS.md
* business/LF-Navigation.md

Create/Edit form design, List/Index design hoặc user nói “Áp dụng thiết kế
tiêu chuẩn”/“Áp dụng chuẩn danh sách”:

1. Đọc `tech/LF-Admin-Form-Design-Standard.md`.
2. Inspect target module và current reference implementation.
3. Đọc `tech/LF-Tech-CSS.md` trước khi thay đổi CSS.

---

# Feature-Based Documentation Routing

Bảng ở phần này định tuyến theo **chức năng thực tế** (feature) của LearnForge,
khác với "Documentation Structure" (định tuyến theo thư mục) và "Mandatory
Documentation Routing Guide" (định tuyến theo loại tác vụ chung) ở trên. Dùng
phần này khi biết trước mình đang làm việc với một chức năng cụ thể (ví dụ
Cohort, Schedule, Bulk Enrollment) và cần xác định ngay bộ tài liệu bắt buộc.

Kiểm kê chức năng dựa trên bằng chứng thực tế trong `docs/` (dùng trường
`Document Path` để xác minh vị trí file) và trong source code (routes,
controllers, services, migrations) tại thời điểm cập nhật bảng này. Chức năng
không có bằng chứng tài liệu hoặc implementation không được đưa vào bảng.

## Cách dùng bảng

1. Bắt đầu từ hàng tương ứng với chức năng cần xử lý.
2. Đọc [Architecture Guardrails](governance/LF-Architecture-Guardrails.md)
   trước — Guardrails áp dụng cho **mọi** chức năng bên dưới và có độ ưu tiên
   cao hơn mọi tài liệu tính năng, nên không lặp lại ở từng hàng.
3. Đọc ADR bắt buộc (nếu có) để xác định architecture decision.
4. Đọc Domain policy để xác định nghiệp vụ, ownership, lifecycle và invariant.
5. Đọc Database documents để xác định schema và constraint.
6. Đọc Quality/Architecture Review để biết rủi ro và blocker đã ghi nhận.
7. Đối chiếu với source code, migration và test hiện tại — cột "Trạng thái
   implementation" trong bảng chỉ là quan sát tại thời điểm viết, không thay
   thế việc tự kiểm tra.
8. Nếu tài liệu mâu thuẫn hoặc không bao phủ yêu cầu, dừng phần bị ảnh hưởng
   và không tự giả định (xem [Rule 2 — Never Guess](#mandatory-ai-agent-rules)
   ở đầu tài liệu này).

`Policy Status` (Approved/Frozen/Draft) mô tả trạng thái **tài liệu chính
sách**, không phải trạng thái implementation. Một domain "Foundation Approved
and Frozen" có thể chưa có migration hoặc controller nào — cột "Trạng thái
implementation" ghi rõ quan sát này riêng, dựa trên sự tồn tại của migration/
controller/service tại thời điểm audit; đây không phải nguồn xác nhận chính
thức, agent vẫn phải tự kiểm tra source code hiện tại trước khi đổi hành vi.

---

## 1. Course Authoring & Publishing

| Chức năng | Domain/Ownership | ADR bắt buộc | Domain policy | Database/schema | Quality/Review | Tài liệu điều kiện | Trình tự đọc |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Category | Course | [ADR-0001](adr/ADR-0001-Course-Foundation.md) (Course Foundation nói chung; không có ADR riêng cho Category) | [LF-Core-Course.md#category](core/LF-Core-Course.md#category) | [core_course_categories.md](database/course/core_course_categories.md) | Chưa có review chuyên biệt | governance/LF-Naming-Convention.md khi thêm field/API mới | Guardrails → ADR-0001 → LF-Core-Course.md#category → core_course_categories.md |
| Course Template Authoring (Template, Section, Lesson, Activity) | Course | [ADR-0001](adr/ADR-0001-Course-Foundation.md) | [LF-Core-Course.md#working-course-definition](core/LF-Core-Course.md#working-course-definition), [#course-authoring-tree-ui](core/LF-Core-Course.md#course-authoring-tree-ui) | [core_course_templates.md](database/course/core_course_templates.md), [core_course_template_sections.md](database/course/core_course_template_sections.md), [core_course_template_lessons.md](database/course/core_course_template_lessons.md), [core_course_template_activities.md](database/course/core_course_template_activities.md) | Chưa có review chuyên biệt cho CRUD cơ bản (xem hàng "Course Template Field Enhancements" cho các review theo field) | prompts/LF-Implementation-Rules.md | Guardrails → ADR-0001 → LF-Core-Course.md#working-course-definition → database docs |
| Template Teacher Assignment | Course | Không có ADR chuyên biệt được xác định | LF-Core-Course.md (không có mục riêng — suy ra từ `## Course Authoring Tree UI`); **Chưa xác định** vị trí chính sách chuyên biệt | [core_course_template_teachers.md](database/course/core_course_template_teachers.md) | Chưa có review chuyên biệt | — | Guardrails → core_course_template_teachers.md → đối chiếu `CourseTemplateTeacherController` |
| Course Template Field Enhancements (Ordering, Activity Estimated Duration, Lesson Role) | Course | Không có ADR chuyên biệt được xác định | [LF-Core-Course.md#course-authoring-tree-ui](core/LF-Core-Course.md#course-authoring-tree-ui) | core_course_template_sections.md, core_course_template_activities.md, core_course_template_lessons.md | [Ordering Review](quality/LF-Course-Template-Ordering-Architecture-Review.md) (Approved and frozen), [Activity Estimated Duration Review](quality/LF-Course-Template-Activity-Estimated-Duration-Architecture-Review.md) (Approved), [Lesson Role Review](quality/LF-Course-Template-Lesson-Role-Architecture-Review.md) (Approved) | — | Guardrails → review tương ứng → database doc tương ứng |
| Course Lesson Multiple Prerequisites | Course | [ADR-0015](adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md) (Approved) | [LF-Core-Course.md](core/LF-Core-Course.md) — mục "Rule 5 Amendment — Multiple Lesson Prerequisites" (heading chứa dấu `—` nên không dùng anchor, đọc trực tiếp trong file) | [core_course_template_lesson_prerequisites.md](database/course/core_course_template_lesson_prerequisites.md), [core_course_template_version_lesson_prerequisites.md](database/course/core_course_template_version_lesson_prerequisites.md) | [Prerequisites Review](quality/LF-Course-Lesson-Multiple-Prerequisites-Architecture-Review.md) (Approved — Post-Implementation Verified) | governance/LF-Architecture-Review-Checklist.md | Guardrails → ADR-0015 → LF-Core-Course.md (Rule 5 Amendment) → database docs → Prerequisites Review |
| Course Publishing & Template Version Snapshot (bao gồm Information/Content Readiness gate) | Course | [ADR-0012](adr/ADR-0012-Course-Template-Published-Version-Snapshot.md) (Approved) | [LF-Core-Course.md#published-course-snapshot](core/LF-Core-Course.md#published-course-snapshot) (mục "Published Course Snapshot"; các gate "Course Template Publish — Information/Content Readiness" nằm ngay sau, đọc trực tiếp trong file do heading chứa dấu `—` không đảm bảo anchor) | [core_course_template_versions.md](database/course/core_course_template_versions.md), core_course_template_version_sections.md, core_course_template_version_lessons.md, core_course_template_version_activities.md | [Version Snapshot Review](quality/LF-Course-Template-Version-Snapshot-Architecture-Review.md) (Approved Review) | governance/LF-Architecture-Review-Checklist.md (thay đổi published snapshot boundary) | Guardrails → ADR-0012 → LF-Core-Course.md (Published Snapshot) → database docs → Version Snapshot Review |
| Duplicate Published Version to Draft | Course | [ADR-0013](adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md) (Approved) | [LF-Core-Course.md#duplicate-published-version-to-draft](core/LF-Core-Course.md#duplicate-published-version-to-draft) | core_course_templates.md (draft target), core_course_template_versions.md (source) | [Duplicate-to-Draft Review](quality/LF-Course-Template-Version-Duplicate-to-Draft-Architecture-Review.md) (Approved Review) | — | Guardrails → ADR-0013 → LF-Core-Course.md (Duplicate Published Version To Draft) → Duplicate-to-Draft Review |
| Course Media Usage (Version Activity Media Binding) | Course × Media | [ADR-0004](adr/ADR-0004-Media-Foundation.md) (Media Foundation), [ADR-0012](adr/ADR-0012-Course-Template-Published-Version-Snapshot.md) (Media Usage Contract) | [LF-Core-Course.md#media-and-assessment](core/LF-Core-Course.md#media-and-assessment), [platform/LF-Media.md](platform/LF-Media.md) | [media_file_usages.md](database/media/media_file_usages.md) | [Version Activity Media Snapshot Review](quality/LF-Version-Activity-Media-Snapshot-Architecture-Review.md) (Approved Review) | — | Guardrails → ADR-0004 → ADR-0012 (Media Usage Contract) → media_file_usages.md → Media Snapshot Review |
| Assessment/Quiz Binding (phía Course) | Course × Assessment | [ADR-0003](adr/ADR-0003-Assessment-Foundation.md) (Approved) | [LF-Core-Course.md#media-and-assessment](core/LF-Core-Course.md#media-and-assessment), [core/LF-Core-Assessment.md](core/LF-Core-Assessment.md) | [database/assessment/README.md](database/assessment/README.md) — ⚠️ **Chưa triển khai**: không có migration/model Assessment nào trong source code tại thời điểm audit | Chưa có review chuyên biệt | — | Guardrails → ADR-0003 → LF-Core-Assessment.md → database/assessment/README.md (lưu ý chưa triển khai) |
| Course Learning Path (chuỗi Product được sắp xếp) | Course | Không có ADR chuyên biệt được xác định | **Chưa xác định** — `LF-Core-Course.md` không có mục riêng nói về Learning Path; đây là khoảng trống định tuyến, không tự suy diễn | [core_course_learning_paths.md](database/course/core_course_learning_paths.md), [core_course_learning_path_items.md](database/course/core_course_learning_path_items.md) | Chưa có review chuyên biệt | Không nhầm với `track_learning_paths` (Track Domain, xem mục Track ở phần 4) — đây là hai khái niệm khác nhau dù cùng tên | Guardrails → core_course_learning_paths.md (đọc trực tiếp vì domain policy chưa bao phủ) |

---

## 2. Product & Enrollment

| Chức năng | Domain/Ownership | ADR bắt buộc | Domain policy | Database/schema | Quality/Review | Tài liệu điều kiện | Trình tự đọc |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Course Product (CRUD & Lifecycle) | Course × Product | [ADR-0014](adr/ADR-0014-Product-Offering-And-Draft-Binding.md) (Product Offering and Draft Content Binding, Approved) | [LF-Core-Course.md#course-product](core/LF-Core-Course.md#course-product) | [core_course_products.md](database/course/core_course_products.md), [core_course_products_v2.md](database/course/core_course_products_v2.md) (Foundation Approved and Frozen) | [Course Product Review](quality/LF-Course-Product-Architecture-Review.md) (Approved Review), [Integrated Product v2 Review](quality/LF-Course-Product-Integrated-Architecture-Review.md) (Approved and frozen) | governance/LF-Architecture-Review-Checklist.md (đổi Product/Version binding) | Guardrails → ADR-0014 → LF-Core-Course.md#course-product → database docs → 2 review trên |
| Product Item | Course × Product | [ADR-0014](adr/ADR-0014-Product-Offering-And-Draft-Binding.md) | [LF-Core-Course.md#course-product](core/LF-Core-Course.md#course-product) | [core_course_product_items.md](database/course/core_course_product_items.md), [core_course_product_items_v2.md](database/course/core_course_product_items_v2.md) | ⚠️ [LF-Course-Product-Items-Architecture-Review.md](quality/LF-Course-Product-Items-Architecture-Review.md) **đã SUPERSEDED**, chỉ giữ cho lịch sử — dùng [Integrated Product v2 Review](quality/LF-Course-Product-Integrated-Architecture-Review.md) làm canonical | — | Guardrails → ADR-0014 → core_course_product_items_v2.md → Integrated Product v2 Review (canonical, không dùng bản superseded) |
| Product Registration & Promotion Window | Course × Product | [ADR-0014](adr/ADR-0014-Product-Offering-And-Draft-Binding.md) | [LF-Core-Course.md#product-registration-and-promotion-windows](core/LF-Core-Course.md#product-registration-and-promotion-windows) | core_course_products.md, core_course_products_v2.md | Bao phủ một phần trong [Course Product Review](quality/LF-Course-Product-Architecture-Review.md); chưa có review riêng cho windows | — | Guardrails → LF-Core-Course.md#product-registration-and-promotion-windows → core_course_products.md |
| Related Product | Course × Product | Không có ADR chuyên biệt được xác định | LF-Core-Course.md#course-product (không có mục con riêng cho Relations) | [core_course_product_relations.md](database/course/core_course_product_relations.md) | [Product Relations Review](quality/LF-Course-Product-Relations-Architecture-Review.md) (Approved) | — | Guardrails → core_course_product_relations.md → Product Relations Review |
| Enrollment (Single, Lifecycle, Version Lock, Access/Review Period) | Course × Product | [ADR-0001](adr/ADR-0001-Course-Foundation.md) (Rule 4 — Enrollment Freeze) | [LF-Core-Course.md#enrollment](core/LF-Core-Course.md#enrollment), [#enrollment-creation-and-immutable-binding](core/LF-Core-Course.md#enrollment-creation-and-immutable-binding), [#enrollment-runtime-authority](core/LF-Core-Course.md#enrollment-runtime-authority) | [core_course_enrollments.md](database/course/core_course_enrollments.md) | [Enrollment Lifecycle Review](quality/LF-Enrollment-Lifecycle-Architecture-Review.md) (Approved and Frozen — Policy 2) | governance/LF-Architecture-Review-Checklist.md (đổi version lock/freeze) | Guardrails → ADR-0001 (Rule 4) → LF-Core-Course.md#enrollment → core_course_enrollments.md → Enrollment Lifecycle Review |
| Bulk Enrollment | Course × Product | Không có ADR chuyên biệt được xác định (kế thừa ADR-0001 Rule 4) | [LF-Core-Course.md#admin-bulk-enrollment](core/LF-Core-Course.md#admin-bulk-enrollment) | [core_course_enrollment_submissions.md](database/course/core_course_enrollment_submissions.md) (Approved and Frozen), core_course_enrollments.md | [Bulk Enrollment Review](quality/LF-Bulk-Enrollment-Architecture-Review.md) (Approved and Frozen), [Enrollment Lifecycle Review](quality/LF-Enrollment-Lifecycle-Architecture-Review.md) (bao phủ cả bulk transitions) | quality/LF-Regression-Audit.md (atomic submission, idempotency) | Guardrails → LF-Core-Course.md#admin-bulk-enrollment → core_course_enrollment_submissions.md → Bulk Enrollment Review → Enrollment Lifecycle Review |
| Student Progress (Course/Lesson/Activity Progress) | Course × Track | [ADR-0001](adr/ADR-0001-Course-Foundation.md) (Rule 5 — Versioned Progress) | [LF-Core-Course.md#learning-progress](core/LF-Core-Course.md#learning-progress) | [core_course_progress.md](database/course/core_course_progress.md), [core_course_lesson_progress.md](database/course/core_course_lesson_progress.md), [core_course_activity_progress.md](database/course/core_course_activity_progress.md) | Chưa có review chuyên biệt | [platform/LF-Track.md](platform/LF-Track.md) — ⚠️ Track hiện **chưa triển khai** (không có migration `track_*`), chỉ là spec đã duyệt | Guardrails → ADR-0001 (Rule 5) → LF-Core-Course.md#learning-progress → database docs |
| Completion & Certificate | Course × Certificate | [ADR-0011](adr/ADR-0011-Certificate-Foundation.md) (Certificate Foundation, Frozen) | [LF-Core-Course.md#completion-and-certificate](core/LF-Core-Course.md#completion-and-certificate), [core/LF-Core-Certificate.md](core/LF-Core-Certificate.md) | [core_certificate_templates.md](database/course/core_certificate_templates.md), [core_certificate_template_products.md](database/course/core_certificate_template_products.md), [core_certificate_issued_certificates.md](database/course/core_certificate_issued_certificates.md), [core_certificate_verification_logs.md](database/course/core_certificate_verification_logs.md), [core_certificate_download_logs.md](database/course/core_certificate_download_logs.md) | Chưa có review chuyên biệt | Bảng migrated (schema tồn tại) nhưng chưa xác nhận controller/UI phát hành certificate — kiểm tra source code trước khi coi là hoàn thiện | Guardrails → ADR-0011 → LF-Core-Certificate.md → 5 database docs trên |
| Learner Engagement (Notes, Bookmarks, Reviews, Favorites) | Course | Không có ADR chuyên biệt được xác định | [LF-Core-Course.md#notes-and-bookmarks](core/LF-Core-Course.md#notes-and-bookmarks), [#reviews](core/LF-Core-Course.md#reviews) | [core_course_notes.md](database/course/core_course_notes.md), [core_course_bookmarks.md](database/course/core_course_bookmarks.md), [core_course_reviews.md](database/course/core_course_reviews.md), [core_course_favorites.md](database/course/core_course_favorites.md) | Chưa có review chuyên biệt | — | Guardrails → LF-Core-Course.md#notes-and-bookmarks / #reviews → database docs tương ứng |

---

## 3. Cohort & LiveClass

| Chức năng | Domain/Ownership | ADR bắt buộc | Domain policy | Database/schema | Quality/Review | Tài liệu điều kiện | Trình tự đọc |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Cohort (bao gồm Cohort Teacher, Cohort Student) | Course × LiveClass | [ADR-0001](adr/ADR-0001-Course-Foundation.md) (Cohort Draft Setup Operations amendment) | [LF-Core-LiveClass.md#cohort-centered-session-policy](core/LF-Core-LiveClass.md#cohort-centered-session-policy) | [core_course_cohorts.md](database/course/core_course_cohorts.md), [core_course_cohort_students.md](database/course/core_course_cohort_students.md), [core_course_cohort_teachers.md](database/course/core_course_cohort_teachers.md) | [Course Cohort Review](quality/LF-Course-Cohort-Architecture-Review.md) (Approved Review — binding, lifecycle, membership, legacy migration) | governance/LF-Architecture-Review-Checklist.md | Guardrails → ADR-0001 (Amendments) → LF-Core-LiveClass.md#cohort-centered-session-policy → database docs → Course Cohort Review |
| LiveClass Schedule (Schedule, Slot, Exclusion, Preview) | LiveClass | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) (Cohort Schedule Foundation Amendment) | [LF-Core-LiveClass.md#liveclass-schedules](core/LF-Core-LiveClass.md#liveclass-schedules), [#schedule-preview](core/LF-Core-LiveClass.md#schedule-preview), [#schedule-lifecycle](core/LF-Core-LiveClass.md#schedule-lifecycle) | [core_liveclass_schedules.md](database/liveclass/core_liveclass_schedules.md), [core_liveclass_schedule_slots.md](database/liveclass/core_liveclass_schedule_slots.md), [core_liveclass_schedule_exclusions.md](database/liveclass/core_liveclass_schedule_exclusions.md) | [Cohort Schedule Review](quality/LF-LiveClass-Cohort-Schedule-Architecture-Review.md) (Approved and frozen; phần deferred explicit-confirmation boundary đã bị supersede bởi Origin Review bên dưới) | — | Guardrails → ADR-0002 (Cohort Schedule Foundation Amendment) → LF-Core-LiveClass.md#liveclass-schedules → database docs → Cohort Schedule Review |
| LiveClass Session (bao gồm Session Rescheduling / Schedule-to-Session Origin) | LiveClass | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) (Schedule-To-Session Origin Amendment) | [LF-Core-LiveClass.md#liveclass-sessions](core/LF-Core-LiveClass.md#liveclass-sessions), [#schedule-occurrence-to-session-origin](core/LF-Core-LiveClass.md#schedule-occurrence-to-session-origin) | [core_liveclass_sessions.md](database/liveclass/core_liveclass_sessions.md), [core_liveclass_session_teachers.md](database/liveclass/core_liveclass_session_teachers.md), [core_liveclass_session_schedule_changes.md](database/liveclass/core_liveclass_session_schedule_changes.md), [core_liveclass_session_schedule_origins.md](database/liveclass/core_liveclass_session_schedule_origins.md) | [Schedule Session Origin Review](quality/LF-LiveClass-Schedule-Session-Origin-Architecture-Review.md) (Approved and frozen — canonical cho occurrence confirmation/lineage), [Cohort Session Review](quality/LF-LiveClass-Cohort-Session-Architecture-Review.md) (Approved) | governance/LF-Architecture-Review-Checklist.md | Guardrails → ADR-0002 (Schedule-To-Session Origin Amendment) → LF-Core-LiveClass.md#liveclass-sessions → database docs → 2 review trên |
| Attendance | LiveClass | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) | [LF-Core-LiveClass.md#attendance](core/LF-Core-LiveClass.md#attendance) | [core_liveclass_attendances.md](database/liveclass/core_liveclass_attendances.md) | Bao phủ một phần trong [Cohort Session Review](quality/LF-LiveClass-Cohort-Session-Architecture-Review.md); chưa có review riêng cho Attendance | — | Guardrails → ADR-0002 → LF-Core-LiveClass.md#attendance → core_liveclass_attendances.md |
| Recording | LiveClass × Media | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) (Media Integration), [ADR-0004](adr/ADR-0004-Media-Foundation.md) | [LF-Core-LiveClass.md#recording](core/LF-Core-LiveClass.md#recording), [#relationship-with-media-domain](core/LF-Core-LiveClass.md#relationship-with-media-domain) | [core_liveclass_recordings.md](database/liveclass/core_liveclass_recordings.md) | Chưa có review chuyên biệt | platform/LF-Media.md | Guardrails → ADR-0002 → LF-Core-LiveClass.md#recording → core_liveclass_recordings.md |
| Replay | LiveClass | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) | [LF-Core-LiveClass.md#replay](core/LF-Core-LiveClass.md#replay) | [core_liveclass_replays.md](database/liveclass/core_liveclass_replays.md) | Chưa có review chuyên biệt | — | Guardrails → ADR-0002 → LF-Core-LiveClass.md#replay → core_liveclass_replays.md |
| Live Chat | LiveClass | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) | [LF-Core-LiveClass.md#live-chat](core/LF-Core-LiveClass.md#live-chat) | [core_liveclass_chat_logs.md](database/liveclass/core_liveclass_chat_logs.md) — ⚠️ **Chưa triển khai**: không tìm thấy migration cho `core_liveclass_chat_logs` tại thời điểm audit | Chưa có review chuyên biệt | — | Guardrails → ADR-0002 → LF-Core-LiveClass.md#live-chat → core_liveclass_chat_logs.md (lưu ý chưa triển khai) |
| Teacher Judgment (nguồn Learning Evidence) | LiveClass × Learning | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md), [ADR-0016](adr/ADR-0016-Learning-Foundation.md) | [LF-Core-Learning.md](core/LF-Core-Learning.md) | [core_liveclass_teacher_judgments.md](database/liveclass/core_liveclass_teacher_judgments.md) — migration/contract implemented and **deployed on development; not production** | [Phase 4E Teacher Judgment Design](quality/LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md), [Phase 4E Course Parent-Key Prerequisite Review](quality/LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md), [Phase 4E Runtime Independent Code Review](quality/LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md) (Gate 1 **PASS**; external surface still gated) | governance/LF-Architecture-Review-Checklist.md | Guardrails → ADR-0016 → LF-Core-Learning.md → Phase 4E Design → source migration → disposable rehearsal → development deployment; runtime separately authorized |
| Online/Offline/Hybrid Delivery | LiveClass | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) | [LF-Core-LiveClass.md#hybrid-learning](core/LF-Core-LiveClass.md#hybrid-learning) | core_liveclass_rooms.md, core_liveclass_sessions.md | Chưa có review chuyên biệt | — | Guardrails → ADR-0002 → LF-Core-LiveClass.md#hybrid-learning → core_liveclass_rooms.md |
| LiveClass Media Integration (Room/Session ↔ Media) | LiveClass × Media | [ADR-0002](adr/ADR-0002-LiveClass-Foundation.md) (Media Integration), [ADR-0004](adr/ADR-0004-Media-Foundation.md) | [LF-Core-LiveClass.md#relationship-with-media-domain](core/LF-Core-LiveClass.md#relationship-with-media-domain), [platform/LF-Media.md](platform/LF-Media.md) | [core_liveclass_rooms.md](database/liveclass/core_liveclass_rooms.md), [media_file_usages.md](database/media/media_file_usages.md) | Chưa có review chuyên biệt | — | Guardrails → ADR-0002 → ADR-0004 → LF-Core-LiveClass.md#relationship-with-media-domain |

---

## 4. Learning Foundation

| Chức năng | Domain/Ownership | ADR bắt buộc | Domain policy | Database/schema | Quality/Review | Tài liệu điều kiện | Trình tự đọc |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Learning Framework, Evidence & Mastery | Core/Learning | [ADR-0016](adr/ADR-0016-Learning-Foundation.md) (Frozen Version 1.1) | [core/LF-Core-Learning.md](core/LF-Core-Learning.md) (Frozen), [core/LF-Core-User.md](core/LF-Core-User.md) (Phase 4A prerequisite implemented) | [database/learning/README.md](database/learning/README.md) and ten `core_learning_*` table docs — **deployed on development; production not authorized** | [Learning Database Review](quality/LF-Learning-Foundation-Database-Architecture-Review.md) — Phase 3, 4A, combined 4B/4C and 4D **PASS** on development; AI and Track remain excluded | [governance/LF-Architecture-Review-Checklist.md](governance/LF-Architecture-Review-Checklist.md), [database/LF-Schema-Drift.md](database/LF-Schema-Drift.md) | Guardrails → ADR-0016 → LF-Core-Learning → database/learning/README → deployment evidence → Phase 4E design; production remains separately gated |

## 5. Platform & SaaS

| Chức năng | Domain/Ownership | ADR bắt buộc | Domain policy | Database/schema | Quality/Review | Tài liệu điều kiện | Trình tự đọc |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Tenant/Tenancy | SaaS × Tenant | [ADR-0007](adr/ADR-0007-SaaS-Tenant-Foundation.md) (Frozen) | [saas/LF-SaaS-Tenant.md](saas/LF-SaaS-Tenant.md), [core/LF-Core-Auth.md#tenant-resolution](core/LF-Core-Auth.md#tenant-resolution), [#tenant-context](core/LF-Core-Auth.md#tenant-context) | [database/saas/README.md](database/saas/README.md), [saas_customers.md](database/saas/saas_customers.md) (migrated) — ⚠️ `saas_customer_domains`, `saas_customer_invitations`, `saas_customer_members`, `saas_customer_settings` **chưa có migration** tại thời điểm audit dù đã có database doc | Chưa có review chuyên biệt | tech/LF-Tech-Architecture.md | Guardrails → ADR-0007 → saas/LF-SaaS-Tenant.md → core/LF-Core-Auth.md#tenant-resolution → database/saas docs |
| Authentication | Core | Không có ADR chuyên biệt được xác định (Auth không có ADR riêng trong `docs/adr/`) | [core/LF-Core-Auth.md](core/LF-Core-Auth.md) | Không có database doc riêng cho bảng auth (users thuộc User domain) | Chưa có review chuyên biệt | LF-OS.md, tech/LF-Tech-Architecture.md | Guardrails → LF-OS.md → core/LF-Core-Auth.md |
| Authorization/Roles | Core | Không có ADR chuyên biệt được xác định | [core/LF-Core-Auth.md#role-architecture](core/LF-Core-Auth.md#role-architecture), [core/LF-Core-User.md#user-roles](core/LF-Core-User.md#user-roles) | Không có database doc riêng | Chưa có review chuyên biệt | — | Guardrails → core/LF-Core-Auth.md#role-architecture → core/LF-Core-User.md#user-roles |
| SaaS Commercial (Plan, Subscription, Entitlement) | SaaS × Commercial | [ADR-0008](adr/ADR-0008-SaaS-Commercial-Foundation.md) (Frozen) | [saas/LF-SaaS-Commercial.md](saas/LF-SaaS-Commercial.md) | [database/saas-commercial/README.md](database/saas-commercial/README.md) — ⚠️ **Chưa triển khai**: không có migration cho `saas_plans`/`saas_subscriptions`/`saas_entitlements`/... tại thời điểm audit | Chưa có review chuyên biệt | — | Guardrails → ADR-0008 → saas/LF-SaaS-Commercial.md → database/saas-commercial/README.md (lưu ý chưa triển khai) |
| SaaS Usage (Counter, Event, Summary) | SaaS × Usage | [ADR-0009](adr/ADR-0009-SaaS-Usage-Foundation.md) (Frozen) | [saas/LF-SaaS-Usage.md](saas/LF-SaaS-Usage.md) | [database/saas-usage/README.md](database/saas-usage/README.md) — ⚠️ **Chưa triển khai**: không có migration cho `saas_usage_*` tại thời điểm audit | Chưa có review chuyên biệt | — | Guardrails → ADR-0009 → saas/LF-SaaS-Usage.md → database/saas-usage/README.md (lưu ý chưa triển khai) |
| SaaS Billing (Invoice, Payment, Credit Note) | SaaS × Billing | [ADR-0010](adr/ADR-0010-SaaS-Billing-Foundation.md) (Frozen) | [saas/LF-SaaS-Billing.md](saas/LF-SaaS-Billing.md) | [database/saas-billing/README.md](database/saas-billing/README.md) — ⚠️ **Chưa triển khai**: không có migration cho `saas_invoices`/`saas_payments`/`saas_credit_notes` tại thời điểm audit | Chưa có review chuyên biệt | — | Guardrails → ADR-0010 → saas/LF-SaaS-Billing.md → database/saas-billing/README.md (lưu ý chưa triển khai) |
| Media Storage & Delivery | Platform/Media | [ADR-0004](adr/ADR-0004-Media-Foundation.md) (S3 Storage Decision) | [platform/LF-Media.md](platform/LF-Media.md) | [media_files.md](database/media/media_files.md), [media_variants.md](database/media/media_variants.md), [media_processing_jobs.md](database/media/media_processing_jobs.md), [media_access_logs.md](database/media/media_access_logs.md) | Chưa có review chuyên biệt | tech/LF-Tech-AWS.md | Guardrails → ADR-0004 → platform/LF-Media.md → database docs → tech/LF-Tech-AWS.md |
| Media Library (Category, File CRUD) | Platform/Media | [ADR-0004](adr/ADR-0004-Media-Foundation.md) (Generic Usage Mapping) | [platform/LF-Media.md](platform/LF-Media.md) | [media_categories.md](database/media/media_categories.md), [media_files.md](database/media/media_files.md), [media_file_usages.md](database/media/media_file_usages.md) | Chưa có review chuyên biệt | tech/LF-Admin-Form-Design-Standard.md (UI Media theo LF-Development-Standards.md § Media Upload/Display/Preview/Delete UI Standard) | Guardrails → ADR-0004 → platform/LF-Media.md → database docs |
| Track / Learning Analytics | Platform/Track | [ADR-0005](adr/ADR-0005-Track-Foundation.md) (Frozen) | [platform/LF-Track.md](platform/LF-Track.md) | [database/track/README.md](database/track/README.md) — ⚠️ **Chưa triển khai**: không có migration cho `track_*` tại thời điểm audit | Chưa có review chuyên biệt | — | Guardrails → ADR-0005 → platform/LF-Track.md → database/track/README.md (lưu ý chưa triển khai) |
| AI Intelligence | Platform/AI | [ADR-0006](adr/ADR-0006-AI-Foundation.md) (Frozen) | [platform/LF-AI.md](platform/LF-AI.md) | [database/ai/README.md](database/ai/README.md) — ⚠️ **Chưa triển khai**: không có migration cho `ai_*` tại thời điểm audit | Chưa có review chuyên biệt | — | Guardrails → ADR-0006 → platform/LF-AI.md → database/ai/README.md (lưu ý chưa triển khai) |
| Audit Log | SaaS × Tenant | [ADR-0007](adr/ADR-0007-SaaS-Tenant-Foundation.md) (Audit Architecture) | saas/LF-SaaS-Tenant.md (mục Audit Architecture trong ADR-0007, chưa có mục domain-policy riêng) | [saas_audit_logs.md](database/saas/saas_audit_logs.md) (migrated) | Chưa có review chuyên biệt | — | Guardrails → ADR-0007 (Audit Architecture) → saas_audit_logs.md |

---

## 6. Cross-cutting Concerns

| Chức năng | Domain/Ownership | ADR bắt buộc | Domain policy | Database/schema | Quality/Review | Tài liệu điều kiện | Trình tự đọc |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Tenant Isolation & Data Ownership (`customer_id`) | Cross-cutting | Áp dụng cho mọi ADR có Foundation Table (xem `Foundation Tables` trong từng ADR liên quan) | [LF-Development-Standards.md](LF-Development-Standards.md) (§ Tenant Scope), [core/LF-Core-Auth.md#tenant-isolation-rules](core/LF-Core-Auth.md#tenant-isolation-rules) | Áp dụng cho mọi bảng business data (`customer_id` bắt buộc) | Chưa có review chuyên biệt | governance/LF-Architecture-Guardrails.md (Tenant Guardrails) | Guardrails (Tenant Guardrails) → LF-Development-Standards.md → core/LF-Core-Auth.md#tenant-isolation-rules |
| Naming & Documentation Metadata | Cross-cutting | Không áp dụng | [governance/LF-Naming-Convention.md](governance/LF-Naming-Convention.md) | — | — | app/Console/Commands/DocsLint.php (kiểm tra tự động) | Guardrails → governance/LF-Naming-Convention.md |
| Documentation Manifest & Bilingual Discovery | Cross-cutting | Không áp dụng | [governance/LF-Documentation-Manifest.md](governance/LF-Documentation-Manifest.md) | — | — | [LF-DOCUMENTATION-MANIFEST.json](LF-DOCUMENTATION-MANIFEST.json), app/Console/Commands/DocsLint.php | LF-INDEX routing → manifest candidate discovery → read source → conflict register when needed |
| Admin Form/List Design Standard (Frontend) | Cross-cutting | Không áp dụng | [tech/LF-Admin-Form-Design-Standard.md](tech/LF-Admin-Form-Design-Standard.md) | — | — | tech/LF-Tech-CSS.md (trước khi sửa CSS) | Guardrails → tech/LF-Admin-Form-Design-Standard.md → tech/LF-Tech-CSS.md |
| Existing-Feature Change Safety Protocol | Cross-cutting | Không áp dụng | [LF-Development-Standards.md](LF-Development-Standards.md#existing-feature-change-safety-protocol) | — | [quality/LF-Regression-Audit.md](quality/LF-Regression-Audit.md) | governance/LF-Architecture-Review-Checklist.md (khi impact chạm architecture boundary) | Guardrails → LF-Development-Standards.md → quality/LF-Regression-Audit.md → Architecture Review Checklist (nếu cần) |
| Documentation Conflict Registration | Cross-cutting | Không áp dụng; dùng ADR process nếu resolution thay đổi architecture | [quality/LF-Documentation-Conflicts.md](quality/LF-Documentation-Conflicts.md) | — | [quality/LF-Documentation-Conflicts.md](quality/LF-Documentation-Conflicts.md) | governance/LF-Architecture-Guardrails.md | Guardrails → verify both sources → LF-Documentation-Conflicts.md → STOP affected concern → authority/ADR/review khi áp dụng |
| Documentation Governance & ADR Process | Cross-cutting | [adr/README.md](adr/README.md) (không phải ADR, là quy trình) | [governance/README.md](governance/README.md), [governance/LF-Architecture-Review-Checklist.md](governance/LF-Architecture-Review-Checklist.md) | — | — | — | Guardrails → governance/README.md → adr/README.md |

---

## Ghi chú phạm vi và khoảng trống định tuyến

* **Chức năng liên miền đáng chú ý** (yêu cầu đọc nhiều hơn một domain
  policy): Course Media Usage, Assessment/Quiz Binding, Student Progress,
  Completion & Certificate, LiveClass Media Integration, Recording — mỗi
  chức năng này cần đọc domain policy của cả hai phía tham chiếu (xem cột
  Domain/Ownership dạng `A × B`).
* **Khoảng trống định tuyến đã ghi nhận** (chưa đủ căn cứ, không tự suy
  đoán):
  * Template Teacher Assignment — chưa xác định mục domain-policy chuyên
    biệt trong `LF-Core-Course.md`.
  * Course Learning Path — `LF-Core-Course.md` chưa có mục riêng; không
    nhầm với `track_learning_paths` (khái niệm khác, thuộc Track Domain).
  * Attendance, Recording, Replay, Live Chat, Audit Log, Template Teacher
    Assignment, Related Product, Learner Engagement, Course Category —
    chưa có Quality/Architecture Review chuyên biệt tại thời điểm audit.
* **Chức năng có ADR nền tảng nhưng chưa có migration/implementation nào**
  tại thời điểm audit (Policy Status "Approved"/"Frozen" — không suy ra
  implementation đã hoàn thành): Assessment/Quiz (ADR-0003), Track
  (ADR-0005), AI (ADR-0006), SaaS Commercial (ADR-0008), SaaS Usage
  (ADR-0009), SaaS Billing (ADR-0010), LiveClass Live Chat
  (`core_liveclass_chat_logs`). Certificate (ADR-0011) có migration/schema
  nhưng chưa xác nhận controller/UI phát hành certificate trong source code.
* Danh sách trên phản ánh source code và tài liệu tại thời điểm cập nhật
  bảng này; agent vẫn phải tự kiểm tra migration/route/controller hiện tại
  trước khi coi một chức năng là "đã triển khai" hay "chưa triển khai".

---

End of Document
