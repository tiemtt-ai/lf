# LearnForge Platform Domains

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

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
| Media | [LF-Media-Processing-Contract](LF-Media-Processing-Contract.md) | Review — substrate xử lý Media |
| Media | [LF-Media-Read-Contract](LF-Media-Read-Contract.md) | Review — hợp đồng đọc output dẫn xuất cho AI consumer |
| Track (Learning Intelligence Domain) | [LF-Track](LF-Track.md) | Foundation Approved |
| AI (Learning Intelligence & Decision Support) | [LF-AI](LF-AI.md) | Foundation Approved and Frozen |
| AI Authoring Proposal | [Contract Bước 7](LF-AI-Authoring-Proposal-Contract.md) | v0.8 Frozen / Partial; schema, service và HTTP v1 đã triển khai, kiểm local với provider giả; Owner nghiệm thu backend + HTTP 2026-09-17 (miễn trừ review độc lập); UI chưa có |
| AI Knowledge Sync | [LF-AI-Knowledge-Sync-Contract](LF-AI-Knowledge-Sync-Contract.md) | v1.2 Approved / Implemented — đồng bộ Media → Knowledge; review độc lập PASS WITH DOCUMENTED RISKS; Owner chốt đóng Source/Chunk 2026-09-26; schema có trên `learnforge_db` dev local (xác minh 2026-09-27, drift sạch) |
| AI Authoring Review UI | [LF-AI-Authoring-Review-UI-Design](LF-AI-Authoring-Review-UI-Design.md) | v0.8 Approved / Partial — thiết kế UI duyệt đề xuất AI trên Hoạt động của Khoá học mẫu; P3-A, P3-B, P3-C đã làm (P3-A có review độc lập, P3-B/P3-C Owner quyết không gửi review); B5 hoãn. Xem §13.17. Lịch sử: review độc lập lượt 3 APPROVE, Owner duyệt D1–D9; P3-A được phép bắt đầu |
* [LF-AI-Authoring-P3B-Amendment.md](LF-AI-Authoring-P3B-Amendment.md) — amendment đã duyệt và kế hoạch lát cắt P3-B (Owner duyệt D12–D17, 2026-09-30).
* [LF-AI-Authoring-P3C-Amendment.md](LF-AI-Authoring-P3C-Amendment.md) — amendment đã duyệt và kế hoạch lát cắt P3-C (đề xuất kế thừa, bản nháp kế thừa, rebase; Owner duyệt D18–D27, 2026-10-01).
* [LF-AI-Authoring-Single-Entry-Amendment.md](LF-AI-Authoring-Single-Entry-Amendment.md) — amendment đã duyệt và làm (Owner duyệt D28–D31, 2026-10-05): gom đề xuất AI về tab "Đầu ra & năng lực".

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
