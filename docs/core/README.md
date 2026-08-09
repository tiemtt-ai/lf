# LearnForge Core Domains

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: core/README.md

---

# Purpose

Thư mục `core/` chứa Domain overview của LearnForge. Mỗi overview giải thích:

* Business responsibility.
* Domain boundary.
* Source Of Truth.
* Lifecycle và cross-domain integration ở mức kiến trúc.

Domain overview không chứa danh sách field hoặc table schema đầy đủ. Chi tiết
schema thuộc [docs/database](../database/README.md).

---

# Current Documents

| Domain | Overview | Related ADR | Database Docs |
| --- | --- | --- | --- |
| Auth | [LF-Core-Auth](LF-Core-Auth.md) | As documented | Domain-specific docs when available |
| User | [LF-Core-User](LF-Core-User.md) | As documented | Domain-specific docs when available |
| Course | [LF-Core-Course](LF-Core-Course.md) | [ADR-0001](../adr/ADR-0001-Course-Foundation.md) | [course/](../database/course/) |
| LiveClass | [LF-Core-LiveClass](LF-Core-LiveClass.md) | [ADR-0002](../adr/ADR-0002-LiveClass-Foundation.md) | [liveclass/](../database/liveclass/) |
| Assessment | [LF-Core-Assessment](LF-Core-Assessment.md) | [ADR-0003](../adr/ADR-0003-Assessment-Foundation.md) | [assessment/](../database/assessment/) |
| Certificate | [LF-Core-Certificate](LF-Core-Certificate.md) | [ADR-0011](../adr/ADR-0011-Certificate-Foundation.md) | [course/ Certificate tables](../database/course/) |

Media, Track và AI là Platform Domains và được hướng dẫn tại
[docs/platform](../platform/README.md).

---

# Directory Rules

* Một Domain overview tập trung vào responsibility và business architecture.
* Link tới ADR chịu trách nhiệm cho foundation decision.
* Link tới Database docs thay vì lặp lại toàn bộ fields, indexes và constraints.
* Không đặt table-by-table documentation trong thư mục này.
* Không dùng Domain overview làm review report hoặc implementation status file.

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
