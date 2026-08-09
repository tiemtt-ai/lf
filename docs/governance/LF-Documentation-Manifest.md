# LearnForge Documentation Manifest Standard

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: governance/LF-Documentation-Manifest.md

---

# Purpose

Quy định schema và cách bảo trì
[`LF-DOCUMENTATION-MANIFEST.json`](../LF-DOCUMENTATION-MANIFEST.json), inventory
máy đọc được và metadata tìm kiếm song ngữ cho toàn bộ Markdown chính thức dưới
`docs/`.

Manifest hỗ trợ candidate discovery. Nó không thay
[`LF-INDEX.md`](../LF-INDEX.md), không tạo authority, business rule hoặc thuật
ngữ canonical mới. Agent phải bắt đầu từ LF-INDEX, đọc trực tiếp source được
chọn và kiểm tra
[`LF-Documentation-Conflicts.md`](../quality/LF-Documentation-Conflicts.md)
khi phát hiện inconsistency.

---

# Responsibility Boundary

| Component | Responsibility |
| --- | --- |
| `README.md` | Hướng dẫn sử dụng documentation system |
| `LF-INDEX.md` | Human catalog và routing source of truth |
| Directory README | Ownership và local catalog |
| `LF-DOCUMENTATION-MANIFEST.json` | Machine inventory và bilingual discovery metadata |
| `LF-Glossary.md` | Canonical terminology |
| `LF-Documentation-Conflicts.md` | Verified inconsistency register |

Keyword/synonym trong manifest chỉ phục vụ search và không trở thành canonical
term. Keyword match không chứng minh source đúng, không conflict hoặc đã được
implementation.

---

# Manifest Schema

Top-level fields:

```text
schema_version
generated_or_updated_at
root
default_locale
fallback_locale
source_of_truth
document_count
documents
```

Mỗi document record có:

```text
path
title
area
document_type
document_status
implementation_status
metadata_complete
authority
canonical_for
topics
keywords.vi
keywords.en
identifiers
related_documents
superseded_by
routing_source
```

Legacy document không đủ header metadata dùng `document_status: Unknown`,
`implementation_status: Unknown`, `metadata_complete: false`; không được bịa
giá trị lịch sử. Record sort tăng dần theo `path`, mỗi Markdown xuất hiện đúng
một lần và `document_count` bằng số record thực tế.

## Document type vocabulary

```text
index
directory_guide
governance
adr
domain_policy
database_overview
table_schema
technical_standard
business_document
implementation_rule
quality_standard
architecture_review
conflict_register
```

## Authority vocabulary

```text
routing
governance
architecture_decision
domain
database
engineering
quality
historical
```

`canonical_for` chỉ được điền khi authority đã được source/routing hiện hành
chứng minh. Tài liệu superseded vẫn giữ record, dùng `authority: historical` và
`superseded_by` trỏ tới replacement hiện hành.

---

# Bilingual Search Metadata

Mỗi record cần keyword tiếng Việt có dấu và keyword tiếng Anh liên quan trực
tiếp tới concern thực tế. Identifier kỹ thuật như table, field, route, class,
enum hoặc ADR ID nằm trong `identifiers` và không được dịch.

Không nhồi keyword chung, không sao chép một list cho toàn bộ directory, không
tách title máy móc thành keyword, và không tự động sinh lại semantic keyword từ
toàn văn. Vocabulary tìm kiếm phải được reviewer kiểm tra khi concern đổi.

Search order:

```text
User query
→ Normalize for search
→ Exact identifier/path/title
→ Canonical topic/Glossary term
→ Vietnamese/English keyword
→ Filter by area/document type
→ Related-document expansion
→ Prefer current canonical documents
→ Use LF-INDEX routing
→ Read selected sources directly
→ Check conflict register
```

Historical/superseded source chỉ được ưu tiên khi truy vấn cần context lịch sử.

---

# Related Documents

Chỉ ghi relation có bằng chứng routing thực tế: link trực tiếp giữa ADR,
Domain Policy, Database README/table docs, Quality Review hoặc replacement.
Không tạo fully connected graph theo directory. Mọi path trong
`related_documents`, `superseded_by` và `routing_source` phải tồn tại.

---

# Maintenance Workflow

Cập nhật manifest trong cùng change set khi thêm/xóa/archive/đổi path hoặc
title; đổi metadata status; đổi routing/canonical responsibility; đổi
supersession; hoặc content change làm topic/keyword không còn đúng.

Quy trình:

1. Bắt đầu từ LF-INDEX và directory catalog.
2. Cập nhật inventory/metadata/path bằng dữ liệu thực tế.
3. Curate lại keyword Việt/Anh và relations bị ảnh hưởng.
4. Không chọn source thắng khi có conflict; dùng conflict register và STOP.
5. Chạy `php artisan docs:lint` và targeted manifest tests.
6. Review coverage, duplicate, stale path, metadata và supersession.

`generated_or_updated_at` chỉ đổi khi artifact thực sự được cập nhật; không dùng
runtime timestamp khiến file thay đổi khi content không đổi.

---

# Validation Contract

`php artisan docs:lint` kiểm tra JSON/schema version, count, filesystem
coverage, missing/stale/duplicate/unsorted path, required types, bilingual
keyword arrays, empty/duplicate keyword, status vocabulary, relations,
supersession và routing paths. File Markdown mới thiếu record phải làm lint
fail.

Validator không đánh giá semantic quality của keyword, không dịch tự động,
không phân tích conflict trong văn xuôi và không gọi external service.

---

# Ownership

Owner: Architecture Team.

Manifest changes được review cùng documentation change tạo ra chúng. Glossary,
LF-INDEX và conflict register giữ nguyên ownership hiện hành.
