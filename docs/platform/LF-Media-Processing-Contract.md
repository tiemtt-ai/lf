# LF-Media-Processing-Contract.md

Version: 1.2

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-23

Document Path: platform/LF-Media-Processing-Contract.md

Related ADR:

* [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)

---

# Scope

Hợp đồng cho **substrate xử lý Media**: khi nào một tác vụ được kích hoạt, nó
chạy ra sao, kết quả sống ở đâu, và khi nào kết quả hết hiệu lực.

Tài liệu này **không** định nghĩa Media Read Contract cho AI consumer. Đó là một
tài liệu riêng, phụ thuộc tài liệu này. Tách ra để substrate không bị hợp đồng
consumer chặn — substrate là đường dài nhất và không cần AI để bắt đầu.

Không thuộc phạm vi: AI Proposal persistence, Proposal review workflow, ghi
Learning Node/Mapping, và automatic publish. Tất cả vẫn bị gate theo ADR-0017.

---

# 1. Trigger và phạm vi

Xử lý **không** chạy theo mọi file trong Media Library. Điều kiện kích hoạt là
tất cả các mệnh đề sau cùng đúng:

```text
media_files.status = 'ready' cho phần upload
+ tồn tại media_file_usages ở status 'active'
+ owner_type của usage nằm trong tập được phép xử lý
+ actor gắn usage được authorize trên owner đó
```

Tập `owner_type` được phép xử lý trong Phase 1: `course_activity`.

`course_version_activity` là usage của **published Version Activity** và là bản
bất biến. Nó không kích hoạt job mới: nội dung nhị phân giống hệt file gốc, nên
`source_fingerprint` giống nhau và output đã có sẵn được dùng lại. Đây là lý do
fingerprint neo vào nội dung chứ không neo vào usage.

Không kích hoạt xử lý cho `avatar`, `marketing`, `certificate` hoặc bất kỳ
`owner_type` nào ngoài tập trên. Mở rộng tập này là amendment có review.

## Fan-out theo file type

| `file_type` | Job bắt buộc | Job tuỳ chọn |
| --- | --- | --- |
| `document` | `virus_scan`, `ocr` | `thumbnail` |
| `audio` | `virus_scan`, `speech_to_text` | — |
| `video` | `virus_scan`, `speech_to_text`, `caption` | `transcode`, `thumbnail` |
| `image` | `virus_scan` | `thumbnail` |

Chỉ job **bắt buộc** ảnh hưởng `media_files.status`. MIME không nằm trong tập
được hỗ trợ thì không sinh job nào và không đánh dấu file `failed` — nó chỉ
không có output, và Read Service trả mã lỗi `unsupported_source`.

---

# 2. Orchestration

## Idempotency

`media_processing_jobs.idempotency_key` được sinh xác định, không ngẫu nhiên:

```text
idempotency_key = job_type : media_file_id : source_fingerprint : processing_version : output_profile_hash : attempt
```

Cùng file, cùng loại job, cùng nội dung, cùng phiên bản xử lý, cùng output
profile, cùng lần thử thì
chỉ tồn tại đúng một row. Việc chống trùng nằm ở `UNIQUE (customer_id,
idempotency_key)` trong database, không ở tầng queue: queue có thể giao một
message hai lần, và OCR cùng speech-to-text đều là lời gọi ngoài có tính phí.

## Retry

Retry **luôn tạo row mới** với `attempt = attempt + 1` và `supersedes_job_id`
trỏ về row trước. Không sửa row cũ. Hệ quả có chủ đích: mỗi lần gọi provider có
tính phí đều để lại đúng một row, kể cả lần thất bại, nên chi phí truy vết được.

* Backoff: mũ, gốc 60 giây, hệ số 2, jitter ±20%.
* Giới hạn: 3 attempt cho mỗi `(media_file_id, job_type, source_fingerprint,
  processing_version)`.
* Chỉ retry khi `error_code` thuộc nhóm tạm thời (`provider_timeout`,
  `provider_unavailable`, `rate_limited`). Lỗi vĩnh viễn
  (`unsupported_source`, `corrupt_source`, `quota_exceeded`) không retry.
* Hết attempt: job cuối giữ `failed`, và file chuyển `failed` nếu job đó bắt
  buộc. Không có dead-letter queue riêng — chuỗi job **là** dead-letter record,
  đọc được bằng SQL và không hết hạn.

## Cancellation

`cancelled` chỉ đi từ `pending`. Nói chính xác: **không hỗ trợ operator
cancellation sau khi đã dispatch**. Job đã `processing` không bị huỷ giữa chừng
vì lời gọi provider đã phát sinh chi phí, và một row `cancelled` che mất điều đó.

Nếu provider trả kết quả muộn sau khi vận hành đã bỏ cuộc, job vẫn phải kết thúc
bằng `ready` hoặc `failed` — không được để treo và không được chuyển `cancelled`.
Chi phí đã phát sinh thì phải còn dấu vết.

Huỷ chỉ xảy ra khi usage bị detach trước lúc job bắt đầu, hoặc khi tenant vượt
hạn mức.

## Concurrency

Tối đa một job `processing` cho mỗi `(media_file_id, job_type)`. Thi hành bằng
`SELECT … FOR UPDATE` trên Media File khi chuyển `pending → processing`, không
bằng lock ở tầng queue.

## Trạng thái tổng hợp

`media_files.status` là **read state dẫn xuất**, không do worker ghi tuỳ ý:

```text
mọi job bắt buộc ở 'ready'          → media_files.status = 'ready'
một job bắt buộc 'failed', hết retry → media_files.status = 'failed'
còn job bắt buộc chưa kết thúc       → media_files.status = 'processing'
```

Vocabulary đã hợp nhất về **từ ngữ**, không phải về phạm vi. Ba tầng dùng chung
`processing` / `ready` / `failed`, nhưng mỗi tầng nói về một thứ khác nhau:

| Tầng | `ready` nghĩa là |
| --- | --- |
| Processing Job | Một lần chạy đã thành công |
| Output row (transcript, caption, extracted text, variant) | Chính artifact đó dùng được |
| Media File | **Mọi** output bắt buộc của processing profile đều dùng được |

Hệ quả cụ thể: `thumbnail` thất bại **không** làm video mất `ready`, vì thumbnail
là job tuỳ chọn. Ngược lại, một transcript `failed` làm video mất `ready` vì
`speech_to_text` là bắt buộc cho video. `completed` của Version 1.0 bị loại bỏ.

## Ma trận trạng thái tổng hợp

| Job bắt buộc | Job tuỳ chọn | `media_files.status` |
| --- | --- | --- |
| tất cả `ready` | bất kỳ | `ready` |
| tất cả `ready` | có `failed` | `ready` |
| có `failed`, hết attempt | bất kỳ | `failed` |
| còn `pending`/`processing` | bất kỳ | `processing` |
| có `cancelled` do `quota_exceeded` | bất kỳ | `processing`, chờ hạn mức |

## Ranh giới tác dụng phụ

Job completion không ghi Course Progress, Assessment Result, LiveClass
Attendance, Learning Evidence, Mastery hay bất kỳ AI business output nào. Media
sản xuất Digital Asset; diễn giải thuộc về Domain tiêu thụ.

---

# 3. Source fingerprint và processing version

## Fingerprint

```text
source_fingerprint = SHA-256( media_files.checksum || ':' || file_type )
```

Fingerprint trả lời đúng một câu hỏi: **nội dung nguồn là gì**. Nó không chứa
locale, định dạng đầu ra hay bất kỳ tham số yêu cầu nào — trộn chúng vào đây làm
mất khả năng nhận ra hai job đang đọc cùng một nội dung.

Nền tảng là `media_files.checksum` vốn đã bất biến. Fingerprint **không** gồm
`storage_key`, `display_name` hay bất kỳ metadata nào sửa được: đổi tên file
không được làm output hết hiệu lực.

## Output profile

Tham số quyết định output đi trong một trường riêng:

```text
output_profile      = danh sách khoá=giá trị đã chuẩn hoá, sắp xếp, phân tách bằng ';'
output_profile_hash = SHA-256( output_profile )
```

| `job_type` | `output_profile` gồm |
| --- | --- |
| `ocr` | `locale`, `layout` |
| `speech_to_text` | `locale`, `diarization` |
| `caption` | `locale`, `format` (`vtt`/`srt`/`ass`) |
| `transcode` | `preset` |
| `thumbnail` | `size` |
| `virus_scan`, `compress` | rỗng, hash của chuỗi rỗng |

Đây là lý do một video sinh được transcript `vi` **và** `ko`, và caption `vi`
VTT **và** `vi` SRT, mà không cái nào bị unique key từ chối như hàng trùng lặp.

Hệ quả cố ý: hai Media File khác nhau có cùng nội dung nhị phân sinh cùng
fingerprint, và published Version Activity dùng lại output của working Activity
mà không chạy lại OCR.

## Processing version

```text
processing_version = <extractor|provider>-<version>[+<config-hash>]
```

Ví dụ `tesseract-5.3.0`, `whisper-large-v3+a1b2c3`. Đổi model, đổi provider,
hoặc đổi cấu hình có ảnh hưởng kết quả đều là version mới.

## Stale và revision

Output **không bao giờ bị ghi đè**. Khi fingerprint hoặc processing version
đổi, chạy mới sinh bộ row mới; bộ cũ chuyển `archived`.

| Cái gì đổi | Hệ quả |
| --- | --- |
| Nội dung nhị phân (checksum) | Output cũ `archived`; chạy lại toàn bộ job bắt buộc |
| Extractor/model/cấu hình | Output cũ `archived`; chạy lại loại job liên quan |
| Tên file, display name, usage | Không ảnh hưởng |
| Usage bị detach | Không xoá output; Read Service trả `detached` |

Điều cấm tuyệt đối: chạy lại **không** được sửa hay xoá AI Proposal đã trích
dẫn output cũ, published Course snapshot, hay canonical Learning Mapping. Bản
`archived` phải đọc được mãi mãi, vì một Proposal trích dẫn trang 12 cần trang
12 mà nó đã đọc, không phải trang 12 sau khi OCR lại.

---

# 4. Citation locator

Một hợp đồng duy nhất cho mọi output, chốt **trước** khi tạo bảng, vì OCR neo
theo trang còn transcript neo theo thời gian và nếu để mỗi bảng tự sinh thì
consumer phải hợp nhất hai hình dạng không tương thích.

```text
locator := { locator_type, locator_value }
```

| `locator_type` | Áp dụng cho | `locator_value` |
| --- | --- | --- |
| `page` | extracted text (document) | Số trang, ≥ 1, text thập phân |
| `timespan` | transcript (audio/video) | `<start_ms>-<end_ms>`, số nguyên không âm, `start ≤ end` |

Quy tắc chung:

* `locator_value` luôn là text, không phải số — để hai hình dạng dùng chung một
  cột và một hợp đồng API.
* Locator phải ổn định suốt vòng đời của một `source_fingerprint`.
* Mọi output trả cho consumer phải kèm locator. Không có ngoại lệ: một trích
  dẫn không định vị được thì không phải trích dẫn.
* Thêm `locator_type` mới là amendment có review, không phải một giá trị lọt
  vào lúc code.

**Caption không nằm trong hợp đồng locator.** Một row `media_captions` là một
file asset VTT/SRT/ASS chứa nhiều cue với nhiều mốc thời gian; gán cho nó một
`timespan` duy nhất là bịa ra một mốc không tồn tại. Trích dẫn theo thời gian
dùng `media_transcripts`, nơi một row đã là một đoạn có `timespan` thật.

Nếu Media Read Contract cần trích dẫn ở mức cue của chính caption asset thì đó
là một derived contract riêng — mỗi cue một row với `{timespan, text}` — và phải
được chốt trong Spec B trước khi API consumer dựa vào nó.

---

# 5. Đo lường và hạn mức

Media processing **bị đo**. OCR và speech-to-text là lời gọi ngoài có tính phí,
và trigger là hành vi của tác giả, nên không đo đồng nghĩa với việc một tài
khoản có thể phát sinh chi phí không giới hạn.

* Mỗi job ghi `billable_units` và `billable_unit_type` khi kết thúc, kể cả khi
  `failed` sau khi provider đã tính phí.
* Đơn vị chuẩn: `page` cho OCR, `second` cho speech-to-text và caption,
  `byte` cho transcode.
* Kiểm hạn mức xảy ra **trước** khi chuyển `pending → processing`. Vượt hạn mức
  thì job chuyển `cancelled` với `error_code = quota_exceeded`, không retry.
* `quota_exceeded` là **reserved behavior** cho tới khi Commercial/Entitlement và
  SaaS Usage có runtime contract. Trước thời điểm đó không hạn mức nào được thi
  hành, nên không được mở media processing cho tenant tự phục vụ.

**Phụ thuộc phải nêu rõ:** `saas_usage_counters`, `saas_usage_events` và
`saas_usage_summaries` hiện `not_implemented`. Cho tới khi Usage Foundation
được triển khai, job vẫn **ghi** `billable_units` nhưng **không có** nơi tổng
hợp và không có hạn mức nào được thi hành. Đây là gate bắt buộc trước khi mở
media processing cho tenant tự phục vụ, và phải được nêu trong Architecture
Review.

---

# 6. Bảo mật và vận hành

* Storage riêng tư; mọi truy cập qua signed delivery có thời hạn.
* Tenant isolation ở mọi truy vấn; không có đường đọc xuyên tenant.
* `error_message` và `metadata` không được chứa credential, token hay signed
  URL. Provider trả lỗi kèm URL thì phải cắt trước khi lưu.
* `correlation_id` xuất hiện trong mọi log của một chuỗi job.
* Retention và redaction cho extracted text/transcript: OCR và speech-to-text
  có thể chứa dữ liệu cá nhân trong nội dung học liệu. Chính sách lưu giữ phải
  được chốt trước khi mở cho tenant thật; tài liệu này chưa quy định nó.
* Metric tối thiểu: số job theo `status` và `job_type`, thời lượng, tỉ lệ retry,
  tỉ lệ `failed` theo `error_code`, và `billable_units` theo tenant.

---

# 7. Ranh giới AI

AI là **consumer**, không sở hữu processing state.

* AI không đọc trực tiếp object storage và không ghi bất kỳ bảng `media_*` nào.
* AI đọc qua Media Read Service — hợp đồng riêng, chưa được viết.
* AI Proposal persistence, review workflow, ghi Learning Node/Mapping và
  automatic publish vẫn bị gate theo ADR-0017 §268 và không được mở bởi tài
  liệu này.

---

# Owner

Domain Owner (Media)

# Primary Consumers

* Developer
* Reviewer
* AI Agent
