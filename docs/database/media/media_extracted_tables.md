# Table: media_extracted_tables

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-27

Document Path: database/media/media_extracted_tables.md

Related ADR:

* [ADR-0004 — Media Foundation](../../adr/ADR-0004-Media-Foundation.md)
* [ADR-0018 — Media PII And External Processing Boundary](../../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved
* [ADR-0019 — Media Structured Extraction Boundary](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Approved

Related Specification:
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md),
[LF-Media-Read-Contract](../../platform/LF-Media-Read-Contract.md)

## Purpose

Định danh một **bảng** được trích xuất, cùng kích thước và neo trích dẫn của nó.
Nội dung ô nằm ở `media_table_cells`.

Hai nguồn đi vào cùng một bảng này:

| Nguồn | Neo | Ghi chú |
|---|---|---|
| Bảng trong document (PDF/DOCX) | `locator_type = 'region'` | Trỏ tới một region có `role = 'table'` |
| Worksheet của spreadsheet | `locator_type = 'sheet'` | Một sheet là một bảng; không có region |

Gộp hai nguồn là có chủ ý: consumer đọc bảng không cần biết nó đến từ PDF hay
Excel, và không cần hai đường đọc cho cùng một hình dạng dữ liệu.

## Relationships

`Media File 1 → N Extracted Tables`, scope theo `(locale, processing_version)`.

`Extracted Table 1 → N Table Cells`.

`Extracted Region 1 → 0..1 Extracted Table` khi region có `role = 'table'`.

## Business Rules

* Mọi row tenant-scoped.
* `region_id` NOT NULL khi `locator_type = 'region'`, và NULL khi
  `locator_type = 'sheet'`. Một bảng không thể vừa neo vào vùng vừa neo vào sheet.
* `row_count`/`column_count` là **kích thước lưới quan sát được**, kể cả khi có ô
  trống. Chúng không được suy ra bằng cách đếm row trong `media_table_cells`: một
  bảng có ô trống ở rìa vẫn giữ đúng kích thước.
* Media không gán ý nghĩa cho bảng. `title` là chuỗi đọc được **trên tài liệu**
  (caption, tên sheet), không phải mô tả do máy sinh ra.
* `has_header` là quan sát về hình dạng (hàng đầu được in đậm, lặp lại giữa các
  trang, hoặc sheet có hàng tiêu đề). Nó không khẳng định ý nghĩa của cột.
* Output không bao giờ bị ghi đè; đổi `source_fingerprint` hoặc
  `processing_version` sinh bộ row mới và bộ cũ chuyển `archived`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File nguồn. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra row này. |
| region_id | BIGINT UNSIGNED NULL | Region chứa bảng; NULL với sheet. |
| locale | VARCHAR(20) NOT NULL | Locale của lần trích xuất. |
| locator_type | VARCHAR(20) NOT NULL | `region` hoặc `sheet`. |
| locator_value | VARCHAR(50) NOT NULL | `<page>#<ordinal>` hoặc chỉ số sheet. |
| sequence | INT UNSIGNED NOT NULL | Thứ tự bảng trong tài liệu, ≥ 1. |
| title | TEXT NULL | Caption hoặc tên sheet đọc được trên tài liệu. |
| row_count | INT UNSIGNED NOT NULL | Số hàng của lưới, ≥ 1. |
| column_count | INT UNSIGNED NOT NULL | Số cột của lưới, ≥ 1. |
| has_header | TINYINT(1) NOT NULL DEFAULT 0 | Quan sát về hàng tiêu đề. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100 khi extractor báo cáo. |
| extraction_method | VARCHAR(50) NOT NULL | `ocr`, `embedded_text` hoặc `spreadsheet_cells`. |
| provider | VARCHAR(100) NULL | Extractor; NULL khi đọc trực tiếp cấu trúc nguồn. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/model/cấu hình. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi trích xuất. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái output. |
| metadata | JSON NULL | Provenance bổ sung; **không** chứa nội dung ô. |
| created_at | TIMESTAMP(6) NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP(6) NULL | Thời điểm cập nhật. |

## Constraints And Indexes

```sql
UNIQUE (customer_id, media_file_id, locale, locator_type, locator_value,
        processing_version);
UNIQUE (id, customer_id);
UNIQUE (customer_id, media_file_id, locale, processing_version, sequence);
INDEX  (customer_id, media_file_id, locale, sequence);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);
INDEX  (customer_id, processing_job_id);
INDEX  (customer_id, region_id);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;
FOREIGN KEY (region_id, customer_id, media_file_id, locale, processing_version)
    REFERENCES media_extracted_regions
        (id, customer_id, media_file_id, locale, processing_version) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (locator_type IN ('region','sheet'));
CHECK (extraction_method IN ('ocr','embedded_text','spreadsheet_cells'));
CHECK (row_count >= 1);
CHECK (column_count >= 1);
CHECK (sequence >= 1);
CHECK (has_header IN (0,1));
CHECK (confidence_score IS NULL
       OR (confidence_score >= 0 AND confidence_score <= 100));
CHECK ((locator_type = 'region' AND region_id IS NOT NULL)
       OR (locator_type = 'sheet' AND region_id IS NULL));
```

`CHECK` cuối là thứ giữ cho hai nguồn không lẫn vào nhau. Một row `sheet` mang
`region_id` nghĩa là extractor đã tự bịa ra hình học cho một worksheet, và không
có gì ở tầng ứng dụng bắt được lỗi đó.

Khóa ngoại tới `media_extracted_regions` phủ **cả năm** cột scope, không chỉ
tenant. Chỉ `(region_id, customer_id)` là không đủ: nó vẫn cho phép nối một bảng
của revision A vào một region của revision B, hoặc vào region của một Media File
khác, hoặc của một locale khác — tất cả đều trong cùng tenant nên FK hẹp không
bắt được. Đây là lý do `media_extracted_regions` mang thêm
`UNIQUE (id, customer_id, media_file_id, locale, processing_version)`.

Còn một điều kiện FK **không** biểu diễn được: region đích phải có
`role = 'table'`. Xem § Readiness invariants.

## Readiness invariants

Xuyên bảng, không biểu diễn được bằng CHECK hay FK. Phải kiểm trong cùng
transaction chuyển revision sang `ready`, và phải có test database chứng minh:

1. Khi `locator_type = 'region'`, region đích có `role = 'table'`.
2. `locator_value` của bảng bằng `locator_value` của region đích.
3. Số cell thật trong `media_table_cells` không vượt
   `row_count × column_count`, và mỗi cell nằm trọn trong lưới:
   `row_index + row_span - 1 <= row_count` và
   `column_index + column_span - 1 <= column_count`.
4. Các vùng gộp không chồng nhau: không ô nào nằm trong phạm vi gộp của ô khác.

Vi phạm bất kỳ điều nào là fail toàn revision, không ghi một phần rồi để `ready`.

## Sample Data

```text
id=400, customer_id=1, media_file_id=700, processing_job_id=310, region_id=900,
locale=vi, locator_type=region, locator_value=12#2, sequence=3,
title=Bảng 2.1 — Thời lượng theo học phần, row_count=5, column_count=3,
has_header=1, extraction_method=embedded_text, provider=NULL,
processing_version=local-document-v2, status=ready
```

```text
id=401, customer_id=1, media_file_id=712, processing_job_id=318, region_id=NULL,
locale=vi, locator_type=sheet, locator_value=2, sequence=2, title=Sales,
row_count=1200, column_count=8, has_header=1,
extraction_method=spreadsheet_cells, provider=NULL,
processing_version=local-document-v2, status=ready
```

## Design Notes

`extraction_method = 'spreadsheet_cells'` là giá trị mới so với
`media_extracted_texts`, nơi vocabulary chỉ có `ocr` và `embedded_text`. Một
worksheet không phải text layer của một trang render, và gọi nó là
`embedded_text` sẽ xoá mất phân biệt giữa "đọc cấu trúc nguồn" và "đọc lớp text
của một PDF".

`row_count`/`column_count` tách khỏi số cell thật là điều kiện để phát hiện mất
mát: một bảng khai 5×3 nhưng chỉ có 9 cell là dấu hiệu extractor bỏ sót, và
consumer thấy được điều đó mà không phải đoán.

Bảng trải nhiều trang (bảng dài trong PDF) **chưa được xử lý ở Version 1.0**: mỗi
phần trên mỗi trang là một region riêng nên thành một bảng riêng. Ghép chúng lại
cần một quy tắc nối có review, và đoán sai sẽ tạo ra một bảng không tồn tại trên
tài liệu.
