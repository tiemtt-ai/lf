# Audio Processing — Final Code Review

Version: 2.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-01

Document Path: quality/LF-Audio-Processing-Final-Code-Review.md

---

## 1. Verdict

**PASS_LOCAL_AUDIO_PROCESSING**

Đường Audio local từ Course Activity usage đến `speech_to_text`,
`media_transcripts` theo timespan và Media Read đã được đối chiếu contract ↔ code
↔ test, sửa năm lỗi thực tế, và kiểm chứng bằng engine `faster_whisper_local`
thật trên cả SQLite lẫn MariaDB 11.4.12.

Sau khi sửa, trong phạm vi Audio local còn **0 Critical, 0 High, 0 Medium, 0 Low**
đang mở.

Verdict này **không** xác nhận toàn bộ Media, Document, Video STT, Caption,
AI Knowledge hoặc production.

---

## 2. Exact scope và deferred

### Trong phạm vi (đã đóng)

Audio Media gắn hợp lệ vào `course_activity` qua `media_file_usages`; materialize
và dispatch STT sau commit; provider local Faster Whisper; transcript theo
timespan; provenance `source_fingerprint` / `processing_version` / `locale` /
`processing_job_id`; retry, duplicate dispatch, detach/reattach, callback muộn,
stale revision, recovery job kẹt, concurrency claim; tenant isolation; Media Read
Service và audit `read_derived`; fresh migration và schema drift trên MariaDB
thật.

### `DEFERRED_PRODUCT_OR_PRODUCTION` — không phải finding, không chặn verdict

Product configuration, entitlement, quota/billing SaaS; cấu hình provider
production hoặc external STT provider; Redis/scheduler/worker production soak;
sizing, autoscaling, monitoring/alerting; benchmark chất lượng STT theo ngôn ngữ
ngoài fixture local (CER/WER vẫn `unavailable` theo Owner acceptance 2026-08-29);
retention/legal hold/purge orchestration production.

### Ngoài phạm vi hoàn toàn

AI Knowledge, AI Proposal, Learning Node/Mapping; **Video STT và Caption** cùng
mọi dependency của chúng. `MEDIA_VIDEO_STT_ENABLED` giữ mặc định `false` trong
suite review này; DOC-CONFLICT-0027 § Temporary Safety Rule (4) ghi rõ audio STT
và cap `7.200s` không bị ảnh hưởng bởi conflict video.

---

## 3. Tài liệu và code đã đọc

**Tài liệu:** `LF-INDEX.md` (routing § Media Processing);
`platform/LF-Media.md`; `platform/LF-Media-Processing-Contract.md` (v2.30, toàn
bộ § Scope, § 1 Trigger, § 2 Orchestration, § 3 Fingerprint/Output profile/
Processing version/Stale, § 4 Citation locator, § 5 Đo lường, § Speech-to-text
resource controls, Amendment 2.19/2.21/2.28, D1–D6);
`platform/LF-Media-Read-Contract.md` (v1.15, § 1–§ 8);
`adr/ADR-0004-Media-Foundation.md`;
`adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md`;
`database/media/media_files.md`, `media_file_usages.md`,
`media_processing_jobs.md`, `media_transcripts.md`, `media_access_logs.md`;
`quality/LF-Media-Processing-Substrate-Architecture-Review.md`;
`quality/LF-Media-Read-Contract-Architecture-Review.md`;
`LF-Development-Standards.md`; `quality/LF-Regression-Audit.md`;
`database/LF-Schema-Drift.md`; `database/LF-SCHEMA-CONTRACT.json`;
`quality/LF-Documentation-Conflicts.md` (conflict register).

**Kết luận về conflict register:** không có conflict đang mở nào chặn Audio local.
DOC-CONFLICT-0023/0024/0025/0026/0027 đều RESOLVED; 0026/0027 thuộc nhánh video.
Hai mục còn `DECISION_REQUIRED` — 0014 (`course_category` là `owner_type` không
được tài liệu đặt tên, LOW) và 0015 (`owner_type` không có ràng buộc vật lý,
MEDIUM) — là mục Media-wide đã đăng ký, thuộc quyết định Owner, **không** phải
finding của review này và không tái hiện trên đường Audio (`course_activity` là
`owner_type` duy nhất được xử lý ở Phase 1, và Media Read khớp chính xác
`(customer_id, owner_type, owner_id, usage_type)`).

**Code:** migrations `2026_08_24_000000_create_media_processing_substrate`,
`2026_08_26_000200_open_media_processing_job_structured_identity`,
`2026_08_31_000200_add_document_dispatch_generation`; `MediaService`
(`upload`, `attachUsage`, `detachUsage`, `deleteMedia`, `purgeDatabaseDerivedContent`);
`MediaProcessingOrchestrator`; `AudioProcessingEligibility`;
`ProcessMediaProcessingJob` (claim, persist, failure, `failed()`, `validatedTranscriptUnits`);
`FasterWhisperSpeechToTextProvider`; `runtime/stt/transcribe.py`;
`MediaOutputProfile`; `MediaReadService`; `CourseMediaOwnerContextAuthorizer`;
`MediaMetadataProbe`; `CourseTemplateActivityController` (attach + legacy STT
init); `config/media.php`; `routes/console.php`; `RecoverAudioProcessing`;
`MediaReadDerived`; `MediaReprocess`; và toàn bộ test Media/STT liên quan.

`TranscriptVttCaptionProvider`, `VideoAudioWorkspace`, `VideoSpeechToTextProfile`
và `VideoSttQualification` chỉ được đọc để xác nhận **không** chạm identity hay
đường thực thi của audio; chúng nằm ngoài verdict.

---

## 4. Contract → code → test matrix

| Requirement (nguồn) | Code | Test |
| --- | --- | --- |
| Trigger chỉ từ `media_files.status=ready` + active authorized `course_activity` usage (§ 1) | `MediaService::attachUsage`, `MediaProcessingOrchestrator::assertAudioUsage` | `test_missing_locale_fails_closed...`, `test_retry_after_provider_failure...` |
| Dispatch **sau commit**, không trong transaction tạo Activity (§ Điểm dispatch) | `attachUsage` → `DB::afterCommit` → `createInitialJob` → `DB::afterCommit` | `test_real_audio_activity_usage_after_commit_dispatch_and_authorized_read`; `test_after_commit_callback_is_not_dispatched_on_rollback` |
| Idempotency key xác định; queue giao hai lần không tạo hai job/hai lần gọi provider (§ Idempotency) | `initialIdempotencyKey`, `UNIQUE(customer_id, idempotency_key)`, claim `status='pending'` | `test_real_duplicate_dispatch_creates_one_job_and_one_transcript_revision` |
| Retry tạo row mới, `attempt+1`, `supersedes_job_id`, giữ correlation/generation (§ Retry, D6) | `MediaProcessingOrchestrator::retry` | `test_retry_after_provider_failure_keeps_history_and_requires_active_usage` |
| Lỗi vĩnh viễn (`transcript_invalid`, `audio_limit_exceeded`) không retry (§ Mã lỗi mới của STT) | danh sách error code trong `retry` | `test_invalid_audio_timing_fails_the_whole_revision` (5 data set) |
| `cancelled` chỉ từ `pending`; detach trước khi chạy mới huỷ (§ Cancellation) | `MediaService::detachUsage`, claim guard | `test_audio_detach_cancels_pending_stt_and_reattach_creates_a_new_generation` |
| Reattach authorized tạo successor `dispatch_generation+1`, giữ attempt/correlation (D6) | `createInitialJob` nhánh `$successor` | `test_audio_detach_cancels_pending_stt_and_reattach_creates_a_new_generation` |
| Job terminal không hồi sinh | claim chỉ nhận `pending`; `persistSuccess` khoá lại job và đòi `processing` | `test_a_new_processing_version_archives_the_previous_audio_revision` |
| Callback muộn không ghi transcript cho usage đã detach / media deleted | `persistSuccess` guard media + `AudioProcessingEligibility` | `test_audio_callback_after_detach_cannot_persist_transcript_or_resurrect_job` |
| Tối đa một job `processing` mỗi `(media_file_id, job_type)` (§ Concurrency) | `lockForUpdate` media + `alreadyProcessing` | `test_a_second_envelope_never_claims_while_another_stt_job_is_processing` |
| Recovery không lấy nhầm job đang chạy hợp lệ, không tạo transcript trùng | `media:recover-audio-processing` + `routes/console.php` | `test_recovery_touches_only_expired_audio_jobs_and_is_registered_on_the_scheduler` |
| MIME allowlist, trần dung lượng/thời lượng, duration NULL → `corrupt_source` (§ Định dạng và trần, § Đo thời lượng) | `FasterWhisperSpeechToTextProvider` preflight | `test_preflight_rejection_fails_without_transcript_or_cost` (4 data set) |
| Locale exact `vi|ko|en`, không auto-detect, không fallback (§ Locale) | `config('media.processing.speech_to_text.locales')` + `--locale` ép vào engine | `test_locale_outside_the_phase_one_allowlist_never_produces_a_transcript` |
| Source đọc qua storage abstraction | `copySource` dùng `Storage::disk()->readStream()` | E2E thật (disk `media_local` fake) |
| Một row = một segment; locator `timespan` `<start_ms>-<end_ms>`; nửa mở; tăng dần; không overlap; không zero-length (§ Segmentation) | `validatedTranscriptUnits` | `test_real_audio_...`, `test_invalid_audio_timing_...`, `test_abutting_segments_are_valid...` |
| Timespan không vượt thời lượng Media nguồn | `validatedTranscriptUnits(..., sourceDurationMs)` | data set `vuot thoi luong audio`; smoke data `thêm audio ext2` |
| Vi phạm timing → fail cả revision, 0 row `ready` | validate trước insert, trong transaction | `test_invalid_audio_timing_fails_the_whole_revision` |
| Thứ tự đọc là thứ tự thời gian theo cấu trúc, không theo chuỗi locator | validate ép thứ tự insert; `MediaReadService` `ORDER BY id` | `test_abutting_segments_are_valid_and_read_in_temporal_order` (`2000-15000` sau `1000-2000`) |
| `confidence_score` đúng range, không bịa | `validatedTranscriptUnits` + CHECK `chk_mt_confidence` | `test_audio_transcript_confidence_is_validated_persisted_and_returned_in_temporal_order`, `test_audio_invalid_confidence_fails_entire_revision` |
| Provenance đầy đủ trên mỗi unit (§ 5.2 Spec B) | `persistSuccess` ghi 4 trường | `test_real_audio_...` (assert từng row và từng unit) |
| Revision mới archive bản cũ; archived đọc được khi nêu `processing_version` (§ Stale, § 4.1 Spec B) | `archiveSupersededRevisions` | `test_a_new_processing_version_archives_the_previous_audio_revision` |
| `revision_mismatch` / `revision_unavailable` | `MediaReadService` | cùng test trên |
| Read theo owner context, không nhận bare `media_file_id`, không `first()`/`latest()` (§ 3 Spec B) | chữ ký `read()`; khớp chính xác `(customer_id, owner_type, owner_id, usage_type)`; `ambiguous_source` | `test_media_read_fails_closed_when_exact_usage_slot_is_ambiguous` |
| Mã lỗi có tên cho `pending`/`processing`/`failed`/`detached` (§ 6 Spec B) | `$derivedJobType` job-state fallback | `test_unready_detached_and_deleted_reads_return_stable_errors` |
| Tenant khác không đọc được | `TenantContext` + authorizer | `test_another_tenant_cannot_read_the_audio_transcript` |
| Audit `read_derived` cho cả allowed và denied, không log nội dung (§ 8 Spec B) | `MediaReadService::audit` + `$media ??= mediaForOwner(...)` | `test_real_audio_...`, `test_unready_detached_and_deleted_reads_return_stable_errors` |
| `billable_units` / `billable_unit_type = second` khi job kết thúc, kể cả failed sau khi provider đã tính phí (§ 5) | `FasterWhisperSpeechToTextProvider::recordBillableSeconds` | `test_real_audio_...`, `test_real_failure_after_the_engine_ran_still_records_the_billable_seconds`, `test_preflight_rejection_...` (NULL khi chưa tốn phí) |
| STT fail không làm binary mất `ready` (§ "Bắt buộc" nghĩa là bắt buộc với cái gì) | `media_files.status` chỉ theo `virus_scan` | `test_preflight_rejection_...`, `test_http_audio_transcription_failure_is_visible_but_audio_remains_ready` |
| Xoá Media purge transcript, giữ job/access log làm provenance (§ Retention) | `MediaService::deleteMedia` → `purgeDatabaseDerivedContent` | `test_unready_detached_and_deleted_reads_return_stable_errors` |

---

## 5. Database và schema review

Đối chiếu `database/media/*.md` ↔ migration ↔ schema vật lý trên MariaDB
11.4.12 (đọc từ `information_schema`, in ra bởi
`tests/Support/audio-mariadb-review.php`).

**`media_transcripts`** khớp doc v1.9 từng mục:

```text
UNIQUE uk_mt_revision_locator (customer_id, media_file_id, locale,
                               locator_type, locator_value, processing_version)
UNIQUE uk_mt_tenant_identity  (id, customer_id)
INDEX  idx_mt_file_status     (customer_id, media_file_id, status)
INDEX  idx_mt_fingerprint     (customer_id, source_fingerprint)
FK fk_mt_media_tenant (media_file_id, customer_id) → media_files (id, customer_id)
FK fk_mt_job_tenant   (processing_job_id, customer_id) → media_processing_jobs (id, customer_id)
CHECK chk_mt_status      status IN ('pending','processing','ready','failed','archived')
CHECK chk_mt_locator     locator_type = 'timespan'
CHECK chk_mt_confidence  confidence_score IS NULL OR BETWEEN 0 AND 100
CHECK chk_mt_ready       status <> 'ready' OR text IS NOT NULL
```

Điểm đã xác minh:

* **Tenant cưỡng chế xuyên relation.** Cả hai FK là composite `(id, customer_id)`;
  không có đường nào nối một transcript sang media/job của tenant khác. Mọi
  query trong orchestrator, worker, recovery command và Media Read đều scope
  `customer_id`.
* **Neo provenance.** Mỗi row mang `media_file_id`, `processing_job_id`,
  `source_fingerprint`, `processing_version`, `locale` của đúng lần chạy.
* **Text chỉ nằm trong `text`.** `metadata` của đường Faster Whisper chỉ chứa
  `avg_logprob`; test assert `metadata` không chứa chuỗi text của chính row đó.
  `media_access_logs.metadata` chỉ chứa selector/decision/error code.
* **Không ghi đè.** `archiveSupersededRevisions` chuyển row `ready` cũ sang
  `archived` khi `processing_version` **hoặc** `source_fingerprint` khác; unique
  key có `processing_version` nên hai revision cùng tồn tại hợp lệ, và hai locale
  khác nhau không đụng nhau.
* **Unique không chặn cái hợp lệ.** `(locale, locator_value, processing_version)`
  cho phép nhiều segment, nhiều locale, nhiều revision; chỉ chặn trùng khít.
  Overlap **không** bị unique bắt được — đúng như doc nói — nên luật nằm ở tầng
  persist (§ 7).
* **`confidence_score` DECIMAL(5,2)**; runtime làm tròn 2 chữ số và chỉ ghi khi
  provider báo. `transcribe.py` không báo confidence, nên đường thật ghi `NULL` —
  không bịa từ `avg_logprob`.
* **Không sửa migration lịch sử.** Không cần forward migration cho lượt này:
  cả năm lỗi đều là lỗi code/registration, không phải lỗi schema.
* `schema:drift --docs-only` và `schema:drift --fresh` (MariaDB 11.4.12,
  90 migration files) đều **pass**. DOC-CONFLICT-0031 (`media_access_logs.accessed_at`
  default) không còn tái hiện.

---

## 6. Lifecycle, retry, reattach và concurrency

* **Vocabulary canonical.** Job dùng đúng `pending|processing|ready|failed|cancelled`
  (CHECK `chk_mpj_status`); output dùng `pending|processing|ready|failed|archived`
  (CHECK `chk_mt_status`). Không có chỗ nào trộn hai tập.
* **Attach rồi mới dispatch.** `attachUsage` commit usage, rồi `DB::afterCommit`
  gọi orchestrator; orchestrator lại `DB::afterCommit` để enqueue. Rollback
  không để lại job rác.
* **Duplicate dispatch.** Envelope thứ hai của cùng job id gặp `status<>'pending'`
  và thoát; envelope của cùng identity bị `UNIQUE(customer_id, idempotency_key)`
  và unique `(…, dispatch_generation, attempt)` chặn ở database. Đã chứng minh
  bằng engine thật: một job, một revision.
* **Concurrency.** Claim khoá `media_files` `FOR UPDATE`, rồi từ chối nếu đã có
  job `processing` khác cùng `(media_file_id, job_type)`. Envelope bị từ chối
  **không** bị đánh dấu terminal — nó ở lại `pending` để redeliver.
* **Retry.** Row mới `attempt+1`, `supersedes_job_id` trỏ row cũ, giữ
  `correlation_id` và `dispatch_generation`, backoff mũ có jitter, trần 3, và chỉ
  chạy khi Audio còn active usage. Row cũ giữ nguyên làm dấu vết chi phí.
* **Reattach.** Job `cancelled` chưa `started_at` và không có `error_code` sinh
  successor `dispatch_generation+1`, giữ attempt, `supersedes_job_id` trỏ row
  cancelled — không kẹt ở `cancelled`/`pending`.
* **Callback muộn.** `persistSuccess` khoá lại chính job (đòi `processing`),
  khoá lại media (từ chối `deleted`), rồi kiểm lại active usage. Detach giữa
  chừng ⇒ `source_unavailable`, 0 transcript, job không hồi sinh.
* **Recovery.** `media:recover-audio-processing` chỉ chạm job Audio quá hạn:
  `pending` cũ hơn 3.900s được redeliver **đúng envelope cũ**; `processing` quá
  worker timeout thành `failed`/`provider_timeout`. Job vừa tạo không bị chạm.
  Command này trước đây **không** được đăng ký scheduler — xem finding F2.
* **Deliverability độc lập.** Mọi nhánh STT fail đều giữ `media_files.status='ready'`.

---

## 7. Timespan và transcript integrity

Validate chạy trọn vẹn **trước insert đầu tiên**, trong cùng transaction ghi
transcript và chuyển job sang `ready`:

* `locator_type` phải là `timespan`; `locator_value` khớp
  `^(0|[1-9][0-9]*)-(0|[1-9][0-9]*)$` — millisecond nguyên, không zero-padding,
  không dấu âm, không định dạng đồng hồ;
* `start_ms < end_ms` (chặn segment độ dài 0);
* `start_ms >= previous_end_ms` (nửa mở `[start, end)`; giáp ranh hợp lệ, overlap
  và giảm dần bị từ chối);
* `text` không rỗng sau `trim`;
* `confidence_score`, nếu có, phải là số trong `[0, 100]`.

Bất kỳ vi phạm nào ném `transcript_invalid` ⇒ transaction rollback ⇒ **0 row**
cho revision đó, và `transcript_invalid` là lỗi vĩnh viễn nên không tiêu attempt
vào một input không thể đổi.

Vì thứ tự insert bị ép trùng thứ tự thời gian, `ORDER BY id` của Media Read trở
thành bảo đảm cấu trúc. Test dùng bộ locator `0-1000`, `1000-2000`, `2000-15000`
— sắp theo chuỗi sẽ đảo `2000-15000` lên trước `1000-2000`, nên nó phân biệt được
"đúng do cấu trúc" với "đúng do tình cờ".

**Bằng chứng engine thật.** Fixture giọng nói tổng hợp 18,23s, PCM s16le 16 kHz
mono, 583.426 byte, không PII (dựng tại chỗ bằng `say` + `ffmpeg`, không commit
binary nào). `faster-whisper small int8` sinh **5 segment**:

```text
0-3160      Welcome to this lesson on learning design.
3160-7280   In this module we study how learners build durable knowledge.
7280-11440  The first principle is spaced repetition over many days.
11440-15480 The second principle is retrieval practice before review.
15480-19320 Together these two ideas improve long-term memory.
```

4/4 cặp liền kề giáp ranh chính xác, 0 overlap, 0 segment độ dài 0, locale `en`,
nội dung khớp kịch bản. RTF ≈ 0,31 (5,7s xử lý cho 18,2s audio).

Đây là bằng chứng **correctness** của đường local, không phải quality threshold:
CER/WER vẫn `unavailable` theo Owner acceptance 2026-08-29 và nằm trong
deferred scope.

---

## 8. Media Read, tenant và audit

* Không có API nào nhận bare `media_file_id`; caller bắt buộc truyền `actor_id`,
  `owner_type`, `owner_id`, `usage_type`, `content_type`. `media:read-derived`
  cũng đi qua đúng chữ ký đó.
* Usage được resolve bằng khớp **chính xác** `(customer_id, owner_type, owner_id,
  usage_type)`; hai active row cùng slot ⇒ `ambiguous_source`, không đoán.
* `usage_type=audio` ↔ `content_type=transcript` khớp bảng mapping Phase 1.
* Locale: nêu thì phải đúng locale đó, không nêu thì lấy
  `media_files.processing_locale`; không fallback.
* Mặc định chỉ trả revision `ready` hiện hành, chọn theo `processing_job_id` lớn
  nhất rồi `id`. `archived` chỉ ra khi nêu đích danh `processing_version`;
  `source_fingerprint` lệch ⇒ `revision_mismatch`; version không tồn tại ⇒
  `revision_unavailable`.
* Mỗi unit trả về đủ `text`, `source_fingerprint`, `processing_version`,
  `locale`, `locator{type,value}`, và `confidence` khi có.
* Tenant: `TenantContext` quyết định `customer_id`; actor của tenant khác và
  actor của tenant cũ đọc context đã đổi tenant đều nhận `unauthorized`, và
  không có row audit nào được ghi vào tenant kia.
* Audit: cả `allowed` lẫn `denied` ghi `media_access_logs.action='read_derived'`
  khi owner resolve được tới Media File. Metadata chỉ chứa
  owner/usage/content/locale/version/fingerprint/decision/error code — không có
  transcript text, không có signed URL, không có secret. Bảng này append-only
  bằng trigger.

---

## 9. Findings

Cả năm đều tái hiện được, đều đã sửa trong lượt này, và đều có test chứng minh.

### F1 — MEDIUM — `speech_to_text` không bao giờ ghi `billable_units` (đã sửa)

* **Requirement:** `LF-Media-Processing-Contract` § 5 — *"Mỗi job ghi
  `billable_units` và `billable_unit_type` khi kết thúc, kể cả khi `failed` sau
  khi provider đã tính phí. Đơn vị chuẩn: … `second` cho speech-to-text"*, và
  *"job vẫn **ghi** `billable_units` nhưng **không có** nơi tổng hợp"* — tức phép
  ghi là nghĩa vụ của substrate, không phải hạng mục SaaS bị deferred.
* **File/symbol:** `app/Jobs/ProcessMediaProcessingJob.php:406` (nhánh success)
  và `:150` (nhánh failure) đều gate `in_array($job->job_type, ['ocr',
  'structured_extraction'])`; `FasterWhisperSpeechToTextProvider::process()`
  không trả `usage` và không ghi row.
* **Tái hiện:** chạy bất kỳ STT job nào tới `ready`, đọc
  `media_processing_jobs.billable_units` → `NULL`.
* **Ảnh hưởng:** mọi lời gọi STT có tính phí không để lại phép đo nào. Chuỗi
  retry — thứ contract chỉ định làm dead-letter record đọc được bằng SQL — mất
  đúng cột nói lên chi phí đã tiêu. Khi Usage Foundation bật lên, không có dữ
  liệu lịch sử để đối chiếu.
* **Cách sửa:** `FasterWhisperSpeechToTextProvider::recordBillableSeconds()` ghi
  `billable_units = media_files.duration_seconds`, `billable_unit_type='second'`
  lên chính row job **ngay trước** khi gọi engine — cùng mô hình
  `LocalDocumentProcessingProvider::recordCompletedPages()`. Vì thế: thành công
  giữ giá trị; fail sau khi engine chạy cũng giữ (nhánh failure không xoá cột);
  còn preflight từ chối trước khi tốn phí thì giữ `NULL`, đúng ngữ nghĩa. Không
  cần migration: `chk_mpj_billable_pair` chỉ đòi hai cột cùng NULL hoặc cùng
  non-NULL, và không có CHECK nào giới hạn tập `billable_unit_type`.
* **Test:** `test_real_audio_activity_usage_after_commit_dispatch_and_authorized_read`
  (`second`, > 0), `test_real_failure_after_the_engine_ran_still_records_the_billable_seconds`,
  `test_preflight_rejection_fails_without_transcript_or_cost` (NULL khi chưa tốn phí).

### F2 — MEDIUM — `media:recover-audio-processing` không được đăng ký scheduler (đã sửa)

* **Requirement:** § Cancellation/§ Concurrency — job đã `processing` phải kết
  thúc bằng `ready` hoặc `failed`, *"không được để treo"*. Cơ chế duy nhất đưa
  một job Audio kẹt về trạng thái terminal là command recovery.
* **File/symbol:** `app/Console/Commands/RecoverAudioProcessing.php` tồn tại và
  đúng, nhưng `routes/console.php` chỉ đăng ký
  `media:recover-document-processing`.
* **Tái hiện:** `grep media:recover routes/console.php` — chỉ có bản Document.
  Một STT job bị worker chết bỏ lại ở `processing` sẽ nằm đó vô hạn; Media Read
  trả `processing` mãi mãi.
* **Ảnh hưởng:** cơ chế recovery mà review trước ghi nhận là "có" thực tế không
  bao giờ chạy. Đây là lỗi registration local, không phải hạng mục production
  soak.
* **Cách sửa:** thêm
  `Schedule::command('media:recover-audio-processing')->everyMinute()->withoutOverlapping(2);`
  đúng cadence và guard của bản Document.
* **Test:** `test_recovery_touches_only_expired_audio_jobs_and_is_registered_on_the_scheduler`
  — vừa assert hành vi recovery (job mới không bị chạm, pending quá hạn được
  redeliver đúng envelope cũ, processing quá hạn thành `provider_timeout`), vừa
  assert command có mặt trong `Schedule::events()`.

### F3 — HIGH — Media Read trả `locale_unavailable` cho transcript đang xử lý hoặc đã fail (đã sửa)

* **Requirement:** `LF-Media-Read-Contract` § 6 — `pending`, `processing`,
  `failed` là mã lỗi **có tên**; § 5 — *"Chỉ row `ready` được trả ra. Mọi trạng
  thái khác là mã lỗi có tên, không phải mảng rỗng"*; § 5.1 giải thích chính lý
  do: đặt tên cho sự vắng mặt là cách duy nhất để consumer hành xử đúng.
* **File/symbol:** `app/Services/MediaReadService.php` — bước tra trạng thái job
  khi không có row output bị gate bằng `$documentContent`, tức chỉ áp cho
  `extracted_text`/`region`/`table`. `transcript` rơi thẳng xuống nhánh cuối và
  nhận `locale_unavailable` vì bảng chưa có row nào cho locale đó.
* **Tái hiện:** attach audio với `processing_locale=vi`, để STT job ở `pending`
  (hoặc để nó `failed` bằng bất kỳ preflight nào), rồi
  `MediaReadService::read($actor,'course_activity',$id,'audio','transcript','vi')`
  → `locale_unavailable` thay vì `pending`/`failed`.
* **Ảnh hưởng:** đây đúng là kiểu hỏng § 5.1 mô tả — consumer **không thiếu dữ
  liệu; nó không biết là mình đang thiếu**. `locale_unavailable` nói "locale này
  không có output" và hành động đúng là đổi locale hoặc bỏ qua; sự thật là
  "đang phiên âm" (thử lại sau) hoặc "đã fail" (sẽ không tự có kết quả). Ba tình
  huống, ba hành động, một mã lỗi.
* **Cách sửa:** thay `$documentContent` bằng bảng ánh xạ `$derivedJobType`
  (`extracted_text→ocr`, `region|table→structured_extraction`,
  `transcript→speech_to_text`) và dùng nó cho bước tra trạng thái job. Nhánh
  document giữ nguyên hành vi; transcript nay nhận đúng mã. Bộ lọc locale của
  bước tra dùng `LIKE '%;locale=<x>'` / `LIKE 'locale=<x>;%'` neo hai đầu nên
  `locale=en` không khớp nhầm `locale=en-US`.
* **Test:** `test_unready_detached_and_deleted_reads_return_stable_errors`
  (`pending`), `test_preflight_rejection_fails_without_transcript_or_cost`
  (`failed` cho cả 4 nhánh preflight), và
  `test_locale_outside_the_phase_one_allowlist_never_produces_a_transcript`.
  Hành vi `locale_unavailable` thật (hỏi locale không có job nào) vẫn giữ nguyên —
  `test_read_errors_and_explicit_archived_revision_are_fail_closed` không đổi.

### F4 — MEDIUM — Read bị từ chối vì `detached` không được audit (đã sửa)

* **Requirement:** `LF-Media-Read-Contract` § 8 — *"Mỗi lần đọc thành công **hoặc
  bị từ chối** ghi một dòng `media_access_logs` với `action='read_derived'`"*;
  ngoại lệ duy nhất là khi owner **không** resolve được tới Media File trong
  tenant.
* **File/symbol:** `app/Services/MediaReadService.php` — `read()` ném `detached`
  (và `missing` khi có usage cũ) **trước** khi `$media` được gán, và khối `catch`
  chỉ audit `if ($media)`. Chính file đó đã làm đúng ở `structureCoverage()`:
  `$media ??= $this->mediaForOwner(...)`.
* **Tái hiện:** attach audio, chạy STT tới `ready`, `detachUsage`, rồi `read` →
  ném `detached` nhưng `media_access_logs` không có row `denied` nào cho lần đó.
* **Ảnh hưởng:** transcript có thể chứa dữ liệu cá nhân trong học liệu. Một
  actor dò owner context đã detach không để lại dấu vết nào, trong khi Media
  **resolve được** file mục tiêu — tức ngoại lệ "không invent FK giả" của § 8
  không áp dụng. Đây là lỗ hổng audit, không phải lỗ hổng quyền: read vẫn
  fail-closed đúng.
* **Cách sửa:** trong `catch` của `read()`, thêm
  `$media ??= $this->mediaForOwner($customerId, $ownerType, $ownerId, $usageType);`
  — đúng mẫu `structureCoverage()` đã dùng. Resolve ở đây chỉ để định danh mục
  tiêu ghi log, không cấp quyền đọc; owner không có usage nào vẫn trả `null` và
  vẫn không ghi row.
* **Test:** `test_unready_detached_and_deleted_reads_return_stable_errors` assert
  row `read_derived` với `decision=denied`, `error_code=detached`, và assert
  metadata **không** chứa nội dung transcript.

### F5 — HIGH — Timespan có thể vượt thời lượng Audio nguồn (đã sửa)

* **Requirement:** transcript timespan là citation vào binary nguồn; locator
  ngoài thời lượng nguồn không trỏ tới nội dung tồn tại. Processing Contract
  v2.30 và `media_transcripts` v1.9 chốt
  `end_ms <= media_files.duration_seconds * 1000`.
* **File/symbol:** `ProcessMediaProcessingJob::validatedTranscriptUnits()` trước
  đây chỉ kiểm format, `start < end`, ordering và overlap.
* **Tái hiện thật:** activity `thêm audio ext2`, Media `id=30`; `ffprobe` đo
  `80.979575s`, database ghi `81s`, nhưng Faster Whisper sinh hai locator cuối
  `75000-85000` và `85000-95000`. Job `id=70` vẫn bị đánh dấu `ready`.
* **Ảnh hưởng:** Media Read trả citation trỏ ra ngoài audio; segment cuối hoàn
  toàn không có khoảng thời gian tương ứng trong source.
* **Cách sửa:** truyền upper bound từ Media vào validator và fail toàn revision
  bằng `transcript_invalid` khi bất kỳ `end_ms` vượt bound; không clamp, không
  persist một phần.
* **Test:** data set `vuot thoi luong audio` dùng Media 3 giây và segment
  `0-3001`, assert job failed và 0 transcript rows.

### Findings còn mở trong phạm vi Audio local

**Không có.**

---

## 10. Quan sát ngoài phạm vi Audio local (không phải finding, không chặn verdict)

Ghi lại vì phát hiện được trong lúc chạy, không phải để đưa vào verdict.

`tests/Feature/MediaProcessingSubstrateTest.php` và
`tests/Feature/MediaRevisionLifecycleTest.php` chạy xanh trên SQLite nhưng đỏ
trên MariaDB thật: **9 error + 1 failure**, tất cả do fixture của chính test
`INSERT` thẳng vào `media_processing_jobs` những row vi phạm CHECK thật —
`chk_mpj_ready` (job `ready` phải có `completed_at` và `output_id`),
`chk_mpj_output_pair`, `chk_mpj_failed` — cộng một fixture caption có
`source_fingerprint` NULL. `addMariaDbChecks()` `return` sớm trên SQLite, nên
SQLite không cưỡng chế các CHECK này và fixture lọt.

Đây là **nợ của fixture test**, không phải lỗi runtime: đường ghi thật
(`persistSuccess`) luôn set `output_type`/`output_id`/`completed_at` cùng nhau,
và đã được chứng minh trên chính MariaDB đó bằng E2E engine thật. 8/9 error thuộc
fixture Document/Structured/Caption và 1 thuộc fixture Video; không có cái nào
thuộc Audio. Vì mandate cấm mở rộng sang Document/Video/Caption, tôi **không**
sửa chúng và cũng **không** đưa chúng vào harness Audio —
`tests/Support/audio-mariadb-review.php` ghi rõ lý do loại trừ ngay tại chỗ.

**Đính chính 2026-09-02.** Đoạn trên gộp hai nguyên nhân khác nhau. Các row
`media_processing_jobs` vi phạm `chk_mpj_ready`/`chk_mpj_output_pair` đúng là nợ
fixture. Nhưng phần `MediaRevisionLifecycleTest` thì **không**: lượt review Video
truy ra nguyên nhân thật là chính harness — nó bootstrap Laravel nên Dotenv nạp
`.env` vào environment, tiến trình PHPUnit con thừa kế, và `<env>` trong
`phpunit.xml` **không** ghi đè một biến đã tồn tại (thiếu `force="true"`). Test vì
thế chạy bằng provider/version thật thay vì `fake`. Đã sửa ở cả hai harness bằng
cách gỡ tám biến `MEDIA_*` khỏi child environment. `MediaRevisionLifecycleTest`
chạy xanh trên MariaDB 11.4.12 (12 tests, 48 assertions).

Hai mục `DECISION_REQUIRED` sẵn có của register — DOC-CONFLICT-0014 và 0015
(`owner_type` vocabulary) — vẫn mở ở cấp Owner và không tái hiện trên đường Audio.

---

## 11. Rủi ro tồn dư trong phạm vi Audio local — đã cân nhắc, không phải finding

Mục này tồn tại để lượt review sau **không** phải tự phát hiện lại. Mỗi mục dưới
đây tôi đã soi, và mỗi mục đều có lý do cụ thể để không tính là finding chặn.
Không mục nào trong số này tái hiện được thành hành vi sai với cấu hình hiện tại.

### R1 — `processing_version` của audio không định danh model/compute type

`MediaProcessingOrchestrator::versionFor('speech_to_text', <audio>)` trả thẳng
`config('media.processing.versions.speech_to_text')`, **không** nối hậu tố nào;
chỉ nhánh video mới nối `VideoSpeechToTextProfile::label()`. Hệ quả: nếu operator
đổi `MEDIA_STT_MODEL_PATH` (small → tiny) hoặc `MEDIA_STT_COMPUTE_TYPE`
(int8 → float16) mà quên đổi `MEDIA_SPEECH_TO_TEXT_VERSION`, transcript đổi nội
dung dưới **cùng** một `processing_version` — idempotency coi là cùng revision,
không sinh revision mới, bản cũ không được archive. Audio cũng không có bước
`assertBinaryMatchesInventory()` như nhánh video.

**Vì sao không phải finding:** Amendment Record 2.19 § 1 chốt tường minh rằng
`compute_type` và `threads` *"chỉ được nối vào identity của **video**; audio giữ
identity hiện hành cho tới khi có amendment/migration plan riêng"*. Đây là
deferral do Owner quyết, không phải chỗ bị bỏ sót.

**Điều kiện đóng (Owner):** một amendment kèm migration plan — đổi identity audio
sẽ archive toàn bộ transcript đang hiện hành và bắt chạy lại, nên không thể sửa
lặng lẽ. Giảm nhẹ hiện tại: `.env.example` hướng dẫn đặt
`MEDIA_SPEECH_TO_TEXT_VERSION=faster-whisper-1.2.1-small-int8`, tức yêu cầu
operator tự mã hoá model/compute type vào version.

### R2 — Video STT ghi `error_message` thô (ngoài phạm vi Audio)

Guard privacy trong `ProcessMediaProcessingJob` là
`in_array($job->job_type, ['ocr','structured_extraction']) || ($media->file_type === 'audio' && $job->job_type === 'speech_to_text')`.
Job `speech_to_text` trên **video** rơi ra ngoài guard và nhận
`mb_substr($e->getMessage(), 0, 1000)`. Cùng một dòng code với nhánh audio nên
reviewer sau sẽ nhìn thấy; nó thuộc Video STT, ngoài phạm vi mandate này, và
không ảnh hưởng audio.

### R3 — Read transcript không ghim `processing_job_id`

Nhánh document ghim cả bộ ba `(source_fingerprint, processing_version,
processing_job_id)` trước khi trả row; nhánh transcript chỉ ghim
`processing_version`. Hiện **không** kích hoạt được: `archiveSupersededRevisions`
archive theo cả `processing_version` lẫn `source_fingerprint`, và
`media_files.checksum` bất biến sau upload nên một Media chỉ có một fingerprint.
Đây là bất đối xứng về defence-in-depth, không phải lỗi tái hiện được — ghi lại
để lần sau không mất thời gian truy lại cùng câu hỏi.

### R4 — `pendingCutoff = 3900` hardcoded trong recovery command

Cả `RecoverAudioProcessing` lẫn `RecoverDocumentProcessing` hardcode `3900`, trùng
với default `DB_QUEUE_RETRY_AFTER=3900` nhưng **không** đọc từ config. Đổi
`retry_after` mà quên hai command này sẽ làm cutoff lệch khỏi transport. Bản
Audio giữ đúng precedent của bản Document; sửa thì nên sửa cả hai trong một lượt
riêng, không phải việc của mandate Audio.

### R5 — Fixture E2E là giọng tổng hợp, không phải giọng người thật

Fixture dựng bằng `say` chứng minh **correctness** của đường dẫn (tiếng nói →
transcript → timestamp → locale → read), và cố tình không nói gì về chất lượng
nhận dạng. CER/WER vẫn `unavailable` theo Owner acceptance 2026-08-29. Fixture
`vi`/`ko` giọng người thật vẫn chưa có trong repo vì § Acceptance bắt gitignore
chúng. Bất kỳ ai muốn số liệu chất lượng phải đi qua `runtime/stt/benchmark/`,
không phải qua suite này.

### R6 — Test cross-process: một lỗi harness đã tìm ra và sửa

`AudioQueueRecoveryMariaDbTest` phối hợp bốn tiến trình thật (test, worker bị
chặn, worker busy, worker drain) cộng engine thật. Lượt đầu nó fail 1/4 lần. Đã
truy ra nguyên nhân gốc — và đó **không** phải race ngẫu nhiên mà là lỗi thiết
kế của chính test:

Điều kiện chờ trước khi `SIGKILL` là "job đã `processing` và `billable_units` đã
được ghi". Nhưng `recordBillableSeconds()` chạy **trước** lời gọi engine, nên
điều kiện đó đúng ngay khi engine mới bắt đầu. `SIGKILL` giết tiến trình PHP
nhưng để lại tiến trình Python **mồ côi vẫn đang chạy**, và nó cạnh tranh CPU với
lần chạy engine của worker drain — làm drain vượt timeout 300s. Máy càng tải thì
càng dễ trúng, khớp với việc nó chỉ fail trong ngữ cảnh full harness.

Đã sửa bằng cách để provider bị chặn ghi một mốc `probe=blocked` **sau khi**
`parent::process()` trả về, và test phải chờ mốc đó rồi mới `SIGKILL`. Cửa sổ
crash được kiểm nay là "provider đã có kết quả, writer chưa kịp persist" — đúng
cửa sổ đáng quan tâm hơn, và không còn tiến trình mồ côi nào. Timeout worker
nâng lên 900s cho biên an toàn.

Đo trong lúc verify bản đã sửa: `pmset -g therm` báo `CPU_Speed_Limit = 20`,
load average 5,34 — máy review chạy ở 20% tốc độ danh định. Đây chính là điều
kiện DOC-CONFLICT-0027 mô tả (RTF dao động 4,6 lần trên cùng một input), và là lý
do mọi ngưỡng thời gian trong test này phải rộng chứ không được chỉnh khít.

Test này được đặt ở `--queue-only`, **không** nằm trong lượt harness mặc định —
đúng precedent của `document-mariadb-review.php`, nơi
`DocumentQueueRecoveryMariaDbTest` cũng là opt-in. Lý do là chi phí thời gian và
độ nhạy với tải máy, không phải độ tin cậy của runtime: không lượt nào ghi sai
dữ liệu, khác biệt chỉ nằm ở thời điểm quan sát.

### R7 — Nợ fixture MariaDB của các suite Media khác

Xem § 10. Ngoài phạm vi Audio, không sửa trong lượt này.

### R8 — Rủi ro B4 của Spec B chỉ mới được phủ cho `usage_type=audio`

`LF-Media-Read-Contract` § Rủi ro B4 đòi runtime/test evidence cho `usage_type`
fail-closed trước khi mở HTTP/API. Evidence đó nay đã có **cho audio**
(owner context chính xác, `ambiguous_source`, cross-tenant từ chối, audit cả
allowed lẫn denied). Nó không tự phủ `document`/`video`, và đóng B4 là quyết định
Owner — không phải hệ quả tự động của review này.

---

## 12. Commands đã chạy và kết quả thật

| Command | Kết quả |
| --- | --- |
| `php artisan test` | **968 passed, 2 skipped, 9.706 assertions**, 121,38s |
| `php artisan test --filter=AudioProcessingLocalReviewTest` | **22 passed, 240 assertions** (gồm 3 case chạy engine thật) |
| `vendor/bin/pint --test` trên toàn bộ file đã sửa/thêm | **PASS** |
| `php -l` trên toàn bộ file PHP đã sửa/thêm | **PASS** |
| `php artisan docs:lint` | **PASS** |
| `php artisan schema:drift --docs-only` | **PASS**, 90 migration files |
| `php artisan schema:drift --fresh` trên MariaDB 11.4.12 | **PASS**, 90 migration files, `mode=fresh-ephemeral` |
| `php tests/Support/audio-mariadb-review.php` (lượt mặc định) trên MariaDB 11.4.12 | **OK (31 tests, 253 assertions)**, 5:26 gồm fresh migrate + engine thật |
| `php tests/Support/audio-mariadb-review.php --queue-only` | **OK (1 test, 35 assertions)**, 6:21 — queue database thật, worker thật, `SIGKILL`, recovery, engine thật. Số assertion dao động theo số segment engine trả về (quan sát 35–45); các assert về số lượng dùng `assertGreaterThanOrEqual` nên không phụ thuộc con số đó. Xem § 11 R6 |
| `runtime/stt/transcribe.py` trực tiếp trên fixture | `{"status":"ready","units":5}`, 5,7s cho 18,2s audio |

Hai test bị skip trong suite mặc định:
`CourseTemplatePublishConcurrencyTest::test_template_lock_serializes_same_template_but_not_another_template`
(SQLite không có `SELECT FOR UPDATE`) và
`MediaProcessingSubstrateTest::test_real_local_audio_provider_persists_timespans_and_media_read_returns_them`
(cần `LF_REAL_AUDIO_FIXTURE`). Suite Audio mới **không** skip case nào: nó tự
tổng hợp fixture nên đường engine thật luôn chạy khi runtime có mặt.

### Lệnh tái lập

```bash
php artisan test tests/Feature/AudioProcessingLocalReviewTest.php
php artisan test
php artisan docs:lint
php artisan schema:drift --docs-only
```

Hai lệnh MariaDB bên dưới cần một instance **>= 10.5** đang chạy. XAMPP hiện tại
là MariaDB `10.4.21`, thấp hơn version floor, nên harness sẽ từ chối đúng thiết
kế và **không** tạo database disposable nào — đó là guard, không phải lỗi. Dựng
instance dùng một lần trước (socket path phải ngắn; `/tmp` là vì giới hạn ~104
ký tự của UNIX socket, không phải vì cần quyền gì thêm):

```bash
RT=/tmp/lf-audio-mariadb; rm -rf "$RT"; mkdir -p "$RT/data" \
  && /usr/local/opt/mariadb@11.4/bin/mariadb-install-db --datadir="$RT/data" --auth-root-authentication-method=normal \
  && /usr/local/opt/mariadb@11.4/bin/mariadbd --datadir="$RT/data" --socket="$RT/server.sock" \
       --skip-networking --innodb_flush_log_at_trx_commit=2 --pid-file="$RT/server.pid" &
```

```bash
DB_SOCKET=/tmp/lf-audio-mariadb/server.sock DB_HOST=localhost DB_USERNAME=root DB_PASSWORD='' DB_URL='' php tests/Support/audio-mariadb-review.php
```

Chỉ chạy riêng nhánh cross-process (`--queue-only`), hoặc chỉ suite feature
(`--feature-only`), khi cần khoanh vùng:

```bash
DB_SOCKET=/tmp/lf-audio-mariadb/server.sock DB_HOST=localhost DB_USERNAME=root DB_PASSWORD='' DB_URL='' php tests/Support/audio-mariadb-review.php --queue-only
```

```bash
DB_CONNECTION=mysql DB_SOCKET=/tmp/lf-audio-mariadb/server.sock DB_HOST=localhost DB_USERNAME=root DB_PASSWORD='' DB_URL='' php artisan schema:drift --fresh
```

Tắt và xoá instance sau khi xong; XAMPP không bị đụng tới:

```bash
/usr/local/opt/mariadb@11.4/bin/mariadb-admin --socket=/tmp/lf-audio-mariadb/server.sock -u root shutdown && rm -rf /tmp/lf-audio-mariadb
```

### Môi trường local đã dùng

PHP 8.3.33; Laravel 12.61.0; PHPUnit 11.5.55; Python 3.11.16;
faster-whisper model `small`, `compute_type=int8`, CPU, diarization `off`;
ffmpeg/ffprobe tại `/usr/local/bin`; MariaDB 11.4.12.

MariaDB 11.4.12 được khởi động **riêng**, data directory và socket trong
`/tmp/lf-audio-mariadb`, `--skip-networking`, `innodb_flush_log_at_trx_commit=2`.
Không sửa server XAMPP (10.4.21) và không sửa `.env`. Harness tạo database
`lf_audio_review_<random>`, chạy PHPUnit, in bằng chứng schema, rồi `DROP` trong
`finally`; có production guard và version floor guard. Không có secret kết nối
nào được ghi vào báo cáo. Instance disposable đã được tắt và thư mục runtime đã
xoá sau lượt review.

Fixture audio là **giọng nói tổng hợp**, dựng tại chỗ bằng `say` + `ffmpeg`,
không chứa PII, không được commit, và không có byte nào rời khỏi máy: Phase 1
chỉ dùng provider local và `transcribe.py` ép ba biến offline của Hugging Face
trong chính process.

---

## 13. Files thay đổi

### Sửa lỗi (production code)

| File | Thay đổi |
| --- | --- |
| `app/Services/FasterWhisperSpeechToTextProvider.php` | F1 — `recordBillableSeconds()` ghi `second`/duration lên job trước khi gọi engine |
| `routes/console.php` | F2 — đăng ký `media:recover-audio-processing` vào scheduler |
| `app/Services/MediaReadService.php` | F3 — `$derivedJobType` mở mã lỗi có tên `pending`/`processing`/`failed` cho transcript; F4 — audit lần đọc bị từ chối khi owner resolve được Media File |

### Test và harness

| File | Thay đổi |
| --- | --- |
| `tests/Feature/AudioProcessingLocalReviewTest.php` | Mới — 22 case Audio local, 3 case chạy engine thật, fixture tự tổng hợp |
| `tests/Integration/AudioQueueRecoveryMariaDbTest.php` | Mới — queue database thật + worker thật + `SIGKILL` + recovery + engine thật |
| `tests/Support/audio-queue-worker.php` | Mới — worker probe, guard theo tên database disposable |
| `tests/Support/audio-mariadb-review.php` | Mới — harness MariaDB disposable cho Audio, in bằng chứng CHECK/FK/index vật lý |

### Đã có trong working tree trước lượt review này (đọc, xác minh, giữ nguyên)

`app/Services/AudioProcessingEligibility.php`,
`app/Console/Commands/RecoverAudioProcessing.php`,
`app/Jobs/ProcessMediaProcessingJob.php`,
`app/Services/MediaProcessingOrchestrator.php`,
`app/Services/MediaService.php`,
`tests/Feature/MediaProcessingSubstrateTest.php`,
cùng đăng ký tài liệu trong `docs/LF-INDEX.md`, `docs/quality/README.md`,
`docs/LF-DOCUMENTATION-MANIFEST.json`.

---

## 14. Gate table

| Gate | Kết quả | Cơ sở |
| --- | --- | --- |
| Contract Gate | **PASS** | Contract ↔ code ↔ test matrix § 4 đầy đủ; 4 sai lệch tìm được đã sửa; không có conflict đang mở chặn Audio local |
| Schema Gate | **PASS** | `media_transcripts` khớp doc từng key/CHECK/FK trên MariaDB thật; `schema:drift --docs-only` và `--fresh` đều pass; không sửa migration lịch sử |
| Code Gate | **PASS** | Pint và `php -l` pass trên mọi file đã chạm; sửa nằm đúng phạm vi Audio; không mở rộng sang Video/Caption/Document/Product |
| Local Runtime Gate | **PASS** | E2E engine thật: upload → active Course usage → dispatch sau commit → Faster Whisper → 5 transcript timespan `ready` → authorized Media Read → audit; chạy xanh trên cả SQLite lẫn MariaDB 11.4.12 |
| Test Gate | **PASS** | 968 passed / 9.706 assertions (SQLite); 31 tests / 253 assertions (MariaDB); mọi negative và concurrency case bắt buộc đều có test |
| Product/Production Gate | **NOT EVALUATED — DEFERRED** | Entitlement/quota/billing, external provider, production soak/sizing/monitoring, retention orchestration, benchmark chất lượng: nằm ngoài phạm vi theo mandate, không được đọc thành finding |

---

## 15. Kết luận

**PASS_LOCAL_AUDIO_PROCESSING.**

Bốn lỗi thực tế đã được tìm, sửa và khoá lại bằng test: STT không ghi phép đo
chi phí (F1), command recovery Audio không bao giờ chạy (F2), Media Read nói sai
lý do khi transcript chưa sẵn sàng hoặc đã fail (F3), và lần đọc bị từ chối vì
`detached` không để lại dấu vết audit (F4). Không còn finding nào mở trong phạm
vi Audio local.

Phạm vi này **không** bao gồm và verdict này **không** phát biểu gì về: Document,
Video STT, Caption, AI Knowledge/Proposal/Learning Mapping, và toàn bộ nhóm
Product/production đã liệt kê ở § 2.
