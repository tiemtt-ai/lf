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
* [LF-Course-Template-Version-Snapshot-Architecture-Review.md](LF-Course-Template-Version-Snapshot-Architecture-Review.md)
  — approved architecture conformance review for the Course Template published
  snapshot documentation.
* [LF-Course-Template-Version-Duplicate-to-Draft-Architecture-Review.md](LF-Course-Template-Version-Duplicate-to-Draft-Architecture-Review.md)
  — approved architecture review for replacing the one editable Course
  Template draft from an immutable published Version.
* [LF-Course-Product-Architecture-Review.md](LF-Course-Product-Architecture-Review.md)
  — approved architecture review for Course Product CRUD documentation and
  Product-specific implementation readiness.
* [LF-Course-Product-Items-Architecture-Review.md](LF-Course-Product-Items-Architecture-Review.md)
  — approved architecture review for Course Product Item attach, list and
  remove behavior inside Product management.
* [LF-Course-Product-Relations-Architecture-Review.md](LF-Course-Product-Relations-Architecture-Review.md)
  — approved architecture review for Course Product Relation attach, list and
  remove behavior inside Product management.

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
