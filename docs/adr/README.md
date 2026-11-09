# LearnForge Architecture Decision Records

Version: 1.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-01

Document Path: adr/README.md

---

# Purpose

Architecture Decision Record (ADR) ghi lại quyết định kiến trúc đã được review
và approved, gồm context, decision, consequences và result.

ADR không phải:

* Review report.
* Status file.
* Verification report.
* Danh sách task implementation.

---

# Naming

Mỗi ADR dùng số tuần tự bốn chữ số:

```text
ADR-000x-<Decision-Name>.md
```

Ví dụ:

```text
ADR-0004-Media-Foundation.md
```

Số ADR không được tái sử dụng hoặc đổi để lấp khoảng trống.

---

# Current ADRs

| ADR | Decision | Status |
| --- | --- | --- |
| [ADR-0001](ADR-0001-Course-Foundation.md) | Course Foundation | Approved |
| [ADR-0002](ADR-0002-LiveClass-Foundation.md) | LiveClass Foundation | Approved |
| [ADR-0003](ADR-0003-Assessment-Foundation.md) | Assessment Foundation | Approved |
| [ADR-0004](ADR-0004-Media-Foundation.md) | Media Foundation | Approved |
| [ADR-0005](ADR-0005-Track-Foundation.md) | Track Foundation | Frozen |
| [ADR-0006](ADR-0006-AI-Foundation.md) | AI Foundation | Frozen |
| [ADR-0007](ADR-0007-SaaS-Tenant-Foundation.md) | SaaS Tenant Foundation | Frozen |
| [ADR-0008](ADR-0008-SaaS-Commercial-Foundation.md) | SaaS Commercial Foundation | Frozen |
| [ADR-0009](ADR-0009-SaaS-Usage-Foundation.md) | SaaS Usage Foundation | Frozen |
| [ADR-0010](ADR-0010-SaaS-Billing-Foundation.md) | SaaS Billing Foundation | Frozen |
| [ADR-0011](ADR-0011-Certificate-Foundation.md) | Certificate Foundation | Frozen |
| [ADR-0012](ADR-0012-Course-Template-Published-Version-Snapshot.md) | Course Template Published Version Snapshot | Approved |
| [ADR-0013](ADR-0013-Course-Template-Version-Duplicate-to-Draft.md) | Course Template Version Duplicate to Draft | Approved |
| [ADR-0014](ADR-0014-Product-Offering-And-Draft-Binding.md) | Product Offering and Draft Content Binding | Approved |
| [ADR-0015](ADR-0015-Course-Lesson-Multiple-Prerequisites.md) | Course Lesson Multiple Prerequisites | Approved |
| [ADR-0016](ADR-0016-Learning-Foundation.md) | Learning Foundation Version 1.1 amendment | Frozen |
| [ADR-0017](ADR-0017-AI-Assisted-Learning-Authoring.md) | AI-Assisted Learning Authoring: Proposal, human review, exact-version Mapping | Approved |
| [ADR-0018](ADR-0018-Media-PII-And-External-Processing-Boundary.md) | Media PII classification, redacted derivative và external-processing boundary | Approved |
| [ADR-0019](ADR-0019-Media-Structured-Extraction-Boundary.md) | Locator vocabulary cho region/table/sheet, chỗ chứa structured extraction, ranh giới Media quan sát vs AI diễn giải | Approved |
| [ADR-0020](ADR-0020-AI-Vision-Interpretation-Boundary.md) | Diễn giải biểu đồ/sơ đồ/ảnh: neo trích dẫn, model run bắt buộc, quota chặn trước, retention theo ADR-0018 | Approved |
| [ADR-0021](ADR-0021-Media-Sharing-Within-Tenant.md) | Chia sẻ Media trong tenant: admin thấy hết, giáo viên theo chia sẻ, chặn thu hồi khi đang dùng | Approved |
| [ADR-0022](ADR-0022-Course-Type-On-Template.md) | Loại khóa học chuyển từ Product sang Course Template; thay phần offering của ADR-0014. Amendment 1.1 (2026-11-09): chỉ còn Tự học / Theo lớp | Approved |
| [ADR-0023](ADR-0023-Cohort-Content-Order.md) | Thứ tự Lesson và Activity riêng của từng lớp, ngày thuộc về vị trí, khóa lịch đã qua, học viên thấy thứ tự của lớp | Approved |
| [ADR-0024](ADR-0024-Cohort-Owned-Content.md) | Mỗi lớp có bản nội dung riêng, tab Buổi học giống hoàn toàn tab Nội dung của Template | Archived: Owner rút 2026-11-05 |
| [ADR-0025](ADR-0025-Assessment-Simple-Administration.md) | Module Kiểm tra riêng: đề nháp + phiên bản bất biến, ngữ liệu, ghi danh từng đề thay Enrollment, chấm tay; không AI/HSA; sửa một phần ADR-0003 | Approved |
| [ADR-0026](ADR-0026-Assessment-Rubric.md) | Tiêu chí chấm (rubric) cho câu tự luận / nói: chụp khi xuất bản đề, chấm từng tiêu chí, quy đổi điểm; mở lại phần hoãn của ADR-0025 | Approved |
| [ADR-0027](ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md) | Phân công chấm (theo danh mục, theo đề, giao từng bài, nhận chấm) và nộp tệp cho câu tự luận; mở lại phần hoãn của ADR-0025 | Approved |

---

# Change Policy

Thay đổi kiến trúc lớn phải:

* Tạo ADR mới khi quyết định mới thay thế hoặc mở rộng architecture hiện hữu.
* Tạo amendment có liên kết rõ tới ADR gốc khi chỉ bổ sung phạm vi được owner
  cho phép.
* Không sửa lịch sử của quyết định đã approved theo cách làm mất context cũ.
* Cập nhật Governance, Domain docs và LF-INDEX sau khi quyết định được approved.

ADR chỉ có hiệu lực khi status và approval rõ ràng.

Metadata ADR tuân theo
[Canonical Documentation Metadata](../governance/LF-Naming-Convention.md#canonical-documentation-metadata).
ADR được giữ trường `Status:` như alias máy kiểm tra được của `Document Status:`.
Trạng thái đó không cho biết implementation đã tồn tại; mọi ADR vẫn phải có
`Implementation Status:` độc lập và dựa trên bằng chứng.

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
