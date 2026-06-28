# LF-SaaS-Usage.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF SaaS Usage Architecture

Usage Domain là hệ thống đo lường mức độ sử dụng nền tảng của từng tenant.

Nếu:

Tenant Domain trả lời:

```text
Ai đang sử dụng LearnForge?
```

thì

Usage Domain trả lời:

```text
Họ đang sử dụng LearnForge bao nhiêu?
```

---

# Mission

Đo lường toàn bộ tài nguyên mà tenant sử dụng.

Bao gồm:

* Storage
* Bandwidth
* AI
* Users
* Courses
* Learning Activity

---

# Why Usage Matters

Usage cung cấp measurement cho:

```text
Entitlement Comparison

Billing

Analytics

Capacity Planning

Enterprise Reporting
```

---

# Core Principle

Nếu không đo lường được thì không thể:

* tối ưu
* dự báo
* tính phí

---

# Usage Hierarchy

```text
Tenant

↓

Usage Events

↓

Usage Aggregation

↓

Commercial Entitlement Comparison

↓

Billing
```

---

# Usage Categories

LearnForge chia Usage thành 6 nhóm.

```text
Storage Usage

Bandwidth Usage

User Usage

Learning Usage

Media Usage

AI Usage
```

---

# Database Namespace

```text
saas_usage_*
```

---

# Core Tables

Version 1

```text
saas_usage_logs

saas_usage_daily_summaries

saas_usage_monthly_summaries
```

---

# Usage Event Model

Mọi usage đều bắt đầu từ Event.

---

# Example

```text
Video Uploaded
```

↓

Usage Event

↓

Storage Updated

---

```text
AI Request
```

↓

Usage Event

↓

Token Usage Updated

---

# saas_usage_logs

Là bảng ghi nhận usage gốc.

---

# Responsibilities

Lưu:

* tenant
* loại usage
* giá trị usage
* timestamp

---

# Suggested Fields

```text
id

customer_id

usage_type

resource_type

resource_id

quantity

unit

metadata

created_at
```

---

# Core Principle

Không cập nhật trực tiếp Billing.

Usage chỉ ghi nhận sự kiện.

---

# Usage Types

## Storage Usage

Theo dõi dung lượng lưu trữ.

---

# Sources

```text
Video

Audio

Document

Images

Recordings
```

---

# Metrics

```text
Total Storage

Storage Growth

Storage By Type
```

---

# Example

```text
Video Upload

500 MB

↓

Storage Usage +500 MB
```

---

# Bandwidth Usage

Theo dõi dữ liệu truyền tải.

---

# Sources

```text
Video Streaming

Audio Streaming

Document Download

Replay Viewing
```

---

# Metrics

```text
Total Bandwidth

Bandwidth Per User

Bandwidth Per Course Product
```

---

# Example

```text
Student Watch Video

2 GB Streaming

↓

Bandwidth +2 GB
```

---

# User Usage

Theo dõi người dùng hoạt động.

---

# Metrics

```text
Total Users

Active Users

Monthly Active Users

Daily Active Users
```

---

# Examples

```text
Teachers

Students

Admins
```

---

# Learning Usage

Đo lường hoạt động học tập.

---

# Metrics

```text
Course Products

Enrollments

Template Lessons Completed

Assessments Taken

Live Classes Attended
```

---

# Examples

```text
Course Access

Lesson Completion

Quiz Submission
```

---

# Media Usage

Đo lường việc sử dụng Media Domain.

---

# Metrics

```text
Video Watch Time

Audio Listen Time

Document Views

Replay Views
```

---

# Sources

```text
Media Domain

Track Domain
```

---

# AI Usage

Đây là nhóm usage quan trọng nhất.

---

# Why

AI là:

* tính năng
* đồng thời là chi phí

---

# Metrics

```text
AI Requests

Input Tokens

Output Tokens

Embeddings

Estimated Cost
```

---

# Example

```text
AI Tutor Question

↓

Input Tokens

↓

Output Tokens

↓

Cost Calculation
```

---

# AI Usage Fields

```text
provider

model

input_tokens

output_tokens

total_tokens

estimated_cost
```

---

# Aggregation Architecture

Không đọc trực tiếp Usage Logs để làm báo cáo.

---

# Flow

```text
Usage Logs

↓

Daily Summary

↓

Monthly Summary

↓

Billing
```

---

# Daily Summaries

Database:

```text
saas_usage_daily_summaries
```

---

# Purpose

Tổng hợp usage theo ngày.

---

# Example

```text
2026-06-10

Storage

500 GB

AI Tokens

2,000,000

Bandwidth

100 GB
```

---

# Monthly Summaries

Database:

```text
saas_usage_monthly_summaries
```

---

# Purpose

Tổng hợp usage theo tháng.

---

# Example

```text
June 2026

Storage

700 GB

Bandwidth

1.5 TB

AI Tokens

25M
```

---

# Usage And Entitlement

Usage là Source Of Truth cho lượng đã dùng. Commercial Entitlement là Source
Of Truth cho lượng được phép dùng.

---

# Example

```text
Commercial Entitlement

100 GB Storage
```

---

```text
Current Usage

82 GB
```

---

```text
Remaining

18 GB
```

---

# Used Versus Allowed Flow

```text
Commercial Entitlement

+

Usage Measurement

↓

Used Versus Allowed Evaluation

↓

Commercial Entitlement Decision
```

Consumer thực thi decision bằng cách đọc Entitlement. Usage không tự quyết định
“Can Use?” và không update Entitlement.

---

# Usage And Billing

Usage là đầu vào của Billing Engine.

---

# Examples

```text
Storage

Bandwidth

AI Tokens

Active Students
```

---

# Usage And Analytics

Usage giúp LearnForge hiểu:

```text
Growth

Adoption

Engagement

Cost Drivers
```

---

# Enterprise Reporting

Future Feature

---

# Examples

```text
Storage Trend

AI Trend

Learning Activity Trend

Cost Trend
```

---

# Usage And AI

AI cũng cần Usage.

---

# Examples

AI có thể phân tích:

```text
Top Courses

Inactive Users

Cost Drivers

Adoption Trends
```

---

# Event Sources

Usage nhận dữ liệu từ:

---

# Course Domain

```text
Enrollments

Course Access
```

---

# Assessment Domain

```text
Quiz Attempts

Submissions
```

---

# Media Domain

```text
Uploads

Storage

Streaming
```

---

# LiveClass Domain

```text
Attendance

Replay Views
```

---

# AI Domain

```text
Token Usage

Embeddings

AI Requests
```

---

# Security Rules

## Rule 1

Mọi Usage phải thuộc:

```text
customer_id
```

---

## Rule 2

Không chia sẻ usage giữa tenants.

---

## Rule 3

Usage Logs là immutable.

---

## Rule 4

Summaries được sinh từ Logs.

---

# Design Rules

## Rule 1

Usage chỉ ghi nhận sự kiện.

---

## Rule 2

Usage không chứa business logic.

---

## Rule 3

Usage không chứa billing logic.

---

## Rule 4

Usage phải audit-friendly.

---

## Rule 5

AI Usage phải được track chi tiết.

---

# Current Scope

Version 1

```text
Storage Usage

Bandwidth Usage

User Usage

Learning Usage

Media Usage

AI Usage
```

---

# Planned Scope

```text
Cost Allocation

Chargeback

Forecasting

Enterprise Reporting
```

---

# Relationship With Other Domains

```text
Course Product + Enrollment

↓

Course Template

↓

Assessment

↓

Media

↓

LiveClass

↓

AI

↓

Commercial Entitlement

+

Usage

↓

Billing
```

---

# Final Statement

Usage Domain là hệ thống đo lường của LearnForge.

Nó cho phép nền tảng biết chính xác:

* khách hàng sử dụng gì
* sử dụng bao nhiêu
* tốn bao nhiêu chi phí

và là measurement input bắt buộc cho:

* Entitlement comparison.
* Billing
* Capacity Planning
* Enterprise Analytics

trong kiến trúc SaaS của LearnForge.

---

End of LF-SaaS-Usage
