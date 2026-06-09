# LF-INDEX.md

Version: 2.0

Status: Official

Last Updated: 2026-06

---

# LearnForge Documentation Index

This document is the primary entry point for all LearnForge documentation.

All AI agents (Codex, ChatGPT, Claude, Gemini, Cursor, Windsurf, etc.) should read this file first before making architecture, database, backend, frontend, infrastructure, or business decisions.

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

## Foundation Documents

Read these first when understanding LearnForge architecture.

| Document            | Purpose                                  |
| ------------------- | ---------------------------------------- |
| LF-OS.md            | Product philosophy and design principles |
| LF-Core-Overview.md | LF-Core architecture overview            |
| LF-SaaS-Overview.md | LF-SaaS architecture overview            |

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

# Documentation Routing Guide

Load only the documents required for the current task.

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

# Core Principles

1. Everything belongs to a customer.

   All tenant business data must be scoped by customer_id.

2. Shared infrastructure, isolated data.

3. Track before intelligence.

   Analytics data must exist before AI can generate intelligence.

4. AI is a core capability.

5. Simple before complex.

6. LearnForge owns platform intelligence.

7. Customer owns infrastructure choices.

---

# AI Agent Rules

When working on LearnForge:

1. Read this file first.
2. Follow the Documentation Routing Guide.
3. Load only relevant documents.
4. Do not introduce new architecture patterns without reviewing existing documentation.
5. Preserve tenant isolation.
6. Preserve customer_id-based data ownership.
7. Follow LF-OS principles before implementing new features.

---

End of Document
