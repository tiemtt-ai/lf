# Table: media_extracted_texts

Version: 1.3

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-24

Document Path: database/media/media_extracted_texts.md

Related ADR:

* [ADR-0004 — Media Foundation](../../adr/ADR-0004-Media-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](../../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved

Related Specification:
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)

Version 1.1 áp dụng policy ADR-0018 đã Approved; schema/table implemented không
thay đổi, không có migration hoặc backfill đi kèm.

Version 1.2 áp dụng [ADR-0019](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)
đã Approved: `locator_type` mở thêm `sheet`. **Cần migration** (ALTER CHECK) và
một `processing_version` mới cho spreadsheet; xem Design Notes.

Version 1.3 đóng [DOC-CONFLICT-0017](../../quality/LF-Documentation-Conflicts.md)
theo phương án (a) Owner chọn ngày 2026-08-25: `extraction_method` mở thêm
`spreadsheet_cells`. Đọc cell trực tiếp từ OOXML không phải "lớp text của một
PDF", và gọi nó là `embedded_text` xoá mất đúng phân biệt mà
[media_extracted_tables](media_extracted_tables.md) được thiết kế để giữ.

Implementation Status là `Partial` chứ không phải `Implemented`: bảng đã tồn tại
trong database, nhưng **hai CHECK của Version 1.2–1.3 chưa được migrate** —
`locator_type` vẫn là `page`, `extraction_method` vẫn là hai giá trị. Cả hai đi
chung **một** migration và **một** `processing_version` mới cho spreadsheet; tách
làm hai lần migrate cùng bảng là tự tạo thêm một thế hệ revision không cần thiết.
Trạng thái trở lại `Implemented` khi migration đó được apply.

## Purpose

Lưu text trích xuất từ Media File dạng document (OCR hoặc text layer sẵn có),
theo **từng đơn vị trích dẫn** — mặc định là trang.

Bảng này tồn tại riêng thay vì mở rộng `media_transcripts` vì hai output trả lời
hai câu hỏi khác nhau và neo vào hai trục khác nhau: transcript neo vào **thời
gian**, extracted text neo vào **vị trí trong tài liệu**. Gộp chúng sẽ tạo một
bảng mà một nửa số cột luôn NULL, và buộc consumer phải đoán loại locator.

## Relationships

```text
media_files 1 → N media_extracted_texts   (nhiều locale, nhiều trang)
media_processing_jobs 1 → 0..1 media_extracted_texts
```

## Business Rules

* Extracted text và Media File phải cùng tenant.
* Chỉ áp dụng cho Media File có `file_type = document`.
* Text phải nằm trong cột `text`; **không** nhét vào `metadata`.
* Một row là **một đơn vị trích dẫn**, không phải toàn bộ tài liệu. Tài liệu 40
  trang sinh 40 row cho mỗi locale.
* Allowed `status`: `pending`, `processing`, `ready`, `failed`, `archived` —
  cùng vocabulary với transcript, caption và Media File.
* `confidence_score` từ `0.00` đến `100.00`, chỉ có khi provider báo cáo; text
  layer có sẵn trong PDF không có confidence và để NULL.
* Chỉ row `ready` được Media Read Service trả cho consumer.
* Extracted text là Digital Asset output. AI Domain tự quyết định dùng thế nào;
  Media không diễn giải nội dung và không tạo business state từ nó.
* Theo ADR-0018, text có PII vẫn là output hợp lệ; PII presence
  không đổi status thành `failed`/`cancelled` và không cấp thêm quyền đọc.
* Không đánh dấu row này là redacted nếu source/output chưa qua quy trình
  redaction. Redacted derivative phải có source fingerprint, processing version
  và provenance riêng; không sửa hoặc ghi đè source gốc.
* Retention/deletion và access audit phải liên kết row này với source, derivative,
  crop asset và AI-derived output tương ứng. Quy tắc này chưa thêm field/schema;
  implementation shape cần review riêng.

### Locator

* `locator_type` và `locator_value` theo hợp đồng locator chung tại
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md).
* Với document, `locator_type = 'page'` và `locator_value` là số trang bắt đầu
  từ 1, lưu dạng text thập phân.
* Với spreadsheet, `locator_type = 'sheet'` và `locator_value` là chỉ số sheet
  theo thứ tự workbook, bắt đầu từ 1. Sheet **không** phải trang; dùng `page` cho
  sheet là cách gán ghép mà ADR-0019 § D1 sửa lại.
* Locator là thứ AI trích dẫn khi đề xuất Node hoặc Mapping theo ADR-0017. Nó
  phải ổn định trong suốt vòng đời của một `source_fingerprint`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File nguồn. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra row này. |
| locale | VARCHAR(20) NOT NULL | Locale của text. |
| locator_type | VARCHAR(20) NOT NULL | `page` cho document, `sheet` cho spreadsheet. |
| locator_value | VARCHAR(50) NOT NULL | Số trang hoặc chỉ số sheet, dạng text thập phân. |
| sequence | INT UNSIGNED NOT NULL | Thứ tự đọc trong tài liệu, bắt đầu từ 1. |
| text | LONGTEXT NULL | Nội dung trích xuất của đơn vị này. |
| char_count | INT UNSIGNED NULL | Độ dài text, phục vụ chunking và đo lường. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100 khi provider báo cáo. |
| extraction_method | VARCHAR(50) NOT NULL | `ocr`, `embedded_text` hoặc `spreadsheet_cells`. |
| provider | VARCHAR(100) NULL | OCR provider; NULL khi dùng text layer sẵn có. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/model/cấu hình. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi trích xuất. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái output. |
| metadata | JSON NULL | Bounding box, layout, provenance; **không** chứa text. |
| created_at | TIMESTAMP(6) NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP(6) NULL | Thời điểm cập nhật. |

## Constraints And Indexes

```sql
UNIQUE (customer_id, media_file_id, locale, locator_type, locator_value,
        processing_version);
UNIQUE (id, customer_id);
INDEX  (customer_id, media_file_id, locale, sequence);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);
INDEX  (customer_id, processing_job_id);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (locator_type IN ('page','sheet'));
CHECK (extraction_method IN ('ocr','embedded_text','spreadsheet_cells'));
CHECK (sequence >= 1);
CHECK (confidence_score IS NULL
       OR (confidence_score >= 0 AND confidence_score <= 100));
CHECK (extraction_method <> 'ocr' OR provider IS NOT NULL);
CHECK (status <> 'ready' OR text IS NOT NULL);
```

Unique key gồm `processing_version`: chạy lại bằng extractor mới **không** ghi
đè kết quả cũ mà tạo bộ row mới. Bản cũ chuyển `archived` theo quy tắc stale
trong Processing Contract. Đây là điều kiện để một Proposal AI đã trích dẫn
trang 12 vẫn còn trang 12 mà nó đã đọc.

`CHECK (locator_type IN ('page','sheet'))` mở đúng hai giá trị đã được ADR-0019
duyệt. Mở rộng sang `section` hay `slide` vẫn là amendment có review, không phải
một giá trị lọt vào lúc code. `region` **không** thuộc bảng này: nó sống ở
[media_extracted_regions](media_extracted_regions.md).

Chuyển spreadsheet từ `page` sang `sheet` là **thay đổi có phá vỡ**. Nó không
được backfill tại chỗ: revision cũ giữ nguyên `page` và chuyển `archived`, còn
lần chạy mới sinh revision `sheet` dưới một `processing_version` mới. Sửa
`locator_value` của row cũ sẽ làm mọi citation đã phát ra trỏ sai.

## Sample Data

```text
id=700, customer_id=1, media_file_id=100, processing_job_id=400, locale=vi,
locator_type=page, locator_value=12, sequence=12,
text='Chương 3 — Cấu trúc câu…', char_count=1842, confidence_score=97.40,
extraction_method=ocr, provider=internal_ocr,
processing_version=tesseract-5.3.0, source_fingerprint=9f2c…, status=ready
```

## Design Notes

Không có cột "toàn văn tài liệu". Ghép các row theo `sequence` là việc của
consumer, và việc đó rẻ; ngược lại, tách một blob toàn văn thành trang để trích
dẫn thì không làm được sau khi đã mất ranh giới trang.

`char_count` là cột thật vì AI chunking đọc nó liên tục; tính lại từ `LONGTEXT`
mỗi lần truy vấn là chi phí không cần thiết trên đường nóng.
