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

Course là:

```text
Learning Unit

+

Commercial Product
```

---

## Rule 2

Course phải hỗ trợ:

```text
Public Viewing

Registration

Purchase

Enrollment

Learning
```

---

## Rule 3

Favorite không đồng nghĩa Enrollment.

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
