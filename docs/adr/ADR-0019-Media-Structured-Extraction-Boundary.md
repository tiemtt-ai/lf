# ADR-0019 — Media Structured Extraction Boundary

Version: 1.0

Status: Approved

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-08-25

Proposal Date: 2026-08-25

Approval Date: 2026-08-25

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0019-Media-Structured-Extraction-Boundary.md

Related ADRs:

* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved

Related Specification:

* [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md)
* [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md)
* [media_extracted_texts](../database/media/media_extracted_texts.md)

---

# Amendment Record — Version 1.1 (Proposed)

Amendment Status: **Proposed — pending Architecture Owner approval.** Cho tới khi
được approve, ADR-0019 vẫn là Version 1.0 và mục này không có hiệu lực.

Nguồn: independent review của Database Docs ngày 2026-08-25 phát hiện mâu thuẫn
giữa ADR này và [media_table_cells](../database/media/media_table_cells.md).

**Mâu thuẫn.** § D2 viết cả ba bảng mang `processing_version` +
`source_fingerprint` "trên mỗi row". Database Doc của `media_table_cells` cố ý
**không** có hai cột đó.

**Đề xuất sửa.** Revision identity nằm ở **row sở hữu revision**, không ở mọi row
con:

* `media_extracted_regions` và `media_extracted_tables` mang `processing_version`,
  `source_fingerprint` và `status`.
* `media_table_cells` **kế thừa** cả ba từ bảng cha qua khóa ngoại, và không thể
  `archived` độc lập.

**Lý do.** Cell là owned child không có identity độc lập. Nhân bản version và
fingerprint lên hàng trăm nghìn row cell không thêm khả năng truy vết nào — chúng
luôn bằng giá trị của bảng cha — nhưng tạo ra cơ hội để hai giá trị lệch nhau, và
lúc đó không có nguồn nào đúng.

Đây là mâu thuẫn giữa ADR Approved và Database Doc Draft, đã ghi vào Conflict
Register là **DOC-CONFLICT-0016**
([LF-Documentation-Conflicts](../quality/LF-Documentation-Conflicts.md)). Cho tới
khi amendment được approve hoặc conflict được đóng theo hướng khác, **không được
tạo migration** cho ba bảng này.

---

# Context

Media hiện chỉ sản xuất **text theo trang**. `media_extracted_texts` khoá cứng
điều đó ở tầng database:

```sql
CHECK (locator_type IN ('page'));
```

Hệ quả trên học liệu thật:

* Một bảng trong PDF bị `pdftotext -layout` làm phẳng thành text giữ cột bằng
  khoảng trắng. Consumer đọc vào không phân biệt được ô, hàng, header. Cấu trúc
  vẫn nhìn thấy được bằng mắt người và biến mất hoàn toàn với máy.
* Một worksheet Excel được đọc trực tiếp theo cell, nhưng vẫn phải ép về một
  unit `locator_type = 'page'` với `locator_value` là **chỉ số sheet**. Đây là
  một sự gán ghép: sheet không phải trang, và hợp đồng locator đang bị dùng sai
  nghĩa để không phải sửa schema.
* Vùng/layout (cột, khối, thứ tự đọc) không có chỗ lưu, nên không consumer nào
  trích dẫn được ở mức nhỏ hơn trang.

Đây là điều kiện chặn thật, không phải mong muốn kiến trúc. Benchmark A0 đo được
rằng một engine layout (Docling) tách cột tốt hơn baseline — boundary 0.61→0.82
trên `vi-s2`, 0.66→0.78 trên `ko-s2` — nhưng
[Closure Record của A0](../../LF-A0-Docling-Benchmark-Protocol.md) ghi rõ lợi thế
đó **không lưu được ở schema hiện hành**. Mua năng lực trước khi có chỗ chứa là
chi phí không đổi lấy gì.

Câu hỏi ADR này trả lời: **structured extraction sống ở đâu, dưới hợp đồng
locator nào, và ranh giới giữa "quan sát cấu trúc" với "diễn giải nội dung" nằm
chỗ nào.**

---

# Decision

## D1 — Locator giữ nguyên hình dạng, mở rộng vocabulary

[LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) § 4
freeze một hợp đồng duy nhất:

```text
locator := { locator_type, locator_value }   // locator_value luôn là text
```

Hình dạng đó **không đổi**. Chỉ vocabulary mở rộng:

| `locator_type` | Áp dụng cho | `locator_value` |
| --- | --- | --- |
| `page` | extracted text (document) | Số trang, ≥ 1 |
| `timespan` | transcript (audio/video) | `<start_ms>-<end_ms>` |
| `sheet` | spreadsheet | Chỉ số sheet theo thứ tự workbook, ≥ 1 |
| `region` | vùng trên một trang | `<page>#<ordinal>`, cả hai ≥ 1 |

`region` cố ý **không** mang toạ độ. Bounding box là dữ liệu quan sát, thay đổi
khi đổi extractor hoặc DPI; nếu nhét vào locator thì mọi citation cũ vỡ mỗi lần
đổi `processing_version`. Locator phải ổn định suốt vòng đời của một
`source_fingerprint`, nên nó chỉ định danh *vùng thứ mấy trên trang nào*, còn
hình học nằm ở cột riêng.

`sheet` thay cho việc gán sheet vào `page`. Đây là **sửa một cách dùng sai đang
tồn tại**, không phải mở rộng phạm vi.

## D2 — Cấu trúc không sống trong `media_extracted_texts`

`media_extracted_texts` là text-theo-unit. Nhồi vùng, bảng và ô vào đó biến phần
lớn cột thành NULL và trộn hai loại dữ liệu khác bản chất vào một bảng.

Structured extraction là **content type mới**, không phải cột mới:

| Bảng | Nội dung | Locator |
| --- | --- | --- |
| `media_extracted_regions` | Một vùng: role, hình học, thứ tự đọc | `region` |
| `media_extracted_tables` | Một bảng, neo vào một region hoặc một sheet | `region` \| `sheet` |
| `media_table_cells` | Một ô: row, column, rowspan, colspan, text | thừa kế của bảng cha |

Ba bảng này theo đúng khuôn của substrate hiện hành: tenant composite identity
`UNIQUE (id, customer_id)`, khoá ngoại kép `(parent_id, customer_id)`,
`processing_version` + `source_fingerprint` trên mỗi row, và revision cũ chuyển
`archived` thay vì bị ghi đè.

Chi tiết cột, index và CHECK thuộc Database Docs, không thuộc ADR này.

## D3 — Read Contract nhận thêm content type, không nhận thêm đường đọc

[LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) § 2 liệt kê
`extracted_text | transcript | caption_asset | variant`. ADR này thêm:

* `region`
* `table`

Mọi thứ khác của Read Contract giữ nguyên: owner context, `actor_id` tường minh,
chọn locale, chọn revision, tập mã lỗi đóng, audit `read_derived`. Không có API
riêng cho structured data, không có đường nào cho AI đọc thẳng bảng `media_*`.

## D4 — Ranh giới: Media quan sát, AI diễn giải

Media ghi lại **những gì có trên trang**: đây là một vùng, role của nó là bảng,
nó có 4 hàng 3 cột, ô (2,1) chứa chuỗi này, thứ tự đọc là thế này.

Media **không** ghi ý nghĩa: bảng này nói về doanh thu, cột này là đơn vị tiền
tệ, biểu đồ này cho thấy xu hướng giảm. Diễn giải là việc của AI và phải để lại
`ai_model_runs`, quota và retention theo AI Foundation.

Ranh giới này không phải hình thức. Một khi Media bắt đầu gán ý nghĩa, nó trở
thành nguồn sự thật cho một business state mà nó không sở hữu, và không consumer
nào truy được quyết định đó về một model run nào.

## D5 — Ngoài phạm vi, có lý do

| Không thuộc ADR này | Vì sao |
| --- | --- |
| Diễn giải biểu đồ/sơ đồ/ảnh | Là vision AI. Cần `ai_model_runs`, quota, retention/redaction — tức là AI Foundation phải được implement trước. Xem [Architecture Review của subset Media→AI](../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md) |
| Chọn extractor sinh ra được region/table | Là quyết định Tech Stack riêng. ADR này tạo **chỗ chứa**, không phê duyệt provider nào |
| Citation ở mức cue của caption | Đã được Read Contract ghi là contract riêng cần review (risk B2) |
| Chuỗi công thức và chart nhúng trong Excel | Cần vocabulary riêng; worksheet vào được `table` trước |

Điểm thứ hai quan trọng: ADR này **không phải cửa sau cho Docling**. Hồ sơ A0 đã
đóng với kết luận giữ Tesseract. Mở lại là một quyết định Tech Stack có bằng
chứng riêng, chạy sau khi chỗ chứa này tồn tại — không phải hệ quả tự động của
việc approve ADR này.

---

# Consequences

* Bảng trong PDF và worksheet Excel trở thành dữ liệu máy đọc được, thay vì text
  đã bị làm phẳng.
* Consumer trích dẫn được ở mức nhỏ hơn trang mà không phá hợp đồng locator.
* Cách dùng sai `page` cho sheet được sửa. Đây là **thay đổi có phá vỡ**: unit
  của spreadsheet sẽ mang `locator_type = 'sheet'`, nên phải đi kèm
  `processing_version` mới; revision cũ giữ nguyên `archived` và vẫn đọc được.
* Ba bảng mới, ba content type mới, và một Read Contract amendment cần review.
* Không có provider, dependency, queue hay deployment change nào phát sinh từ
  ADR này.
* Chi phí lưu trữ tăng theo số ô, không theo số trang. Một workbook lớn sinh ra
  nhiều row hơn hẳn một PDF cùng dung lượng; giới hạn tài nguyên cho structured
  extraction phải được chốt trong Database Docs, không để mặc định.

---

# Alternatives Rejected

1. **Thêm cột region/table vào `media_extracted_texts`:** biến phần lớn cột
   thành NULL, trộn hai loại dữ liệu khác bản chất, và buộc mọi consumer text
   phải hiểu schema của bảng.
2. **Nhét toạ độ vào `locator_value`:** phá quy tắc locator ổn định theo
   `source_fingerprint`; đổi DPI là mọi citation cũ trỏ sai.
3. **Lưu bảng dưới dạng JSON hoặc Markdown serialize:** đọc được bằng mắt, không
   truy vấn được, và mỗi extractor sinh một phương ngữ khác nhau.
4. **Giữ nguyên `page` cho spreadsheet:** rẻ hơn hôm nay, nhưng giữ lại một
   locator nói dối về nguồn, và mọi consumer sau này phải học ngoại lệ đó.
5. **Gộp cả diễn giải biểu đồ vào ADR này:** trộn quan sát với suy luận, và tạo
   một business state không truy được về model run nào.

---

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-08-25
Decision: Approved
```

Owner approval mở bước Domain/Database trong workflow, **không** mở bước
Migration. Trước khi có migration hoặc sửa Read Contract vẫn cần: Database Docs
cho ba bảng mới, Read Contract amendment, và **Architecture Review passed**.

Approval này không phê duyệt provider nào và không mở lại hồ sơ A0.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
