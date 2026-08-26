# ADR-0020 — AI Vision Interpretation Boundary

Version: 1.0

Status: Approved

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-08-25

Proposal Date: 2026-08-25

Approval Date: 2026-08-25

Approved By: LearnForge Architecture Owner

Document Path: adr/ADR-0020-AI-Vision-Interpretation-Boundary.md

Related ADRs:

* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md) — Approved, Frozen
* [ADR-0017 — AI-Assisted Learning Authoring](ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved
* [ADR-0019 — Media Structured Extraction Boundary](ADR-0019-Media-Structured-Extraction-Boundary.md) — Approved

Related Specification:

* [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md)
* [LF-AI](../platform/LF-AI.md)
* [ai_model_runs](../database/ai/ai_model_runs.md)

---

# Context

Học liệu thật chứa biểu đồ, sơ đồ và ảnh minh hoạ. Text trích xuất từ chúng chỉ
là nhãn trục và chú thích rời rạc — đủ để tìm kiếm, không đủ để hiểu.

[ADR-0019](ADR-0019-Media-Structured-Extraction-Boundary.md) § D4 đã chốt nửa
ranh giới: **Media quan sát, AI diễn giải.** Media ghi "trang 12 có một vùng
role `figure` ở toạ độ này". Media không ghi "biểu đồ này cho thấy doanh thu
giảm".

Nửa còn lại chưa có contract nào. Không tài liệu nào nói: diễn giải được lưu ở
đâu, neo vào cái gì để trích dẫn lại được, ai trả tiền cho nó, giữ bao lâu, và
xoá thế nào khi nguồn bị xoá. ADR-0006 § Future Extensions liệt kê "Multimodal
knowledge and conversation" là mở rộng chưa quyết.

Hệ quả của khoảng trống: bất kỳ implementation vision nào cũng sẽ phải tự chọn
thay Owner ở năm điểm cùng lúc, và mỗi lựa chọn đều khó đảo ngược sau khi có dữ
liệu.

---

# Decision

## D1 — Diễn giải là output của AI domain, không bao giờ là row `media_*`

Vision interpretation **không** được ghi vào bất kỳ bảng `media_*` nào. Media là
nguồn quan sát; một diễn giải nằm trong bảng Media sẽ biến một suy luận có thể
sai thành dữ liệu nguồn không phân biệt được với sự thật quan sát.

Diễn giải neo vào derived content unit đúng theo cách
[LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) § 7 quy định cho
`ai_knowledge_sources`: owner context, `content_type`, `locale`,
`processing_version`, `source_fingerprint`. Không neo bằng `media_file_id`.

## D2 — Mỗi lần gọi vision tạo đúng một `ai_model_runs`

Không có ngoại lệ, kể cả khi call fail, bị quota chặn, hay bị safety filter chặn.
`ai_model_runs` là bản ghi chi phí và provenance; một call không để lại row là
một chi phí không ai truy được và một diễn giải không biết đến từ model nào.

Diễn giải lưu lại phải mang `run_uuid` của run đã sinh ra nó.

## D3 — Quota chặn **trước** khi gọi provider

Quota là tenant-scoped và được kiểm **trước** lời gọi, không phải sau. Vượt quota
là một error code có tên, trả về cho consumer; **không** được im lặng bỏ qua
diễn giải và trả kết quả như thể tài liệu không có biểu đồ.

Lý do: một pipeline im lặng bỏ qua khi hết quota sẽ tạo ra hai tài liệu giống
nhau cho ra hai kết quả khác nhau tuỳ thời điểm chạy, và không ai phát hiện được
từ output.

## D4 — Diễn giải không bao giờ là Source Of Truth

Diễn giải là derived, có thể regenerate, và **có thể sai**. Nó không được:

* trở thành business state của Course, Assessment, Learning hay Track;
* ghi đè hay sửa bất kỳ output nào của Media;
* được trích dẫn mà không kèm `run_uuid`, model, và `processing_version` của
  unit nguồn.

Mọi đường từ diễn giải vào business state phải đi qua review của con người theo
ADR-0017.

## D5 — Retention, deletion và redaction thừa kế ADR-0018

Diễn giải là một mắt xích của provenance chain mà
[ADR-0018](ADR-0018-Media-PII-And-External-Processing-Boundary.md) bắt phải phủ.
Cụ thể:

* Xoá source Media phải xoá mọi diễn giải dựng từ nó.
* Ảnh có PII: diễn giải của nó cũng là dữ liệu có PII, không được coi là
  metadata vô hại chỉ vì nó do máy sinh.
* Redacted derivative có identity/version/provenance riêng; diễn giải của bản
  redact không thay thế diễn giải của bản gốc và ngược lại.

## D6 — Provider ngoài vẫn cần approval riêng

ADR-0018 § External-processing eligibility áp nguyên vẹn. ADR này **không** phê
duyệt provider vision nào, không phải blanket consent, và không cho phép gửi ảnh
học liệu ra ngoài boundary chỉ vì OCR local đã được phép.

## D7 — Ngoài phạm vi, có lý do

| Không thuộc ADR này | Vì sao |
| --- | --- |
| Chọn provider vision cụ thể | Quyết định Tech Stack riêng, cần bằng chứng riêng |
| Schema của nội dung diễn giải (prompt, output shape) | Phụ thuộc provider; chốt trước khi biết provider là đoán |
| Vision tương tác thời gian thực trong hội thoại | Thuộc "Multimodal conversation" của ADR-0006 Future Extensions |
| Diễn giải video theo khung hình | Cần vocabulary locator riêng; `timespan` hiện chỉ neo transcript |

---

# Consequences

* Biểu đồ, sơ đồ và ảnh trở thành nội dung AI đọc được, với đường truy vết về
  đúng model run đã sinh ra chúng.
* Mỗi diễn giải có chi phí đo được và một trần chặn trước khi phát sinh.
* AI Foundation phải được implement trước: ADR này vô nghĩa nếu `ai_model_runs`
  chưa tồn tại. Xem [Architecture Review của subset Media→AI](../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md).
* Cần thêm bảng lưu diễn giải trong AI domain; hình dạng của nó thuộc Database
  Docs, không thuộc ADR này.
* Không có provider, dependency, queue hay deployment change nào phát sinh từ ADR
  này.

---

# Alternatives Rejected

1. **Lưu diễn giải vào `media_extracted_regions.metadata`:** trộn quan sát với
   suy luận trong cùng một row, và phá § D4 của ADR-0019 ngay khi vừa chốt.
2. **Bỏ qua diễn giải khi hết quota:** tạo ra kết quả không tất định cho cùng một
   tài liệu, không phát hiện được từ output.
3. **Coi diễn giải là metadata vô hại:** ảnh có PII sinh ra diễn giải có PII;
   miễn trừ nó khỏi retention là tạo một lỗ trong provenance chain của ADR-0018.
4. **Gộp vision vào ADR-0019:** trộn "cái gì có trên trang" với "cái đó nghĩa là
   gì" — hai câu hỏi khác chủ thể, khác chi phí, khác vòng đời.

---

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-08-25
Decision: Approved
```

Approval mở bước Domain/Database. **Không** mở migration, không phê duyệt provider
vision nào, và không cho gọi vision trước khi AI Foundation được implement và
Architecture Review pass.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
