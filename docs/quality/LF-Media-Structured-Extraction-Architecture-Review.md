# Media Structured Extraction Architecture Review

Version: 2.6

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-08-28

Review Date: 2026-08-25 (Round 1), 2026-08-26 (Round 2), 2026-08-27 (Round 3)

Document Path: quality/LF-Media-Structured-Extraction-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Media |
| Parent ADR | [ADR-0019 — Media Structured Extraction Boundary](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) **v1.1** — Amendment Approved 2026-08-25 |
| Constraining ADR | [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved |
| Producer Contract | [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) |
| Consumer Contract | [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) v1.7 |
| Review Scope | Ba bảng structured, amendment `media_extracted_texts`, job identity/checks và deterministic Media Read selector |
| Conflicts | DOC-CONFLICT-0016, 0017, 0020 và 0021 — RESOLVED |

Review provenance: **Round 2.** Round 1 (2026-08-25) là independent review của
Database Docs, phát hiện ba invariant vật lý và một xung đột ADR. Tác giả áp dụng
sửa chữa; Round 2 (2026-08-26) là independent rerun trên trạng thái đã sửa, xác
nhận ba invariant đã đóng và yêu cầu năm chỉnh sửa cho database test plan. Verdict
dưới đây là kết quả của Round 2.

Approval boundary: ADR-0019 đã Approved **không** đồng nghĩa thiết kế database đã
được review thông qua. Hai gate độc lập; ô Owner Approval của tài liệu này để
trống có chủ ý.

---

# A — Evidence đã đạt

- [x] **ADR-0019 Approved** chốt được bốn thứ trước khi có schema: locator giữ
      nguyên hình dạng chỉ mở vocabulary, structured extraction là content type
      mới chứ không phải cột mới, Read Contract không nhận đường đọc mới, và
      ranh giới Media quan sát / AI diễn giải.
- [x] **Ba Database Doc** tồn tại với cột, index, CHECK và Sample Data đầy đủ,
      theo khuôn substrate hiện hành.
- [x] **Read Contract v1.5** thêm `region`/`table` vào § 2 và § 5, thêm trường
      `structure`, và đã có ADR-0019 trong `Related ADR`.
- [x] **Tenant composite identity** trên cả ba bảng, khóa ngoại kép tới
      `media_files` và `media_processing_jobs`.
- [x] **FK scope 5 cột** giữa `media_extracted_tables` và
      `media_extracted_regions` phủ `(id, customer_id, media_file_id, locale,
      processing_version)`, đóng lỗ nối bảng revision A vào region revision B.
- [x] **`bbox` CHECK dạng all-or-none tường minh**, đóng lỗ `UNKNOWN` của dạng
      `bbox_x IS NULL OR (...)`.
- [x] **Readiness invariants** được ghi thành hợp đồng validation ở cả
      `media_extracted_regions` và `media_extracted_tables`: `reading_order` liên
      tục `1..N`, `ordinal` liên tục theo trang, region đích có `role = 'table'`,
      cell nằm trọn trong lưới, merged range không chồng nhau.
- [x] **`CASCADE` cho `media_table_cells`** có lý do đúng: owned-child lifecycle
      phục vụ purge/retention nguyên tử theo ADR-0018. Lý do sai trước đó
      ("RESTRICT gây orphan") đã được sửa — `RESTRICT` ngăn xoá parent, không tạo
      orphan.

# B — Blocker B1: xung đột ADR — **ĐÃ ĐÓNG 2026-08-25**

ADR-0019 § D2 tuyên bố cả ba bảng mang `processing_version` +
`source_fingerprint` "trên mỗi row". `media_table_cells` cố ý không có hai cột đó
và kế thừa từ bảng cha.

* Trạng thái: **[DOC-CONFLICT-0016](LF-Documentation-Conflicts.md) — RESOLVED 2026-08-25.**
* Đóng bằng: ADR-0019 Amendment Record Version 1.1, **Approved 2026-08-25**.
  Revision identity nằm ở row sở hữu; `media_table_cells` kế thừa
  `processing_version`/`source_fingerprint`/`status` từ bảng cha.
* DOC-CONFLICT-0017 (`extraction_method` hai tên) cũng đóng cùng ngày theo phương
  án (a): `media_extracted_texts` v1.3 mở vocabulary thêm `spreadsheet_cells`.

# C — Blocker B2: resource limits — **ĐÃ FREEZE 2026-08-25**

[LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md)
§ "Structured extraction resource controls" đã được Owner freeze:

| Giới hạn | Giá trị |
| --- | --- |
| `max_extracted_characters` | `500000`, tính trên **tổng** text page/sheet + region + cell |
| `max_regions_per_page` | `100` — Owner amendment 2026-08-28, dựa trên evidence PDF 100 trang có trang 15 sinh 61 region hợp lệ |
| `max_regions_per_document` | `5000` |
| `max_table_cells_per_document` | `200000`, đếm row cell thực persist; merged cell tính một row |
| `max_tables_per_document` | **Không có** — đã bị chặn bởi trần region và `max_pages` |

Error semantics và atomic readiness cũng đã freeze:
`structured_extraction_too_large`, fail toàn revision, không truncate;
region/table/cell cùng `ready` hoặc cùng không.

Hai refinement do independent review yêu cầu đã được ghi đúng: ngân sách ký tự
**bao gồm text cell** (nếu bỏ, một workbook 199.000 cell × 20 ký tự vẫn dưới trần
cell nhưng vượt xa ngân sách), và trần region freeze **cả hai mức** per-page và
per-document (chỉ có trần tổng thì một trang vẫn sinh được 5.000 vùng).

**Owner resource amendment — 2026-08-28.** Owner phê duyệt nâng riêng
`max_regions_per_page` từ `50` lên `100` sau nghiệm thu PDF tiếng Hàn 100 trang:
1.924 region toàn tài liệu, 14 bảng, 302 cell; trang 15 có 61 region và là giới
hạn duy nhất bị vượt. Quyết định giữ `max_regions_per_document = 5000`, giữ
atomic failure/no truncation và yêu cầu tăng processing version trước khi chạy
lại. Đây là approval cho resource-policy amendment, không điền thay ô Owner
Approval toàn bộ Architecture Review tại § H.

`max_table_cells_per_document = 200000` là giá trị duy nhất không suy ra được từ
một giới hạn đã freeze khác; nó phải được xem lại khi có workbook thật đầu tiên.

# D — Trạng thái `media_extracted_texts`

`media_extracted_texts` v1.2 mở `CHECK (locator_type IN ('page','sheet'))` theo
ADR-0019 § D1, nhưng:

* **CHECK chưa được migrate**; schema contract vẫn ghi `page`.
* Document Implementation Status vì thế là **`Partial`**, không phải
  `Implemented`.
* Đây là **migration riêng**, tách khỏi migration ba bảng mới. Nó cũng là thay
  đổi có phá vỡ với spreadsheet: revision cũ giữ `page` và chuyển `archived`,
  lần chạy mới sinh revision `sheet` dưới một `processing_version` mới. Không
  backfill tại chỗ — sửa `locator_value` của row cũ sẽ làm mọi citation đã phát
  ra trỏ sai.

# E — Rủi ro còn lại

| # | Rủi ro | Ảnh hưởng |
| --- | --- | --- |
| R1 | `max_table_cells_per_document = 200000` đã freeze nhưng chưa đo trên workbook thật | Đặt thấp làm fail học liệu hợp lệ; phải xem lại khi có workbook đầu tiên |
| R2 | Bảng PDF trải nhiều trang chưa được ghép: mỗi phần là một region nên thành một bảng riêng | Consumer nhận nhiều bảng rời cho một bảng logic; quy tắc nối cần review riêng, đoán sai tạo ra bảng không tồn tại |
| R3 | Khối lượng retention/purge chưa được đo | Một lần purge revision có thể chạm hàng trăm nghìn row cell; `CASCADE` làm nó nguyên tử nhưng không làm nó nhanh |
| R4 | Chưa có database test cho composite FK 5 cột, các CHECK mới, và readiness invariants | Ba invariant vật lý và bốn readiness invariant hiện chỉ tồn tại trên giấy |
| R5 | ~~`extraction_method` hai tên~~ — đóng 2026-08-25 theo phương án (a) | Còn lại: migration phải gộp `locator_type` và `extraction_method` vào **một** lần, không tách |

R4 là điều kiện bắt buộc để review chạy lại: readiness invariants **không** biểu
diễn được bằng CHECK, nên nếu không có test chứng minh chúng được thực thi trong
transaction chuyển `ready`, chúng chỉ là chú thích.

# F — Database test plan

Plan này tách làm **hai gate ở hai thời điểm khác nhau**. Trộn chúng lại là lỗi
của Version 2.0: nó biến "chưa có runtime" thành "thiếu test migration", và tạo
áp lực viết test rỗng hoặc dựng runtime chỉ để mở migration.

| Gate | Cho phép gì | Nhóm test |
| --- | --- | --- |
| **Gate M** | Apply migration / schema | F.1, F.2, F.4.5, F.4.7, F.6 + schema contract đầy đủ + MariaDB integration + `schema:drift --fresh` |
| **Gate R** | Producer persist structured output, hoặc chuyển revision `ready` | F.3, F.4.1–F.4.4, F.5, **F.5.6**, cùng evidence về atomic commit/failure |

Ba nhóm của Gate R **không phải thiếu sót của migration test**. Chúng là
test-first requirement cho persistence service kế tiếp: không có đường ghi nào
tồn tại thì không có gì để chúng khẳng định.

**F.5.6 (zero row trong cả bốn bảng) là blocker bắt buộc của Gate R**, không được
hạ thành documented risk.

## F.0 — Vì sao phải là MariaDB

`phpunit.xml` đặt `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Migration
substrate mở đầu `addMariaDbChecks()` bằng:

```php
if (DB::getDriverName() === 'sqlite') {
    return;
}
```

Nghĩa là **không một CHECK constraint nào của miền Media đang được test hiện tại
thực thi**. 774 test đang pass không chứng minh gì về CHECK, và sẽ không chứng
minh gì về CHECK mới của region/table/cell.

Vehicle bắt buộc: job CI **`integration-mysql`** đã có sẵn trong
`.github/workflows/application-tests.yml` — MariaDB 11.4.3, `DB_CONNECTION=mysql`,
database `lf_ci_integration`. Job này đã chạy `MediaProcessingSubstrateMariaDbTest`
nên khuôn mẫu đã tồn tại: đặt file ở `tests/Integration/*MariaDbTest.php`,
self-skip khi driver không phải `mysql`, và **thêm tên file vào danh sách trong
job** — job liệt kê từng file có chủ ý, không quét cả thư mục.

**Không dùng `schema:drift --fresh`.** Command đó dựng database tạm rồi
`DROP DATABASE` ngay trong cùng lần chạy ([SchemaDrift.php:157](../../app/Console/Commands/SchemaDrift.php));
nó là công cụ thanh tra schema, không phải nơi PHPUnit có thể chạy test.

SQLite chỉ dùng **supplemental**: logic ứng dụng thuần, và các trigger có nhánh
SQLite. Mọi khẳng định về CHECK, composite FK, unique key, CASCADE, readiness
transaction, resource control và migration phải đến từ MariaDB.

## F.1 — CHECK vocabulary

| # | Case | Kỳ vọng | Tầng chặn |
|---|---|---|---|
| 1.1 | `media_extracted_texts.locator_type = 'page'` | accept | — |
| 1.2 | `= 'sheet'` | accept | — |
| 1.3 | `= 'region'` hoặc `'slide'` | reject | CHECK |
| 1.4 | `extraction_method` lần lượt `ocr`, `embedded_text`, `spreadsheet_cells` | accept | — |
| 1.5 | `extraction_method = 'spreadsheet'` (thiếu hậu tố) | reject | CHECK |
| 1.6 | `media_extracted_regions.locator_type = 'page'` | reject | CHECK |
| 1.7 | bbox cả bốn NULL | accept | — |
| 1.8 | bbox cả bốn hợp lệ trong `0..1` | accept | — |
| **1.9** | **`bbox_x` có giá trị, `bbox_y` NULL** | **reject** | CHECK |
| 1.10 | `bbox_x + bbox_width > 1` | reject | CHECK |
| 1.11 | `bbox_width = 0` | reject | CHECK |
| 1.12 | `bbox_x < 0` | reject | CHECK |
| 1.13 | `extraction_method = 'ocr'` với `provider IS NULL` | reject | CHECK |
| 1.14 | `role = 'figure'` với `text` không NULL | reject | CHECK |

Case **1.9 là regression test bắt buộc**: dạng CHECK cũ
`bbox_x IS NULL OR (...)` cho `UNKNOWN` ở đây và **để lọt**. Nếu case này pass mà
không có test, lỗi sẽ quay lại lần refactor sau.

## F.2 — Composite FK 5 cột

| # | Case | Kỳ vọng |
|---|---|---|
| 2.1 | table trỏ region khớp cả `(id, customer_id, media_file_id, locale, processing_version)` | accept |
| 2.2 | cùng tenant, khác `media_file_id` | reject |
| 2.3 | cùng tenant + media file, khác `locale` | reject |
| 2.4 | cùng tenant + media file + locale, khác `processing_version` | reject |
| 2.5 | khác `customer_id` (cross-tenant) | reject |
| 2.6 | `locator_type = 'sheet'` nhưng `region_id` NOT NULL | reject (CHECK, không phải FK) |
| 2.7 | `locator_type = 'region'` nhưng `region_id` NULL | reject (CHECK) |

2.2–2.4 là các case FK hẹp `(region_id, customer_id)` **không** bắt được; chúng là
lý do tồn tại của khóa 5 cột và phải có test riêng cho từng cột scope.

## F.3 — Readiness transaction

Kiểm ở mức service trong cùng transaction chuyển revision sang `ready`. Chạy được
trên SQLite, nhưng phải có bản MariaDB để chứng minh không xung đột với CHECK.

| # | Case | Kỳ vọng |
|---|---|---|
| 3.1 | `reading_order` = 1,2,3 liên tục | ready thành công |
| 3.2 | `reading_order` = 1,2,4 | revision không thể `ready` |
| 3.3 | `ordinal` trong một trang = 1,3 | không thể `ready` |
| 3.4 | table trỏ region có `role = 'paragraph'` | không thể `ready` |
| 3.5 | `locator_value` của table khác của region đích | không thể `ready` |
| 3.6 | table `ready` nhưng không có row cell nào | không thể `ready` (atomic readiness) |
| 3.7 | region `ready`, table còn `pending` | không thể `ready` |

## F.4 — Cell invariants

| # | Case | Kỳ vọng | Tầng chặn |
|---|---|---|---|
| 4.1 | `row_index + row_span - 1 > table.row_count` | reject | readiness transaction |
| 4.2 | `column_index + column_span - 1 > table.column_count` | reject | readiness transaction |
| 4.3 | hai merged range chồng nhau | reject | readiness transaction |
| 4.4 | merged cell sinh nhiều hơn một row trong phạm vi gộp | reject | readiness transaction |
| 4.5 | trùng `(customer_id, extracted_table_id, row_index, column_index)` | reject | UNIQUE |
| 4.6 | lưới thưa: bỏ trống ô giữa bảng | accept |  |
| 4.7 | xoá table cha | mọi cell bị xoá theo trong cùng câu lệnh | CASCADE |

4.7 là test của quyết định `CASCADE`: phải chứng minh purge nguyên tử, không phải
chỉ chứng minh cell biến mất.

## F.5 — Resource controls

| # | Case | Kỳ vọng |
|---|---|---|
| 5.1 | vượt `max_regions_per_page` | `structured_extraction_too_large` |
| 5.2 | vượt `max_regions_per_document` | `structured_extraction_too_large` |
| 5.3 | tổng text ba nguồn vượt `max_extracted_characters` | `structured_extraction_too_large` |
| 5.4 | vượt `max_table_cells_per_document` | `structured_extraction_too_large` |
| 5.5 | đúng bằng trần, không vượt | accept |
| 5.6 | sau mỗi lần fail ở 5.1–5.4 | **zero row** trong cả **bốn** bảng |
| 5.7 | production default đọc từ config | `max_table_cells_per_document === 200000`, `max_regions_per_page === 100`, `max_regions_per_document === 5000`, `max_extracted_characters === 500000` |

**Dùng config override nhỏ, không insert theo giá trị production.** Case 5.4/5.5
chạy với `max_table_cells_per_document` override thành `5`, rồi insert 6 và 5 row;
case 5.1/5.2 override trần region tương tự. Insert 200.001 row chỉ để chứng minh
một phép so sánh là biến một unit test thành một bài kiểm tra chịu tải, chạy hàng
phút trong CI và che mất chính khẳng định cần chứng minh.

Giá trị production được test **riêng** ở case 5.7: đọc thẳng từ config và so với
hằng số. Nó bảo vệ chống việc ai đó đổi trần mà không đổi contract — thứ mà các
case override không bao giờ phát hiện được.

**5.6 kiểm cả bốn bảng, gồm `media_extracted_texts`.** Ba bảng structured mới là
chưa đủ: một revision spreadsheet ghi text cấp sheet vào `media_extracted_texts`
trước, nên fail toàn revision mà bảng đó còn row là partial write đúng nghĩa —
và là loại partial khó thấy nhất, vì ba bảng mới đều sạch.

5.6 là assertion quan trọng nhất của nhóm: contract nói fail toàn revision, không
truncate. Test phải đếm row sau khi fail, không chỉ bắt exception.

**Ghi chú cho reviewer:** sau Owner amendment 2026-08-28,
`max_pages (100) × max_regions_per_page (100) = 10000`, lớn hơn
`max_regions_per_document = 5000`. Trần tài liệu vì vậy có thể chặn độc lập ngay
với production defaults. Case 5.2 vẫn dùng config override nhỏ để test nhanh;
case 5.7 phải bảo vệ cả hai giá trị production mới.

## F.6 — Migration

| # | Case | Kỳ vọng |
|---|---|---|
| 6.1 | `locator_type` và `extraction_method` đổi trong **cùng một** migration | Sau khi migrate, đọc **CHECK vật lý** từ `information_schema.CHECK_CONSTRAINTS` của MariaDB và assert cả hai vocabulary mới cùng hiện diện |
| 6.2 | row revision cũ `locator_type = 'page'`, `extraction_method = 'embedded_text'` sau khi migrate | vẫn đọc được qua Read Service, không backfill |
| 6.3 | rollback khi **không** có row `sheet`/`spreadsheet_cells` | phục hồi CHECK cũ, thành công |
| 6.4 | rollback khi **có** row `sheet` hoặc `spreadsheet_cells` | preflight fail-closed với thông báo rõ; **không** xoá row, không hạ giá trị, không sửa im lặng |
| 6.5 | migrate → rollback → migrate lại trên database có dữ liệu 6.2 | idempotent, dữ liệu nguyên vẹn |

6.1 phải soi constraint thật, không đọc migration inventory. Inventory chỉ nói
"có một file migration"; nó không nói file đó đã ALTER đủ hai CHECK. Một migration
sửa `locator_type` và quên `extraction_method` vẫn qua được inventory và fail ở
production khi row `spreadsheet_cells` đầu tiên được ghi.

6.4 là case dễ bỏ nhất và hỏng nặng nhất: một rollback "thành công" bằng cách âm
thầm ghi đè `sheet` thành `page` sẽ làm mọi citation đã phát ra trỏ sai mà không
có lỗi nào.

## F.8 — Bổ sung do amendment job identity (Approved 2026-08-27)

Phạm vi review Round 2 **không** gồm job identity của structured extraction. Đối
chiếu ngày 2026-08-27 phát hiện khoảng trống đó (DOC-CONFLICT-0019); Owner chọn
hướng `job_type = 'structured_extraction'` và ký ADR-0019 Amendment v1.2 cùng
Processing Contract v2.1 trong ngày. Hệ quả cho test plan:

| # | Case | Kỳ vọng | Tầng chặn |
|---|---|---|---|
| 8.1 | `job_type = 'structured_extraction'` | accept | CHECK |
| 8.2 | `job_type = 'structured_extractions'` (sai chính tả) | reject | CHECK |
| 8.3 | `output_type` lần lượt `extracted_region`, `extracted_table` | accept | CHECK |
| 8.4 | job `structured_extraction` `ready` với `output_id` NULL | reject | `chk_mpj_ready` |
| 8.5 | Sau migration, đọc `information_schema.CHECK_CONSTRAINTS` và assert cả `job_type` lẫn `output_type` mang vocabulary mới | pass | — |
| 8.6 | Rollback khi đã có row `structured_extraction` | preflight fail-closed, không xoá row | migration |

8.3 và 8.5 **không chạy được cho tới khi DOC-CONFLICT-0020 đóng**: § Keys của
`media_processing_jobs` mô tả một CHECK vocabulary cho `output_type` mà schema
vật lý không có. Không có gì để "mở"; migration hoặc tạo mới CHECK đó, hoặc tài
liệu phải sửa. Viết test trước khi chốt điều này là viết test cho một schema
chưa tồn tại.

Ba nhóm này thuộc **Gate M**, không phải Gate R: chúng là ràng buộc vật lý của
một bảng đang chạy.

## F.9 — Nghiệm thu bằng tài liệu thật (v2.3, 2026-08-27)

§ F.1–F.8 chứng minh **ràng buộc vật lý**. Chúng không nói gì về việc provider
đọc đúng một tài liệu thật. Bộ nghiệm thu tối thiểu:

| # | Fixture | PASS khi |
|---|---|---|
| 9.1 | PDF chỉ có text | Đủ số trang, không mất text, `reading_order` liên tục `1..N` |
| 9.2 | PDF scan | Mọi trang có row; `extraction_method = 'ocr'` |
| 9.3 | PDF lai text + scan | Mỗi trang mang đúng `extraction_method` của chính nó |
| 9.4 | PDF có bảng | Đúng số hàng/cột; ô rỗng vẫn giữ toạ độ; region đích có `role = 'table'` |
| 9.5 | PDF có biểu đồ | Có region `role = 'figure'`, `bbox` đúng vị trí; **không** có trường ý nghĩa nào |
| 9.6 | PDF có sơ đồ luồng | Mỗi khối là một region `figure`; chữ trong khối giữ nguyên. **Không** khẳng định quan hệ giữa khối |
| 9.7 | DOCX | Heading/paragraph/table ra đúng `role` |
| 9.8 | XLSX nhiều sheet | `locator_type = 'sheet'`; mỗi sheet một table; `extraction_method = 'spreadsheet_cells'` |
| 9.9 | Reprocess cùng file | Revision cũ `archived`, citation cũ vẫn trỏ đúng nội dung cũ |

**Tiêu chí bị loại bỏ có chủ ý.** "Nhận diện mũi tên", "thứ tự liên kết của sơ
đồ", "biểu đồ này nói gì" **không** nằm trong bộ này theo
[ADR-0019 § D7](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md). Chúng
không sai vì thiếu engine tốt; chúng không thuộc Media. Đổi engine không chuyển
được dòng nào vào bảng trên.

**Corpus.** Không có harness A0 nào còn tồn tại; bộ fixture này phải được dựng
lại. Tài liệu thật có PII học viên chịu eligibility riêng theo
[ADR-0018](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — không
được đưa vào corpus chỉ vì tiện.

## F.7 — Điều kiện đủ của plan

Plan được coi là đủ khi mọi dòng ở F.1–F.6 có một test tương ứng, và mỗi test có
**một** khẳng định về tầng chặn (CHECK, FK, UNIQUE, CASCADE, hay readiness
transaction).

**Bắt buộc chạy trên MariaDB qua job `integration-mysql`:** F.1, F.2, F.3, F.4.5,
F.4.7, F.5 và F.6 — nghĩa là toàn bộ trừ các case logic thuần của F.4.1–F.4.4 và
F.4.6.

SQLite chỉ **supplemental**, không được dùng làm bằng chứng cho bất kỳ dòng nào ở
trên. Một test constraint chạy trên SQLite pass mà không assert gì là tệ hơn không
có test: nó tạo cảm giác đã phủ.

Số test không phải tiêu chí. Tiêu chí là: **không invariant nào trong review này
chỉ tồn tại trên giấy.**

# G — Migration và runtime gate

```text
Verdict (Round 2, 2026-08-26)

PASS WITH DOCUMENTED RISKS

Migration authorized  (Gate M)

YES — MariaDB evidence 2026-08-27

Runtime authorized    (Gate R)

NO
```

Verdict chuyển khỏi `BLOCKED` vì hai blocker kiến trúc đã đóng và database test
plan đã được independent reviewer xác nhận khả thi sau năm chỉnh sửa của Round 2.

`Migration authorized` vẫn là **NO**, và đây không phải mâu thuẫn: thiết kế đủ cơ
sở để tiến tới, nhưng test ở § F mới **được lập kế hoạch**, chưa được viết và chưa
chạy xanh. Một invariant có kế hoạch test không phải một invariant đã được chứng
minh.

Migration cho `media_extracted_regions`, `media_extracted_tables`,
`media_table_cells` và cho amendment `locator_type` + `extraction_method` của
`media_extracted_texts` chỉ được viết khi **đồng thời**:

1. ~~B1~~ — **ĐÃ ĐÓNG 2026-08-25.** ADR-0019 Amendment v1.1 Approved;
   DOC-CONFLICT-0016 và 0017 đều RESOLVED.
2. ~~B2~~ — **ĐÃ FREEZE 2026-08-25** trong Processing Contract.
3. ~~Rerun độc lập~~ — **XONG 2026-08-26.** Round 2 chuyển verdict sang
   `PASS WITH DOCUMENTED RISKS`.
4. ~~Database test plan~~ — **XONG.** § F, đã qua Round 2 với năm chỉnh sửa.
5. **Còn lại của Gate M — giữ nguyên không nới:**
   * viết test vật lý F.1, F.2, F.4.5, F.4.7, F.6 và F.8;
   * thêm file integration mới vào **danh sách explicit** của job
     `integration-mysql` (job liệt kê từng file, không quét thư mục);
   * chạy xanh trên MariaDB 11.4.3;
   * có **evidence riêng** cho hai case Gate M, không gộp vào báo cáo chung:
     **F.1.9** partial-NULL bbox bị CHECK chặn; **F.6.4** rollback khi đã có
     `sheet`/`spreadsheet_cells` fail-closed và không sửa dữ liệu.

F.3, F.4.1–F.4.4 và toàn bộ F.5 thuộc **Gate R**. Đặc biệt F.5.6 — fail toàn
revision với zero row trong cả bốn bảng — vẫn là blocker bắt buộc trước runtime,
nhưng không phải bằng chứng cần có để apply schema migration.

Chỉ sau evidence đó `Migration authorized` mới chuyển `YES`. Viết migration và
test theo thứ tự plan được phép bắt đầu ngay; **hoàn tất** và **deployable** thì
không.

**R1–R5 vẫn giữ sau khi mở migration.** Chúng không phải điều kiện của gate mà là
rủi ro vận hành còn sống — đặc biệt R1 (cell cap chưa đo trên workbook thật),
R3 (khối lượng purge) và R2 (bảng PDF nhiều trang chưa ghép).

Cái còn thiếu bây giờ là **bằng chứng test**, không còn là quyết định Owner và
không còn là thiết kế. Đây là điều kiện cuối cùng trước migration.


**Addendum 2026-08-27 — Gate M vừa rộng ra.** Verdict Round 2 ở trên được đưa ra
trên phạm vi hai migration. Amendment job identity, **Approved 2026-08-27**,
thêm **migration thứ ba** trên `media_processing_jobs` và nhóm test § F.8.

Chữ ký của Owner cho amendment **không** phải chữ ký review. Verdict
`PASS WITH DOCUMENTED RISKS` của Round 2 được đưa ra trước khi phạm vi này tồn
tại và **không** phủ nó. Gate M chỉ đóng khi § F.1–F.6 **và** § F.8 cùng chạy
xanh, và § F.8 cần một lượt review độc lập — Round 3 — trước khi migration thứ
ba được viết. Hai gate độc lập; đây là lý do ô § H vẫn để trống.

§ F.8 case 8.3 và 8.5 còn bị chặn thêm bởi **DOC-CONFLICT-0020**, vẫn
`DECISION_REQUIRED` sau ngày 2026-08-27.

## Round 3 — job identity và read selector (2026-08-27)

Round 3 mở đúng hai phạm vi phát sinh sau Round 2:

1. `media_processing_jobs` tạo vật lý bốn CHECK bị drift, đồng thời mở
   `structured_extraction` và hai output type mới. Migration phải preflight và
   rollback fail-closed; không sửa lịch sử để ép qua constraint.
2. Media Read dùng `usage_type` bắt buộc, exact-slot lookup và
   `ambiguous_source`; không còn query-order policy `first()`/`latest()`.

Kết quả review contract: **PASS WITH DOCUMENTED RISKS**. Hai quyết định đóng
DOC-CONFLICT-0020/0021, giữ tenant/owner authorization và không mở thêm quyền cho
AI. Migration thứ ba được phép **viết và test**, nhưng Gate M vẫn `NO` cho tới
F.1/F.2/F.4/F.6/F.8 và inventory CHECK đã chạy xanh trên MariaDB local tạm:
20 tests, 61 assertions; `schema:drift --fresh` PASS. Gate M chuyển **YES**.
Runtime structured vẫn
Gate R `NO` cho tới khi F.3/F.4/F.5, nhất là zero-row rollback F.5.6, có evidence.

Addendum cũ nói DOC-CONFLICT-0020 còn mở được supersede bởi Round 3 này. Không
đánh dấu Owner Approval ở § H; review pass không thay chữ ký deploy/runtime.

Deployment evidence: ba migration structured đã apply vào `learnforge_db` batch
16 ngày 2026-08-27; `schema:drift --connection=mysql` PASS và ledger không còn
pending migration. Việc này chỉ triển khai schema; production structured provider
vẫn chưa được bind và Runtime Gate R vẫn `NO`.

## Round 4 — A1 hybrid provider authorization (2026-08-27)

Owner phê duyệt ADR-0019 v1.4 và Tech Stack v1.2: `docling_local` được implement
như process offline Python 3.11/Docling 2.119.0, chỉ cung cấp layout; text vẫn từ
Poppler/Tesseract canonical. Review giữ các gate sau:

* Không network/model download trong job; thiếu binary/model trả
  `provider_unavailable`.
* Page limit được kiểm trước khi model load; deadline và worker timeout hiện hành
  vẫn áp dụng.
* JSON output phải qua toàn bộ Gate R validation trước insert.
* Local enable chỉ sau smoke trên fixture thật. AWS enable chỉ sau exact
  package/model/config parity và memory sizing.
* Figure không được phân loại chart/diagram/image và không sinh semantic arrow
  relationship; đó là ADR-0020.

Verdict provider design: **PASS WITH DOCUMENTED RISKS**. Runtime Gate R chỉ đổi
sang YES sau test F.3–F.5 và provider acceptance F.9; approval này không tự biến
implementation chưa chạy thành production-ready.

# H — Owner Approval

```text
Role: LearnForge Architecture Owner
Date:
Decision:
```

Để trống có chủ ý. ADR-0019 đã Approved **không** suy ra approval cho thiết kế
database; đó là hai gate riêng trong workflow Governance → ADR → Domain →
Database → Review → Freeze → Migration.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* Database Owner
