# LF Data Modeling Standard

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

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

# Core Principle

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

difficulty

score
```

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

# Intentional Denormalization / Read Model Principle

LearnForge cho phép denormalization có chủ đích để phục vụ:

* Dashboard
* Reporting
* Analytics
* AI Recommendation
* Search
* Performance
* Audit Snapshot
* Historical Consistency

Không tự động xem các nhóm field sau là lỗi:

* snapshot fields
* counter fields
* aggregate fields
* cache fields
* read-model fields
* last-position fields
* title snapshot fields
* metadata fields

Các field này được chấp nhận khi:

* Có mục đích nghiệp vụ rõ ràng.
* Có nguồn dữ liệu gốc rõ ràng.
* Có rule cập nhật hoặc recalculation.
* Không tạo nhiều nguồn sự thật mâu thuẫn.
* Không được dùng thay thế audit log khi nghiệp vụ cần lịch sử chính xác.

Khi review database, chỉ đánh dấu denormalized field là `Problem` nếu:

* Mâu thuẫn với source of truth.
* Không có source rõ ràng.
* Có thể làm sai Billing, Certificate, Completion hoặc AI.
* Bị dùng như dữ liệu thật trong khi chỉ là marketing/display cache.

Mỗi read-model field nên mô tả:

```text
Purpose

Source Of Truth

Update / Recalculation Rule

Allowed Consumers
```

---

# LiveClass Operational Data Principle

LiveClass là Operational Domain và chỉ sinh:

```text
Room

Session

Attendance

Recording

Replay

Chat
```

LiveClass không quyết định Course Completion, Course Progress hoặc
Certificate. Các source of truth tương ứng thuộc Course Domain:

```text
core_course_activity_progress

core_course_progress

core_course_completions

certificate eligibility
```

Attendance percentage, replay percentage, watch position và các operational
status là evidence/read models. Chúng chỉ được dùng làm input cho Course
Progress recalculation và không được trở thành nguồn completion song song.

---

# Domain Responsibility Principle

Một Domain chỉ sở hữu dữ liệu và business rules của chính Domain đó.

Cross-domain relationship không trao quyền cho Domain nguồn cập nhật trực tiếp
business state, quyết định completion hoặc ghi đè source of truth của Domain
đích.

Flow đúng:

```text
Source Domain

↓

Evidence / Event / Request

↓

Target Domain

↓

Target Domain tự quyết định
```

Examples:

* LiveClass lưu Attendance; Course Domain đọc evidence và tự tính Progress.
* Assessment lưu Score/Pass-Fail; Certificate Domain tự quyết định issuance.
* Media/Track lưu Video Watched evidence; Course Progress tự quyết định Lesson
  completion.
* AI lưu Recommendation; Course Domain hoặc User tự quyết định Enrollment.

Khi thiết kế cross-domain field hoặc relationship, phải ghi rõ:

```text
Owning Domain

Source Of Truth

Evidence / Event / Request Contract

Target Domain Decision
```

Không được thiết kế trigger, write path hoặc denormalized field làm phát sinh
source of truth song song ở Domain khác.

---

# Course Template Versioning Principle

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
* Working Template và published Version đều phải có ít nhất một Section.
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

track_lesson_progress

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

# LearnForge Principle

```text
Understand Business First

↓

Design Data Structure

↓

Implement Database
```

Đây là tiêu chuẩn chính thức cho toàn bộ LF-Core, LF-SaaS, Media, Track và AI Domains.
