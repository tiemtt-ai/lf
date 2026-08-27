# Media Processing Substrate Architecture Review

Version: 1.16

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-27

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
| Privacy ADR | [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved 2026-08-25 |
| Specification | [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) v2.0 Approved |
| Database Docs | `media_files` v1.4, `media_processing_jobs` v2.4, `media_extracted_texts` v1.0, `media_transcripts` v1.4, `media_captions` v1.4, `media_variants` v1.1, `media_access_logs` v1.2 |
| Read Contract Evidence | [Media Read Contract Architecture Review](LF-Media-Read-Contract-Architecture-Review.md) v1.2 — self-assessment; independent review pending |
| Review Scope | Substrate xử lý Media; Spec B remains a separate pending independent review. AI Proposal and learner runtime stay out of scope |

# Review Scope

Reviewed: hợp đồng trigger/scope, orchestration, fingerprint, output profile,
citation locator, đo lường, và bốn table contract nêu trên.

Not reviewed trực tiếp và cố ý ngoài phạm vi: Media Read Contract cho AI consumer
(Spec B) — có A–H author self-assessment packet nhưng chưa có independent
reviewer/verdict; review này không thay thế gate đó; AI Proposal
persistence và review workflow (gated bởi
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
- [x] Output profile canonicalization chốt key ordering, BCP 47, enum/boolean,
      empty profile hashing và default Phase 1; worker không còn quyền đoán.
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
- [x] Retry chain, limit, backoff eligibility và highest attempt đều dùng full
      scope có `customer_id` và `output_profile_hash`; profile khác độc lập.
- [x] Required profile set Phase 1 xác định chính xác theo file type; additional
      locale/format là optional/on-demand và không tham gia aggregate.
- [x] Canonical locale lấy từ cột `media_files.processing_locale`, do
      Course service ghi từ actor attach được authorize; thiếu/xung đột locale
      fail-closed với `required_profile_configuration_missing`, không treo.
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
- [x] Cùng profile/cùng attempt vẫn bị database unique key chặn; `vi`/`ko` và
      `vi-VTT`/`vi-SRT` có retry chain độc lập.
- [x] CHECK cho mọi enum và mọi bất biến trạng thái–thời gian–output.
- [x] Locator có hợp đồng chung, chốt trước khi tạo bảng.
- [x] Forward migration đã được author và kiểm chứng trên database tạm.
- [ ] Sáu bảng vẫn `not_implemented` trên database tham chiếu; migration chưa
      được deploy vào `learnforge_db`.

# F — Architecture

- [x] Idempotency nằm ở database, không ở queue.
- [x] Mỗi lần gọi provider có tính phí để lại đúng một row, kể cả lần thất bại.
- [x] Concurrency thi hành bằng row lock trên Media File, không bằng queue lock.
- [x] Không có dead-letter queue riêng; chuỗi job là dead-letter record đọc được
      bằng SQL và không hết hạn.
- [x] Ranh giới ADR-0017 giữ nguyên: không mở AI Proposal, không ghi Learning.

# G — Documentation

- [x] Các tài liệu contract/database có metadata đầy đủ và được route từ
      `LF-INDEX.md`.
- [x] `docs:lint` và `schema:drift` passed.
- [x] Chín tài liệu `database/media/` đã gỡ khỏi legacy metadata allowlist
      (106 → 98 file), nên lint từ nay thực sự kiểm chúng.
- [x] Đã commit lên `main` tại `e460dce`; reviewer độc lập đã đọc diff thật.

# H — Ready For Next Gate

- [x] Migration shape đã được mô tả cho cả sáu bảng.
- [x] Yêu cầu test HIGH đã xác định: transcript `vi` fail đủ 3 attempt vẫn không
      ngăn transcript `ko` enqueue/ready/retry; caption `vi-VTT` và `vi-SRT` có
      retry chain độc lập; duplicate cùng profile/cùng attempt bị database chặn;
      required/optional aggregate, tenant isolation và bản `archived` bất biến.
- [x] Owner Approval recorded theo directive ngày 2026-08-24.
- [ ] DOC-CONFLICT-0014 và DOC-CONFLICT-0015 chưa đóng.

# Documented Risks

| # | Rủi ro | Trạng thái |
| --- | --- | --- |
| R1 | `quota_exceeded` là reserved behavior; `saas_usage_counters`, `saas_usage_events`, `saas_usage_summaries` đều `not_implemented`, nên không hạn mức nào được thi hành | Chặn việc mở media processing cho tenant tự phục vụ |
| R2 | ADR-0018 đã approve: PII presence không phải OCR failure, local deterministic processing giữ owner/tenant authorization, redaction tạo derivative riêng và external provider cần approval độc lập. Retention duration/deletion orchestration/full provenance audit vẫn chưa có implementation evidence | **Narrowed, vẫn open** — không chặn candidate/local OCR chỉ vì PII; vẫn chặn production/real-tenant rollout và external processing chưa approved |
| R3 | Media Read Contract v1.3 có scoped runtime/test nhưng A–H record do cùng implementation stream lập; chưa có independent reviewer | **Open** — chặn việc coi Spec B là architecture-approved và chặn real AI consumer rollout |
| R4 | `owner_type` không có ràng buộc vật lý (DOC-CONFLICT-0015) và `course_category` chưa được phê chuẩn (DOC-CONFLICT-0014) | Không chặn substrate; chặn việc siết vocabulary |
| R6 | Code đã đổi upload mới sang `processing`; `virus_scan` clean mới đưa file về `ready`, infected hoặc provider unavailable đưa về `failed` | **Closed in code và development**; localhost dùng fake adapter. Production vẫn bị chặn tới khi có virus provider thật |
| R5 | Forward migration đã deploy trên `learnforge_db`; ledger không còn pending, connection drift xanh, 14 Media File cũ vẫn `ready` | **Closed** — deployment không sửa trạng thái dữ liệu lịch sử |
| R7 | Denied read chỉ ghi `media_access_logs` khi owner resolve được tới Media File; schema hiện tại bắt buộc FK nên dò owner không tồn tại không có audit sink | Mở; cần security audit sink không phụ thuộc Media FK qua contract/review riêng. Audit insert failure hiện phát `Log::warning`, không còn mất dấu im lặng |
| R8 | Hard kill (`SIGKILL`), OOM, container eviction hoặc host failure không chạy job `failed()`; row có thể còn `processing` và giữ concurrency guard | **Open, không chặn local** — trước AWS production phải có reviewed stale-processing sweeper chuyển row quá `$timeout + safety margin` sang `failed/provider_timeout` bằng conditional update |

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

## Blocker closure addendum — 2026-08-24

Hai contract gap còn lại đã được đối chiếu nhất quán trong contract v1.3 và
database docs liên quan:

1. Retry identity không còn rút gọn theo job. Full scope là `(customer_id,
   media_file_id, job_type, source_fingerprint, processing_version,
   output_profile_hash)` cho retry chain, limit, backoff và highest attempt.
   Unique key hiện hữu tiếp tục chặn duplicate cùng profile/cùng attempt.
2. Required output profile set Phase 1 đã deterministic: locale canonical được
   persist tại cột `media_files.processing_locale`; OCR dùng
   `layout=preserve`, STT dùng `diarization=off`, caption dùng `format=vtt`.
   Missing/conflicting locale fail-closed, còn additional profile không tham gia
   file aggregate.

Evidence là wording đồng bộ tại `LF-Media-Processing-Contract` v1.3,
`media_files` v1.3, `media_processing_jobs` v2.3, `media_transcripts` v1.3 và
`media_captions` v1.3. Đây là contract review evidence, không phải runtime test
evidence; sáu bảng vẫn chưa được implement.

# Independent Review Round 3 — 2026-08-23

Round 2 báo hai blocker. Vòng kiểm chứng độc lập trên `7c9ae99` cho thấy **một**
blocker đã đóng (locale/required profile) và **một** vẫn nguyên, cộng một defect
mới do chính cách sửa Round 2 tạo ra.

| Phát hiện | Trạng thái |
| --- | --- |
| Deliverability: ma trận tổng hợp vẫn để `ocr`/`speech_to_text` chặn `media_files.status`. Ghép với `MediaFileDeliveryController` (404 nếu `status <> 'ready'`), `CourseActivityMediaPresenter` và `CourseActivityMediaPreviewAuthorizer`, hệ quả là **video 404 trong suốt thời gian phiên âm** | Đã sửa ở Contract v1.3 và `media_files` v1.2: `status` chỉ phản ánh deliverability, chỉ `virus_scan` và cấu hình profile ảnh hưởng nó; output dẫn xuất đọc từ chính row output |
| Locale canonical đặt trong `metadata` JSON — một khóa nghiệp vụ sống trong JSON tự do, đúng thứ contract cấm với `source_fingerprint` và `processing_version`. Locale quyết định `output_profile_hash`, tức quyết định unique key của job | Đã nâng thành cột thật `processing_locale`, kèm `processing_error_code`. Cả hai đánh dấu **Not Implemented** cho tới migration |
| Điểm dispatch chưa được quy định | Contract v1.3 thêm mục "Điểm dispatch": enqueue **sau commit** (`DB::afterCommit`), và đặt ở service dùng chung vì đã có hai entry point cùng gọi `attachUploadedMedia` |

Ghi nhận: cả ba đều là defect ở lớp implementation của hợp đồng; không cái nào
chạm ranh giới sở hữu domain. Nhưng defect thứ nhất chỉ lộ ra khi đối chiếu
contract với **code đang chạy** — `docs:lint` và `schema:drift` xanh suốt trong
lúc nó tồn tại, vì không công cụ nào so hợp đồng tài liệu với gate trong
controller.

# Review Result

```text
PASS WITH DOCUMENTED RISKS — không còn blocker contract. Architecture Owner đã
approve scoped implementation ngày 2026-08-24; Spec B independent review vẫn
pending.
Forward migration đã deploy và ba mode schema drift đều pass. Development có
fake virus adapter; production virus provider thật vẫn là deployment
precondition. Retention/redaction và self-service quota tiếp tục gated; R5/R6
đã đóng theo trạng thái trong bảng risks.
```

# Owner Approval

```text
Role: LearnForge Architecture Owner
Date: 2026-08-24
Decision: Approved for forward migration and scoped runtime implementation by
          the owner directive recorded in the implementation request. No
          approval is inferred for retention policy or external providers.
```

# Required Future Reviews

* Chính sách retention/redaction cho nội dung trích xuất.
* Runtime contract cho SaaS Usage/Entitlement trước khi `quota_exceeded` có hiệu
  lực thật.
* DOC-CONFLICT-0014 và DOC-CONFLICT-0015.
* Cue-level caption citation contract, nếu Spec B cần tới nó.

Owner decisions đang chờ, không được implementation tự chọn:

1. DOC-CONFLICT-0014 — `course_category` có phải canonical `owner_type` hợp lệ:
   **Có hay Không?**
2. DOC-CONFLICT-0015 — sau khi vocabulary được chốt, có thêm CHECK constraint
   cho `media_file_usages.owner_type`: **Có hay Không?**

# HIGH Implementation Audit — 2026-08-24

Verdict: **PASS WITH DOCUMENTED PRODUCTION GATES** cho migration, orchestration,
fake provider và Media Read Service trong scope đã duyệt.

Evidence đã chạy trên `main`:

* migration dry-run sinh đủ sáu bảng, hai cột `media_files`, composite tenant
  foreign keys, retry uniqueness và append-only trigger;
* fake provider xác minh clean/infected virus scan, OCR/STT/caption output,
  required profile deterministic và binary readiness độc lập;
* transcript `ko` hết ba attempt không tiêu hao/chặn transcript `vi`; caption
  `vi-VTT` và `vi-SRT` có chain độc lập; duplicate cùng profile/cùng attempt bị
  database chặn;
* retry chỉ từ highest attempt, exponential backoff có jitter ±20%, dispatch
  sau commit và rollback không enqueue;
* Media Read Service xác minh tenant/owner authorization, exact locale/revision,
  archived explicit read, revision mismatch/unavailable, locator và immutable
  access audit (UPDATE/DELETE đều bị database chặn);
* full application suite: `745` tests, `8292` assertions, `1` skipped, `0` failure.

Implementation Status giữ ở `Partial`: migration đã deploy trên development,
document OCR self-hosted đã chạy local nhưng chưa deploy trên AWS; production
STT/caption/virus provider chưa được chọn hoặc cấp credential. Runtime
fail-closed bằng `provider_unavailable`.

## Local document provider evidence — 2026-08-25

Scoped runtime `local_document` đã nối capability document OCR mà không mở thêm
domain side effect. Source được đọc bằng Laravel Storage stream (local/S3), file
trung gian nằm trong thư mục tạm riêng và luôn cleanup. Provider chỉ trả units;
`ProcessMediaProcessingJob` giữ quyền persist tenant/job revision vào
`media_extracted_texts`, gồm provenance `embedded_text` hoặc `ocr` theo từng
unit.

Evidence local trên source thật:

* DOCX media 5: job `ready`, 1 page `embedded_text`, locale `ko`, 932 ký tự;
* PDF media 8: job `ready`, 2 page `embedded_text`, locale `vi`, lần lượt 4.604
  và 3.122 ký tự;
* XLSX media 11: LibreOffice conversion, job `ready`, 13 page text units;
* image-only PDF smoke: Poppler render + Tesseract `vie+eng`, 1 page `ocr`,
  3.279 ký tự; object smoke đã bị xoá sau test;
* feature test xác minh upload TXT thật đi hết provider runtime và row
  `media_extracted_texts` giữ đúng text/method/provider.

Evidence này phê duyệt shape implementation self-hosted trong scope owner yêu
cầu, không suy diễn rằng AWS deployment đã sẵn sàng. AWS worker còn phải đóng
binary/container/IAM/ephemeral-storage/observability và production activation
vẫn bị R1/R2 cùng virus-provider gate chặn. STT/caption vẫn unconfigured.

### Resource-control review closure

Review hậu provider phát hiện một blocker và hai resource-control findings.
Đã đóng bằng implementation và regression evidence:

* `ProcessMediaProcessingJob` có timeout `3.600s`, `failOnTimeout=true` và
  `failed()` chuyển duy nhất row còn `processing` sang
  `failed/provider_timeout`; test chứng minh row sau đó tạo được retry attempt 2;
* provider deadline `3.300s` co mọi command timeout theo thời gian còn lại;
  Redis/database/Beanstalkd retry-after default `3.900s`, giữ invariant
  `provider < worker < visibility`;
* page count được lấy trước extraction và cap 100; test 101 trang fail bằng
  `page_limit_exceeded` trước command extraction;
* DOCX kiểm declared expanded size và bounded-copy 8 MB; test archive 101 byte
  với cap 100 byte fail trước copy;
* text cap hạ từ 20.000.000 xuống 500.000 ký tự và fail toàn revision, không
  truncate artifact.

Resource-control verdict: **PASS cho local runtime shape**. AWS deployment còn
phải chứng minh đồng thời SQS visibility `> 3.600s`, supervisor timeout
`>= 3.600s`, SQS message retention `> visibility timeout`, và đóng R8 bằng
stale-processing sweeper đã review. Đây là deployment evidence, không phải lý
do để mở R1/R2.

**Deployment precondition:** không deploy runtime Media Processing này nếu
forward migration chưa được apply hoặc `MEDIA_VIRUS_SCAN_PROVIDER` chưa trỏ tới
provider production đã approved/configured/credentialed. Vi phạm điều kiện thứ
hai làm mọi upload mới lập tức `failed` và không deliver được; đây không phải
rủi ro có thể cấu hình sau khi ship.

Không có Owner Approval suy diễn cho external provider, retention/redaction hay
self-service quota. R1–R4 và R7 còn mở; R5/R6 đã đóng trên development.

## PII policy amendment review — 2026-08-25

Review Version 1.15 đánh giá ADR-0018 và contract Version 2.0, không
đánh giá runtime implementation.

| Boundary | Review result |
| --- | --- |
| PII presence | Không phải `failed`/`cancelled`; không chặn enqueue hay local deterministic OCR |
| Local processing eligibility | Chỉ hợp lệ trong tenant/owner authorization và provider boundary đã approved |
| Redaction | Derivative riêng, fingerprint/version/provenance riêng; không sửa source |
| External processing | Cần approval riêng theo provider/purpose/data/retention/audit; local OCR không cấp quyền này |
| Media Read | Không nới quyền cho AI consumer; owner-context authorization và decision audit giữ nguyên |
| Retention/audit | Bao phủ source, OCR/transcript, redacted derivative, crop và AI-derived output; duration/deletion implementation vẫn là gate |
| A0 corpus | PII được phép khi có Owner approval/evidence, local-only, restricted access, no external call và deletion date |
| Resource limit | Độc lập với PII; PDF 121 trang vẫn `page_limit_exceeded` với limit 100 |

Không phát hiện hai nguồn Approved mâu thuẫn thật sự; đây là contract gap đã
được đóng bằng ADR-0018 nên không tạo conflict record mới. Verdict phần policy
là `PASS WITH DOCUMENTED RISKS`: Owner đã approve boundary; R2 vẫn mở cho
retention/deletion implementation evidence và mọi external provider vẫn cần
approval riêng. Review cũ về substrate/runtime không bị viết lại thành approval
cho external provider.

### Owner Approval — PII policy amendment

```text
Role: LearnForge Architecture Owner
Date: 2026-08-25
Decision: Approved ADR-0018 and the aligned documentation contracts.
          No runtime, deployment or external-provider approval is inferred.
```

## Kênh biểu đạt trạng thái triển khai — chốt 2026-08-24

Một vòng closure đã hạ sáu bảng xuống `not_implemented` để phản ánh việc chưa
deploy. Cách đó trung thực nhưng sai kênh, và nó làm **hai CI gate đang xanh
chuyển sang đỏ**: `docs-lint.yml` chạy cả `schema:drift --docs-only` và
`schema:drift --fresh`, và cả hai đều fail vì công cụ coi "contract deferred mà
có migration tạo bảng" là HIGH.

Ngữ nghĩa thật của công cụ, đo bằng cách chạy cả ba mode:

| `implementation_status` trong contract | Nghĩa |
| --- | --- |
| `implemented` | Migration tạo bảng **tồn tại trong source**. `--fresh` dựng database từ migration rồi đối chiếu, nên đây là điều kiện để hai CI gate xanh |
| `not_implemented` | Không có migration nào tạo bảng đó |

Việc **đã deploy hay chưa** không nằm trong contract. Nó được `--connection`
trả lời, và mode đó cố ý không phải CI gate vì nó phụ thuộc trạng thái của một
database cụ thể.

Đây cũng là tiền lệ Learning Foundation: migration author ở Phase 4C/4D với
`--fresh` xanh, rồi deployment lên development database là một bước được Owner
cho phép riêng, ghi bằng số bảng và ledger.

Trạng thái trước deployment:

```text
schema:drift --docs-only      passed      ← CI gate
schema:drift --fresh          passed      ← CI gate
schema:drift --connection=mysql  failed   ← trước deployment:
   6 table.missing · 2 column.missing · 1 index · migration.pending
```

Sáu table doc và schema contract trở lại `Implemented`; contract được dựng lại
từ chính database sạch nên mô tả đúng cột, index, khóa ngoại, CHECK và trigger
thật.

Deployment evidence ngày 2026-08-24: migration đã apply trên `learnforge_db`;
cả ba mode `schema:drift --connection=mysql`, `--docs-only` và `--fresh` đều
pass; database có đủ 9 bảng `media_*`; migration ledger không còn pending; 14
Media File lịch sử vẫn giữ `status=ready`. R5 vì vậy được đóng.

## Independent runtime review closure — 2026-08-24

Review độc lập hậu implementation báo `BLOCKED` với 2 blocker, 2 high và 3
medium. Runtime đã đóng các phát hiện có thể xử lý trong scope:

* virus provider chưa cấu hình chuyển cả scan job và Media File sang
  `failed/provider_unavailable` ngay; không còn treo `processing`, không bypass
  binary scan;
* Media Read nhận `actor_id` explicit, không phụ thuộc HTTP request, nên cùng
  authorization contract dùng được trong queue/console;
* read bị từ chối trên target resolve được ghi append-only audit với
  `decision=denied` và error code;
* duplicate initial insert bắt database uniqueness, đọc lại canonical row khi
  race thay vì trả 500;
* checksum NULL/rỗng fail-closed trước fingerprint;
* current revision chọn theo `processing_job_id`/row identity, không theo
  timestamp của từng page/segment;
* thêm `MediaProcessingSubstrateMariaDbTest` và CI MariaDB entry để thực thi
  CHECK cùng immutable UPDATE/DELETE trigger vật lý.

MariaDB integration đã được reviewer chạy thật: `2 passed`; toàn bộ job CI
MariaDB gồm 9 file: `98 passed, 439 assertions`. Runtime findings đã đóng.

Database/schema deployment blocker đã đóng. Gate còn lại trên upload path là
provider: development `.env` dùng `fake` để smoke-test/local authoring; không
được diễn giải adapter này là virus scan thật hoặc production approval.

## Course Activity document upload closure — 2026-08-27

```text
Capability: Upload/replace/remove document for course_activity
Development verdict: PASS
Production verdict: BLOCKED pending approved virus-scan provider
Media Processing overall: PARTIAL
```

Closure evidence nằm ở implementation và regression suite hiện hành:

* attach được tenant-scope qua `media_file_usages` và owner Course authorize;
* create/edit/replace/remove giữ đúng lifecycle và redirect;
* duplicate attach giữ idempotency, replace không sửa binary lịch sử;
* private preview/delivery fail-closed theo tenant, owner context và trạng thái
  file;
* dispatch processing sau commit; rollback không để lại job rác;
* OCR/extracted text là output dẫn xuất, không đổi kết luận upload capability.

Đóng capability này **không** đóng structured extraction, Gate M/Gate R, Spec B
production access, provider OCR/STT/caption production, retention/redaction hay
AI Knowledge. Không được dùng verdict hẹp này để đổi `Implementation Status` của
toàn review khỏi `Partial`.

---

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
