# LearnForge SaaS Domains

Version: 1.0

Status: Official Directory Guide

Last Updated: 2026-06

---

# Purpose

Thư mục `saas/` chứa tài liệu cho các Domain phục vụ multi-tenant và commercial
platform của LearnForge.

SaaS Domains quản lý tenant context, usage, entitlement, subscription và
billing trong boundary đã được phê duyệt.

---

# Current Documents

| SaaS Domain | Document | Responsibility |
| --- | --- | --- |
| Tenant | [LF-SaaS-Tenant](LF-SaaS-Tenant.md) | Foundation Approved and Frozen — Version 1.0; identity, domains, membership và isolation |
| Usage | [LF-SaaS-Usage](LF-SaaS-Usage.md) | Tenant resource-consumption measurement |
| Billing | [LF-SaaS-Billing](LF-SaaS-Billing.md) | Billing calculation và invoice state |
| Subscription | Planned | Plan entitlement, quota, renewal và subscription lifecycle |

---

# Directory Rules

SaaS Domain:

* Phục vụ multi-tenant hoặc commercial platform capability.
* Giữ tenant isolation và `customer_id` ownership.
* Chỉ sở hữu business state trong SaaS boundary của chính nó.

SaaS Domain không chứa hoặc quyết định:

* Course Progress.
* Course Completion.
* Learning activity logic.
* Assessment Result.
* LiveClass Attendance.

Learning Domain có thể tiêu thụ tenant context hoặc entitlement, nhưng vẫn tự
quyết định learning business state.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent

## Documentation Status

Official

Version 1.0

## Documentation Lifecycle

```text
Draft

↓

Review

↓

Approved

↓

Frozen

↓

Archived
```

## Directory Policy

This directory is part of the official LearnForge documentation.

Do not place:

* Temporary analysis.
* AI conversation output.
* Review notes.
* Generated reports.

inside this directory.

Use:

```text
docs/quality
```

or a working directory.
