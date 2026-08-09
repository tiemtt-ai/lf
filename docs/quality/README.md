# LearnForge Quality

Version: 1.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: quality/README.md

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
* [LF-Course-Template-Ordering-Architecture-Review.md](LF-Course-Template-Ordering-Architecture-Review.md)
  — approved and frozen Course Template tenant/category ordering review.
* [LF-Course-Template-Activity-Estimated-Duration-Architecture-Review.md](LF-Course-Template-Activity-Estimated-Duration-Architecture-Review.md)
  — approved Course Template Activity estimated duration architecture review.
* [LF-Course-Template-Lesson-Role-Architecture-Review.md](LF-Course-Template-Lesson-Role-Architecture-Review.md)
  — approved Course Template Lesson role architecture review.
* [LF-Version-Activity-Media-Snapshot-Architecture-Review.md](LF-Version-Activity-Media-Snapshot-Architecture-Review.md)
  — approved Version Activity media snapshot architecture review.
* [LF-Course-Product-Architecture-Review.md](LF-Course-Product-Architecture-Review.md)
  — approved architecture review for Course Product CRUD documentation and
  Product-specific implementation readiness.
* [LF-Course-Product-Integrated-Architecture-Review.md](LF-Course-Product-Integrated-Architecture-Review.md)
  — approved and frozen integrated Product v2 phase-one review; supersedes
  LF-Course-Product-Items-Architecture-Review.md.
* [LF-Course-Product-Items-Architecture-Review.md](LF-Course-Product-Items-Architecture-Review.md)
  — superseded by LF-Course-Product-Integrated-Architecture-Review.md;
  retained for historical context only.
* [LF-Course-Product-Relations-Architecture-Review.md](LF-Course-Product-Relations-Architecture-Review.md)
  — approved architecture review for Course Product Relation attach, list and
  remove behavior inside Product management.
* [LF-Course-Cohort-Architecture-Review.md](LF-Course-Cohort-Architecture-Review.md)
  — approved Cohort binding, lifecycle, membership and legacy migration review.
* [LF-LiveClass-Cohort-Schedule-Architecture-Review.md](LF-LiveClass-Cohort-Schedule-Architecture-Review.md)
  — approved and frozen LiveClass recurring Cohort Schedule CRUD/Preview
  review; its deferred explicit-confirmation boundary is superseded by the
  Origin review below.
* [LF-LiveClass-Schedule-Session-Origin-Architecture-Review.md](LF-LiveClass-Schedule-Session-Origin-Architecture-Review.md)
  — approved and frozen immutable Schedule-occurrence to Session lineage,
  atomic confirmation and legacy-classification review.
* [LF-LiveClass-Cohort-Session-Architecture-Review.md](LF-LiveClass-Cohort-Session-Architecture-Review.md)
  — architecture review for Cohort-bound LiveClass Sessions.
* [LF-Course-Lesson-Multiple-Prerequisites-Architecture-Review.md](LF-Course-Lesson-Multiple-Prerequisites-Architecture-Review.md)
  — architecture review for multiple Lesson prerequisites.
* [LF-Bulk-Enrollment-Architecture-Review.md](LF-Bulk-Enrollment-Architecture-Review.md)
  — approved and frozen architecture review for Admin bulk Enrollment
  creation, re-enrollment and atomic-submission idempotency.
* [LF-Enrollment-Lifecycle-Architecture-Review.md](LF-Enrollment-Lifecycle-Architecture-Review.md)
  — approved and frozen review for single and atomic bulk Enrollment lifecycle
  transitions.

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
