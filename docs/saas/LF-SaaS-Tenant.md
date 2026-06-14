# LF-SaaS-Tenant.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF SaaS Tenant Architecture

Tenant Domain là nền tảng Multi-Tenant của LearnForge.

Nó cho phép:

* Một codebase
* Một platform
* Nhiều khách hàng

cùng sử dụng hệ thống mà vẫn đảm bảo:

* Data Isolation
* Security
* Scalability

---

# Mission

Quản lý khách hàng sử dụng LearnForge.

Cho phép mỗi khách hàng có:

* người dùng riêng
* khóa học riêng
* dữ liệu riêng
* AI riêng
* branding riêng

trên cùng một nền tảng.

---

# Core Principle

Everything Belongs To A Customer.

---

# SaaS Hierarchy

```text id="tenant001"
LearnForge

↓

Customer (Tenant)

↓

Users

↓

Courses

↓

Assessments

↓

Media

↓

Tracking

↓

AI
```

---

# What Is A Tenant?

Một Tenant đại diện cho một tổ chức sử dụng LearnForge.

Ví dụ:

```text id="tenant002"
KAHA

VISANG

ABC School

XYZ Academy

Corporate Training Center
```

---

# Tenant Website Architecture

Mỗi tenant sở hữu một website riêng.

Website Tenant cung cấp ba chế độ trải nghiệm:

```text
Public Mode

Student Mode

Back Office Mode
```

---

# Public Mode

Dành cho Visitor trước khi login.

Bao gồm:

```text
Homepage

Course Catalog

Service Catalog

Teachers

News

Contact
```

---

# Student Mode

Student sử dụng chính Website Tenant sau khi login.

Không chuyển Student sang portal riêng.

Website Tenant hiển thị thêm:

```text
My Courses

Learning History

AI Tutor

Student Profile
```

---

# Back Office Mode

Customer Admin và Teacher sử dụng khu vực vận hành riêng.

```text
customer_admin

↓

/admin
```

```text
teacher

↓

/teacher
```

---

# Multi-Tenant Strategy

LearnForge sử dụng:

```text id="tenant003"
Shared Database

+

Tenant Isolation
```

---

# Why Shared Database

Giúp:

* triển khai nhanh
* giảm chi phí
* dễ bảo trì
* dễ nâng cấp

---

# Tenant Isolation

Mọi dữ liệu được phân tách bằng:

```text id="tenant004"
customer_id
```

---

# Example

```text id="tenant005"
customer_id = 1
```

↓

KAHA

---

```text id="tenant006"
customer_id = 2
```

↓

VISANG

---

# Database Namespace

```text id="tenant007"
saas_customers
```

---

# Core Table

## saas_customers

Là bảng tenant trung tâm.

---

# Responsibilities

Quản lý:

* tenant
* domain
* branding
* settings
* status

---

# Suggested Fields

```text id="tenant008"
id

name

slug

subdomain

custom_domain

email

phone

theme_key

layout_key

status

metadata

created_at

updated_at
```

---

# Tenant Lifecycle

```text id="tenant009"
Register

↓

Provision

↓

Active

↓

Suspended

↓

Archived
```

---

# Register

Khách hàng tạo tenant mới.

---

# Provision

Khởi tạo dữ liệu mặc định.

---

# Active

Tenant hoạt động bình thường.

---

# Suspended

Tạm khóa.

---

# Archived

Ngừng hoạt động nhưng giữ dữ liệu.

---

# Tenant Status

```text id="tenant010"
active

inactive

suspended

archived
```

---

# Tenant Resolution

Tenant phải được xác định trước mọi request.

---

# Flow

```text id="tenant011"
Request

↓

ResolveTenant

↓

TenantContext

↓

Application
```

---

# Resolution Methods

## Subdomain

Ví dụ:

```text id="tenant012"
kaha.learnforge.vn
```

---

```text id="tenant013"
visang.learnforge.vn
```

---

## Custom Domain

Ví dụ:

```text id="tenant014"
academy.com
```

---

```text id="tenant015"
learn.company.vn
```

---

# Tenant Context

TenantContext là nguồn tenant hiện hành.

---

# Responsibilities

```php
TenantContext::customer()

TenantContext::customerId()

TenantContext::slug()

TenantContext::themeKey()

TenantContext::layoutKey()
```

---

# Core Rule

Mọi module phải truy cập tenant thông qua:

```php
TenantContext::customerId()
```

---

Không được:

```php
hardcode customer_id
```

---

# Tenant Ownership Model

```text id="tenant016"
Tenant

1

↓

N

Users
```

---

```text id="tenant017"
Tenant

1

↓

N

Courses
```

---

```text id="tenant018"
Tenant

1

↓

N

Assessments
```

---

```text id="tenant019"
Tenant

1

↓

N

Media
```

---

```text id="tenant020"
Tenant

1

↓

N

AI Data
```

---

# Branding Architecture

Mỗi tenant có thể có branding riêng.

---

# Supported Settings

```text id="tenant021"
Logo

Theme

Color Scheme

Language

Homepage Layout
```

---

# Example

```text id="tenant022"
KAHA

Theme: Korean

Language: ko
```

---

# Tenant Configuration

Mỗi tenant có thể có cấu hình riêng.

---

# Examples

```text id="tenant023"
Default Language

Timezone

Currency

Attendance Rules

Replay Rules
```

---

# Metadata Field

Các thiết lập mở rộng được lưu trong:

```text id="tenant024"
metadata
```

---

# Tenant Provisioning

Khi tenant mới được tạo:

---

# Create Tenant

```text id="tenant025"
saas_customers
```

---

# Create Admin User

```text id="tenant026"
customer_admin
```

---

# Create Default Settings

```text id="tenant027"
theme

language

layout
```

---

# Create Default Roles

```text id="tenant028"
admin

teacher

student
```

---

# Customer Self Registration

Future & Current Hybrid

---

# Flow

```text id="tenant029"
Register Customer

↓

Create Tenant

↓

Create Admin

↓

Activate Tenant
```

---

# Tenant Security

## Rule 1

Tenant Isolation bắt buộc.

---

## Rule 2

Không cho phép truy cập dữ liệu tenant khác.

---

## Rule 3

Mọi truy vấn nghiệp vụ phải lọc:

```text id="tenant030"
customer_id
```

---

## Rule 4

Mọi AI Request phải tenant-scoped.

---

## Rule 5

Mọi Media phải tenant-scoped.

---

# Tenant And Authentication

Authentication luôn phụ thuộc Tenant.

---

# Flow

```text id="tenant031"
Resolve Tenant

↓

Authenticate User

↓

Validate Tenant Ownership

↓

Role-Based Experience
```

---

# Tenant And User

Relationship:

```text id="tenant032"
Tenant

1

↓

N

Users
```

---

# Tenant And Course

Relationship:

```text id="tenant033"
Tenant

1

↓

N

Courses
```

---

# Tenant And Assessment

Relationship:

```text id="tenant034"
Tenant

1

↓

N

Assessments
```

---

# Tenant And Media

Relationship:

```text id="tenant035"
Tenant

1

↓

N

Media Assets
```

---

# Tenant And AI

Relationship:

```text id="tenant036"
Tenant

1

↓

N

Knowledge Sources

AI Conversations

AI Insights
```

---

# BYOC Architecture

## Purpose

Cho phép khách hàng sử dụng cloud riêng.

---

# Examples

```text id="tenant037"
Dedicated AWS

Dedicated S3

Dedicated CloudFront
```

---

# Ownership Model

```text id="tenant038"
Customer Owns Infrastructure
```

---

```text id="tenant039"
LearnForge Owns Platform Intelligence
```

---

# BYOK Architecture

## Purpose

Cho phép khách hàng sử dụng AI Key riêng.

---

# Examples

```text id="tenant040"
OpenAI

Claude

Gemini

Azure OpenAI
```

---

# Configuration

Có thể cấu hình ở cấp tenant:

```text id="tenant041"
provider

model

api_key
```

---

# Future Enterprise Features

```text id="tenant042"
Dedicated Database

Dedicated Redis

Dedicated Search

Private Networking

SSO

SAML
```

---

# Design Rules

## Rule 1

customer_id là khóa phân tách dữ liệu.

---

## Rule 2

Tenant phải được resolve trước authentication.

---

## Rule 3

Mọi module đều phải tenant-aware.

---

## Rule 4

Không được có dữ liệu nghiệp vụ không thuộc tenant.

---

## Rule 5

TenantContext là nguồn dữ liệu tenant duy nhất.

---

# Current Scope

Version 1

```text id="tenant043"
Tenant Resolution

Tenant Context

Subdomain Support

Custom Domain Support

Branding

Settings
```

---

# Planned Scope

```text id="tenant044"
Subscription

Quota

Billing

BYOC

BYOK

Enterprise Features
```

---

# Final Statement

Tenant Domain là nền móng của toàn bộ LearnForge SaaS.

Nó đảm bảo rằng:

* mỗi khách hàng có không gian riêng
* dữ liệu được cô lập an toàn
* nền tảng có thể mở rộng đến hàng nghìn tenant

trong khi vẫn duy trì:

* một codebase
* một platform
* một hệ thống vận hành thống nhất

Đây là nền tảng để LearnForge phát triển thành AI-Native Multi-Tenant Learning Platform.

---

End of LF-SaaS-Tenant
