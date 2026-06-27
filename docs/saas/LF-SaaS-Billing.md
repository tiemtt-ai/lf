# LF-SaaS-Billing.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF SaaS Billing Architecture

Billing Domain là hệ thống quản lý doanh thu và chi phí sử dụng nền tảng LearnForge.

Nếu:

Usage Domain trả lời:

```text id="bill001"
Customer dùng bao nhiêu?
```

thì

Billing Domain trả lời:

```text id="bill002"
Customer phải trả bao nhiêu?
```

---

# Mission

Cho phép LearnForge:

* quản lý subscription
* quản lý quota
* tính toán usage cost
* tính toán AI cost
* tạo invoice
* hỗ trợ enterprise billing

---

# Billing Philosophy

Billing không nên phụ thuộc vào một mô hình duy nhất.

LearnForge phải hỗ trợ:

```text id="bill003"
Subscription Billing

Usage Billing

Hybrid Billing
```

---

# Core Principle

Billing luôn được tính từ:

```text id="bill004"
Plan

+

Quota

+

Usage
```

---

# Billing Hierarchy

```text id="bill005"
Tenant

↓

Subscription

↓

Quota

↓

Usage

↓

Billing Calculation

↓

Invoice
```

---

# Billing Domains

Billing Layer gồm:

```text id="bill006"
Plans

Subscriptions

Quotas

Usage Billing

Invoices

Payments
```

---

# Database Namespace

```text id="bill007"
saas_billing_*
```

---

# Core Tables

Version 1

```text id="bill008"
saas_plans

saas_subscriptions

saas_quotas

saas_billing_summaries

saas_invoices
```

---

# SaaS Plans

## Purpose

Định nghĩa các gói dịch vụ.

---

# Database

```text id="bill009"
saas_plans
```

---

# Examples

```text id="bill010"
Starter

Professional

Enterprise
```

---

# Suggested Fields

```text id="bill011"
id

name

description

monthly_price

yearly_price

status

metadata
```

---

# Plan Features

Ví dụ:

```text id="bill012"
Users

Storage

AI Tokens

Course Products

Teachers
```

---

# Subscription Architecture

## Purpose

Liên kết tenant với plan.

---

# Database

```text id="bill013"
saas_subscriptions
```

---

# Relationship

```text id="bill014"
Tenant

1

↓

1

Subscription
```

---

# Subscription Fields

```text id="bill015"
customer_id

plan_id

start_date

end_date

status

billing_cycle
```

---

# Billing Cycle

```text id="bill016"
monthly

quarterly

yearly
```

---

# Subscription Status

```text id="bill017"
trial

active

expired

cancelled

suspended
```

---

# Trial Support

LearnForge hỗ trợ:

```text id="bill018"
Free Trial
```

---

# Example

```text id="bill019"
14 Days Trial

↓

Upgrade

↓

Paid Plan
```

---

# Quota Architecture

## Purpose

Giới hạn việc sử dụng theo plan.

---

# Database

```text id="bill020"
saas_quotas
```

---

# Example Quotas

```text id="bill021"
Users

Teachers

Students

Storage

Bandwidth

AI Tokens
```

---

# Example

Starter:

```text id="bill022"
100 Students

50 GB Storage

1M AI Tokens
```

---

Professional:

```text id="bill023"
1000 Students

500 GB Storage

10M AI Tokens
```

---

# Quota Validation

Flow:

```text id="bill024"
Usage

↓

Quota Check

↓

Allow

or

Block
```

---

# Usage Billing

## Purpose

Tính phí dựa trên mức sử dụng.

---

# Billing Sources

```text id="bill025"
Storage

Bandwidth

AI Usage

Active Users
```

---

# Example

```text id="bill026"
Storage

500 GB Used

↓

100 GB Included

↓

400 GB Overage
```

---

# Overage Billing

Future Feature

---

# Example

```text id="bill027"
Included

100 GB
```

---

```text id="bill028"
Used

130 GB
```

---

```text id="bill029"
Overage

30 GB
```

---

```text id="bill030"
Extra Charge
```

---

# AI Billing

AI là thành phần đặc biệt nhất.

---

# Why

AI tạo ra chi phí thực tế.

---

# Metrics

```text id="bill031"
Input Tokens

Output Tokens

Embeddings

AI Requests
```

---

# AI Cost Sources

```text id="bill032"
OpenAI

Claude

Gemini

Azure OpenAI
```

---

# Example

```text id="bill033"
1,000,000 Tokens

↓

Cost

↓

Billing Summary
```

---

# BYOK Support

Nếu khách hàng dùng API Key riêng:

```text id="bill034"
AI Cost = 0
```

---

Nhưng vẫn theo dõi:

```text id="bill035"
AI Usage
```

---

# Billing Summary

## Purpose

Tổng hợp chi phí theo chu kỳ.

---

# Database

```text id="bill036"
saas_billing_summaries
```

---

# Example

```text id="bill037"
June 2026

Subscription

$99

Storage

$15

AI

$22

Total

$136
```

---

# Invoice Architecture

## Purpose

Quản lý hóa đơn.

---

# Database

```text id="bill038"
saas_invoices
```

---

# Invoice Fields

```text id="bill039"
customer_id

billing_period

subtotal

tax

discount

total

status
```

---

# Invoice Status

```text id="bill040"
draft

issued

paid

overdue

cancelled
```

---

# Payment Architecture

Version 1

Tối giản.

---

# Future Providers

```text id="bill041"
Stripe

PayPal

VNPay

Momo

Bank Transfer
```

---

# Revenue Model

LearnForge hỗ trợ nhiều mô hình.

---

# Model 1

Subscription Only

```text id="bill042"
Fixed Monthly Price
```

---

# Model 2

Subscription + AI

```text id="bill043"
Plan

+

AI Usage
```

---

# Model 3

Subscription + Usage

```text id="bill044"
Plan

+

Storage

+

Bandwidth

+

AI
```

---

# Model 4

Enterprise

```text id="bill045"
Custom Contract
```

---

# Cost Allocation

Future Feature

---

# Purpose

Hiểu chi phí thực tế của từng tenant.

---

# Examples

```text id="bill046"
Storage Cost

Bandwidth Cost

AI Cost

Infrastructure Cost
```

---

# Billing And Usage

Usage luôn là nguồn dữ liệu đầu vào.

---

# Flow

```text id="bill047"
Usage Logs

↓

Daily Summary

↓

Monthly Summary

↓

Billing Engine
```

---

# Billing And AI

AI là nguồn doanh thu tương lai quan trọng.

---

# Examples

```text id="bill048"
AI Tutor

AI Grading

AI Analytics

AI Content Generation
```

---

# Billing And Tenant

Relationship:

```text id="bill049"
Tenant

1

↓

N

Invoices
```

---

# Billing Security

## Rule 1

Mọi Billing phải thuộc:

```text id="bill050"
customer_id
```

---

## Rule 2

Không hiển thị dữ liệu billing giữa tenants.

---

## Rule 3

Invoice là immutable sau khi phát hành.

---

## Rule 4

Billing Summary phải audit-friendly.

---

# Design Rules

## Rule 1

Billing không đọc trực tiếp từ business tables.

---

## Rule 2

Billing sử dụng Usage Summaries.

---

## Rule 3

AI Billing phải tách riêng.

---

## Rule 4

Quota phải độc lập với Billing.

---

## Rule 5

BYOC và BYOK phải được hỗ trợ.

---

# Current Scope

Version 1

```text id="bill051"
Plans

Subscriptions

Quotas

Billing Summaries

Invoices
```

---

# Planned Scope

```text id="bill052"
Payments

Auto Renewal

Stripe

Chargeback

Cost Allocation

Enterprise Billing
```

---

# Relationship With Other Domains

```text id="bill053"
Tenant

↓

Usage

↓

Quota

↓

Billing

↓

Invoice
```

---

# Strategic Direction

LearnForge không chỉ bán:

```text id="bill054"
LMS Access
```

---

LearnForge hướng tới bán:

```text id="bill055"
Learning Intelligence Platform
```

---

Điều đó có nghĩa:

AI Usage sẽ trở thành một phần quan trọng trong chiến lược Billing dài hạn.

---

# Final Statement

Billing Domain là hệ thống thương mại hóa của LearnForge.

Nó chuyển đổi:

* Subscription
* Usage
* AI Consumption

thành doanh thu có thể đo lường và quản lý.

Thông qua Billing Domain, LearnForge có thể mở rộng từ:

* trung tâm nhỏ
* trường học
* doanh nghiệp

đến các khách hàng Enterprise,

trong khi vẫn duy trì một kiến trúc SaaS thống nhất và có khả năng mở rộng cao.

---

End of LF-SaaS-Billing
