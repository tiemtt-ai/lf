# LearnForge Governance

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: governance/README.md

---

# Purpose

Governance là tầng tài liệu cao nhất của LearnForge. Governance xác định
principle, pattern, constraint, terminology và direction mà ADR, Domain docs,
Database docs và implementation phải tuân thủ.

Nếu tài liệu cấp thấp xung đột Governance, áp dụng
[Architecture Guardrails](LF-Architecture-Guardrails.md) và dừng thay đổi để
review.

---

# Contents

Đọc theo thứ tự:

1. [Architecture Principles](LF-Architecture-Principles.md)
2. [Architecture Patterns](LF-Architecture-Patterns.md)
3. [Architecture Guardrails](LF-Architecture-Guardrails.md)
4. [Domain Map](LF-Domain-Map.md)
5. [Data Flow](LF-Data-Flow.md)
6. [Glossary](LF-Glossary.md)
7. [Naming Convention](LF-Naming-Convention.md)
8. [Architecture Roadmap](LF-Architecture-Roadmap.md)
9. [Architecture Review Checklist](LF-Architecture-Review-Checklist.md)

Architecture Decision Records được lưu riêng tại
[docs/adr](../adr/README.md).

---

# Directory Rules

Thư mục này chứa:

* Architecture Principles.
* Architecture Patterns.
* Architecture Guardrails.
* Domain Map và Data Flow.
* Glossary và Naming Convention.
* Architecture Roadmap.
* Architecture Review Checklist.

Thư mục này không chứa:

* Review report hoặc status report tạm thời.
* Verification output.
* QA hoặc regression checklist.
* Database schema hoặc table documentation.
* Implementation notes.

Quality và regression documentation thuộc
[docs/quality](../quality/README.md). Table documentation thuộc
[docs/database](../database/README.md).

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
