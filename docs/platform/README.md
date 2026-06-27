# LearnForge Platform Domains

Version: 1.0

Status: Official Directory Guide

Last Updated: 2026-06

---

# Purpose

Thư mục `platform/` chứa tài liệu cho Platform Domains và shared capabilities
được nhiều Business Domain sử dụng.

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

| Platform Domain | Document | Status |
| --- | --- | --- |
| Media | [LF-Media](LF-Media.md) | Foundation Approved |
| Track | [LF-Track](LF-Track.md) | Planned |
| AI | [LF-AI](LF-AI.md) | Planned / Strategic Architecture |

Media foundation decision:
[ADR-0004](../adr/ADR-0004-Media-Foundation.md).

---

# Directory Rules

* Mô tả responsibility, boundary, Source Of Truth và integration contract của
  Platform Domain.
* Link tới ADR và Database docs liên quan.
* Không ghi business state của consumer thành ownership của Platform Domain.
* Không đặt table-by-table documentation hoặc quality report trong thư mục này.
