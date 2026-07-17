# LF Data Modeling Standard

Version: 1.0

Status: Official Foundation

Last Updated: 2026-07

---

# Purpose

Tài liệu này định nghĩa quy chuẩn thiết kế Database cho LearnForge.

Mục tiêu:

* Chuẩn hóa cách thiết kế Domain
* Chuẩn hóa cách đặt tên bảng
* Chuẩn hóa cách mô tả bảng
* Chuẩn hóa cách thiết kế field
* Giúp BA, Developer và AI Agent hiểu cùng một cấu trúc

---

# Core Modeling Rule

Không bắt đầu từ Field.

Phải bắt đầu từ Domain.

Nguyên tắc:

```text
Domain

↓

Table

↓

Relationship

↓

Business Rules

↓

Fields

↓

Indexes
```

---

# Why

Sai lầm phổ biến khi thiết kế hệ thống:

```text
Tạo bảng

↓

Nghĩ field

↓

Thêm field

↓

Sửa field

↓

Thiết kế lại
```

Cách tiếp cận này thường dẫn tới:

* Thiếu nghiệp vụ
* Dư field
* Thiếu relationship
* Khó mở rộng

---

# LearnForge Design Flow

Mọi thiết kế database phải tuân theo quy trình:

```text
1. Domain

↓

2. Table

↓

3. Relationship

↓

4. Business Rules

↓

5. Fields

↓

6. Indexes

↓

7. Sample Data
```

---

# Step 1

Domain Definition

---

## Purpose

Xác định phạm vi nghiệp vụ.

---

## Questions

Domain phải trả lời:

```text
Domain này quản lý cái gì?
```

Ví dụ:

```text
Course Domain

Quản lý khóa học và nội dung học tập.
```

```text
Assessment Domain

Quản lý đánh giá năng lực học tập.
```

```text
Track Domain

Quản lý hành vi học tập.
```

---

# Step 2

Table Definition

---

## Purpose

Xác định vai trò của bảng.

---

## Required Information

Mỗi bảng phải có:

### Table Name

Ví dụ:

```text
core_assessment_question_banks
```

### Purpose

Ví dụ:

```text
Kho lưu trữ câu hỏi.

Là nguồn dữ liệu gốc của Assessment Domain.
```

---

## Rule

Không thiết kế field khi chưa thống nhất:

* tên bảng
* mục đích bảng

---

# Step 3

Relationship Definition

---

## Purpose

Xác định quan hệ dữ liệu.

---

## Required Information

Mỗi bảng phải mô tả:

### Parent Relationship

Ví dụ:

```text
Question Bank

1

↓

N

Questions
```

### Child Relationship

Ví dụ:

```text
Course Template

1

↓

N

Template Lessons
```

Published learning relationship:

```text
Course Template

1

↓

N

Course Template Versions

↓

Version Sections

↓

Version Lessons

↓

Version Activities
```

### Cross Domain Relationship

Ví dụ:

```text
Course Product / Course Template Version

↓

Assessment

↓

Tracking

↓

AI
```

---

## Rule

Relationship phải được xác định trước Field.

Nếu chưa rõ relationship thì chưa thiết kế schema.

---

# Step 4

Business Rules

---

## Purpose

Xác định các quy tắc nghiệp vụ.

---

## Examples

### Course Template

```text
- Phải thuộc customer_id

- Có người tạo

- Có trạng thái

- Có thể publish hoặc archive
```

### Quiz

```text
- Thuộc Question Bank

- Có thể nhiều Attempt

- Có giới hạn thời gian
```

---

## Rule

Business Rule quyết định Field.

Không làm ngược lại.

---

# Step 5

Field Design

---

## Purpose

Thiết kế dữ liệu chi tiết.

---

## Rule

Mỗi Field bắt buộc phải có:

```text
Tên Field

Kiểu dữ liệu

Ý nghĩa nghiệp vụ
```

---

## Wrong Example

```text
id
name
status
created_at
```

---

## Correct Example

### id

```text
Kiểu:

BIGINT

Ý nghĩa:

Khóa chính của bảng.
```

### customer_id

```text
Kiểu:

BIGINT

Ý nghĩa:

Tenant sở hữu dữ liệu.

Áp dụng nguyên tắc:

Everything Belongs To A Customer.
```

### name

```text
Kiểu:

VARCHAR(255)

Ý nghĩa:

Tên hiển thị của đối tượng.
```

### status

```text
Kiểu:

VARCHAR(50)

Ý nghĩa:

Trạng thái nghiệp vụ của bản ghi.
```

---

# Field Categories

Field nên được nhóm theo mục đích.

---

## Identity Fields

```text
id

uuid
```

---

## Ownership Fields

```text
customer_id

created_by

updated_by
```

---

## Business Fields

```text
title

name

description

notes

difficulty

score
```

### Description and Notes Rule

`description` is user-facing or business-facing descriptive content.

Depending on the entity, `description` may be displayed in:

* UI
* storefront
* detail pages
* reports
* user-facing screens

`notes` is internal tenant/admin/teacher note content.

`notes` is used for operational comments and is not public-facing by default.

Business entities that are directly managed by Customer Admin or Teacher should
support `description` and `notes` when they provide real business value.

Do not add `description` and `notes` blindly to every table.

Do not apply this rule by default to:

* mapping/junction tables
* snapshot/version tables
* log/event tables
* tracking tables
* usage/billing event tables
* system/internal tables
* derived read models

Metadata JSON is not a replacement for `description` or `notes`.

Metadata/system JSON fields must not be treated as user-editable form fields by
default.

---

## Relationship Fields

```text
product_id

template_id

template_lesson_id

template_version_id

version_section_id

version_lesson_id

version_activity_id

quiz_id

user_id
```

---

## Status Fields

```text
status

published_at

completed_at
```

---

## Audit Fields

```text
created_at

updated_at

deleted_at
```

---

# Architecture Principles Reference

Canonical definitions:

[LF-Architecture-Principles.md](governance/LF-Architecture-Principles.md)

Data modeling áp dụng đặc biệt:

* Domain Responsibility Principle
* Source Of Truth Principle
* Immutable Principle
* Snapshot Principle
* Versioning Principle
* Evidence Principle
* Platform Domain Principle
* Operational Data Principle
* Evaluation Domain Principle
* Generic Reference Principle
* Tenant Isolation Principle
* Read Model Principle
* Append Only Principle
* AI Consumer Principle

Mỗi cross-domain/read-model design phải ghi rõ owning Domain, source of truth,
snapshot hoặc evidence contract, update/recalculation rule và allowed
consumers. Không tạo source of truth song song.

---

# Course Template Versioning Standard

LearnForge tách:

```text
Course Template = working draft

Course Template Version = published immutable snapshot
```

Database design phải tuân thủ:

* Product Item tham chiếu `template_version_id`.
* Enrollment khóa `template_version_id`.
* Lesson Progress tham chiếu `version_lesson_id`.
* Activity Progress tham chiếu `version_activity_id`.
* Version Section/Lesson/Activity lưu source Template IDs chỉ để lineage.
* Published Version không được update learning content.
* Thay đổi working Template không làm thay đổi dữ liệu học của Enrollment cũ.
* Version lifecycle dùng `draft_snapshot`, `published`, `deprecated`, `archived`.
* Deprecated/archived Version không làm thay đổi existing Enrollment.
* Template lifecycle and Product Template/Version eligibility are defined by
  [LF-Core-Course.md](core/LF-Core-Course.md#course-template-lifecycle-and-product-eligibility);
  mutable Template status never becomes runtime learning authority.
* Section là tùy chọn; working và published Lesson có thể thuộc trực tiếp
  Template/Version hoặc Section cùng owner.
* Một Enrollment là một learning cycle; re-enrollment tạo record mới.
* Progress, Completion và Product-based Certificate dùng `enrollment_id` để
  phân biệt learning cycle.
* Không đặt unique vĩnh viễn trên bộ
  `(customer_id, user_id/student_id, product_id)`.
* Notes và Bookmarks yêu cầu Enrollment `active`.
* Review dùng `user_id`; Student là role, không phải identity field.
* Certificate verification log luôn có `customer_id NOT NULL`.
* Foundation Certificate mapping có tối đa một active mapping cho mỗi Product;
  `minimum_score_percentage` dùng thang phần trăm.

---

# Naming Rules

---

## Table Names

Sử dụng:

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

## Relationship Tables

Sử dụng:

```text
entity_entity
```

Ví dụ:

```text
core_assessment_question_topics

core_course_template_teachers
```

---

## Primary Key

```text
id
```

---

## Foreign Key

```text
product_id

template_id

template_lesson_id

template_version_id

version_lesson_id

version_activity_id

user_id

customer_id
```

---

## Status Fields

Luôn sử dụng:

```text
status
```

Không sử dụng:

```text
is_active

is_deleted

is_enabled
```

nếu trạng thái có thể mở rộng trong tương lai.

---

# Index Design

---

## Purpose

Tăng hiệu năng truy vấn.

---

## Rule

Index phải được thiết kế sau khi hoàn thành nghiệp vụ.

---

## Common Indexes

### Tenant Scope

```sql
(customer_id)
```

### Ownership Lookup

```sql
(customer_id, user_id)
```

### Status Query

```sql
(customer_id, status)
```

### Date Query

```sql
(customer_id, created_at)
```

---

# Sample Data

---

## Purpose

Giúp BA và Developer hiểu schema.

---

## Example

```text
id = 1

customer_id = 100

title = TOPIK Beginner

status = published
```

---

# Standard Table Design Template

```text
Table Name

Purpose

Relationships

Business Rules

Fields

Indexes

Sample Data
```

---

# Example

## Table Name

```text
core_course_templates
```

---

## Purpose

Lưu định nghĩa gốc của khóa học.

---

## Relationships

```text
Customer

1

↓

N

Course Templates
```

```text
Category

1

↓

N

Course Templates
```

```text
Course Template

1

↓

N

Template Lessons
```

---

## Business Rules

```text
- Phải thuộc customer_id

- Có category khi nghiệp vụ yêu cầu

- Có trạng thái nghiệp vụ

- Có vòng đời khóa học
```

---

## Fields

### id

```text
BIGINT

Khóa chính.
```

### customer_id

```text
BIGINT

Tenant sở hữu khóa học.
```

### category_id

```text
BIGINT

Category của Course Template.
```

### title

```text
VARCHAR(255)

Tên khóa học.
```

### description

```text
TEXT

Mô tả khóa học.
```

### status

```text
VARCHAR(50)

draft

published

completed

archived
```

### created_at

```text
DATETIME

Ngày tạo.
```

### updated_at

```text
DATETIME

Ngày cập nhật.
```

---

## Indexes

```sql
(customer_id)

(customer_id, status)

(customer_id, category_id)
```

---

## Sample Data

```text
id = 1

customer_id = 1

teacher_id = 10

title = TOPIK Beginner

status = published
```

---

# Related Implementation Rule Documents

Database design is documented in this file.

Actual CRUD/database-backed implementation must also follow:

- [docs/prompts/LF-Implementation-Rules.md](prompts/LF-Implementation-Rules.md)

The implementation must not invent fields, indexes, relationships, or UI behavior outside the documented table and domain design.

---

# Final Rule

Mọi thiết kế Database của LearnForge phải đi theo trình tự:

```text
Domain

↓

Table

↓

Relationship

↓

Business Rules

↓

Fields

↓

Indexes
```

Không được bắt đầu từ Field.

Field chỉ là kết quả cuối cùng của việc hiểu đúng nghiệp vụ.

---

# Modeling Workflow

```text
Understand Business First

↓

Design Data Structure

↓

Implement Database
```

Đây là tiêu chuẩn chính thức cho toàn bộ LF-Core, LF-SaaS, Media, Track và AI Domains.
