# Table: media_table_cells

Version: 1.2

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-31

Document Path: database/media/media_table_cells.md

Related ADR:

* [ADR-0004 — Media Foundation](../../adr/ADR-0004-Media-Foundation.md)
* [ADR-0018 — Media PII And External Processing Boundary](../../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved
* [ADR-0019 — Media Structured Extraction Boundary](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Approved

Related Specification:
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md),
[LF-Media-Read-Contract](../../platform/LF-Media-Read-Contract.md)

## Purpose

Lưu từng **ô** của một bảng đã trích xuất: vị trí trong lưới, phạm vi gộp, và
text của ô.

Đây là mức chi tiết duy nhất cho phép consumer trả lời "giá trị ở hàng 3, cột
Đơn giá là bao nhiêu" mà không phải phân tích lại một khối text đã bị làm phẳng.

## Relationships

`Extracted Table 1 → N Table Cells`.

Cell **không** tham chiếu trực tiếp tới Media File hay region: nó thừa kế toàn bộ
neo trích dẫn từ bảng cha. Một cell được trích dẫn bằng locator của bảng cộng với
`(row_index, column_index)`.

## Business Rules

* Mọi row tenant-scoped; cell luôn thuộc đúng một bảng trong cùng tenant.
* `row_index` và `column_index` đánh số **từ 1**, theo lưới quan sát được, không
  theo thứ tự xuất hiện trong file nguồn. Với spreadsheet, cột `A` là 1.
* Ô trống **không** cần row. Lưới thưa là hợp lệ; kích thước thật nằm ở
  `row_count`/`column_count` của bảng cha.
* Ô gộp chỉ sinh **một** row, tại ô trên-trái của vùng gộp, với `row_span` và
  `column_span` > 1. Không nhân bản giá trị ra các ô bị che.
* `is_header` là quan sát hình dạng, không phải khẳng định ngữ nghĩa của cột.
* `text` là chuỗi đọc được của ô. Với spreadsheet, đó là **giá trị hiển thị**,
  không phải công thức; chuỗi công thức nằm ngoài phạm vi Version 1.0 theo
  ADR-0019 § D5.
* Cell có PII vẫn là output hợp lệ theo ADR-0018.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| extracted_table_id | BIGINT UNSIGNED NOT NULL | Bảng cha. |
| row_index | INT UNSIGNED NOT NULL | Hàng trong lưới, ≥ 1. |
| column_index | INT UNSIGNED NOT NULL | Cột trong lưới, ≥ 1. |
| row_span | INT UNSIGNED NOT NULL DEFAULT 1 | Số hàng ô này chiếm. |
| column_span | INT UNSIGNED NOT NULL DEFAULT 1 | Số cột ô này chiếm. |
| is_header | TINYINT(1) NOT NULL DEFAULT 0 | Ô thuộc hàng/cột tiêu đề. |
| text | LONGTEXT NULL | Nội dung đọc được của ô. |
| char_count | INT UNSIGNED NULL | Độ dài text. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100 khi extractor báo cáo. |
| metadata | JSON NULL | Provenance bổ sung; **không** chứa text. |
| created_at | TIMESTAMP(6) NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP(6) NULL | Thời điểm cập nhật. |

Cell **không** có `status`, `processing_version` hay `source_fingerprint`. Chúng
thuộc bảng cha, và một cell không thể `archived` độc lập với bảng chứa nó.
Revision cũ được archive ở mức bảng; cell theo bảng cha qua khóa ngoại.

## Constraints And Indexes

```sql
UNIQUE (customer_id, extracted_table_id, row_index, column_index);
UNIQUE (id, customer_id);
INDEX  (customer_id, extracted_table_id, row_index);
INDEX  (customer_id, extracted_table_id, is_header);

FOREIGN KEY (extracted_table_id, customer_id)
    REFERENCES media_extracted_tables (id, customer_id) CASCADE;

CHECK (row_index >= 1);
CHECK (column_index >= 1);
CHECK (row_span >= 1);
CHECK (column_span >= 1);
CHECK (is_header IN (0,1));
CHECK (confidence_score IS NULL
       OR (confidence_score >= 0 AND confidence_score <= 100));
```

`UNIQUE (customer_id, extracted_table_id, row_index, column_index)` là thứ khiến
một ô không thể bị ghi hai lần với hai giá trị khác nhau. Không có nó, một
extractor sinh trùng ô sẽ tạo ra một bảng đọc ra kết quả khác nhau giữa hai lần
query.

`CASCADE` ở đây là **ngoại lệ có chủ ý** so với `RESTRICT` dùng ở mọi khóa ngoại
khác của miền Media. Lý do: cell là owned child, không có identity độc lập với
bảng cha, và ADR-0018 buộc retention/deletion phải phủ toàn bộ provenance chain.
`CASCADE` khiến một lần purge revision là **nguyên tử**; `RESTRICT` sẽ chặn xoá
parent cho tới khi hàng trăm nghìn cell được xoá tay trước, biến một nghĩa vụ
xoá theo policy thành một quy trình nhiều bước dễ bỏ dở giữa chừng.

Nói cho chính xác: `RESTRICT` **không** tạo ra orphan — nó ngăn xoá parent. Vấn
đề của nó ở đây là làm việc xoá theo retention không còn nguyên tử, không phải
rò rỉ dữ liệu mồ côi.

## Sample Data

```text
id=51000, customer_id=1, extracted_table_id=400, row_index=1, column_index=1,
row_span=1, column_span=1, is_header=1, text=Học phần, char_count=8
```

```text
id=51004, customer_id=1, extracted_table_id=400, row_index=2, column_index=1,
row_span=2, column_span=1, is_header=0, text=Nhập môn, char_count=8
```

## Readiness invariants

Các điều kiện dưới đây xuyên `media_table_cells` và `media_extracted_tables`, nên
không biểu diễn được bằng CHECK. Chúng thuộc hợp đồng validation của
[media_extracted_tables](media_extracted_tables.md) § Readiness invariants và
phải có test database chứng minh:

* `row_index + row_span - 1 <= table.row_count`;
* `column_index + column_span - 1 <= table.column_count`;
* không hai vùng gộp nào chồng lên nhau.

## Design Notes

**Đây là bảng có rủi ro tăng trưởng lớn nhất của miền Media.** Dung lượng không
tăng theo số trang mà theo số ô: một workbook 50 sheet × 1200 hàng × 8 cột sinh
ra 480.000 row từ một file vài MB. `max_extracted_characters` hiện hành không
chặn được điều đó vì nó đếm ký tự, không đếm row.

Owner đã freeze ngày 2026-08-25:
`max_table_cells_per_document = 200000`, đếm **row cell thực persist** — merged
cell chỉ tính một row. Vượt: `structured_extraction_too_large`, fail toàn
revision, không truncate.

Text của cell tính vào cùng ngân sách `max_extracted_characters = 500000` với text
cấp trang và cấp region. Đây là ràng buộc chặt hơn trần cell trong nhiều trường
hợp: một workbook 199.000 cell × 20 ký tự vẫn dưới trần cell nhưng vượt xa ngân
sách ký tự.

Con số 200.000 không suy ra được từ một giới hạn đã freeze khác — nó được chọn
cùng bậc độ lớn với ngân sách 500.000 ký tự, và **phải được xem lại khi có workbook
thật đầu tiên**.

Ô gộp chỉ sinh một row là lựa chọn giữ **đúng thứ có trên tài liệu**. Nhân bản giá
trị ra các ô bị che sẽ dễ đọc hơn cho consumer đơn giản, nhưng tạo ra dữ liệu
không tồn tại và làm hỏng phép đếm ô.
---

## D1–D6 amendment — Approved 2026-08-31

Owner approval trong task Document Processing. D1 CHECK is_header IN (0,1); D2 giữ tọa độ và merged spans, không mở rộng area thành các row giả.

Migration forward mới sau review; preflight báo count và IDs vi phạm rồi abort, không tự fill/delete. Approval thiết kế không phải evidence schema đã deployed.
