# LearnForge Platform Domains

Version: 1.0

Status: Official Directory Guide

Last Updated: 2026-06

Document Path: platform/README.md

---

# Purpose

Thư mục `platform/` chứa tài liệu cho Platform Domains, shared capabilities và
Learning Intelligence capability hiện được catalog tại đây.

Platform Domain sở hữu dữ liệu và business rules của capability đó, nhưng
không sở hữu business state của consumer Domain.

Ví dụ:

```text
Media → owns Digital Asset

Course → owns Progress and Completion
```

Media không complete Course; Track không thay đổi Assessment Result; AI không
tự thực thi business decision của consumer.

---

# Current Documents

| Capability | Document | Status |
| --- | --- | --- |
| Media | [LF-Media](LF-Media.md) | Foundation Approved |
| Track (Learning Intelligence Domain) | [LF-Track](LF-Track.md) | Foundation Approved |
| AI (Learning Intelligence & Decision Support) | [LF-AI](LF-AI.md) | Foundation Approved and Frozen |

Media foundation decision:
[ADR-0004](../adr/ADR-0004-Media-Foundation.md).

---

# Directory Rules

* Mô tả responsibility, boundary, Source Of Truth và integration contract của
  Platform Domain.
* Link tới ADR và Database docs liên quan.
* Không ghi business state của consumer thành ownership của Platform Domain.
* Không đặt table-by-table documentation hoặc quality report trong thư mục này.

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
