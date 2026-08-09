# Architecture Review Checklist

Version: 1.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: governance/LF-Architecture-Review-Checklist.md

---

# Purpose

Checklist review chuẩn cho mọi Domain mới của LearnForge.

Tài liệu này không định nghĩa Architecture hoặc Database Design. Reviewer dùng
checklist để xác nhận proposal đã tuân thủ các tài liệu governance và Domain
docs liên quan.

---

# Existing-Feature Architecture Gate

Với `Existing-Feature Change`, checklist này chỉ bắt buộc khi impact analysis
cho thấy thay đổi chạm boundary kiến trúc. Trước khi approve implementation,
reviewer phải xác nhận:

- [ ] Source of truth và domain ownership không bị thay đổi ngoài chủ đích?
- [ ] Lifecycle/state transition và invariant đã được review?
- [ ] Tenant isolation, authentication và authorization boundary được bảo toàn?
- [ ] Public contract, route/API/event và backward compatibility đã được review?
- [ ] Historical data, snapshot/versioning và audit evidence được bảo toàn?
- [ ] Schema, migration, rollback và data backfill là lossless hoặc rủi ro đã
      được duyệt?

Nếu một mục chưa xác định hoặc chưa được approve, kết quả phải là `Blocked`;
không được chuyển tiếp sang implementation. Regression verification chi tiết
thuộc [LF Regression Audit](../quality/LF-Regression-Audit.md).

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | |
| Review Date | |
| Reviewer | |
| Domain Doc | |
| ADR | |
| Version | |

---

## Section A — Domain Boundary

- [ ] Domain Responsibility rõ?
- [ ] Không chồng lấn responsibility của Domain khác?
- [ ] Source Of Truth đã xác định?
- [ ] Business Boundary rõ?

Evidence / Notes:

```text

```

---

## Section B — Data Ownership

- [ ] Business data có `customer_id` hoặc tenant ownership chain hợp lệ?
- [ ] Tenant Isolation đã được review?
- [ ] Data Ownership rõ?
- [ ] Generic Reference được dùng đúng boundary?

Evidence / Notes:

```text

```

---

## Section C — Versioning

- [ ] Draft lifecycle rõ?
- [ ] Publish boundary rõ?
- [ ] Published data Immutable?
- [ ] Snapshot boundary rõ?
- [ ] Version ownership và lifecycle rõ?

Evidence / Notes:

```text

```

---

## Section D — Business Rules

- [ ] Cross-domain effect được biểu diễn bằng Evidence, Event hoặc Request?
- [ ] Không update trực tiếp business state của Domain khác?
- [ ] Read Model được dùng đúng và không thay Source Of Truth?
- [ ] Lifecycle và state transition rõ?

Evidence / Notes:

```text

```

---

## Section E — Database

- [ ] Primary Key đã review?
- [ ] Foreign Key và relationship đã review?
- [ ] Index strategy đã review?
- [ ] Metadata usage đúng?
- [ ] Status lifecycle rõ?
- [ ] Visibility tách khỏi authorization?
- [ ] Ownership và Audit fields đầy đủ?

Evidence / Notes:

```text

```

---

## Section F — Architecture

- [ ] Applicable Architecture Principles đã được ghi nhận?
- [ ] Applicable Architecture Patterns đã được ghi nhận?
- [ ] Architecture Guardrails đã được kiểm tra?
- [ ] ADR đã được review và approved?

Evidence / Notes:

```text

```

---

## Section G — Documentation

- [ ] Domain Doc hoàn chỉnh?
- [ ] Database Docs hoàn chỉnh?
- [ ] ADR hoàn chỉnh?
- [ ] LF-INDEX đã cập nhật?
- [ ] Governance references đã liên kết?

Evidence / Notes:

```text

```

---

## Section H — Ready For Code

- [ ] Migration design ready?
- [ ] Laravel implementation plan ready?
- [ ] Architecture Review passed?
- [ ] Foundation Freeze được owner xác nhận?

Evidence / Notes:

```text

```

---

# Review Result

## Score

Score:

```text
____ / 100
```

Section weights:

| Section | Weight |
| --- | ---: |
| A — Domain Boundary | 15 |
| B — Data Ownership | 15 |
| C — Versioning | 15 |
| D — Business Rules | 15 |
| E — Database | 15 |
| F — Architecture | 10 |
| G — Documentation | 10 |
| H — Ready For Code | 5 |
| **Total** | **100** |

Trong mỗi Section, chia đều weight cho các checklist item. Item không áp dụng
phải được đánh dấu `N/A`, có lý do và được reviewer chấp thuận; điểm của
Section đó được chuẩn hóa trên các item còn áp dụng.

## Classification

| Classification | Score | Required Condition |
| --- | ---: | --- |
| Foundation Ready | 90–100 | Không có Critical Failure và toàn bộ Section H đạt |
| Needs Review | 70–89 | Còn item cần làm rõ hoặc bổ sung |
| Blocked | 0–69 | Hoặc có bất kỳ Critical Failure nào |

Critical Failure:

* Domain Responsibility hoặc Source Of Truth chưa xác định.
* Có nguy cơ cross-tenant data access.
* Domain cập nhật trực tiếp business state của Domain khác.
* Vi phạm Architecture Guardrails chưa được xử lý.
* ADR chưa approved.
* Foundation Freeze chưa được owner xác nhận.

## Decision

- [ ] Foundation Ready
- [ ] Needs Review
- [ ] Blocked

Required Actions:

```text

```

Owner Approval:

```text
Name:
Date:
Decision:
```

---

# Usage

Checklist phải được hoàn tất trước:

* Migration.
* Implementation.
* Code review.

Review flow:

```text
Domain Documentation

↓

Architecture Review Checklist

↓

Owner Review

↓

Foundation Freeze

↓

Migration and Implementation
```

Checklist đã pass không thay thế ADR, Guardrails, security review hoặc
regression audit khi các tài liệu đó được yêu cầu.
