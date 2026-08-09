# LearnForge Documentation

Version: 1.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: README.md

---

# Purpose

Đây là entry point để Codex, developer và reviewer sử dụng tài liệu LearnForge
đúng thứ tự và đúng phạm vi.

LearnForge documentation có các vai trò khác nhau:

| Document | Role |
| --- | --- |
| `README.md` | Hướng dẫn cách đọc, sử dụng và đặt tài liệu trong từng thư mục |
| [LF-INDEX.md](LF-INDEX.md) | Catalog liệt kê tài liệu và routing theo loại công việc |
| [LF-DOCUMENTATION-MANIFEST.json](LF-DOCUMENTATION-MANIFEST.json) | Inventory máy đọc được và metadata tìm kiếm Việt/Anh |

Đọc README để biết cách dùng hệ thống tài liệu. Dùng LF-INDEX để tìm tài liệu
cụ thể.

Manifest chỉ hỗ trợ tìm candidate theo title/topic/keyword/identifier. Luôn bắt
đầu từ LF-INDEX, áp dụng routing, đọc trực tiếp source được chọn và kiểm tra
conflict register khi có inconsistency. Keyword match không chứng minh policy
đúng hoặc implementation đã hoàn thành. Schema và maintenance rules nằm tại
[Documentation Manifest Standard](governance/LF-Documentation-Manifest.md).

---

# Reading Order

1. [Governance](governance/README.md)
2. [Architecture Decision Records](adr/README.md)
3. [Core Domains](core/README.md)
4. [Database Documentation](database/README.md)
5. [Prompt and Implementation Rules](prompts/README.md)
6. [Platform Domains](platform/README.md)
7. [SaaS Domains](saas/README.md)
8. [Quality and Regression](quality/README.md)

Không cần tải toàn bộ tài liệu cho mọi task. Sau khi hiểu Governance, dùng
[Documentation Routing Guide](LF-INDEX.md#documentation-routing-guide) để chỉ
đọc các tài liệu liên quan.

---

# Documentation Areas

| Area | Responsibility |
| --- | --- |
| `governance/` | Principles, Patterns, Guardrails và định hướng kiến trúc cấp hệ thống |
| `adr/` | Quyết định kiến trúc đã được review và approved |
| `core/` | Domain overview và business responsibility |
| `database/` | Schema và table documentation theo Domain |
| `prompts/` | Reusable CRUD, UI và database implementation rules cho AI agents |
| `platform/` | Shared Platform Domain và capability |
| `saas/` | Multi-tenant và commercial platform Domain |
| `quality/` | QA, regression và implementation conformance |
| `tech/` | Runtime, framework, infrastructure và frontend technology |
| `business/` | Product, commercial, UX và navigation context |

Tài liệu phải nằm trong khu vực đúng với vai trò của nó. Không dùng một thư
mục làm nơi lưu report tạm hoặc tài liệu không rõ ownership.

---

# Working Rules

1. Đọc [LF-INDEX.md](LF-INDEX.md) trước khi thay đổi architecture, schema hoặc
   implementation.
2. Đọc Governance trước ADR và Domain docs.
3. Chỉ load tài liệu liên quan đến task hiện tại.
4. Nếu tài liệu cấp thấp xung đột Guardrails, dừng và báo cáo.
5. Khi thêm tài liệu mới, cập nhật README của thư mục và LF-INDEX nếu tài liệu
   là một phần của catalog chính thức.

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
