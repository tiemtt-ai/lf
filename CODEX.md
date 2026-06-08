# CODEX.md

# LearnForge (LF)

## Project Identity

LearnForge (LF) is an AI-Native Multi-Tenant LMS SaaS platform.

Primary users:

* Schools
* Training Centers
* Independent Teachers
* Corporate Training Organizations

LearnForge is built around three architectural pillars:

* LF-Core
* LF-SaaS
* LF-OS

The human developer is the final authority for all architecture and implementation decisions.

---

# Core Philosophy

## Rule 1

Everything belongs to a customer.

customer_id is the center of the platform.

Every business record must belong to a customer.

---

## Rule 2

Shared infrastructure.

Isolated data.

LearnForge uses a shared-first SaaS architecture.

---

## Rule 3

AI is a first-class citizen.

The platform must be designed to support:

* AI Tutor
* AI Analytics
* AI Grading
* AI Personalization

---

## Rule 4

Track data before building intelligence.

Learning behavior data is collected through:

track_*

AI intelligence is generated through:

ai_*

---

## Rule 5

Simple before complex.

Do not introduce unnecessary abstractions.

Avoid over-engineering.

---

# Technology Stack

Backend:

* Laravel 12
* PHP 8.3

Frontend:

* Blade
* Livewire 3
* AlpineJS

Database:

* MySQL

Cache:

* Redis

Realtime:

* Reverb

Queue:

* Laravel Queue

Infrastructure:

* AWS Ready

---

# Multi-Tenant Rules

LearnForge is a multi-tenant platform.

Tenant isolation is mandatory.

Every business query must be scoped by:

customer_id

Always use:

TenantContext::customerId()

Never bypass tenant isolation.

Never access records from another tenant.

Never remove tenant filters.

Never introduce global queries unless explicitly requested.

---

# Authentication Rules

Authentication uses Laravel User Model.

Location:

app/Models/User.php

Current official roles:

* customer_admin
* teacher
* student

Future:

* super_admin

Single login page:

/login

Redirects:

customer_admin -> /admin

teacher -> /teacher

student -> /student

Do not modify authentication flow unless explicitly requested.

---

# Protected Components

Never modify the following components unless explicitly requested:

* TenantContext
* ResolveTenant Middleware
* RequireTenantUser Middleware
* RoleMiddleware
* Authentication Flow
* Login Redirect Logic
* Tenant Resolution Logic

Always assume these components are production-critical.

---

# Coding Convention

Preferred:

* Migration
* DB::table()
* Controller
* Livewire

Avoid Eloquent Models unless clearly necessary.

Prefer simple query builder logic.

Avoid introducing repositories, services, traits, or patterns unless they provide clear value.

Keep code easy to understand and maintain.

---

# Database Namespace Convention

LF-Core

* core_*

LF-SaaS

* saas_*

Media Layer

* media_*

Tracking Layer

* track_*

AI Layer

* ai_*

Always follow existing naming conventions.

Do not invent new prefixes.

---

# Architecture Vocabulary

High-Level Architecture:

* LF-Core
* LF-SaaS
* LF-OS

Implementation Layer:

* core_
* saas_
* media_
* track_
* ai_

Use these terms consistently.

---

# Current Project Status

Completed:

* Tenant Foundation
* Authentication
* Role System
* Customer Registration
* Assessment Architecture

Current Priority:

1. core_course_categories
2. core_courses
3. core_lessons

Future:

* Media Engine
* Tracking Engine
* AI Engine
* Billing Engine

---

# AI Architecture Rules

LearnForge is provider-agnostic.

The architecture must support:

* OpenAI
* Anthropic
* Gemini
* Azure OpenAI
* OpenRouter

Do not hardcode a single AI provider.

Future AI features include:

* AI Tutor
* AI Grading
* AI Analytics
* AI Personalization

---

# Infrastructure Rules

LearnForge supports:

* Shared AWS
* Dedicated AWS
* BYOC (Bring Your Own Cloud)

LearnForge supports:

* Shared AI Keys
* BYOK (Bring Your Own Key)

Do not couple platform intelligence to infrastructure ownership.

Principle:

Customer owns infrastructure choices.

LearnForge owns platform intelligence.

---

# Security Rules

Never expose:

* API Keys
* OpenAI Keys
* AWS Credentials
* Database Passwords
* Secrets
* Tokens

Never output sensitive configuration data.

Never suggest storing secrets in source code.

---

# Git Rules

You MAY:

* Read repository
* Analyze code
* Create code
* Modify code
* Create files
* Refactor within task scope

You MUST NOT:

* git commit
* git push
* git merge
* git rebase
* git reset --hard
* git clean -fd

Never perform destructive git operations.

The human developer is responsible for:

* Review
* Commit
* Push
* Merge Request
* Release

---

# Working Rules

Before making changes:

1. Analyze the task
2. Explain the implementation plan
3. Identify affected files
4. Wait for approval when requested

After making changes:

1. List modified files
2. Summarize changes
3. Highlight risks
4. Suggest testing steps

Do not make unrelated changes.

Stay within the requested scope.

---

# Project Knowledge

Always read these files before major implementation work:

1. LF-ARCHITECTURE.md
2. LF-DATABASE.md
3. LF-MODULES.md
4. LF-ROADMAP.md

Treat those files as the source of truth for LearnForge architecture.

# End of File
