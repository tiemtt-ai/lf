# LF-Core-Auth.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF-Core Authentication Architecture

LF-Core Authentication là hệ thống xác thực, phân quyền và cô lập tenant của LearnForge.

Mục tiêu:

* Xác thực người dùng
* Xác định tenant hiện tại
* Bảo vệ dữ liệu giữa các tenant
* Điều hướng người dùng đúng khu vực làm việc
* Làm nền tảng cho toàn bộ hệ thống LMS SaaS

---

# Design Objectives

Hệ thống Authentication của LearnForge được thiết kế theo các nguyên tắc:

* Multi-Tenant First
* Security First
* Simplicity First
* Enterprise Ready

Mọi cơ chế xác thực đều phải đảm bảo:

* Tenant Isolation
* Role Isolation
* Secure Authentication
* Secure Password Recovery
* Secure Email Verification

---

# Authentication Flow Overview

```text
User Request

↓

Resolve Tenant

↓

Authenticate User

↓

Validate Tenant Ownership

↓

Validate Role

↓

Redirect To Portal
```

---

# Core Components

Authentication Foundation bao gồm:

```text
Tenant Resolution

↓

Tenant Context

↓

User Authentication

↓

Role Authorization

↓

Portal Access Control
```

---

# Tenant Resolution

## Purpose

Xác định tenant hiện tại dựa trên domain hoặc subdomain.

---

## Example

```text
kaha.learnforge.vn
```

↓

```text
ResolveTenant
```

↓

```text
saas_customers
```

↓

```text
TenantContext
```

↓

```text
customer_id = 1
```

---

# Supported Resolution Methods

## Subdomain

```text
kaha.learnforge.vn
```

```text
visang.learnforge.vn
```

```text
abcschool.learnforge.vn
```

---

## Custom Domain

```text
academy.com
```

```text
learn.company.vn
```

---

# Tenant Context

## Purpose

TenantContext là nguồn tenant hiện hành của toàn bộ request.

---

## Responsibilities

Cung cấp:

```php
TenantContext::customer()

TenantContext::customerId()

TenantContext::slug()

TenantContext::themeKey()

TenantContext::layoutKey()
```

---

# Core Principle

Mọi dữ liệu nghiệp vụ phải được truy vấn theo:

```php
TenantContext::customerId()
```

---

# Example

Đúng:

```php
DB::table('users')
    ->where('customer_id', TenantContext::customerId())
    ->get();
```

Sai:

```php
DB::table('users')->get();
```

---

# User Architecture

LearnForge sử dụng:

```text
users
```

làm bảng xác thực trung tâm.

---

# Core Fields

```text
id

customer_id

role

status

name

email

password

email_verified_at
```

---

# User Ownership

Mọi user đều thuộc:

```text
customer_id
```

---

# Example

```text
Tenant A

customer_id = 1

Users:
- Admin
- Teachers
- Students
```

---

```text
Tenant B

customer_id = 2
```

không thể truy cập dữ liệu của Tenant A.

---

# Authentication Method

LearnForge sử dụng:

Laravel Authentication

làm nền tảng xác thực.

---

# Login Endpoint

Duy nhất:

```text
/login
```

---

# Design Rule

Không sử dụng:

```text
/admin-login

/teacher-login

/student-login
```

---

# Why Single Login

Giảm:

* complexity
* maintenance cost
* user confusion

---

# Login Flow

```text
User Login

↓

Authenticate

↓

Check Status

↓

Check Tenant

↓

Check Role

↓

Redirect
```

---

# Role Architecture

Current Roles:

```text
customer_admin

teacher

student
```

---

# Future Role

```text
super_admin
```

---

# Customer Admin

Portal:

```text
/admin
```

Responsibilities:

* User Management
* Course Management
* Assessment Management
* Reports
* Billing
* Settings

---

# Teacher

Portal:

```text
/teacher
```

Responsibilities:

* Teaching
* Lessons
* Exams
* Grading
* Student Monitoring

---

# Student

Portal:

```text
/student
```

Responsibilities:

* Learning
* Quiz
* Assignment
* AI Tutor

---

# Redirect Strategy

Sau khi login:

```php
switch ($user->role) {

    case 'customer_admin':
        return redirect('/admin');

    case 'teacher':
        return redirect('/teacher');

    case 'student':
        return redirect('/student');

    default:
        abort(403);
}
```

---

# Middleware Architecture

LearnForge sử dụng nhiều lớp middleware.

---

# ResolveTenant

Purpose:

Xác định tenant hiện tại.

---

# Auth

Purpose:

Xác thực người dùng.

---

# Verified

Purpose:

Bắt buộc email đã xác thực.

---

# TenantUser

Purpose:

Đảm bảo user thuộc tenant hiện tại.

---

# Role

Purpose:

Giới hạn quyền truy cập theo role.

---

# Middleware Stack

```text
tenant

↓

auth

↓

verified

↓

tenant.user

↓

role
```

---

# Route Protection Example

Admin:

```php
Route::middleware([
    'tenant',
    'auth',
    'verified',
    'tenant.user',
    'role:customer_admin'
]);
```

---

Teacher:

```php
role:teacher
```

---

Student:

```php
role:student
```

---

# Tenant Isolation Rules

Đây là nguyên tắc quan trọng nhất.

---

# Rule 1

Mọi dữ liệu đều thuộc về:

```text
customer_id
```

---

# Rule 2

Không truy vấn dữ liệu ngoài tenant hiện hành.

---

# Rule 3

Không sử dụng dữ liệu từ tenant khác.

---

# Rule 4

Mọi module mới đều phải hỗ trợ tenant isolation.

Bao gồm:

* Course
* Assessment
* Media
* Track
* AI

---

# Password Reset Architecture

LearnForge sử dụng:

Tenant Scoped Password Reset

---

# Security Goal

Ngăn chặn:

* Cross Tenant Recovery
* Tenant Discovery
* User Enumeration

---

# Validation Rules

Password reset yêu cầu:

* email tồn tại
* customer_id đúng
* user active

---

# Generic Response

Luôn trả về:

```text
If your email exists in our system,
we will send a password reset link.
```

---

# Email Verification

LearnForge sử dụng:

Laravel MustVerifyEmail

---

# Verification Requirements

User phải:

```text
email_verified_at != null
```

để truy cập khu vực được bảo vệ.

---

# Verification Security

Luồng xác thực email phải:

* tenant scoped
* authenticated
* tenant validated

---

# Active Customer Admin Protection

## Business Rule

Mỗi tenant luôn phải có:

ít nhất một customer_admin đang hoạt động.

---

# Protected Actions

Không cho phép:

* xoá admin cuối cùng
* disable admin cuối cùng
* hạ quyền admin cuối cùng

---

# Goal

Ngăn tenant mất quyền quản trị.

---

# Security Foundation

Các lớp bảo vệ hiện tại:

```text
Tenant Isolation

Tenant Scoped Password Reset

Tenant Scoped Email Verification

Active Customer Admin Protection
```

---

# Development Rules

Khi phát triển module mới:

luôn kiểm tra:

```text
customer_id
```

trước tiên.

---

# Examples

Course Module

```text
customer_id
```

---

Assessment Module

```text
customer_id
```

---

Media Module

```text
customer_id
```

---

Track Module

```text
customer_id
```

---

AI Module

```text
customer_id
```

---

# Future Enhancements

Planned:

```text
RBAC

Permission Matrix

Audit Logs

Session Monitoring

SSO

OAuth

SAML
```

---

# Core Authentication Principles

1. One Login System

2. Tenant First

3. Security First

4. Least Privilege

5. Every User Belongs To A Customer

6. Every Request Must Respect Tenant Boundaries

---

# Final Statement

Authentication trong LearnForge không chỉ là đăng nhập.

Nó là nền tảng đảm bảo:

* Tenant Isolation
* Security
* Role Separation
* Data Ownership

cho toàn bộ hệ sinh thái LearnForge.

Mọi module trong LF-Core và LF-SaaS đều phải kế thừa các nguyên tắc được định nghĩa trong tài liệu này.

---

End of LF-Core-Auth
