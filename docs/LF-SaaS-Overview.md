# LF-SaaS-Overview.md

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: LF-SaaS-Overview.md

---

# LF-SaaS

LF-SaaS là nền tảng kinh doanh của LearnForge.

Nếu:

LF-Core

giải quyết:

"Làm thế nào để người học học tập?"

thì:

LF-SaaS

giải quyết:

"Làm thế nào để LearnForge phục vụ nhiều khách hàng trên cùng một nền tảng?"

---

# Mission

Mục tiêu của LF-SaaS là:

Cho phép một hệ thống LearnForge duy nhất phục vụ:

* trường học
* trung tâm đào tạo
* giáo viên độc lập
* doanh nghiệp

một cách:

* an toàn
* tách biệt dữ liệu
* dễ mở rộng
* dễ tính phí
* dễ quản trị

---

# Core Philosophy

LearnForge là:

Shared-First SaaS

but

BYOC / BYOK Ready

Điều này có nghĩa:

Mặc định:

* dùng hạ tầng chung
* dùng AI chung
* dùng nền tảng chung

Nhưng vẫn sẵn sàng cho khách hàng doanh nghiệp:

* AWS riêng
* Storage riêng
* AI Key riêng

---

# SaaS Business Hierarchy

```text
LearnForge

↓

Customer (Tenant)

↓

Teacher

↓

Student
```

---

# Roles In SaaS Layer

## Platform Owner

LearnForge

Sở hữu:

* Platform
* Source Code
* Infrastructure
* SaaS Engine
* Billing Engine
* AI Intelligence

---

## Customer

Tenant

Ví dụ:

* KAHA
* VISANG
* ABC School
* XYZ Academy

Khách hàng sử dụng nền tảng để:

* kinh doanh khóa học và dịch vụ
* đào tạo học viên
* quản lý giáo viên
* vận hành Tenant Website

---

## Teacher

Người tạo và cung cấp nội dung học tập.

---

## Student

Người sử dụng cuối cùng.

Người học.

---

# Multi-Tenant Foundation

LF-SaaS được xây dựng dựa trên:

Multi-Tenant Architecture

---

# Core Principle

Một hệ thống.

Một codebase.

Nhiều khách hàng.

---

# Tenant Isolation

Dữ liệu được phân tách bằng:

customer_id

---

# Example

```text
Customer A

customer_id = 1

↓

Course Templates + Course Products

↓

Enrollments + Students

↓

AI Data
```

hoàn toàn độc lập với:

```text
Customer B

customer_id = 2
```

---

# Tenant Resolution

Ví dụ:

```text
kaha.learnforge.vn
```

↓

ResolveTenant

↓

TenantContext

↓

customer_id

---

# Why Multi-Tenant Matters

Giúp LearnForge:

* giảm chi phí vận hành
* triển khai nhanh
* dễ nâng cấp
* dễ mở rộng

mà vẫn đảm bảo:

tenant isolation

---

# SaaS Building Blocks

LF-SaaS gồm 4 khối chính:

```text
Tenant

↓

Commercial

↓

Usage

↓

Billing
```

---

# Block 1

Tenant

---

# Purpose

Quản lý khách hàng sử dụng nền tảng.

---

# Responsibilities

* Customer
* Domain
* Branding
* Theme
* Settings
* Membership
* Invitation
* Tenant Audit

---

# Example

```text
saas_customers

saas_customer_settings

saas_customer_domains

saas_customer_members

saas_customer_invitations

saas_audit_logs
```

---

# Block 2

Commercial

---

# Purpose

Quản lý Plan, Subscription và quyền sử dụng capability.

---

# Responsibilities

* Plan Catalog
* Plan Features
* Subscription Lifecycle
* Subscription Items
* Effective Entitlements

---

# Example

```text
Starter

Professional

Enterprise
```

Commercial trả lời:

```text
Can Use?
```

Commercial không lưu Usage, Invoice hoặc Payment.

Foundation tables:

```text
saas_plans

saas_plan_features

saas_subscriptions

saas_subscription_items

saas_entitlements
```

---

# Block 3

Usage

---

# Purpose

Đo lường việc sử dụng hệ thống.

---

# Why Usage Matters

Không đo được usage thì:

* không so sánh được Usage với Entitlement
* không tính được billing
* không tối ưu được hệ thống

---

# Usage Categories

## Storage Usage

Ví dụ:

* Video
* PDF
* Audio

---

## Bandwidth Usage

Ví dụ:

* Video Streaming
* File Download

---

## Learning Usage

Ví dụ:

* Active Students
* Course Access
* Live Class

---

## AI Usage

Ví dụ:

* Token Usage
* AI Requests
* AI Cost

---

# Example

```text
saas_usage_events

saas_usage_counters

saas_usage_summaries
```

---

# Block 4

Billing

---

# Purpose

Phát hành nghĩa vụ thanh toán, ghi nhận settlement và điều chỉnh/refund.

---

# Billing Sources

Có thể bao gồm:

* Subscription
* Storage
* AI Usage
* Active Users
* Student Count

---

# Example

```text
saas_invoices

saas_invoice_items

saas_payments

saas_payment_methods

saas_credit_notes
```

---

# Commercial Entitlement Context

Commercial Entitlement xác định quyền và giới hạn được cấp cho Customer.
Billing chỉ đọc context này và không update Entitlement.

---

# Examples

Storage

```text
100 GB
```

---

AI Tokens

```text
5,000,000 tokens
```

---

Students

```text
500 students
```

---

Teachers

```text
20 teachers
```

---

# Relationship Between Usage And Billing

```text
Commercial Entitlement

↓ Can Use?

Usage

↓ Used.

↓

Billing Calculation

↓

Invoice
```

---

# AI In SaaS

AI là thành phần đặc biệt trong LF-SaaS.

Bởi vì:

AI vừa là tính năng

vừa là chi phí vận hành.

---

# AI Tracking

Mọi request AI phải ghi nhận:

* customer_id
* user_id
* provider
* model

---

# AI Cost Tracking

Theo dõi:

* input_tokens
* output_tokens
* total_tokens
* request_cost

---

# AI Billing

Cho phép:

* entitlement comparison
* analytics
* chargeback
* enterprise reporting

---

# BYOC

Bring Your Own Cloud

---

# Purpose

Cho phép khách hàng doanh nghiệp sử dụng:

* AWS riêng
* S3 riêng
* CloudFront riêng

---

# Benefits

Khách hàng:

* sở hữu hạ tầng
* kiểm soát dữ liệu
* tuân thủ chính sách nội bộ

---

# BYOK

Bring Your Own Key

---

# Purpose

Cho phép khách hàng sử dụng:

* OpenAI riêng
* Claude riêng
* Gemini riêng

---

# LearnForge Responsibility

Dù dùng AI Key riêng,

LearnForge vẫn quản lý:

* Workflow
* Analytics
* Tracking
* Personalization

---

# Infrastructure Ownership Model

```text
Customer

owns

↓

Cloud
Storage
AI Keys
```

---

```text
LearnForge

owns

↓

Platform
Workflow
Analytics
Tracking
Intelligence
```

---

# LF-Core And LF-SaaS

LF-Core

quản lý:

* học tập
* bài học
* bài thi
* AI học tập

---

LF-SaaS

quản lý:

* khách hàng
* Plan
* Subscription
* Entitlement
* usage
* billing

---

Hai lớp này không thay thế nhau.

Chúng bổ sung cho nhau.

---

# Current SaaS Scope

Current

```text
Tenant

Authentication

Role System

Customer Registration

Tenant Website

Student Experience

SaaS Commercial Foundation v1.0

SaaS Usage Foundation v1.0

SaaS Billing Foundation v1.0
```

---

# Planned

```text
Enterprise Features
```

---

# Final Statement

LF-SaaS là bộ máy kinh doanh của LearnForge.

Nó cho phép một nền tảng duy nhất:

phục vụ nhiều khách hàng,

đo lường việc sử dụng,

tính toán chi phí,

và mở rộng từ khách hàng nhỏ đến khách hàng doanh nghiệp,

mà vẫn giữ nguyên một nền tảng thống nhất.

---

End of LF-SaaS-Overview
