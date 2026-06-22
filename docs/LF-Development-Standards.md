# LF Development Standards

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# Purpose

Tài liệu này định nghĩa các tiêu chuẩn phát triển phần mềm của LearnForge.

Mục tiêu:

* Giúp Developer viết code nhất quán
* Giúp AI Agent (Codex, Cursor, Claude Code, ChatGPT) sinh code đúng chuẩn
* Giảm khác biệt giữa các module
* Đảm bảo khả năng mở rộng dài hạn

---

# Core Principles

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
course_id
lesson_id
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
core_courses
core_lessons
core_assessment_questions
media_files
track_lesson_progress
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

# Naming Standards

## Tables

Format:

```text
domain_entity
```

Ví dụ:

```text
core_courses
core_lessons
media_files
track_video_watch_logs
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
DB::table('core_courses')
    ->where('customer_id', TenantContext::customerId())
    ->get();
```

---

## Forbidden

Không được:

```php
DB::table('core_courses')->get();
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

# AI Development Rules

## AI Context

AI phải hiểu:

```text
customer_id
user_id
course_id
lesson_id
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
