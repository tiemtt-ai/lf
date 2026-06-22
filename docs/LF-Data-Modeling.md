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
Course

1

↓

N

Lessons
```

### Cross Domain Relationship

Ví dụ:

```text
Course

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

### Course

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
course_id

lesson_id

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

# Naming Rules

---

## Table Names

Sử dụng:

```text
domain_entity
```

Ví dụ:

```text
core_courses

core_lessons

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

core_course_teacher_assignments
```

---

## Primary Key

```text
id
```

---

## Foreign Key

```text
course_id

lesson_id

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
core_courses
```

---

## Purpose

Lưu thông tin khóa học.

---

## Relationships

```text
Customer

1

↓

N

Courses
```

```text
Teacher

1

↓

N

Courses
```

```text
Course

1

↓

N

Lessons
```

---

## Business Rules

```text
- Phải thuộc customer_id

- Có người phụ trách

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

### teacher_id

```text
BIGINT

Giáo viên phụ trách.
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

(customer_id, teacher_id)
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
