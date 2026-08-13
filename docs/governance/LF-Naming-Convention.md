# LearnForge Naming Convention

Version: 1.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-12

Document Path: governance/LF-Naming-Convention.md

---

# Purpose

Tài liệu này chuẩn hóa cách đặt tên trên toàn dự án LearnForge. Tên phải thể
hiện đúng Domain, business meaning và ownership; naming không được tạo ra
Source of Truth hoặc Domain boundary mới.

Các thuật ngữ trong tên phải tuân theo [LF-Glossary](LF-Glossary.md).

---

# General Rules

* Dùng một tên nhất quán cho cùng một khái niệm.
* Ưu tiên business meaning rõ ràng hơn viết tắt.
* Tên phải thể hiện Domain context khi đứng riêng có thể gây mơ hồ.
* Không dùng magic number hoặc tên chung chung như `data`, `info`, `object`.
* Không mã hóa business rule tạm thời vào tên nếu lifecycle có thể mở rộng.
* Tên mới phải giữ backward compatibility hoặc đi qua ADR và migration plan
  được phê duyệt.

---

# Canonical Documentation Metadata

Mọi file Markdown trong `docs/` phải khai báo metadata ở phần đầu tài liệu:

```text
Version: <existing semantic/document version>
Document Status: <Draft|Review|Approved|Frozen|Archived>
Implementation Status: <Not Implemented|Partial|Implemented|Not Applicable|Unknown>
Last Updated: YYYY-MM-DD
Document Path: <path relative to docs/>
```

ADR được phép dùng trường `Status:` thay cho `Document Status:` để bảo toàn
format lịch sử. Trong ADR, `Status:` có cùng vocabulary và ý nghĩa với
`Document Status:`; không được khai báo cả hai với giá trị khác nhau.

## Document Status

`Document Status` chỉ phản ánh lifecycle và hiệu lực của tài liệu hoặc policy:

| Value | Meaning |
| --- | --- |
| `Draft` | Đang soạn, chưa sẵn sàng để review chính thức |
| `Review` | Đang được review, chưa được phê duyệt |
| `Approved` | Đã được phê duyệt và có hiệu lực |
| `Frozen` | Đã phê duyệt và được đóng băng; thay đổi phải theo governance hiện hành |
| `Archived` | Không còn là tài liệu hiệu lực hiện tại |

Các nhãn như `Official`, `Mandatory`, `Foundation`, `Directory Guide`,
`Review Report` mô tả authority hoặc loại tài liệu, không phải lifecycle
status. Chúng phải nằm trong nội dung, title hoặc trường mô tả phù hợp; không
được ghép vào `Document Status`.

## Implementation Status

`Implementation Status` chỉ phản ánh mức độ triển khai đã được xác minh trong
source code và database:

| Value | Required meaning |
| --- | --- |
| `Not Implemented` | Đã kiểm tra phạm vi liên quan và chưa tìm thấy implementation |
| `Partial` | Có bằng chứng cho một phần, nhưng chưa đủ toàn bộ phạm vi tài liệu |
| `Implemented` | Toàn bộ phạm vi được tuyên bố đã có bằng chứng phù hợp |
| `Not Applicable` | Governance, convention, index, directory guide hoặc tài liệu không đại diện cho chức năng triển khai |
| `Unknown` | Chưa đủ bằng chứng để kết luận; không được đoán |

`Document Status` và `Implementation Status` độc lập. `Approved` hoặc `Frozen`
không chứng minh code đã được triển khai; `Not Implemented` hoặc `Partial` cũng
không làm thay đổi hiệu lực của một policy đã phê duyệt.

Muốn dùng `Implemented`, reviewer phải đối chiếu bằng chứng phù hợp với toàn bộ
phạm vi tài liệu, tối thiểu gồm source code và migration/schema khi có dữ liệu,
cùng route/controller/service và automated test khi các thành phần đó áp dụng.
Bằng chứng phải truy vết được trong tài liệu hoặc review liên quan. Nếu bằng
chứng thiếu hoặc không còn kiểm chứng được, dùng `Unknown`.

## Templates by document type

| Document type | Document Status | Implementation Status guidance |
| --- | --- | --- |
| Governance, convention, index, catalog, directory README | Lifecycle thực tế | `Not Applicable` |
| ADR | `Status:` alias canonical hoặc `Document Status:` | Theo bằng chứng; mặc định `Unknown`, không suy ra từ ADR status |
| Domain policy, database/schema, technical implementation standard | Lifecycle thực tế | Một trong năm giá trị canonical theo bằng chứng |
| Quality/architecture review | Lifecycle của chính review | Trạng thái implementation phải dựa trên evidence của review; nếu không đủ thì `Unknown` |
| Historical/superseded | `Archived` khi đã chính thức hết hiệu lực | Giữ kết luận có bằng chứng; nếu không đủ thì `Unknown` |

## Date, path and version rules

* `Last Updated` phải là ngày đầy đủ `YYYY-MM-DD` và là ngày tài liệu thực sự
  được sửa. Không suy diễn ngày lịch sử từ Git hoặc tự bổ sung ngày giả.
* Khi migration metadata sửa file trong đợt hiện tại, dùng ngày thực hiện
  migration. File chưa thể xác định hoặc chưa thể sửa phải nằm trong legacy
  allowlist có lý do rõ ràng; lint phải báo số nợ còn lại.
* `Document Path` là đường dẫn tương đối từ `docs/`, giữ đúng chữ hoa/chữ
  thường và phải trùng vị trí file thật. Ví dụ `LF-INDEX.md` và
  `governance/LF-Naming-Convention.md`.
* Chỉ thay metadata không tự động làm tăng `Version`. Chỉ tăng version theo
  policy thay đổi nội dung nghiệp vụ/kiến trúc hoặc khi owner yêu cầu.
* Tài liệu legacy phải được migrate hoặc đưa vào allowlist tạm thời với path và
  lý do chính xác. File legacy mới hoặc file ngoài allowlist phải làm lint fail.
* Tài liệu superseded phải giữ context lịch sử và liên kết tới tài liệu thay
  thế; không cơ học đổi các kết quả lịch sử thành trạng thái triển khai hiện tại.

---

# Database

## Table Names

* Dùng plural `snake_case`.
* Dùng prefix thể hiện Domain hoặc platform boundary.
* Tên bảng phải mô tả entity hoặc relationship, không mô tả màn hình.

Ví dụ:

```text
core_course_templates
core_liveclass_sessions
core_assessment_attempts
media_files
media_usages
track_events
```

Prefix chuẩn:

| Scope | Prefix |
| --- | --- |
| Course | `core_course_` |
| Certificate | `core_certificate_` |
| LiveClass | `core_liveclass_` |
| Assessment | `core_assessment_` |
| Learning | `core_learning_` |
| Media | `media_` |
| Track | `track_` |
| AI | `ai_` |
| SaaS | `saas_` |

## Primary Keys

Primary key mặc định:

```text
id
```

Kiểu dữ liệu phải theo
[LF-Data-Modeling](../LF-Data-Modeling.md). Không dùng tên như
`session_primary_key`.

## Foreign Keys

Foreign key dùng tên singular của entity đích:

```text
<entity>_id
```

Ví dụ:

```text
customer_id
template_version_id
version_activity_id
media_file_id
created_by
updated_by
```

Tên foreign key phải phản ánh đúng quan hệ. Không dùng tên mơ hồ như
`type_id`, `parent_key` hoặc `reference_id` khi entity đích đã biết rõ.

## Timestamps

Tên timestamp chuẩn:

```text
created_at
updated_at
deleted_at
```

`deleted_at` chỉ dùng khi business lifecycle cho phép soft delete. Timestamp
nghiệp vụ phải mô tả sự kiện rõ ràng:

```text
published_at
started_at
completed_at
cancelled_at
```

## Ownership and Audit

Business data tenant-scoped dùng:

```text
customer_id
```

Audit actor dùng:

```text
created_by
updated_by
```

Không thay `customer_id` bằng tên tenant tùy ý. Quan hệ ownership phải tuân
theo Tenant Boundary và Architecture Guardrails.

## Metadata

Trường mở rộng dùng:

```text
metadata
```

`metadata` không được chứa business state canonical, foreign key bắt buộc hoặc
dữ liệu cần constraint và truy vấn thường xuyên.

## Status

Lifecycle có nhiều trạng thái dùng:

```text
status
```

Giá trị phải là enum có tên rõ nghĩa. Không dùng nhiều boolean chồng chéo như
`is_draft`, `is_published`, `is_archived` cho cùng một lifecycle.

## Visibility

Khả năng hiển thị hoặc khám phá dùng:

```text
visibility
```

`visibility` không thay thế authorization, Policy hoặc tenant isolation.

---

# Enum

* Dùng lowercase `snake_case`.
* Dùng giá trị có ý nghĩa nghiệp vụ.
* Không dùng số magic.
* Không đổi nghĩa của giá trị đã phát hành.
* Giá trị mới phải tương thích với consumer hiện hữu.

Ví dụ:

```text
draft
published
archived

pending
completed
cancelled
```

Không dùng:

```text
0
1
2

inProgress
PUBLISHED
```

---

# Laravel

Class dùng `PascalCase`; method và property dùng `camelCase`. Namespace và
folder phải thể hiện Domain hoặc bounded context.

| Component | Convention | Example |
| --- | --- | --- |
| Service | `<Capability>Service` | `CoursePublishingService` |
| Repository | `<Entity>Repository` | `MediaFileRepository` |
| Policy | `<Model>Policy` | `LiveClassSessionPolicy` |
| Event | Mô tả sự kiện đã xảy ra | `AssessmentAttemptSubmitted` |
| Listener | Mô tả phản ứng với Event | `CreateEvaluationEvidence` |
| Job | Mô tả công việc có thể queue | `GenerateMediaVariant` |
| DTO | `<Purpose>DTO` | `CreateLiveClassSessionDTO` |

Repository không phải abstraction bắt buộc. Chỉ tạo khi Domain cần một data
access boundary rõ ràng và phù hợp
[LF-Development-Standards](../LF-Development-Standards.md).

Event dùng past tense. Command hoặc request dùng động từ hành động. Job phải
được đặt tên theo outcome, không theo cơ chế queue.

---

# API

## Endpoints

* Dùng resource nouns dạng plural.
* Dùng lowercase `kebab-case` cho URL segment.
* Đặt version ở prefix API khi public contract yêu cầu versioning.
* Không đưa action vào endpoint khi HTTP method đã diễn đạt action.

Ví dụ:

```text
/api/v1/live-class-sessions
/api/v1/media-files/{media_file}
```

Custom action chỉ dùng khi không thể biểu diễn hợp lý bằng resource lifecycle:

```text
POST /api/v1/course-template-versions/{version}/publish
```

## Requests and Responses

* JSON field dùng `snake_case`.
* ID dùng tên canonical như `version_activity_id`.
* Timestamp dùng ISO 8601 và timezone rõ ràng.
* Response không đổi nghĩa hoặc kiểu của field đã public.
* Error code phải ổn định và có ý nghĩa máy đọc được.

## Pagination

Collection response phân tách rõ:

```text
data
links
meta
```

Pagination input dùng tên nhất quán:

```text
page
per_page
```

Không tạo biến thể riêng cho từng endpoint nếu chưa có ADR hoặc API standard
thay thế.

---

# Files

## Markdown

* Governance và tài liệu cấp hệ thống: `LF-<Topic>.md`.
* Domain overview: `LF-<Domain>.md` theo routing hiện hành.
* Table documentation: tên file trùng chính xác tên bảng, dạng `snake_case.md`.

## ADR

```text
ADR-000x-<Decision-Name>.md
```

Số ADR tăng tuần tự, có bốn chữ số và không tái sử dụng.

## Migration

Theo Laravel:

```text
YYYY_MM_DD_HHMMSS_<action>_<table_name>.php
```

Ví dụ:

```text
2026_06_27_120000_create_media_files_table.php
```

## Seeder

```text
<Domain><Purpose>Seeder.php
```

Ví dụ:

```text
CourseFoundationSeeder.php
```

## Tests

* Test class và file kết thúc bằng `Test`.
* Feature test mô tả behavior qua boundary.
* Unit test mô tả unit hoặc business rule độc lập.

Ví dụ:

```text
tests/Feature/LiveClass/CreateSessionTest.php
tests/Unit/Assessment/ScoreCalculationTest.php
```

---

# Documentation

| Document Type | Naming Convention | Example |
| --- | --- | --- |
| ADR | `ADR-000x-<Decision-Name>.md` | `ADR-0004-Media-Foundation.md` |
| Governance | `LF-<Topic>.md` | `LF-Architecture-Patterns.md` |
| Core table | `core_<domain>_<entity>.md` | `core_liveclass_sessions.md` |
| Media table | `media_<entity>.md` | `media_files.md` |
| Track table | `track_<entity>.md` | `track_events.md` |

Heading đầu tiên phải dùng tên canonical của tài liệu. Link nội bộ dùng path
relative và label có ý nghĩa.

---

# Naming Review

Trước khi thêm tên mới:

```text
Check Glossary

↓

Confirm Owner Domain

↓

Apply Naming Convention

↓

Check Backward Compatibility
```

Nếu tên mới thay đổi Domain boundary, Source of Truth hoặc public contract,
phải tạo ADR trước khi implementation.
