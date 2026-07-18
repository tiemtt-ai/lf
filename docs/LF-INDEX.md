# LF-INDEX.md

Version: 2.0

Status: Official

Last Updated: 2026-07

---

# LearnForge Documentation Index

This document is the official catalog and routing guide for LearnForge
documentation.

Start at [docs/README.md](README.md) to learn how the documentation areas are
used. Then use this index to locate the documents relevant to the current task.

All AI agents (Codex, ChatGPT, Claude, Gemini, Cursor, Windsurf, etc.) should
consult this catalog before making architecture, database, backend, frontend,
infrastructure, or business decisions.

---

# Mandatory AI Agent Rules

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

---

## Platform Modules

Location:

```text
docs/platform/
```

| Document | Purpose |
| --- | --- |
| [platform/LF-Media.md](platform/LF-Media.md) | Media processing |
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
| [saas/LF-SaaS-Tenant.md](saas/LF-SaaS-Tenant.md) | Multi-tenant architecture |
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
| [tech/LF-Admin-Form-Design-Standard.md](tech/LF-Admin-Form-Design-Standard.md) | Canonical presentation standard cho LF Admin Create/Edit forms; kích hoạt bởi “Áp dụng thiết kế tiêu chuẩn” và các trigger tương đương |
| [tech/LF-Tech-AWS.md](tech/LF-Tech-AWS.md) | AWS infrastructure |

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

---

## Quality Documents

Location:

```text
docs/quality/
```

| Document | Purpose |
| --- | --- |
| [quality/README.md](quality/README.md) | Quality area usage and boundaries |
| [quality/LF-Regression-Audit.md](quality/LF-Regression-Audit.md) | Mandatory regression checklist after major changes |
| [quality/LF-Course-Template-Version-Snapshot-Architecture-Review.md](quality/LF-Course-Template-Version-Snapshot-Architecture-Review.md) | Approved Course Template Version snapshot architecture review |
| [quality/LF-Course-Template-Version-Duplicate-to-Draft-Architecture-Review.md](quality/LF-Course-Template-Version-Duplicate-to-Draft-Architecture-Review.md) | Approved Course Template Version duplicate-to-draft architecture review |
| [quality/LF-Course-Template-Ordering-Architecture-Review.md](quality/LF-Course-Template-Ordering-Architecture-Review.md) | Approved and frozen Course Template tenant/category ordering review |
| [quality/LF-Course-Product-Architecture-Review.md](quality/LF-Course-Product-Architecture-Review.md) | Approved Course Product CRUD architecture review |
| [quality/LF-Course-Product-Integrated-Architecture-Review.md](quality/LF-Course-Product-Integrated-Architecture-Review.md) | Approved and frozen integrated Product v2 phase-one review |
| [quality/LF-Course-Product-Items-Architecture-Review.md](quality/LF-Course-Product-Items-Architecture-Review.md) | Approved Course Product Items architecture review |
| [quality/LF-Course-Product-Relations-Architecture-Review.md](quality/LF-Course-Product-Relations-Architecture-Review.md) | Approved Course Product Relations architecture review |

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
and is additionally required for the major change categories defined by the
Documentation Routing Guide.

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

* platform/LF-Media.md
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

Create/Edit form design hoặc user nói “Áp dụng thiết kế tiêu chuẩn”:

1. Đọc `tech/LF-Admin-Form-Design-Standard.md`.
2. Inspect target module và current reference implementation.
3. Đọc `tech/LF-Tech-CSS.md` trước khi thay đổi CSS.

---

End of Document
