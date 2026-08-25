# Table: media_extracted_regions

Version: 1.0

Document Status: Draft

Implementation Status: Not Implemented

Last Updated: 2026-08-25

Document Path: database/media/media_extracted_regions.md

Related ADR:

* [ADR-0004 — Media Foundation](../../adr/ADR-0004-Media-Foundation.md)
* [ADR-0018 — Media PII And External Processing Boundary](../../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved
* [ADR-0019 — Media Structured Extraction Boundary](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Approved

Related Specification:
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md),
[LF-Media-Read-Contract](../../platform/LF-Media-Read-Contract.md)

## Purpose

Lưu **vùng** quan sát được trên một trang document: role của vùng, hình học,
thứ tự đọc và text thuộc về nó.

Bảng này tồn tại riêng thay vì thêm cột vào `media_extracted_texts` vì hai output
trả lời hai câu hỏi khác nhau. `media_extracted_texts` trả lời "trang 12 viết gì";
bảng này trả lời "trang 12 gồm những khối nào, khối nào là bảng, đọc theo thứ tự
nào". Nhồi chung sẽ khiến phần lớn cột NULL với mọi extractor không sinh layout.

Media **chỉ quan sát**. Role là hình dạng nhìn thấy được (`table`, `figure`,
`heading`), không phải ý nghĩa (`bảng doanh thu`, `sơ đồ quy trình`). Diễn giải
thuộc AI theo ADR-0019 § D4.

## Relationships

`Media File 1 → N Extracted Regions`, scope theo `(locale, processing_version)`.

`Region 1 → 0..1 Extracted Table` khi `role = 'table'`.

Region **không** tham chiếu tới `media_extracted_texts`: hai bảng là hai mức chi
tiết của cùng một lần chạy, nối với nhau bằng
`(media_file_id, locale, processing_version, page)`.

## Business Rules

* Mọi row tenant-scoped; extractor không sinh layout thì đơn giản là không sinh
  row nào — thiếu region không phải lỗi.
* `locator_type = 'region'`, `locator_value = '<page>#<ordinal>'`, cả hai ≥ 1,
  theo hợp đồng locator tại
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
  § 4 mở rộng bởi ADR-0019 § D1.
* Hình học **không** nằm trong locator. `bbox_*` lưu riêng, chuẩn hoá 0..1 theo
  kích thước trang, nên đổi DPI không làm đổi giá trị và không làm hỏng citation
  cũ.
* `ordinal` là thứ tự vùng trong **một trang**; `reading_order` là thứ tự trong
  **cả tài liệu**. Hai giá trị khác nhau và đều cần: một citation neo theo trang,
  một consumer đọc tuần tự cần trình tự toàn cục.
* Output không bao giờ bị ghi đè. Đổi `source_fingerprint` hoặc
  `processing_version` sinh bộ row mới; bộ cũ chuyển `archived` theo quy tắc
  stale trong Processing Contract.
* Text của region là text của **chính vùng đó**. Nó trùng lặp có chủ ý với text
  cấp trang; xem Design Notes.
* Region có PII vẫn là output hợp lệ theo ADR-0018; PII presence không đổi
  `status` và không cấp thêm quyền đọc.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File nguồn. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra row này. |
| locale | VARCHAR(20) NOT NULL | Locale của lần trích xuất. |
| locator_type | VARCHAR(20) NOT NULL | Luôn là `region`. |
| locator_value | VARCHAR(50) NOT NULL | `<page>#<ordinal>`, dạng text. |
| page | INT UNSIGNED NOT NULL | Số trang, ≥ 1. Denormalize để index và sắp xếp. |
| ordinal | INT UNSIGNED NOT NULL | Thứ tự vùng trong trang, ≥ 1. |
| reading_order | INT UNSIGNED NOT NULL | Thứ tự đọc trong toàn tài liệu, ≥ 1. |
| role | VARCHAR(30) NOT NULL | Hình dạng quan sát được của vùng. |
| bbox_x | DECIMAL(9,6) NULL | Cạnh trái, chuẩn hoá 0..1 theo chiều rộng trang. |
| bbox_y | DECIMAL(9,6) NULL | Cạnh trên, chuẩn hoá 0..1 theo chiều cao trang. |
| bbox_width | DECIMAL(9,6) NULL | Chiều rộng, chuẩn hoá 0..1. |
| bbox_height | DECIMAL(9,6) NULL | Chiều cao, chuẩn hoá 0..1. |
| text | LONGTEXT NULL | Text thuộc vùng này. NULL với `figure`. |
| char_count | INT UNSIGNED NULL | Độ dài text, phục vụ chunking và đo lường. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100 khi extractor báo cáo. |
| extraction_method | VARCHAR(50) NOT NULL | `ocr` hoặc `embedded_text`. |
| provider | VARCHAR(100) NULL | Extractor; NULL khi dùng text layer sẵn có. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/model/cấu hình. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi trích xuất. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái output. |
| metadata | JSON NULL | Provenance bổ sung; **không** chứa text. |
| created_at | TIMESTAMP(6) NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP(6) NULL | Thời điểm cập nhật. |

## Constraints And Indexes

```sql
UNIQUE (customer_id, media_file_id, locale, locator_type, locator_value,
        processing_version);
UNIQUE (id, customer_id);
UNIQUE (customer_id, media_file_id, locale, processing_version, reading_order);
UNIQUE (id, customer_id, media_file_id, locale, processing_version);
INDEX  (customer_id, media_file_id, locale, page, ordinal);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);
INDEX  (customer_id, processing_job_id);
INDEX  (customer_id, media_file_id, role);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (locator_type IN ('region'));
CHECK (role IN ('paragraph','heading','list','table','figure','caption',
                'header','footer','other'));
CHECK (extraction_method IN ('ocr','embedded_text'));
CHECK (page >= 1);
CHECK (ordinal >= 1);
CHECK (reading_order >= 1);
CHECK (confidence_score IS NULL
       OR (confidence_score >= 0 AND confidence_score <= 100));
CHECK (extraction_method <> 'ocr' OR provider IS NOT NULL);
CHECK (
  (bbox_x IS NULL AND bbox_y IS NULL
   AND bbox_width IS NULL AND bbox_height IS NULL)
  OR
  (bbox_x IS NOT NULL AND bbox_y IS NOT NULL
   AND bbox_width IS NOT NULL AND bbox_height IS NOT NULL
   AND bbox_x >= 0 AND bbox_y >= 0
   AND bbox_width > 0 AND bbox_height > 0
   AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1)
);
CHECK (role <> 'figure' OR text IS NULL);
```

`UNIQUE (…, reading_order)` chỉ bảo đảm **không trùng** trong mỗi revision. Nó
**không** bảo đảm dãy liên tục `1..N`: bộ giá trị `1, 2, 9` vẫn hợp lệ với
database. Tính liên tục là **readiness invariant**, xem § Readiness invariants.

`UNIQUE (id, customer_id, media_file_id, locale, processing_version)` không phục
vụ truy vấn. Nó tồn tại để `media_extracted_tables` tham chiếu ngược bằng khóa
ngoại phủ đủ scope, chứ không chỉ phủ tenant.

`CHECK` của `bbox_*` viết dạng all-or-none tường minh. Dạng `bbox_x IS NULL OR
(…)` **không** đủ: với `bbox_x` có giá trị và `bbox_y` NULL, vế sau cho `UNKNOWN`,
và SQL CHECK chỉ fail khi kết quả là FALSE — bộ toạ độ khuyết một nửa sẽ lọt qua.

Chuẩn hoá 0..1 là điều làm hình học độc lập với DPI. Đây chính là lý do ADR-0019
§ D1 giữ toạ độ **ngoài** locator.

## Readiness invariants

Các điều kiện dưới đây **không biểu diễn được bằng CHECK** vì chúng xuyên nhiều
row. Chúng phải được kiểm trong cùng transaction chuyển revision sang `ready`,
và phải có test database chứng minh, trước khi bảng này được coi là Foundation
Ready:

1. `reading_order` của một revision là dãy liên tục `1..N`, không khuyết, không
   nhảy. Khuyết một giá trị nghĩa là extractor đã bỏ sót một vùng mà không ai
   biết là bỏ sót.
2. `ordinal` trong mỗi `page` là dãy liên tục `1..M`.
3. Mỗi region có `role = 'table'` có đúng 0 hoặc 1 row trong
   `media_extracted_tables`.

Revision không thoả một trong ba điều kiện phải fail toàn phần, không được ghi
một phần rồi để `ready`.

`CHECK (locator_type IN ('region'))` cố ý chỉ mở một giá trị, giống cách
`media_extracted_texts` chỉ mở `page`. Bảng này không phải nơi chứa mọi loại
locator tương lai.

## Sample Data

```text
id=900, customer_id=1, media_file_id=700, processing_job_id=310,
locale=vi, locator_type=region, locator_value=12#2, page=12, ordinal=2,
reading_order=41, role=table, bbox_x=0.081000, bbox_y=0.412000,
bbox_width=0.838000, bbox_height=0.221000, text=NULL, char_count=NULL,
extraction_method=embedded_text, provider=NULL,
processing_version=local-document-v2, status=ready
```

## Design Notes

**Text trùng lặp là có chủ ý.** Cùng một đoạn văn xuất hiện ở text cấp trang và
ở region chứa nó. Bỏ trùng lặp bằng cách bỏ text cấp trang sẽ phá mọi citation
`page` đang tồn tại; bỏ text ở region sẽ khiến region chỉ còn là trang trí — AI
biết "có một khối ở đây" mà không đọc được khối đó. Cái giá là dung lượng, và nó
phải được tính vào giới hạn tài nguyên: cap ký tự của một revision áp cho **tổng**
text cấp trang và text region, không phải riêng từng bảng.

Giới hạn số region trên mỗi document là **quyết định Owner chưa chốt**. Đề xuất
`max_regions_per_document = 2000` (100 trang × 20 vùng), vượt thì fail toàn
revision bằng error code `structured_extraction_too_large`, không truncate. Fail
toàn phần là quy tắc đã có sẵn của `extracted_text_too_large`; truncate tạo ra
một tài liệu thiếu vùng mà không ai biết là thiếu.

Region cho spreadsheet: **không có**. Sheet không có hình học trang, nên
worksheet đi thẳng vào `media_extracted_tables` với `locator_type = 'sheet'`.
