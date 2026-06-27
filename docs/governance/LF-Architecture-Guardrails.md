# LF-Architecture-Guardrails.md

Version: 1.0

Status: Mandatory

Last Updated: 2026-06

---

# Purpose

Tài liệu này định nghĩa các nguyên tắc kiến trúc cốt lõi của LearnForge.

Mọi thay đổi hệ thống phải tuân thủ các nguyên tắc trong tài liệu này.

Nếu một thay đổi vi phạm Guardrails:

```text
STOP

Report

Review

Approve

Then Implement
```

Không được tự ý thay đổi.

---

# Core Principle

Không được phá vỡ các kiến trúc đã được xác nhận ổn định.

Mọi tính năng mới phải:

```text
Compatible

Backward Compatible

Tenant Aware

Role Aware
```

---

# LearnForge Core Architecture

```text
One Codebase

↓

One Platform

↓

Multi Tenant

↓

Multiple Experiences
```

---

# Tenant Guardrails

## Rule 1

Mọi dữ liệu nghiệp vụ phải thuộc một tenant.

---

## Rule 2

Mọi dữ liệu nghiệp vụ phải có:

```text
customer_id
```

hoặc được suy ra từ:

```php
TenantContext::customerId()
```

---

## Rule 3

Không được hardcode:

```php
customer_id
```

---

## Rule 4

Tenant phải được resolve trước authentication.

Flow:

```text
Request

↓

ResolveTenant

↓

TenantContext

↓

Authentication

↓

Authorization

↓

Application
```

---

## Rule 5

User Tenant A không được truy cập dữ liệu Tenant B.

---

# Authentication Guardrails

## Rule 1

Chỉ có một màn hình đăng nhập.

```text
/login
```

---

## Rule 2

Không được tạo:

```text
/admin-login

/teacher-login

/student-login
```

---

## Rule 3

Authentication phải phụ thuộc Tenant.

Flow:

```text
Resolve Tenant

↓

Authenticate User

↓

Validate Tenant Ownership

↓

Role Experience
```

---

# Role Guardrails

## Official Roles

```text
customer_admin

teacher

student
```

---

## Future Role

```text
super_admin
```

---

## Rule 1

Không được xoá:

```text
role:student
```

---

## Rule 2

Không được đổi tên role hiện tại.

---

## Rule 3

Role phải được kiểm tra bằng middleware.

Ví dụ:

```php
role:customer_admin

role:teacher

role:student
```

---

# User Experience Guardrails

## Official Experiences

```text
Visitor Experience

Student Experience

Teacher Back Office

Admin Back Office
```

---

# Visitor Experience

Entry:

```text
/
```

---

Hiển thị:

```text
Homepage

Courses

Assessments

Services

Teachers

About

Contact
```

---

# Student Experience

Student sử dụng:

```text
Tenant Website
```

sau khi login.

---

Student không sử dụng portal riêng.

---

Student Login Redirect

```text
/
```

---

Student Menu

```text
Home

Courses

Assessments

Services

My Courses

Learning History

AI Tutor

Profile
```

---

# Forbidden Patterns

Không được tạo:

```text
Student Portal

Student Dashboard

/student
```

làm entry chính.

---

# Teacher Experience

Entry:

```text
/teacher
```

---

Teacher sử dụng:

```text
Teacher Back Office
```

---

# Admin Experience

Entry:

```text
/admin
```

---

Admin sử dụng:

```text
Admin Back Office
```

---

# Course Guardrails

## Rule 1

Course Definition là:

```text
Course Template (Working Draft)

↓

Course Template Version (Published Immutable Snapshot)
```

Course Commerce là:

```text
Course Product
```

---

## Rule 2

Course Product phải hỗ trợ:

```text
Public Viewing

Registration

Purchase

Enrollment

Learning
```

---

## Rule 3

Course Product chỉ tham chiếu published Course Template Version.

Course Product không expose working Template draft.

---

## Rule 4

Enrollment luôn thuộc Course Product và khóa `template_version_id`.

Product đổi Version không được silent-update Enrollment hiện có.

---

## Rule 5

Learning Progress tham chiếu trực tiếp Version Lesson và Version Activity:

```text
version_lesson_id

version_activity_id
```

Không dùng working `template_lesson_id` hoặc `template_activity_id` làm progress source.

---

## Rule 6

Favorite không đồng nghĩa Enrollment.

---

## Rule 7

Published Template Version là immutable.

Muốn thay đổi published content phải tạo Version mới.

Version lifecycle:

```text
draft_snapshot

published

deprecated

archived
```

Deprecated Version không bán mới nhưng existing Enrollment vẫn tiếp tục học.

Archived Version chỉ lưu trữ/audit và không được Product mới sử dụng.

---

## Rule 8

Không tạo lại:

```text
core_courses

core_course_sections

core_course_lessons

core_course_activities
```

Template Version snapshots không phải Runtime Course.

---

## Intentional Denormalization / Read Model Principle

LearnForge cho phép denormalization có chủ đích cho Dashboard, Reporting,
Analytics, AI Recommendation, Search, Performance, Audit Snapshot và
Historical Consistency.

Snapshot, counter, aggregate, cache, read-model, last-position, title snapshot
và metadata fields được chấp nhận khi:

```text
Purpose rõ ràng

Source of Truth rõ ràng

Update / Recalculation Rule rõ ràng

Không tạo nguồn sự thật mâu thuẫn
```

Display hoặc marketing cache không được dùng làm dữ liệu thật cho:

```text
Billing

Certificate

Completion

AI
```

Read-model fields không thay thế audit logs khi cần lịch sử chính xác.

---

# Certificate Verification Guardrails

* Verification luôn chạy trong tenant context.
* Không hỗ trợ Global Verification.
* `core_certificate_verification_logs.customer_id` luôn `NOT NULL`.
* Failed certificate lookup vẫn phải có tenant owner.

---

# Course Foundation P1 Guardrails

* Một Enrollment là một learning cycle; re-enrollment tạo Enrollment mới.
* Không dùng permanent unique User/Student–Product để chặn re-enrollment.
* Progress, Completion và Product-based Certificate luôn tham chiếu
  `enrollment_id`.
* Working Template và published Version đều phải có ít nhất một Section.
* Một Enrollment chỉ có một Cohort membership record; chuyển lớp dùng `UPDATE`,
  không tạo history và không dùng `is_current`.
* Notes/Bookmarks chỉ được tạo hoặc cập nhật với Enrollment `active`.
* Review identity dùng `user_id`, không dùng `student_id`.
* Foundation giới hạn một active Certificate mapping trên mỗi Product.
* Certificate threshold dùng `minimum_score_percentage`, không dùng absolute score.

---

# Internationalization Guardrails

## Supported Languages

Current:

```text
vi

en
```

---

## Default Locale

```text
vi
```

---

## Fallback Locale

```text
en
```

---

## Translation Convention

Mọi key phải theo format:

```text
LF_module_feature_role_name
```

Ví dụ:

```text
LF_navigation_menu_public_home

LF_auth_login_title

LF_course_card_register
```

---

## Rule

Không hardcode UI text nếu thuộc multilingual layer.

---

# Navigation Guardrails

## Public Routes

```text
/

/courses

/assessments

/services

/teachers

/about

/contact
```

---

## Student Routes

```text
/my-courses

/learning-history

/ai-tutor

/profile
```

---

## Admin Routes

```text
/admin
```

---

## Teacher Routes

```text
/teacher
```

---

# Middleware Guardrails

Protected Student Routes:

```text
tenant

auth

verified

tenant.user

role:student
```

---

Protected Teacher Routes:

```text
tenant

auth

verified

tenant.user

role:teacher
```

---

Protected Admin Routes:

```text
tenant

auth

verified

tenant.user

role:customer_admin
```

---

# Testing Guardrails

Mọi thay đổi lớn phải chạy:

```bash
php artisan test

./vendor/bin/pint

npm run build

git diff --check
```

---

# Final Statement

Nếu một tính năng mới làm thay đổi:

```text
Tenant Architecture

Authentication

Authorization

Navigation

Student Experience

Role Model

Internationalization
```

thì phải được review trước khi merge.

Guardrails này có độ ưu tiên cao hơn mọi tài liệu tính năng.

---

End of LF-Architecture-Guardrails
