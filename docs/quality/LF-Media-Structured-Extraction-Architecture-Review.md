# Media Structured Extraction Architecture Review

Version: 1.0

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-25

Review Date: 2026-08-25

Document Path: quality/LF-Media-Structured-Extraction-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Media |
| Parent ADR | [ADR-0019 — Media Structured Extraction Boundary](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) v1.0 — Approved 2026-08-25 |
| Constraining ADR | [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved |
| Producer Contract | [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) |
| Consumer Contract | [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) v1.5 |
| Review Scope | `media_extracted_regions`, `media_extracted_tables`, `media_table_cells`, và amendment `locator_type` của `media_extracted_texts` |
| Open Conflict | [DOC-CONFLICT-0016](LF-Documentation-Conflicts.md) — OPEN |

Review provenance: findings trong tài liệu này đến từ một **independent review**
của Database Docs ngày 2026-08-25, do một reviewer không phải tác giả của ADR-0019
và ba Database Doc thực hiện. Tác giả đã áp dụng các sửa chữa; **review chưa được
chạy lại sau khi sửa**. Verdict dưới đây là trạng thái tại thời điểm phát hiện,
không phải kết quả sau sửa.

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

# B — Blocker B1: xung đột ADR chưa đóng

ADR-0019 § D2 tuyên bố cả ba bảng mang `processing_version` +
`source_fingerprint` "trên mỗi row". `media_table_cells` cố ý không có hai cột đó
và kế thừa từ bảng cha.

* Trạng thái: **[DOC-CONFLICT-0016](LF-Documentation-Conflicts.md) — OPEN**.
* Đề xuất đóng: [ADR-0019 Amendment Record Version 1.1](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md),
  hiện **Proposed — pending Architecture Owner approval**.
* Owner chọn một trong hai: approve amendment (revision identity nằm ở row sở
  hữu, cell kế thừa), hoặc giữ § D2 nguyên văn và thêm hai cột vào
  `media_table_cells`.

Đây là mâu thuẫn giữa một ADR **Approved** và một Database Doc **Draft**. Viết
migration khi nó còn mở nghĩa là chọn thay Owner.

# C — Blocker B2: resource limits chưa freeze

[LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md)
§ "Structured extraction resource controls — chưa freeze" liệt kê bốn giới hạn,
tất cả đang ở trạng thái **Chưa chốt**:

| Giới hạn | Trạng thái |
| --- | --- |
| Tổng ký tự một revision, tính cả text cấp trang **và** cấp region | Chưa chốt |
| Số region mỗi document | Đề xuất `2000`, chưa có bằng chứng |
| Số bảng mỗi document | Chưa có đề xuất |
| Số cell mỗi document | Đề xuất `200000`, **chưa có workbook evidence** |

Cùng hai điều kiện đi kèm cũng chưa freeze: error semantics
(`structured_extraction_too_large`, fail toàn revision, không truncate) và atomic
readiness (region/table/cell cùng `ready` hoặc cùng không).

§ 3 của Processing Contract tuyên bố resource control là **contract**, không phải
tuning tuỳ ý. Deploy structured extraction với bốn giới hạn để trống là ship một
đường ghi không có trần.

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
| R1 | `max_table_cells_per_document = 200000` là đề xuất, chưa đo trên workbook thật | Đặt thấp làm fail học liệu hợp lệ; đặt cao làm mất tác dụng trần |
| R2 | Bảng PDF trải nhiều trang chưa được ghép: mỗi phần là một region nên thành một bảng riêng | Consumer nhận nhiều bảng rời cho một bảng logic; quy tắc nối cần review riêng, đoán sai tạo ra bảng không tồn tại |
| R3 | Khối lượng retention/purge chưa được đo | Một lần purge revision có thể chạm hàng trăm nghìn row cell; `CASCADE` làm nó nguyên tử nhưng không làm nó nhanh |
| R4 | Chưa có database test cho composite FK 5 cột, các CHECK mới, và readiness invariants | Ba invariant vật lý và bốn readiness invariant hiện chỉ tồn tại trên giấy |

R4 là điều kiện bắt buộc để review chạy lại: readiness invariants **không** biểu
diễn được bằng CHECK, nên nếu không có test chứng minh chúng được thực thi trong
transaction chuyển `ready`, chúng chỉ là chú thích.

# F — Migration và runtime gate

```text
Verdict

BLOCKED

Migration authorized

NO
```

Cấm tạo migration cho `media_extracted_regions`, `media_extracted_tables`,
`media_table_cells` và cho amendment `locator_type` của `media_extracted_texts`
cho tới khi **đồng thời**:

1. B1 đóng — Owner quyết ADR-0019 Amendment v1.1, DOC-CONFLICT-0016 chuyển
   RESOLVED;
2. B2 đóng — bốn resource limit cùng error semantics và atomic readiness được
   freeze trong Processing Contract;
3. Review này được **chạy lại** và verdict chuyển `PASS` hoặc
   `PASS WITH DOCUMENTED RISKS`.

Verdict `BLOCKED` **không** phải kết luận rằng hướng thiết kế sai. Ba invariant
vật lý đã được sửa và hướng thiết kế được đánh giá hợp lệ; cái còn thiếu là hai
quyết định Owner và bằng chứng test.

# G — Owner Approval

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
