# LearnForge Quality

Version: 1.0

Status: Official Directory Guide

Last Updated: 2026-06

---

# Purpose

Thư mục `quality/` là khu vực Quality, QA và Regression của LearnForge.

Quality không phải Governance và không định nghĩa Architecture. Quality kiểm
tra implementation còn tuân thủ Architecture, Guardrails và acceptance
criteria hay không.

---

# Current Document

* [LF-Regression-Audit.md](LF-Regression-Audit.md) — checklist regression bắt
  buộc sau các thay đổi lớn được Documentation Routing Guide xác định.
* [LF-SaaS-Usage-Foundation-Review.md](LF-SaaS-Usage-Foundation-Review.md) —
  owner review artifact cho SaaS Usage Foundation In Design.

---

# Future Documents

* Release Checklist.
* Security Checklist.
* Performance Checklist.

Tài liệu Future chỉ được thêm khi scope, owner và usage đã rõ.

---

# Directory Rules

Thư mục này chứa checklist và quy trình xác minh implementation quality.

Thư mục này không:

* Định nghĩa Domain boundary.
* Thay đổi Source Of Truth.
* Tạo Architecture Principle hoặc Pattern.
* Thay thế ADR hoặc Architecture Review.
* Chứa table schema documentation.

Nếu quality review phát hiện xung đột kiến trúc, report vấn đề và quay lại
Governance hoặc ADR; không tự định nghĩa kiến trúc mới trong quality report.

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
* Unapproved review notes.
* Raw generated reports.

inside this directory.

Approved Quality, QA and Regression artifacts are allowed. Use a working
directory for temporary artifacts before review.
