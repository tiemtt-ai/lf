# LearnForge Database Documentation

Version: 1.0

Status: Official Directory Guide

Last Updated: 2026-06

---

# Purpose

Thư mục `database/` chỉ chứa schema và table documentation, được tổ chức theo
Domain.

Ví dụ:

```text
database/
├── course/
├── liveclass/
├── assessment/
├── media/
├── track/
├── ai/
├── saas/
├── saas-commercial/
└── saas-usage/
```

Mỗi Domain folder mô tả tables thuộc ownership của Domain đó. Việc đặt file
trong folder không được dùng để thay đổi Domain ownership đã được Governance
và ADR xác định.

---

# File Naming

File table documentation phải trùng chính xác tên bảng:

```text
core_course_templates

↓

core_course_templates.md
```

```text
media_files

↓

media_files.md
```

Tên file dùng lowercase `snake_case` và hậu tố `.md`.

---

# Table Documentation Scope

Table documentation có thể chứa:

* Purpose và Domain ownership.
* Relationships.
* Business rules liên quan tới persistence.
* Fields và data types.
* Primary key, foreign key và indexes.
* Tenant ownership.
* Status, visibility, metadata và audit behavior.

Domain responsibility tổng quan thuộc [docs/core](../core/README.md) hoặc
[docs/platform](../platform/README.md). Quyết định kiến trúc thuộc
[docs/adr](../adr/README.md).

---

# Directory Rules

Không đặt các loại tài liệu sau trong `database/`:

* Status.
* Review report.
* Verification report.
* Temporary analysis.
* QA hoặc regression checklist.
* Architecture Decision Record.

Các artifact tạm thời phải được loại bỏ sau review hoặc chuyển tới khu vực phù
hợp. Database folder không phải archive cho quá trình review.

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
