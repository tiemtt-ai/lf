# LF Development Standards

Version: 1.0

Status: Official Foundation

Last Updated: 2026-07

---

# Purpose

Tài liệu này định nghĩa các tiêu chuẩn phát triển phần mềm của LearnForge.

Mục tiêu:

* Giúp Developer viết code nhất quán
* Giúp AI Agent (Codex, Cursor, Claude Code, ChatGPT) sinh code đúng chuẩn
* Giảm khác biệt giữa các module
* Đảm bảo khả năng mở rộng dài hạn

---

# Current Stable Baseline

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

# Before Coding Rules For AI Agents

Before modifying LearnForge code, AI Agents must:

* Inspect existing routes, controllers, views, middleware, migrations, and tests related to the task.
* Reuse the current architecture before creating new architecture.
* Prefer modifying existing files over creating duplicate files.
* Do not recreate existing flows.
* Do not rename stable roles, routes, middleware, layouts, or tenant/auth concepts unless explicitly requested.
* Do not create `/admin-login`, `/teacher-login`, or `/student-login`.
* Do not create `/student` as the primary student portal.
* Preserve single `/login`.
* Preserve student redirect to `/`.
* Preserve customer_admin redirect to `/admin`.
* Preserve teacher redirect to `/teacher`.
* Preserve tenant middleware and tenant.user checks.
* Preserve `customer_id` tenant isolation.
* Prefer `DB::table()` unless Eloquent is clearly needed.
* Add or update Feature Tests when behavior changes.

---

# Core Development Rules

## Rule 1

Tenant First

Mọi dữ liệu nghiệp vụ phải thuộc:

```text
customer_id
```

---

## Rule 2

Security First

Không truy cập dữ liệu ngoài tenant hiện hành.

---

## Rule 3

Simple Before Complex

Ưu tiên giải pháp đơn giản.

Không over-engineering.

---

## Rule 4

Monolith First

Kiến trúc mặc định:

```text
Laravel Monolith
```

Không tách microservice nếu chưa có nhu cầu thực tế.

---

# Technology Stack

## Backend

```text
Laravel 12
PHP 8.3+
MySQL
Redis
```

---

## Frontend

```text
Blade
Livewire 3
AlpineJS
Vite
```

---

## Infrastructure

```text
AWS
S3
Redis
```

---

# Database Standards

## Primary Key

Luôn sử dụng:

```php
id
```

Kiểu:

```php
BIGINT UNSIGNED
```

---

## Foreign Key Naming

```php
customer_id
user_id
product_id
template_id
template_lesson_id
template_version_id
version_section_id
version_lesson_id
version_activity_id
quiz_id
```

---

## Ownership

Mọi business table phải có:

```php
customer_id
```

Ví dụ:

```text
core_course_templates
core_course_template_lessons
core_course_template_versions
core_course_template_version_lessons
core_assessment_questions
media_files
track_events
```

---

## Audit Fields

Mặc định:

```php
created_at
updated_at
```

---

## Soft Delete

Chỉ dùng khi thật sự cần.

Không mặc định thêm:

```php
deleted_at
```

---

# Architecture Principles Reference

Canonical definitions:

[LF-Architecture-Principles.md](governance/LF-Architecture-Principles.md)

Development phải áp dụng toàn bộ 17 principles, đặc biệt Domain Responsibility,
Source Of Truth, Immutable, Snapshot, Versioning, Evidence, Tenant Isolation,
Read Model, Append Only, AI Consumer, Backward Compatibility và ADR.

Implementation-specific standards trong tài liệu này không được định nghĩa lại
principles hoặc tạo business-state authority cạnh tranh.

---

## Course Template Versioning Standard

```text
Working Template

↓ publish

Immutable Template Version

↓

Product

↓

Enrollment

↓

Progress
```

Rules:

* Working Template tables dùng `template_*` foreign keys.
* Published learning tables dùng `template_version_id` và `version_*_id`.
* Product Item dùng `template_version_id`.
* Enrollment lưu `template_version_id`.
* Progress dùng `version_lesson_id` và `version_activity_id`.
* Source Template IDs trong Version tables chỉ dùng lineage/reporting.
* Không update published Version learning content.
* Không silent-migrate Enrollment khi Product đổi Version.
* Version lifecycle dùng `draft_snapshot`, `published`, `deprecated`, `archived`.
* Deprecated/archived Version không làm thay đổi existing Enrollment.

Course Foundation constraints:

* Một Enrollment là một learning cycle; re-enrollment tạo Enrollment mới.
* Không unique vĩnh viễn theo `customer_id`, `user_id`/`student_id`, `product_id`.
* Progress, Completion và Product-based Certificate phải tham chiếu `enrollment_id`.
* Section bắt buộc trong working Template và published Version.
* Notes/Bookmarks chỉ được tạo hoặc cập nhật khi Enrollment `active`.
* Review dùng `user_id`, không dùng `student_id`.
* Foundation Certificate mapping giới hạn một active mapping trên mỗi Product.
* Certificate `minimum_score_percentage` là phần trăm chuẩn hóa.
* Certificate verification luôn tenant-scoped và `customer_id` phải `NOT NULL`.

---

# Naming Standards

## Tables

Format:

```text
domain_entity
```

Ví dụ:

```text
core_course_templates
core_course_template_lessons
media_files
track_events
ai_conversations
```

---

## Columns

Format:

```text
snake_case
```

Ví dụ:

```text
customer_id
teacher_id
total_score
created_at
```

---

## Status Fields

Ưu tiên:

```text
status
```

Ví dụ:

```text
draft
published
active
inactive
completed
archived
```

---

# Laravel Standards

## Query Style

Ưu tiên:

```php
DB::table()
```

---

Không mặc định dùng:

```php
Eloquent Model
```

trừ khi thực sự cần.

---

## Tenant Scope

Mọi query business data phải lọc:

```php
TenantContext::customerId()
```

Ví dụ:

```php
DB::table('core_course_templates')
    ->where('customer_id', TenantContext::customerId())
    ->get();
```

---

## Forbidden

Không được:

```php
DB::table('core_course_templates')->get();
```

---

# Routing Standards

## Authentication

Chỉ có:

```text
/login
```

Không tạo:

```text
/admin-login
/teacher-login
/student-login
```

---

## Redirect

```php
customer_admin => /admin

teacher => /teacher

student => /
```

---

# UI Standards

## Responsive First

Bắt buộc kiểm tra:

```text
375px
768px
1366px
1440px
```

---

## Layouts

```text
Public Layout

Admin Layout

Teacher Layout

Student Website Experience
```

---

# Testing Standards

## Required

Mọi tính năng mới phải kiểm tra:

```text
Tenant Isolation

Role Authorization

Authentication

Happy Path
```

---

## Feature Test

Ưu tiên:

```php
Feature Tests
```

---

# AI Agent Implementation Rules

Before implementing any CRUD/database-backed module, AI Agents must follow:

- [docs/prompts/LF-Implementation-Rules.md](prompts/LF-Implementation-Rules.md)

This rule document is mandatory unless explicitly overridden by a domain ADR or governance document.

---

# AI Development Rules

## AI Context

AI phải hiểu:

```text
customer_id
user_id
product_id
enrollment_id
template_version_id
version_lesson_id
version_activity_id
```

nếu có ngữ cảnh học tập.

---

## AI Tracking

Mọi AI Request phải lưu:

```text
customer_id
user_id
provider
model
```

---

# Migration Standards

## Rule 1

Không sửa migration đã chạy production.

---

## Rule 2

Tạo migration mới để thay đổi schema.

---

## Rule 3

Mỗi module một migration rõ ràng.

---

# Code Generation Rules For AI Agents

Khi sinh code cho LearnForge:

1. Luôn kiểm tra tenant isolation.
2. Luôn thêm customer_id cho business data.
3. Ưu tiên DB::table().
4. Không dùng Eloquent nếu không cần.
5. Sử dụng Blade + Livewire.
6. Tuân thủ naming convention LF.
7. Tạo Feature Test cho chức năng mới.
8. Không tạo kiến trúc phức tạp vượt nhu cầu hiện tại.

---

# Final Statement

Mọi code trong LearnForge phải:

```text
Simple

Secure

Tenant Aware

AI Ready

Enterprise Ready
```

Đây là tiêu chuẩn phát triển chính thức cho toàn bộ LF-Core, LF-SaaS, Media, Track và AI Domains.
