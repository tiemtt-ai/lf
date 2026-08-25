# LF-Media-Read-Contract.md

Version: 1.3

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-24

Document Path: platform/LF-Media-Read-Contract.md

Related ADR:

* [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)

Related Specification:
[LF-Media-Processing-Contract](LF-Media-Processing-Contract.md)

---

# Scope

Hợp đồng đọc output dẫn xuất của Media cho consumer, trước hết là AI.

Đây là Spec B. Substrate sản xuất output nằm ở
[LF-Media-Processing-Contract](LF-Media-Processing-Contract.md); tài liệu này chỉ
quy định cách đọc chúng ra.

Không thuộc phạm vi: AI Proposal persistence, review workflow, ghi Learning
Node/Mapping. Tất cả vẫn gated theo ADR-0017 §268.

---

# 1. Nguyên tắc

AI là **consumer**. Nó không sở hữu processing state, và ba điều sau là tuyệt
đối:

* AI **không** đọc trực tiếp object storage.
* AI **không** query bảng `media_*`.
* AI **không** ghi bất kỳ bảng `media_*` nào.

Mọi truy cập đi qua Media Read Service. Lý do không phải hình thức: quyền truy
cập, trạng thái readiness, chọn locale và citation locator đều là luật của Media,
và một consumer đọc thẳng bảng sẽ tự diễn giải lại chúng — mỗi consumer một
kiểu.

---

# 2. Đơn vị đọc

Service trả **derived content unit**, không trả file:

```text
unit := {
  media_file_id, source_fingerprint, processing_version,
  content_type,           // extracted_text | transcript | caption_asset | variant
  locale,
  locator: { type, value },   // page | timespan | null
  text,                   // null với caption_asset và variant
  delivery_url,           // signed, chỉ với caption_asset và variant
  confidence,             // null khi provider không báo cáo
  status
}
```

`locator` là `null` chỉ với `caption_asset` và `variant` — hai thứ là file, không
phải đoạn trích dẫn được. Xem § 5.

---

# 3. Định danh nguồn

Consumer gọi theo **owner context**, không theo `media_file_id`:

```text
GET derived-content
  actor_id              bắt buộc; explicit cho HTTP | queue | console
  owner_type            course_activity | course_version_activity
  owner_id
  content_type
  locale                (tuỳ chọn; xem § 4)
  processing_version    (tuỳ chọn; xem § 4.1)
  source_fingerprint    (tuỳ chọn; xem § 4.1)
```

Media tự phân giải owner → active usage → Media File. Consumer không được cầm
`media_file_id` trực tiếp, vì quyền truy cập gắn với owner chứ không gắn với
file: cùng một file có thể phục vụ hai Activity với hai mức quyền khác nhau.

Authorization: tenant từ context hiện hành, và actor phải được authorize trên
owner đó theo luật của Course Domain. Media không tự diễn giải business state
của owner; nó hỏi Course adapter.

Service không đọc actor ngầm từ HTTP request. Caller truyền `actor_id` tường
minh; authorizer nạp actor trong đúng `customer_id` và kiểm trạng thái/role.
Do đó cùng contract dùng được từ AI queue/console mà không nới quyền.

---

# 4. Chọn locale

1. Nếu request nêu `locale`, trả đúng locale đó hoặc lỗi `locale_unavailable`.
2. Nếu không nêu, trả locale canonical — `media_files.processing_locale`.
3. **Không** fallback sang locale khác, không dùng locale UI, browser hay user
   preference.

Fallback im lặng nguy hiểm hơn lỗi: một Proposal AI trích dẫn transcript tiếng
Hàn trong khi tác giả tưởng đang đọc tiếng Việt là sai lầm không ai phát hiện
được từ output.

## 4.1. Chọn revision

Mặc định service trả **bản hiện hành** — row `ready` mới nhất cho
`(owner, content_type, locale)`.

Revision hiện hành được chọn theo processing job identity lớn nhất
(`processing_job_id`, rồi `id` làm tie-break), không theo `created_at` của một
output row; nhiều page/segment của hai revision không thể làm lệch lựa chọn.

Consumer đọc lại một bản cũ bằng cách nêu đích danh:

| Tham số | Tác dụng |
| --- | --- |
| `processing_version` | Chọn đúng revision đó, kể cả khi status là `archived` |
| `source_fingerprint` | Ràng buộc thêm rằng revision đó dựng từ đúng nội dung nguồn này |

Quy tắc:

* Nêu `processing_version` thì service trả revision đó **bất kể** `ready` hay
  `archived`. Đây là ngoại lệ duy nhất của luật "chỉ trả `ready`" ở § 5, và nó
  tồn tại vì một Proposal đã trích dẫn trang 12 của bản cũ cần đọc lại đúng bản
  đó — không phải trang 12 sau khi OCR lại.
* Nêu cả hai thì cả hai phải khớp cùng một row; lệch nhau là lỗi, không phải
  ưu tiên cái này bỏ cái kia.
* Revision phải thuộc **đúng owner context** đang gọi. Một `processing_version`
  hợp lệ của Media File khác không cấp quyền đọc: authorization vẫn gắn với
  owner, không gắn với version.
* Không nêu gì thì không bao giờ trả `archived`.

| Tình huống | Mã lỗi |
| --- | --- |
| `processing_version` không tồn tại cho owner/content_type/locale này | `revision_unavailable` |
| `source_fingerprint` không khớp revision đã nêu | `revision_mismatch` |
| Revision tồn tại nhưng thuộc owner context khác | `unauthorized` |

---

# 5. Readiness và citation

Chỉ row `ready` được trả ra. Mọi trạng thái khác là mã lỗi có tên, không phải
mảng rỗng.

| content_type | Nguồn | Locator |
| --- | --- | --- |
| `extracted_text` | `media_extracted_texts` | `page` |
| `transcript` | `media_transcripts` | `timespan` |
| `caption_asset` | `media_captions` | `null` — file VTT/SRT/ASS, trả `delivery_url` |
| `variant` | `media_variants` | `null` — asset thay thế, trả `delivery_url` |

**Caption không trích dẫn được ở mức cue.** Một row là một file chứa nhiều cue.
Consumer cần trích dẫn theo thời gian phải dùng `transcript`, nơi một row đã là
một đoạn có `timespan` thật. Nếu về sau cần citation ở mức cue của chính caption
asset, đó là một derived cue contract riêng — mỗi cue một row với
`{timespan, text}` — và phải được review trước khi API mở nó.

Mọi unit trả ra **bắt buộc** kèm `source_fingerprint` và `processing_version`.
Consumer lưu hai giá trị này cùng mọi thứ nó tạo ra: đó là cách một Proposal
biết được nó đã đọc bản nào, và là điều kiện để phát hiện stale mà không phải
đoán.

---

# 6. Mã lỗi

Đóng, có tên, không dùng mảng rỗng để biểu đạt lỗi:

| Mã | Nghĩa |
| --- | --- |
| `pending` | Output chưa bắt đầu xử lý |
| `processing` | Đang xử lý; thử lại sau |
| `failed` | Xử lý thất bại; sẽ không tự có kết quả |
| `unauthorized` | Actor không được authorize trên owner |
| `detached` | Usage đã detach; output còn nhưng không phục vụ qua owner này |
| `archived` | Bản này đã bị thay bởi revision mới |
| `missing` | Owner không có Media nào ở `content_type` yêu cầu |
| `locale_unavailable` | Không có output ở locale yêu cầu |
| `unsupported_source` | MIME không nằm trong tập được hỗ trợ |
| `revision_unavailable` | `processing_version` được nêu không tồn tại trong owner context này |
| `revision_mismatch` | `source_fingerprint` không khớp revision đã nêu |

`archived` **vẫn đọc được** khi consumer nêu đích danh `processing_version`; quy
tắc và mã lỗi đầy đủ ở § 4.1. Không nêu thì mặc định là bản hiện hành và
`archived` không bao giờ được trả ra.

---

# 7. AI Knowledge Source

`ai_knowledge_sources` đăng ký theo derived content unit, không theo Media File,
và lưu `source_fingerprint` cùng `processing_version` của unit đã đọc.

Khi Media sinh revision mới, chunk và embedding dựng từ bản cũ trở thành
**stale** và phải rebuild. Media **không** tự động rebuild và không xoá gì của
AI: Media báo trạng thái, AI quyết định.

AI vẫn là consumer. Không có đường nào từ AI ghi ngược vào processing state.

---

# 8. Audit

Mỗi lần đọc thành công hoặc bị từ chối ghi một dòng `media_access_logs` với
`action = 'read_derived'`, `source_type` là consumer đã gọi, và metadata chứa
`decision = allowed|denied` cùng error code ổn định. Khi owner không resolve
được tới Media File trong tenant thì không thể ghi row vì audit schema bắt buộc
`media_file_id`; trường hợp đó vẫn fail-closed nhưng không invent một FK giả.
OCR và transcript có thể chứa dữ liệu cá nhân trong học liệu; ai đọc hoặc cố đọc
cái gì, lúc nào, phải trả lời được khi target tồn tại.

---

# Rủi ro đã ghi nhận

| # | Rủi ro |
| --- | --- |
| B1 | Chính sách retention/redaction cho extracted text và transcript chưa có. Chặn việc mở Read Service cho consumer thật |
| B2 | Cue-level caption citation chưa có contract; nếu AI cần, phải review trước |
| B3 | Sáu bảng `media_*` vẫn `not_implemented`; hợp đồng này không đọc được gì cho tới khi chúng tồn tại |

---

# Owner

Domain Owner (Media)

# Primary Consumers

* Developer
* Reviewer
* AI Agent
