# LearnForge Technology Documentation

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: tech/README.md

---

# Purpose

Thư mục `tech/` mô tả technology architecture và technical operating
environment của LearnForge.

Technology documentation mô tả:

* Runtime.
* Framework.
* Infrastructure.
* Deployment.
* Frontend architecture.
* CSS architecture.

Technology documentation không mô tả:

* Business rules.
* Domain ownership.
* Source Of Truth.
* Database ownership.

Các quyết định business và ownership phải được đọc từ Governance, ADR và
Domain docs.

---

# Current Documents

* [LF-Tech-Architecture](LF-Tech-Architecture.md).
* [LF-Tech-Stack](LF-Tech-Stack.md).
* [LF-Tech-AWS](LF-Tech-AWS.md).
* [LF-Tech-CSS](LF-Tech-CSS.md).
* [LF Admin Form And List Design Standard](LF-Admin-Form-Design-Standard.md) —
  chuẩn presentation canonical cho LF Admin Create/Edit forms và List/Index
  pages.

---

# Do

* Cập nhật technology decisions.
* Cập nhật runtime và framework compatibility.
* Cập nhật infrastructure và deployment architecture.
* Giữ technical documentation phù hợp với ADR và Guardrails.

---

# Don't

* Không đặt business rules.
* Không đặt ADR.
* Không đặt review report.
* Không đặt database docs.

---

## Owner

Architecture Team

## Primary Consumers

* Backend Developers
* Frontend Developers
* DevOps
* AI Agents

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
