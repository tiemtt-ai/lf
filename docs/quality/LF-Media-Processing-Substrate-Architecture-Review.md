# Media Processing Substrate Architecture Review

Version: 1.1

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-23

Review Date: 2026-08-23

Document Path: quality/LF-Media-Processing-Substrate-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Media × AI × Course |
| Domain Docs | [LF-Media](../platform/LF-Media.md), [LF-AI](../platform/LF-AI.md) |
| Parent ADR | [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md) |
| Constraining ADR | [ADR-0017 — AI-Assisted Learning Authoring](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md) |
| Specification | [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) v1.2 |
| Database Docs | `media_processing_jobs` v2.2, `media_extracted_texts` v1.0, `media_transcripts` v1.2, `media_captions` v1.2, `media_variants`, `media_access_logs` |
| Review Scope | Substrate xử lý Media. **Không** gồm Media Read Contract cho AI, AI Proposal, và learner runtime |

# Review Scope

Reviewed: hợp đồng trigger/scope, orchestration, fingerprint, output profile,
citation locator, đo lường, và bốn table contract nêu trên.

Not reviewed và cố ý ngoài phạm vi: Media Read Contract cho AI consumer
(Spec B, chưa viết); AI Proposal persistence và review workflow (gated bởi
ADR-0017 §268); chính sách retention/redaction cho nội dung trích xuất.

# A — Domain Boundary

- [x] Media sở hữu file, job, và mọi output dẫn xuất.
- [x] AI là consumer; không đọc object storage, không ghi bảng `media_*`.
- [x] Job completion không ghi Course Progress, Assessment Result, LiveClass
      Attendance, Learning Evidence hay Mastery.
- [x] Không tạo foreign key từ Media sang Course, Assessment, LiveClass hoặc AI.

# B — Data Ownership

- [x] Mọi quan hệ tenant-scoped; ba FK của job đều composite `(id, customer_id)`.
- [x] Output neo vào lần chạy đã tạo ra nó qua `processing_job_id`.
- [x] `owner_type` được phép xử lý giới hạn ở `course_activity` trong Phase 1;
      mở rộng là amendment có review.

# C — Versioning

- [x] `source_fingerprint` chỉ mô tả nội dung nguồn; không chứa locale hay tham
      số output.
- [x] `output_profile_hash` mô tả yêu cầu output, tách khỏi fingerprint.
- [x] `processing_version` mô tả extractor/provider/model/cấu hình.
- [x] Chạy lại không ghi đè: bộ row mới, bộ cũ `archived`.
- [x] Published Version Activity dùng lại output của working Activity vì cùng
      fingerprint; không chạy lại và không phát sinh chi phí lần hai.
- [x] Bản `archived` đọc được vĩnh viễn, để một Proposal đã trích dẫn trang 12
      vẫn còn trang 12 mà nó đã đọc.

# D — Business Rules

- [x] Vocabulary hợp nhất về từ ngữ; ba tầng `ready` được định nghĩa riêng, kèm
      ma trận trạng thái tổng hợp.
- [x] Job tuỳ chọn thất bại không làm Media File mất `ready`.
- [x] Retry sinh row mới với `attempt` và `supersedes_job_id`; không sửa row cũ.
- [x] Không hỗ trợ operator cancellation sau dispatch; provider callback muộn
      vẫn phải kết thúc `ready` hoặc `failed`.
- [x] Chuyển trạng thái là một chiều; job đã kết thúc là bất biến trừ cột audit.

# E — Database

- [x] Ba unique key với ba vai trò tách bạch, có bảng giải thích khóa nào chặn
      cái gì.
- [x] Duplicate initial enqueue bị chặn bởi khóa `attempt = 1`, **không** bởi
      `UNIQUE (customer_id, supersedes_job_id)` — MariaDB cho phép nhiều `NULL`
      trong unique index nên khóa đó không ràng buộc job đầu tiên.
- [x] `output_profile_hash` nằm trong unique key, nên nhiều locale và nhiều định
      dạng cùng tồn tại hợp lệ.
- [x] CHECK cho mọi enum và mọi bất biến trạng thái–thời gian–output.
- [x] Locator có hợp đồng chung, chốt trước khi tạo bảng.
- [ ] Sáu bảng vẫn `not_implemented`; không migration nào được authorize bởi
      review này.

# F — Architecture

- [x] Idempotency nằm ở database, không ở queue.
- [x] Mỗi lần gọi provider có tính phí để lại đúng một row, kể cả lần thất bại.
- [x] Concurrency thi hành bằng row lock trên Media File, không bằng queue lock.
- [x] Không có dead-letter queue riêng; chuỗi job là dead-letter record đọc được
      bằng SQL và không hết hạn.
- [x] Ranh giới ADR-0017 giữ nguyên: không mở AI Proposal, không ghi Learning.

# G — Documentation

- [x] Bốn tài liệu có metadata đầy đủ và được route từ `LF-INDEX.md`.
- [x] `docs:lint` và `schema:drift` passed.
- [x] Chín tài liệu `database/media/` đã gỡ khỏi legacy metadata allowlist
      (106 → 98 file), nên lint từ nay thực sự kiểm chúng.
- [x] Đã commit lên `main` tại `e460dce`; reviewer độc lập đã đọc diff thật.

# H — Ready For Next Gate

- [x] Migration shape đã được mô tả cho cả sáu bảng.
- [x] Yêu cầu test HIGH đã xác định: idempotency đa locale/đa định dạng, chuỗi
      retry, ma trận trạng thái tổng hợp, tenant isolation, bất biến của bản
      `archived`.
- [ ] Owner Approval recorded.
- [ ] DOC-CONFLICT-0014 và DOC-CONFLICT-0015 chưa đóng.

# Documented Risks

| # | Rủi ro | Trạng thái |
| --- | --- | --- |
| R1 | `quota_exceeded` là reserved behavior; `saas_usage_counters`, `saas_usage_events`, `saas_usage_summaries` đều `not_implemented`, nên không hạn mức nào được thi hành | Chặn việc mở media processing cho tenant tự phục vụ |
| R2 | Chính sách retention/redaction cho extracted text và transcript chưa có; nội dung học liệu có thể chứa dữ liệu cá nhân | Chặn việc mở cho tenant thật |
| R3 | Media Read Contract cho AI chưa viết | Không chặn substrate; chặn mọi consumer AI |
| R4 | `owner_type` không có ràng buộc vật lý (DOC-CONFLICT-0015) và `course_category` chưa được phê chuẩn (DOC-CONFLICT-0014) | Không chặn substrate; chặn việc siết vocabulary |
| R5 | Sáu bảng chưa tồn tại; đây là phase triển khai Foundation, không phải hoàn thiện tài liệu | Đã ghi nhận trong scope |

# Independent Review Round 2 — 2026-08-23 (`e460dce`, branch `main`)

Reviewer độc lập đọc diff thật trên `main` và trả verdict `BLOCKED` với ba phát
hiện. Cả ba đều đúng và đều thuộc lớp implementation của hợp đồng, không chạm
ranh giới kiến trúc.

| Mức | Phát hiện | Khắc phục |
| --- | --- | --- |
| Blocker | `virus_scan` không thể đạt `ready`: nó là job bắt buộc cho mọi `file_type`, nhưng CHECK đòi job `ready` phải có `output_id`, mà scan không sinh asset nào. Hệ quả: **không Media File nào đạt được `ready`** | `media_processing_jobs` v2.2 phân nhóm job sinh asset và job không sinh asset; CHECK tách làm ba, cho phép đúng `virus_scan` `ready` mà không có output. Nhiễm virus vẫn ghi dấu bằng `failed` + `error_code = infected_source` |
| Blocker | Unique key Version 1.0 vẫn còn nguyên trong transcript và caption, chặn đúng cơ chế revision mà contract mới cam kết. Hai tài liệu mang **hai block SQL mâu thuẫn** | Đã loại bỏ hai key cũ, gộp về một block, và ghi rõ trong tài liệu vì sao chúng bị bỏ |
| High | Caption locator không biểu đạt được trích dẫn thật: một row là một file VTT/SRT/ASS chứa nhiều cue, nhưng contract bắt nó mang đúng một `timespan` | Caption v1.2 bỏ hẳn locator và trở lại đúng bản chất file asset. Trích dẫn theo thời gian dùng `media_transcripts`, nơi một row đã là một đoạn. Cue-level citation nếu cần là derived contract riêng, chốt trong Spec B |

Sửa kèm theo: `media_transcripts` v1.2 nói rõ một row là **một đoạn** chứ không
phải toàn bộ transcript — cùng nguyên tắc với một row là một trang ở
`media_extracted_texts` — và câu "một canonical transcript mỗi file/locale" của
Version 1.0 đã được thay vì processing versioning làm nó hết đúng.

Ghi nhận về nguồn gốc lỗi thứ hai: nó do một patch có script gắn thêm block SQL
mới mà không gỡ block cũ. Không công cụ nào bắt được — `docs:lint` kiểm metadata
và routing, không kiểm mâu thuẫn nội dung giữa hai đoạn trong cùng một tài liệu.
Đây là lý do vòng review đọc diff thật là bắt buộc, không phải hình thức.

# Review Result

```text
BLOCKED (Round 2) — ba phát hiện đã được khắc phục ở
media_processing_jobs v2.2, media_transcripts v1.2, media_captions v1.2 và
LF-Media-Processing-Contract v1.2. Chờ reviewer độc lập xác nhận lại.

Verdict dự kiến sau xác nhận: PASS WITH DOCUMENTED RISKS — ready for Owner
Approval; migration, runtime, API và queue worker vẫn chưa được authorize, và
media processing chưa mở cho tenant tự phục vụ khi R1 và R2 còn nguyên.
```

# Required Future Reviews

* Owner approval, rồi chuyển bốn Database Document sang `Approved`.
* Media Read Contract for AI Consumers (Spec B), review riêng.
* Chính sách retention/redaction cho nội dung trích xuất.
* Runtime contract cho SaaS Usage/Entitlement trước khi `quota_exceeded` có hiệu
  lực thật.
* HIGH implementation audit trước migration sáu bảng.
* DOC-CONFLICT-0014 và DOC-CONFLICT-0015.
* Cue-level caption citation contract, nếu Spec B cần tới nó.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
