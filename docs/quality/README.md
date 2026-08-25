# LearnForge Quality

Version: 1.14

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-24

Document Path: quality/README.md

---

# Purpose

Thư mục `quality/` là khu vực Quality, QA và Regression của LearnForge.

Quality không phải Governance và không định nghĩa Architecture. Quality kiểm
tra implementation còn tuân thủ Architecture, Guardrails và acceptance
criteria hay không.

---

# Current Document

* [LF-Regression-Audit.md](LF-Regression-Audit.md) — checklist bắt buộc cho mọi
  `Existing-Feature Change`; canonical Audit Level là `LOW`, `MEDIUM`, `HIGH`
  và độ sâu kiểm chứng theo mức cao nhất áp dụng.
* [LF-Documentation-Conflicts.md](LF-Documentation-Conflicts.md) — canonical
  register cho inconsistency đã xác minh; kiểm tra register và dừng affected
  concern khi hai official sources không thể đồng thời được thỏa mãn.
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
* [LF-Course-Template-Learning-Mapping-Intent-Architecture-Review.md](LF-Course-Template-Learning-Mapping-Intent-Architecture-Review.md)
  — Course Template Learning Mapping Intent contract review; PASS with Owner
  approval pending, no migration authorized.
* [LF-Media-Processing-Substrate-Architecture-Review.md](LF-Media-Processing-Substrate-Architecture-Review.md)
  — approved Media Processing substrate contract review; PASS with documented
  risks and scoped implementation authorization.
* [LF-Media-Read-Contract-Architecture-Review.md](LF-Media-Read-Contract-Architecture-Review.md)
  — owner-context, revision, citation, signed-delivery and append-only audit
  self-assessment packet; independent architecture review pending.
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
* [LF-Learning-Foundation-Database-Architecture-Review.md](LF-Learning-Foundation-Database-Architecture-Review.md)
  — approved Phase 3 Database/Architecture Review for the ten Learning
  Foundation physical contracts; Foundation is frozen and migration remains a
  separate authorization.
* [LF-Learning-Foundation-Phase-4C-Trigger-Specification.md](LF-Learning-Foundation-Phase-4C-Trigger-Specification.md)
  — review draft defining the 24-trigger semantics, error catalog, JSON paths
  and negative-test obligations before combined Phase 4B/4C authorization.
* [LF-Learning-Foundation-Phase-4C-Trigger-Static-Review.md](LF-Learning-Foundation-Phase-4C-Trigger-Static-Review.md)
  — static remediation passed, but disposable rehearsal is BLOCKED by the
  candidate `JSON_TABLE` dependency conflicting with the MariaDB 10.5 floor.
* [LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md](LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md)
  — Phase 4E design/readiness review for immutable Teacher Judgment source,
  default-deny authorization and end-to-end Learning projection preparation.
* [LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md](LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md)
  — HIGH documentation review for four released Course composite parent keys
  required by tenant-safe Teacher Judgment source foreign keys.
* [LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md](LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md)
  — Gate 1 independent runtime/migration code review; PASS after four passes.
  The external Framework authoring surface passed Gate 2 on 2026-08-23 through
  recorded MariaDB HTTP/service evidence and Owner attestation.
* [LF-Schema-Drift-Trigger-Identity-Regression-Audit.md](LF-Schema-Drift-Trigger-Identity-Regression-Audit.md)
  — HIGH Existing-Feature Change audit for opt-in trigger identity enforcement
  in the shared schema-drift quality gate.

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
