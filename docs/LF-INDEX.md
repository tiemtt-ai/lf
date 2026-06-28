# LF-INDEX.md

Version: 2.0

Status: Official

Last Updated: 2026-06

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

# Documentation Structure

## Directory Guides

| Area | Guide |
| --- | --- |
| Documentation entry point | [README.md](README.md) |
| Governance | [governance/README.md](governance/README.md) |
| Architecture Decision Records | [adr/README.md](adr/README.md) |
| Core Domains | [core/README.md](core/README.md) |
| Database Documentation | [database/README.md](database/README.md) |
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
| [saas/LF-SaaS-Usage.md](saas/LF-SaaS-Usage.md) | Usage tracking |
| [saas/LF-SaaS-Billing.md](saas/LF-SaaS-Billing.md) | Billing and subscriptions |

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

# Documentation Routing Guide

Load only the documents required for the current task.

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

---

End of Document
