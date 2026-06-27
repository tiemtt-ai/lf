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

# About LearnForge

LearnForge (LF) is an AI-Native Multi-Tenant LMS SaaS platform.

Architecture is organized into four major groups:

* LF-Core
* LF-SaaS
* LF-Platform
* LF-OS

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

---

## Foundation Documents

Read these first when understanding LearnForge architecture.

| Document            | Purpose                                  |
| ------------------- | ---------------------------------------- |
| LF-OS.md            | Product philosophy and design principles |
| LF-Core-Overview.md | LF-Core architecture overview            |
| LF-SaaS-Overview.md | LF-SaaS architecture overview            |

---

## Engineering Standards

Location:

```text
docs/
```

| Document                    | Purpose                                                                                         |
| --------------------------- | ----------------------------------------------------------------------------------------------- |
| LF-Data-Modeling.md         | Database design methodology: domain, table, relationship, business rules, fields, indexes       |
| LF-Development-Standards.md | Coding, migration, testing, tenant isolation, and implementation standards                      |

---

## Core Modules

Location:

```text
docs/core/
```

| Document                   | Purpose                     |
| -------------------------- | --------------------------- |
| core/LF-Core-Auth.md       | Authentication architecture |
| core/LF-Core-User.md       | User management             |
| core/LF-Core-Course.md     | Course management           |
| core/LF-Core-Assessment.md | Assessment engine           |
| core/LF-Core-LiveClass.md  | Live class engine           |

---

## Platform Modules

Location:

```text
docs/platform/
```

| Document             | Purpose            |
| -------------------- | ------------------ |
| platform/LF-Media.md | Media processing   |
| platform/LF-Track.md | Learning analytics |
| platform/LF-AI.md    | AI intelligence    |

---

## SaaS Modules

Location:

```text
docs/saas/
```

| Document                | Purpose                   |
| ----------------------- | ------------------------- |
| saas/LF-SaaS-Tenant.md  | Multi-tenant architecture |
| saas/LF-SaaS-Usage.md   | Usage tracking            |
| saas/LF-SaaS-Billing.md | Billing and subscriptions |

---

## Technology Documents

Location:

```text
docs/tech/
```

| Document                     | Purpose             |
| ---------------------------- | ------------------- |
| tech/LF-Tech-Stack.md        | Technology stack    |
| tech/LF-Tech-Architecture.md | System architecture |
| tech/LF-Tech-CSS.md          | CSS architecture    |
| tech/LF-Tech-AWS.md          | AWS infrastructure  |

---

## Business Documents

Location:

```text
docs/business/
```

| Document                      | Purpose           |
| ----------------------------- | ----------------- |
| business/LF-Business-Model.md | Business model    |
| business/LF-Navigation.md     | Navigation and UX |

---

## Governance Documents

Location:

```text
docs/governance/
```

| Document                                 | Purpose                                                       |
| ---------------------------------------- | ------------------------------------------------------------- |
| governance/LF-Architecture-Principles.md | Canonical architecture principles and single source of truth  |
| governance/LF-Architecture-Patterns.md   | Approved patterns for recurring architecture problems         |
| governance/LF-Architecture-Guardrails.md | Mandatory architecture rules and non-negotiable constraints   |
| governance/LF-Domain-Map.md              | System-wide Domain Architecture and ownership map             |
| governance/LF-Data-Flow.md               | Cross-domain business data flows                              |
| governance/LF-Glossary.md                | Canonical definitions, ownership, and terminology             |
| governance/LF-Naming-Convention.md       | Project-wide naming conventions                               |
| governance/LF-Architecture-Roadmap.md    | Long-term architecture sequence, dependencies, and milestones |
| governance/LF-Architecture-Review-Checklist.md | Standard review gate for new Domain foundations          |

---

## Architecture Decision Records

Location:

```text
docs/adr/
```

| Document | Purpose |
| --- | --- |
| adr/README.md | ADR usage, naming, and change policy |
| adr/ADR-0001-Course-Foundation.md | Course Foundation decision |
| adr/ADR-0002-LiveClass-Foundation.md | LiveClass Foundation decision |
| adr/ADR-0003-Assessment-Foundation.md | Assessment Foundation decision |
| adr/ADR-0004-Media-Foundation.md | Media Foundation decision |

---

## Quality Documents

Location:

```text
docs/quality/
```

| Document | Purpose |
| --- | --- |
| quality/README.md | Quality area usage and boundaries |
| quality/LF-Regression-Audit.md | Mandatory regression checklist after major changes |

---

# Governance Reading Order

1. Architecture Principles
2. Architecture Patterns
3. Architecture Guardrails
4. Domain Map
5. Data Flow
6. Glossary
7. Naming Convention
8. Architecture Roadmap
9. Architecture Review Checklist
10. Architecture Decision Records

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

# LearnForge Architecture Layers

## LF-Core

Technical learning foundation.

Modules:

* Authentication
* User Management
* Course Management
* Assessment Engine
* Live Class Engine

---

## LF-SaaS

Business SaaS foundation.

Modules:

* Multi Tenant
* Usage Tracking
* Billing
* Subscription
* Customer Isolation

---

## LF-Platform

Shared platform services.

Modules:

* Media
* Tracking
* AI

---

## LF-OS

LearnForge operating principles.

Includes:

* AI Native
* Simplicity First
* Async First
* Tenant First
* Long-Term Scalability

---

# Database Domains

## core_

Learning platform domain.

Examples:

* core_user_*
* core_course_*
* core_assessment_*
* core_liveclass_*

---

## saas_

SaaS business domain.

Examples:

* saas_customers
* saas_subscriptions
* saas_usage_logs
* saas_billing_summaries

---

## media_

Media processing domain.

Examples:

* media_files
* media_videos
* media_documents
* media_transcripts

---

## track_

Learning analytics domain.

Examples:

* track_lesson_progress
* track_video_watch_logs
* track_document_view_logs

---

## ai_

AI intelligence domain.

Examples:

* ai_knowledge_sources
* ai_knowledge_chunks
* ai_conversations
* ai_learning_insights

---

# Architecture Principles

Canonical source:

[governance/LF-Architecture-Principles.md](governance/LF-Architecture-Principles.md)

LF-INDEX không định nghĩa lại principles.

---

# AI Agent Entry Point

## Current Stable Baseline

Current stable baseline:

* Laravel 12
* PHP 8.3+
* Blade + Livewire 3 + AlpineJS
* MySQL
* Redis
* Laravel Reverb
* Single `/login`
* Student redirect: `/`
* Customer Admin redirect: `/admin`
* Teacher redirect: `/teacher`
* Tenant middleware required
* `tenant.user` middleware required for protected areas
* `customer_id` required for business data
* `TenantContext::customerId()` is the tenant source of truth
* `DB::table()` first
* Feature Tests required for auth, tenant isolation, role authorization, and happy path

---

# AI Agent Rules

When working on LearnForge:

1. Start at `docs/README.md`.
2. Use this file as the documentation catalog and routing guide.
3. Follow the Documentation Routing Guide.
4. Load only relevant documents.
5. Do not introduce new architecture patterns without reviewing existing documentation.
6. Preserve tenant isolation.
7. Preserve customer_id-based data ownership.
8. Follow LF-OS principles before implementing new features.
9. Always check LF-Architecture-Guardrails.md before making structural changes.
10. Treat LF-Architecture-Principles.md as the canonical principle source.
11. Run `quality/LF-Regression-Audit.md` after major changes.
12. If module docs conflict with Principles or Guardrails, stop and report the conflict.
13. Before creating or changing database schema, read LF-Data-Modeling.md.
14. Before writing or changing code, read LF-Development-Standards.md.

---

End of Document
