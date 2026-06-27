# LearnForge Architecture Decision Records

Version: 1.0

Status: Official Directory Guide

Last Updated: 2026-06

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

---

# Change Policy

Thay đổi kiến trúc lớn phải:

* Tạo ADR mới khi quyết định mới thay thế hoặc mở rộng architecture hiện hữu.
* Tạo amendment có liên kết rõ tới ADR gốc khi chỉ bổ sung phạm vi được owner
  cho phép.
* Không sửa lịch sử của quyết định đã approved theo cách làm mất context cũ.
* Cập nhật Governance, Domain docs và LF-INDEX sau khi quyết định được approved.

ADR chỉ có hiệu lực khi status và approval rõ ràng.
