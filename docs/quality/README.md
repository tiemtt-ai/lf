# LearnForge Quality

Version: 1.16

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-09-08

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
* [LF-UI-Async-State-Engineering-Practice.md](LF-UI-Async-State-Engineering-Practice.md) — nguyên tắc và danh sách kiểm cho giao diện có nhiều luồng bất đồng bộ dùng chung trạng thái (2026-09-30).
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
* [LF-A0-Docling-Closure-Evidence.md](LF-A0-Docling-Closure-Evidence.md)
* [LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md](LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md)
* [LF-AI-Knowledge-Source-Role-Alignment-Review.md](LF-AI-Knowledge-Source-Role-Alignment-Review.md) — Architecture Review độc lập K3, 2026-09-28: lượt 4 **APPROVE**; migration đã apply dev 2026-09-29, DOC-CONFLICT-0040 RESOLVED.
* [LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md](LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md) — brief Architecture Review K3 (2026-09-28): vocabulary `source_role` Knowledge hẹp hơn role Media; review trước khi tạo forward migration.
* [LF-AI-Authoring-Review-UI-Design-Review.md](LF-AI-Authoring-Review-UI-Design-Review.md) — review độc lập thiết kế UI Phần 3, 2026-09-29: lượt 1–2 APPROVE WITH CHANGES, lượt 3 **APPROVE v0.4**; P3-A được phép bắt đầu sau khi Owner xác nhận (2026-09-29).
* [LF-AI-Authoring-Review-UI-Design-Reviewer-Brief.md](LF-AI-Authoring-Review-UI-Design-Reviewer-Brief.md) — brief review độc lập thiết kế UI Phần 3 (2026-09-29).
* [LF-AI-Authoring-Review-UI-P3A-Review.md](LF-AI-Authoring-Review-UI-P3A-Review.md) — review độc lập implementation P3-A: REJECT, 5 HIGH + 6 MEDIUM (2026-09-29).
* [LF-AI-Authoring-Review-UI-P3A-Review-Round2.md](LF-AI-Authoring-Review-UI-P3A-Review-Round2.md) — review độc lập implementation P3-A lượt 2: REJECT, 2 HIGH + 2 MEDIUM mới (2026-09-29).
* [LF-AI-Authoring-Review-UI-P3A-Review-Round3.md](LF-AI-Authoring-Review-UI-P3A-Review-Round3.md) — review độc lập implementation P3-A lượt 3: REJECT, 1 HIGH mới, N4 một phần (2026-09-30).
* [LF-AI-Authoring-Review-UI-P3A-Review-Round4.md](LF-AI-Authoring-Review-UI-P3A-Review-Round4.md) — review độc lập implementation P3-A lượt 4: REJECT, 1 HIGH mới, N4 một phần (2026-09-30).
* [LF-AI-Authoring-Review-UI-P3A-Review-Round5.md](LF-AI-Authoring-Review-UI-P3A-Review-Round5.md) — review độc lập implementation P3-A lượt 5: REJECT, 1 HIGH mới (S1), 2 MEDIUM (2026-09-30).
* [LF-AI-Authoring-Review-UI-P3A-Review-Round6.md](LF-AI-Authoring-Review-UI-P3A-Review-Round6.md) — review độc lập implementation P3-A lượt 6: APPROVE WITH CHANGES (2026-09-30).
* [LF-AI-Authoring-Review-UI-P3A-Reviewer-Brief.md](LF-AI-Authoring-Review-UI-P3A-Reviewer-Brief.md) — brief review độc lập implementation P3-A (2026-09-29).
* [LF-AI-Part-2-Closure-Review.md](LF-AI-Part-2-Closure-Review.md) — final independent closure review Phần 2, 2026-09-27: lượt 1 **CHANGES REQUIRED** (C1–C4), lượt 2 C2 còn mở, lượt 3 **BLOCKED** (reviewer mất tư cách độc lập); round 4 (§13, 2026-09-29): **PASS WITH DOCUMENTED RISKS — Phần 2 đóng**.
* [LF-AI-Part-2-Closure-Reviewer-Brief.md](LF-AI-Part-2-Closure-Reviewer-Brief.md) — brief final independent closure review Phần 2 (AI Knowledge, bước 0–7): 10 điều kiện đóng, trọng tâm Bước 4–7 chưa từng review độc lập, xoá Media xuyên suốt, mutation.
* [LF-AI-Provider-Execution-Gate-Implementation-Review.md](LF-AI-Provider-Execution-Gate-Implementation-Review.md)
* [LF-AI-Embedding-Qdrant-Implementation-Review.md](LF-AI-Embedding-Qdrant-Implementation-Review.md)
* [LF-AI-Embedding-Qdrant-Architecture-Review.md](LF-AI-Embedding-Qdrant-Architecture-Review.md) — review snapshot `b5da390`; không suy rộng verdict sang bản vá sau snapshot.
* [LF-AI-Embedding-Qdrant-Reviewer-Brief.md](LF-AI-Embedding-Qdrant-Reviewer-Brief.md)
* [LF-AI-Vision-Interpretation-Implementation-Review.md](LF-AI-Vision-Interpretation-Implementation-Review.md)
* [LF-AI-Authoring-Proposal-Implementation-Review.md](LF-AI-Authoring-Proposal-Implementation-Review.md) — Bước 7: lịch sử quyết định, schema/backend/HTTP, bằng chứng và giới hạn; Owner nghiệm thu backend + HTTP 2026-09-17 theo miễn trừ review độc lập, không phải independent PASS.
* [LF-AI-Knowledge-Backbone-Implementation-Record.md](LF-AI-Knowledge-Backbone-Implementation-Record.md) — xương sống Knowledge 2026-09-26: revision identity, đồng bộ Media → Knowledge, reading_order, đồng bộ tài liệu, remediation ba lượt review; review độc lập PASS WITH DOCUMENTED RISKS; **Owner chốt đóng Source/Chunk 2026-09-26**.
* [LF-AI-Knowledge-Backbone-Independent-Review.md](LF-AI-Knowledge-Backbone-Independent-Review.md) — review độc lập xương sống 2026-09-26, ba lượt: lượt 1 CHANGES REQUIRED (F1–F5), lượt 2 CHANGES REQUIRED (F6), **lượt 3 PASS WITH DOCUMENTED RISKS** (F1–F6 CLOSED; F7 LOW coverage đã xử lý sau lượt 3, chưa re-review).
* [LF-AI-Knowledge-Backbone-Reviewer-Brief.md](LF-AI-Knowledge-Backbone-Reviewer-Brief.md) — brief review độc lập xương sống Knowledge (bước 3 lộ trình): phạm vi, ràng buộc độc lập/an toàn, câu hỏi A–G, lệnh kiểm chứng, định dạng báo cáo.
* [LF-AI-Migrations-Pre-Apply-Review.md](LF-AI-Migrations-Pre-Apply-Review.md) — review độc lập bốn migration AI, hai lượt (2026-09-26/27): từng migration APPLY-READY WITH DOCUMENTED RISKS trên 10.4.21 và 11.4.12; lượt 2 đóng H2, R1, L1 sau khi gia cố M4; **toàn bộ lần apply vẫn BLOCKED** — còn B1 (trạng thái thật Foundation batch 27), H1 (engine dưới floor) và rehearsal trên dump; runbook phải giữ tài khoản DEFINER của trigger.
* [LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md](LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md) — brief review độc lập cả bốn migration AI trước khi apply lên `learnforge_db` (Owner chọn phương án a, 2026-09-26); v1.1 đính chính: Foundation cũ có thể đã apply ở batch 27 (B1), rollback `generation` chỉ từ chối khi `generation > 1`; engine đích 10.4.21 dưới floor 10.5; rehearsal trên dump do Owner giao.
* [LF-SaaS-Commercial-Usage-Packet-Reviewer-Brief.md](LF-SaaS-Commercial-Usage-Packet-Reviewer-Brief.md)
* [LF-Implicit-Timestamp-OnUpdate-Audit.md](LF-Implicit-Timestamp-OnUpdate-Audit.md)
* [LF-Audio-Processing-Final-Code-Review.md](LF-Audio-Processing-Final-Code-Review.md) — review Audio local, real offline Faster Whisper E2E và `PASS_LOCAL_AUDIO_PROCESSING`.
* [LF-Video-Transcript-Caption-Final-Code-Review.md](LF-Video-Transcript-Caption-Final-Code-Review.md) — review Video Transcript + Caption local, real FFmpeg/Faster Whisper/VTT E2E.
* [LF-Document-Processing-Final-Code-Review.md](LF-Document-Processing-Final-Code-Review.md) — review Document local, real OCR/Docling E2E và các findings còn mở.
* [LF-Media-Read-Contract-Architecture-Review.md](LF-Media-Read-Contract-Architecture-Review.md)
* [LF-Media-Structured-Extraction-Architecture-Review.md](LF-Media-Structured-Extraction-Architecture-Review.md)
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
* [LF-Learning-Gate-2-Independent-Review.md](LF-Learning-Gate-2-Independent-Review.md)
  — independent 0b re-validation of the Learning Framework authoring boundary;
  Gate 2 PASS with one documented wording risk.
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
