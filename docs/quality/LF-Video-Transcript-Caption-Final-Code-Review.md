# Video Transcript And Caption — Final Code Review

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-05

Document Path: quality/LF-Video-Transcript-Caption-Final-Code-Review.md

---

## 1. Verdict

**PASS_LOCAL_VIDEO_TRANSCRIPT_AND_CAPTION**

Re-validation độc lập ngày 2026-09-05 tại HEAD
`c51b5e8fb0bbdb4cb6c62566e632ae5bdf410723`: Audio và Video final suites chạy
chung ngoài sandbox đạt **28 tests, 357 assertions, 0 failed**. Video transcript
và caption Phase 1 vì thế được ghi `IMPLEMENTATION: CLOSED`, `LOCAL_RUNTIME:
PASS`; production vẫn `NOT_APPROVED` và feature gate vẫn mặc định tắt.

Capability `Video → speech_to_text → media_transcripts theo timespan → caption
asset VTT → Media Read` đã được đối chiếu contract ↔ database ↔ code ↔ test, sửa
ba lỗi thực tế cộng một fixture không hợp lệ, và kiểm chứng bằng FFmpeg thật và
engine `faster_whisper_local` thật trên cả SQLite lẫn MariaDB 11.4.12.

Sau khi sửa, trong phạm vi Video local còn **0 Critical, 0 High, 0 Medium, 0 Low**
đang mở.

Verdict này đóng **đúng một capability trên local**. Nó **không** tuyên bố hoàn
thành Media nói chung, Audio-only STT, Document Processing, AI Knowledge, Product
hay production. `Implementation Status` của toàn miền Media **không** đổi.

---

## 2. Scope và explicit deferred boundary

### Trong phạm vi (đã đóng)

Video gắn vào `course_activity` qua `media_file_usages`; opt-in STT/caption tại
attach; video → audio tạm qua FFmpeg → STT; transcript theo `timespan`; caption
asset VTT dựng từ transcript đã `ready`; versioning và stale cascade; retry,
cancellation, queue recovery, late callback; Media Read theo owner context, tenant
isolation, locale/revision selector, access audit; database/migration/schema drift;
tests và tài liệu closure.

### `DEFERRED_PRODUCT_OR_PRODUCTION` — không phải finding, không chặn verdict

Production qualification evidence, soak/benchmark, deployment gate;
Redis/scheduler/worker production; Product entitlement/quota/billing; external
provider approval và production credential/configuration; retention, legal hold,
purge orchestration production.

`MEDIA_VIDEO_STT_ENABLED` giữ mặc định `false`. DOC-CONFLICT-0027 vẫn chi phối
production: không được mở Video STT/Caption ở production cho tới khi đo soak trên
đúng hardware class và chốt đồng bộ duration cap, provider deadline, worker
timeout, `retry_after` và queue visibility timeout. Review này **không** đụng tới
gate đó.

### Ngoài phạm vi hoàn toàn

AI Knowledge/Proposal/Learning Node; Document Processing; Audio-only STT; video
thumbnail/transcode ngoài phần audio tạm bắt buộc cho STT; caption locale
translation và SRT/ASS mới ngoài những gì code hiện đã public.

---

## 3. Tài liệu và code đã đọc

**Tài liệu:** `LF-INDEX.md` (routing § Media Processing); `platform/LF-Media.md`;
`platform/LF-Media-Processing-Contract.md` (Amendment 2.19/2.21/2.28, § 1 Trigger,
§ 2 Orchestration, § 3 Fingerprint/Output profile/Processing version/Stale,
§ 4 Citation locator, § 5 Đo lường, § Speech-to-text resource controls, § Caption
dựng từ transcript, § Bất biến caption persistence, § VTT Phase 1, D1–D6);
`platform/LF-Media-Read-Contract.md` (§ 1–§ 8);
`adr/ADR-0004-Media-Foundation.md`; `adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md`;
`database/media/media_files.md`, `media_file_usages.md`, `media_processing_jobs.md`,
`media_transcripts.md` (v1.9), `media_captions.md` (v1.10), `media_access_logs.md`;
`quality/LF-Media-Processing-Substrate-Architecture-Review.md`;
`quality/LF-Media-Read-Contract-Architecture-Review.md`;
`LF-Development-Standards.md`; `quality/LF-Regression-Audit.md`;
`database/LF-Schema-Drift.md`; `database/LF-SCHEMA-CONTRACT.json`;
`quality/LF-Documentation-Conflicts.md`.

**Conflict register:** không có conflict đang mở chặn Video local.
DOC-CONFLICT-0024 (caption dựng từ transcript), 0025 (caption sau transcript
`ready`), 0026 (cap video/cue) đều RESOLVED. DOC-CONFLICT-0027 RESOLVED ở mức
"provisional, local-test only, production gate giữ đóng" — đúng phạm vi review
này. 0014/0015 (`owner_type` vocabulary) vẫn `DECISION_REQUIRED` ở cấp Owner và
không tái hiện trên đường Video.

**Code:** `MediaService` (`upload`, `attachUsage`, `detachUsage`, `deleteMedia`);
`MediaProcessingOrchestrator` (`materializeForCourseActivity`,
`initialMaterializationProfiles`, `createInitialJob`, `retry`,
`materializeOnDemandProfile`, `versionFor`, `assertAudioUsage`);
`SpeechToTextProcessingEligibility`; `ProcessMediaProcessingJob` (claim,
`persistSuccess`, `archiveSupersededRevisions`,
`archiveCaptionsBuiltOnSupersededTranscript`, `materializeCaptionAfterTranscript`,
`purgeCaptionAsset`, `purgeVideoAudioWorkspace`, `failed()`,
`validatedTranscriptUnits`); `FasterWhisperSpeechToTextProvider` (nhánh video,
`extractAudio`); `VideoSpeechToTextProfile`; `VideoAudioWorkspace`;
`VideoSttQualification`; `TranscriptVttCaptionProvider`; `TranscriptVttSerializer`;
`CaptionAssetStorage`; `MediaReadService`; `FakeMediaProcessingProvider`;
`RecoverAudioProcessing`; `config/media.php`; migrations Media substrate,
`2026_08_29_000000_add_caption_transcript_provenance`, generation/key-width; và
toàn bộ test Media/Video/Caption liên quan.

---

## 4. Contract → database → code → test matrix

| Requirement (nguồn) | Database | Code | Test |
| --- | --- | --- | --- |
| Không opt-in: video vẫn upload/deliver, không sinh STT/caption | — | `initialMaterializationProfiles`, metadata `speech_to_text` | `test_video_without_opt_in_needs_no_locale_and_creates_no_stt_job` |
| Checkbox giả mạo không vượt guard | — | orchestrator + provider | `test_http_audio_upload_forging_the_checkbox...`, `test_unqualified_video_request_uploads_but_creates_no_stt_or_caption_job` |
| Gate kiểm ở **cả** materialization và provider | — | `initialMaterializationProfiles`, `FasterWhisperSpeechToTextProvider` | `test_video_stt_is_off_by_default_and_creates_no_job`, `test_the_provider_refuses_video_even_when_a_job_already_exists` |
| Job đã trong queue, gate tắt trước khi chạy → fail có tên | — | provider `video_stt_disabled` | `test_a_queued_video_job_is_refused_when_the_gate_is_switched_off` |
| Qualification hết hạn/lệch identity → chặn | — | `VideoSttQualification` | `test_production_video_stt_requires_current_unexpired_qualification_evidence`, `test_the_provider_refuses_a_queued_video_when_qualification_is_no_longer_valid` |
| Dispatch sau commit; rollback không để job rác | — | `DB::afterCommit` hai tầng | `test_after_commit_callback_is_not_dispatched_on_rollback` |
| Fingerprint là video gốc, không phải audio tạm | `media_transcripts.source_fingerprint` | `sourceFingerprint()` | `test_real_video_pipeline_...` (assert `sha256(checksum:video)`) |
| `processing_version` định danh engine + ffmpeg + codec/rate/channels + filters + compute_type/threads | `media_processing_jobs.processing_version` | `VideoSpeechToTextProfile::label()`, `versionFor` | `test_changing_an_extraction_parameter_changes_the_video_processing_version`, `test_video_threads_participate_in_identity...`, `test_video_revision_identity_comes_from_inventory_not_from_probing` |
| Identity không được tràn cột | `VARCHAR(100)` | guard `strlen > 100` (V3) | `test_a_long_ffmpeg_inventory_string_never_overflows_the_version_column` |
| ffmpeg version là inventory; worker probe và fail-closed khi lệch | — | `assertBinaryMatchesInventory()` | `test_an_undeclared_ffmpeg_version_fails_closed`, `test_an_ffmpeg_inventory_mismatch_fails_closed_before_extraction` |
| Workspace audio tạm riêng theo job/attempt, dọn sau mọi nhánh | — | `VideoAudioWorkspace`, `finally` + `failed()` | `test_real_video_pipeline_...`, `test_a_corrupt_video_fails_extraction_without_output_or_workspace_residue` |
| MIME/duration/size guard video có mã lỗi riêng | `media_files` | provider preflight | `test_a_video_mime_outside_the_allowlist_is_unsupported`, `test_video_over_ninety_minutes_is_refused_before_the_model_runs`, `test_video_over_one_gibibyte_is_refused_with_the_video_error_code` |
| FFmpeg lỗi → `audio_extraction_failed`, không output | — | `extractAudio` | `test_a_corrupt_video_fails_extraction_without_output_or_workspace_residue` |
| Segment tăng dần, không overlap/zero-length, không vượt duration | `chk_mt_locator`, unique revision-locator | `validatedTranscriptUnits` | `test_video_transcript_timespan_cannot_exceed_source_duration`, `test_real_video_pipeline_...` |
| Revision chỉ `ready` khi toàn bộ segment validate/persist xong | — | validate trước insert, trong transaction | `test_invalid_transcript_rolls_back_every_segment_and_names_the_error` |
| Transcript cũ archive, citation cũ đọc được | `chk_mt_status` | `archiveSupersededRevisions` | `test_a_new_processing_version_archives_the_previous_ready_revision`, `test_the_archived_revision_stays_readable_by_explicit_version` |
| Caption dựng **từ transcript**, không chạy model riêng | — | `TranscriptVttCaptionProvider` | `test_the_caption_row_records_the_transcript_revision_it_was_built_from` |
| Chọn **đúng một** transcript revision `ready`; thiếu/mơ hồ → fail-closed | — | `transcriptRevision()` | `test_caption_fails_closed_when_the_transcript_revision_is_ambiguous`, `test_caption_job_preserves_ambiguous_source_error_code` |
| Bất biến tồn-tại-revision ở **tầng persist** | `chk_mc_provenance` | guard trong `persistSuccess` (V1) | `test_a_caption_callback_landing_after_a_new_transcript_revision_is_rejected` |
| Transcript revision mới → archive transcript cũ, archive caption phụ thuộc, materialize caption mới | `media_captions.transcript_processing_version` | `archiveCaptionsBuiltOnSupersededTranscript`, `materializeCaptionAfterTranscript` | `test_a_new_transcript_revision_archives_the_caption_built_on_the_old_one`, `test_a_transcript_rerun_materializes_a_new_caption_chain` |
| Caption cùng locale transcript; không có locator | `media_captions` không có cột locator | `MediaReadService` trả `locator = null` | `test_the_caption_row_records_the_transcript_revision_it_was_built_from` |
| VTT: `WEBVTT`, UTF-8 không BOM, LF, `HH:MM:SS.mmm` từ integer ms | — | `TranscriptVttSerializer` | `assertVttIsWellFormed` trong `test_real_video_pipeline_...` |
| Một segment = một cue, giữ `[start,end)`, đúng thứ tự | — | serializer | như trên |
| Text có `-->` hoặc control char → fail revision | — | `cueText()` | `test_a_caption_write_that_silently_stores_nothing_is_refused` và unit serializer |
| Object ghi atomically, verify tồn tại/độ dài trước khi `ready` | `chk_mc_ready` | `CaptionAssetStorage::write` | `test_a_caption_write_that_silently_stores_nothing_is_refused` |
| Persist hỏng sau storage write → cleanup object | — | `purgeCaptionAsset` | `test_caption_asset_is_purged_when_persistence_rolls_back`, `test_a_caption_callback_landing_...` |
| Storage key gồm tenant/media/locale/fingerprint/version/format | `uk` `(customer_id, storage_key)` | `CaptionAssetStorage::key` | `test_real_video_pipeline_...` |
| Chỉ caption `ready` được Media Read trả | — | `MediaReadService` | `test_an_unready_caption_asset_reports_the_job_state_not_a_missing_locale` |
| Output chưa ready trả mã lỗi **có tên** | — | `$derivedJobType` gồm `caption_asset` (V2) | như trên |
| Detach/cancel/reattach không publish stale | — | `detachUsage`, claim guard, `persistSuccess` | `test_video_detach_cancels_pending_stt_and_caption_and_reattach_creates_a_new_generation`, `test_video_callback_after_detach_cannot_persist_transcript_or_caption`, `test_video_caption_callback_after_detach_cannot_publish_asset` |
| Retry chain độc lập giữa STT và caption | — | `retry` | `test_transcript_retries_three_times_while_caption_profiles_stay_independent`, `test_a_failed_caption_chain_retries_independently_up_to_three_attempts` |
| Queue recovery chỉ lấy job kẹt | — | `media:recover-audio-processing` | `test_speech_to_text_recovery_also_handles_expired_video_jobs` |
| Tenant isolation cho cả hai content type | composite FK | `TenantContext` + authorizer | `test_another_tenant_cannot_read_video_transcript_or_caption` |
| Allowed và denied read đều audit, không lộ nội dung/URL ký | append-only trigger | `MediaReadService::audit` | `test_real_video_pipeline_...` |

---

## 5. Video trigger và lifecycle review

Chuỗi đã xác minh trên binary thật:

```text
authorized Course video usage + opt-in
→ virus_scan (deliverability) + speech_to_text materialization
→ FFmpeg tách audio vào workspace theo (tenant, media, job, attempt)
→ faster-whisper → validate toàn bộ segment → persist transcript `ready`
→ materializeCaptionAfterTranscript (sau commit)
→ TranscriptVttCaptionProvider dựng VTT → verify object → caption `ready`
→ Media Read transcript + caption_asset
```

* **Opt-in.** Không tick thì `initialMaterializationProfiles` chỉ trả `virus_scan`;
  không có STT lẫn caption. Video vẫn `ready` và phát được.
* **Gate hai tầng.** `MEDIA_VIDEO_STT_ENABLED` kiểm lúc materialize và lại trong
  provider. Job nằm sẵn trong queue rồi gate bị tắt vẫn fail bằng
  `video_stt_disabled` — đã kiểm bằng cách tắt gate giữa dispatch và `handle()`.
* **Caption là required-nhưng-deferred.** Nó không nằm trong initial set và chỉ
  được materialize sau khi transcript commit `ready`, đúng DOC-CONFLICT-0025.
  STT fail vĩnh viễn ⇒ **không** có caption job nào, không có caption failure giả.
* **Deliverability độc lập.** Mọi nhánh STT/caption fail đều giữ
  `media_files.status = 'ready'`.
* **Terminal không hồi sinh.** Claim chỉ nhận `pending`; `persistSuccess` khoá lại
  job và đòi `processing` cho cả `speech_to_text` lẫn `caption`.
* **Detach/cancel/reattach.** Detach usage cuối cùng cancel job `pending` của cả
  hai job type; reattach tạo successor `dispatch_generation + 1` giữ
  attempt/correlation; callback muộn sau detach fail `source_unavailable` và
  không ghi transcript lẫn caption.
* **Recovery.** `media:recover-audio-processing` phủ cả `audio` và `video`, chỉ
  chạm job quá hạn.
* **Không có dependency ngầm qua retry/backoff.** Caption phụ thuộc transcript
  bằng một phép chọn revision tường minh, không bằng thời gian.

---

## 6. STT và transcript review

* `source_fingerprint` là `SHA-256(checksum || ':video')` của **binary gốc**, không
  đổi khi audio tạm đổi — đã assert trực tiếp trong E2E.
* `processing_version` mang engine + `ffmpeg-<inventory>` + codec/sample rate/
  channels + hash của **chính argument set** truyền cho `Process` + hash execution
  (`compute_type`, `threads`). Đổi bất kỳ input nào sinh version mới; audio giữ
  identity riêng, không bị chạm.
* ffmpeg version đến từ **inventory deployment**, không probe lúc tạo job; worker
  probe binary thật và fail `extraction_profile_mismatch` khi lệch,
  `provider_unavailable` khi chưa khai.
* Workspace audio tạm: `0700`, deterministic theo `(customer, media, job, attempt)`,
  dọn ở `finally` của provider, ở `failed()` khi worker bị giết, và không bao giờ
  thành `media_files` hay có signed URL.
* Validate segment chạy trọn vẹn trước insert đầu tiên trong cùng transaction:
  định dạng locator, `start < end`, nửa mở không overlap, `end_ms <=
  duration_seconds * 1000`, text không rỗng, confidence trong `[0,100]`. Vi phạm ⇒
  `transcript_invalid`, 0 row.
* Revision cũ `archived`, đọc lại được khi nêu đích danh `processing_version`.

**Evidence engine thật.** Video tổng hợp 19,1s (nền navy 320×240, h264+aac,
156.116 byte, giọng nói tổng hợp, không PII): FFmpeg tách PCM s16le 16 kHz mono,
faster-whisper `small int8` sinh nhiều segment `ready`, tất cả trong `[0, duration]`,
tăng dần, không overlap.

---

## 7. Caption / VTT / provenance / stale cascade review

* Caption **không** chạy model: nó đọc transcript rows của đúng một revision.
* Chọn revision bằng `(customer_id, media_file_id, locale, source_fingerprint,
  status=ready)`; rỗng ⇒ `transcript_unavailable`, nhiều hơn một ⇒
  `ambiguous_source`. Không `first()`, không đoán.
* **Bất biến ở tầng persist** (finding V1): trước khi insert caption row, worker
  kiểm lại revision đó vẫn `ready`. Provider chạy ở transaction khác nên phép kiểm
  của provider một mình không đủ.
* `transcript_processing_version` được ghi cho mọi caption do job sinh ra;
  `chk_mc_provenance` cưỡng chế ở database.
* Stale cascade: transcript revision mới ⇒ archive transcript cũ **và** caption
  dựng trên nó trong cùng transaction, rồi materialize caption chain mới. Cả hai
  vế đều có test — archive mà quên materialize là một nửa công việc.
* VTT: `WEBVTT` là byte đầu tiên, không BOM, newline LF, timestamp
  `HH:MM:SS.mmm` dựng từ **integer millisecond** (không đi qua float), một segment
  một cue, thứ tự cue trùng thứ tự transcript, `[start,end)` giữ nguyên. Text chứa
  `-->`, dòng trống hoặc control character ⇒ `caption_invalid`, fail cả revision.
* Object được `put` rồi **verify** tồn tại và đúng độ dài trước khi database thành
  `ready`; verify hỏng ⇒ xoá object và `caption_write_failed`. Persist hỏng sau khi
  đã ghi object ⇒ `purgeCaptionAsset`, và cleanup thất bại được log ở mức `error`
  chứ không bị nuốt.
* Storage key gồm tenant, media, fingerprint, caption processing version, locale và
  format ⇒ hai revision không ghi đè nhau.

---

## 8. Media Read, tenant và audit review

* Chữ ký `read()` bắt buộc `actor_id`, `owner_type`, `owner_id`, `usage_type`,
  `content_type`; không có API nhận bare `media_file_id`.
* Usage resolve bằng khớp **chính xác**; nhiều active row cùng slot ⇒
  `ambiguous_source`. Không `first()`/`latest()`.
* `usage_type=video` hợp lệ với `transcript`, `caption_asset` và `variant` theo
  bảng mapping Phase 1.
* Mặc định chỉ trả revision `ready` hiện hành; `archived` chỉ khi nêu đích danh
  `processing_version`; fingerprint lệch ⇒ `revision_mismatch`.
* Transcript trả text + locator timespan + fingerprint + processing version +
  locale + confidence khi có. Caption asset trả `delivery_url` ký và `locator =
  null` — không bịa timespan cho một file nhiều cue.
* Output chưa ready trả mã lỗi **có tên** (`pending`/`processing`/`failed`) cho cả
  `transcript` và `caption_asset` (finding V2).
* Cross-tenant bị từ chối cho cả hai content type, và không ghi audit row nào vào
  tenant kia.
* Mọi lần đọc allowed/denied ghi `media_access_logs.action = 'read_derived'`;
  metadata chỉ chứa selector/decision/error code — đã assert rằng nó không chứa
  transcript text và không chứa `X-Amz-Signature`.

---

## 9. Findings

Cả ba đều tái hiện được, đều đã sửa, đều có test đỏ-trước/xanh-sau.

### V1 — HIGH — Caption persist không kiểm lại transcript revision (đã sửa)

* **Requirement:** `media_captions.md` § "CHECK không chứng minh transcript
  revision tồn tại" — *"Trước khi ghi một caption row do job sinh ra, phải tồn tại
  ít nhất một row `media_transcripts` `ready` với cùng `customer_id`,
  `media_file_id`, `locale`, `source_fingerprint` và `processing_version =
  transcript_processing_version`. Không thoả thì fail cả revision caption, không
  ghi row nào."* Bất biến này được đặt ở **tầng persist** một cách có chủ ý.
* **File/symbol:** `app/Jobs/ProcessMediaProcessingJob.php::persistSuccess()`,
  nhánh `caption` — insert thẳng `transcript_processing_version` từ
  `$result` mà không kiểm lại.
* **Tái hiện:** caption job đang chạy đã chọn transcript v1 và ghi VTT; trước khi
  `persistSuccess` chạy, một STT revision v2 commit và archive v1.
  `speech_to_text` và `caption` là hai `job_type` khác nhau nên guard
  một-job-`processing`-mỗi-`(media_file_id, job_type)` **không** serialize chúng.
  Kết quả: caption row `ready` trỏ tới một transcript revision đã `archived`.
* **Ảnh hưởng:** đúng kịch bản `media_captions.md` § "Provenance và stale dây
  chuyền" cảnh báo — người học xem phụ đề của bản phiên âm đã lỗi thời trong khi
  AI đọc bản mới, hai nội dung khác nhau cho cùng một video. Thêm một hệ quả thứ
  hai: caption stale vừa insert chạy `archiveSupersededRevisions` và archive
  chính caption hiện hành, đảo ngược thứ tự revision.
* **Cách sửa:** kiểm lại sự tồn tại của revision `ready` ngay trong transaction
  persist, trước insert; không thoả ⇒ `transcript_unavailable`. Worker sẵn có
  `purgeCaptionAsset` nên object VTT vừa ghi được dọn.
* **Test:** `test_a_caption_callback_landing_after_a_new_transcript_revision_is_rejected`.
  Đã xác nhận đỏ khi gỡ guard (`-'failed' +'ready'`) và xanh khi có guard.

### V2 — MEDIUM — Media Read trả `locale_unavailable` cho caption chưa ready (đã sửa)

* **Requirement:** `LF-Media-Read-Contract` § 6 — `pending`, `processing`,
  `failed` là mã lỗi **có tên**; § 5 — mọi trạng thái khác `ready` là mã lỗi có
  tên, không phải mảng rỗng.
* **File/symbol:** `app/Services/MediaReadService.php` — bảng `$derivedJobType`
  phủ `extracted_text`, `region`, `table`, `transcript` nhưng **thiếu**
  `caption_asset`, nên nhánh caption rơi xuống suy luận theo output row.
* **Tái hiện:** video có transcript `ready`; caption job `failed`
  (`provider_unavailable`); `read(..., 'video', 'caption_asset', 'vi')` trả
  `locale_unavailable` thay vì `failed`.
* **Ảnh hưởng:** consumer đọc thành "locale này không có phụ đề" và bỏ qua, trong
  khi sự thật là "đã hỏng và sẽ không tự có kết quả" — hai tình huống đòi hai
  hành động khác hẳn. Đây đúng loại hỏng § 5.1 Spec B mô tả.
* **Cách sửa:** thêm `'caption_asset' => 'caption'` vào bảng ánh xạ.
* **Test:** `test_an_unready_caption_asset_reports_the_job_state_not_a_missing_locale`
  (đỏ trước: `-'failed' +'locale_unavailable'`).

### V3 — MEDIUM — `processing_version` của video STT tràn `VARCHAR(100)` (đã sửa)

* **Requirement:** `media_processing_jobs.md` § Fields —
  `processing_version VARCHAR(100) NOT NULL`. Amendment 2.19 § 1 bắt identity
  video phải mang engine + ffmpeg version + codec/sample format/rate/channels +
  filter hash + execution hash.
* **File/symbol:** `MediaProcessingOrchestrator::versionFor()` — nhánh video nối
  `VideoSpeechToTextProfile::label()` **không có** guard độ dài, trong khi nhánh
  OCR ngay phía trên đã có đúng guard đó (`strlen > 100` ⇒ hash).
* **Tái hiện:** `ffmpeg_version` là chuỗi **inventory tự do** do deployment khai.
  Với base khuyến nghị `faster-whisper-1.2.1-small-int8`, identity đã là **88/100**;
  một chuỗi kiểu distro `7:6.1.1-3ubuntu5` đẩy lên **99/100**. Vượt 100 ⇒ MariaDB
  ném `SQLSTATE[22001] Data too long for column 'processing_version'`. Lỗi này
  **vô hình trên SQLite** vì SQLite không cưỡng chế độ dài VARCHAR — nó chỉ lộ ra
  khi tôi chạy suite trên MariaDB 11.4.12.
* **Ảnh hưởng:** exception không bắt được ném trong `DB::afterCommit` của
  `attachUsage` — usage **đã commit** nhưng job STT không bao giờ được tạo, và
  người dùng nhận lỗi 500 khi gắn video. Cùng họ với DOC-CONFLICT-0028, nơi
  `idempotency_key` đã tràn cột một lần rồi.
* **Cách sửa:** mirror guard của nhánh OCR — `strlen($version) > 100` ⇒
  `'video-stt-'.hash('sha256', $version)` (74 ký tự). Chỉ đổi identity cho những
  version vốn đang **crash**, nên không âm thầm re-identify revision nào đang chạy.
* **Test:** `test_a_long_ffmpeg_inventory_string_never_overflows_the_version_column`
  — đo trực tiếp độ dài và xác nhận version vẫn đổi khi extraction profile đổi.

### V4 — LOW — Fake caption fixture sinh row không thể tồn tại (đã sửa)

* **Requirement:** `media_captions.md` — `CHECK (processing_job_id IS NULL OR
  transcript_processing_version IS NOT NULL)`.
* **File/symbol:** `FakeMediaProcessingProvider` — nhánh `caption` chỉ trả
  `storage_key`, nên worker ghi `transcript_processing_version = NULL` trong khi
  `processing_job_id` có giá trị.
* **Tái hiện:** bất kỳ test caption nào dùng fake provider, chạy trên MariaDB.
* **Ảnh hưởng:** không phải lỗi runtime production (provider thật luôn trả
  revision), nhưng fixture tạo ra một row vi phạm CHECK vật lý — SQLite bỏ qua
  CHECK nên nó xanh ở suite mặc định và chỉ hỏng ở database thật. Nó cũng làm
  guard V1 trông như một regression.
* **Cách sửa:** fake trả revision `ready` hiện hành của đúng
  `(customer, media, locale, fingerprint)`; không có thì trả `null` để guard V1
  fail-closed đúng hợp đồng.
* **Test:** toàn bộ suite caption chạy xanh trên MariaDB 11.4.12.

### V5 — LOW — Harness MariaDB bị `.env` ghi đè cấu hình test (đã sửa)

* **Requirement:** một lượt kiểm chứng phải chạy đúng cấu hình mà nó tuyên bố.
* **File/symbol:** `tests/Support/video-mariadb-review.php` và
  `tests/Support/audio-mariadb-review.php`.
* **Tái hiện:** harness `require` `bootstrap/app.php` để đọc cấu hình database,
  nên Dotenv nạp `.env` **vào environment của tiến trình**. Tiến trình PHPUnit con
  thừa kế environment đó, và `<env>` trong `phpunit.xml` **không** ghi đè một biến
  đã tồn tại vì không khai `force="true"`. Biến quyết định là
  **`QUEUE_CONNECTION`**: `.env` đặt `redis`, nên `ProcessMediaProcessingJob` được
  đẩy vào Redis thay vì chạy sync — **không output nào được tạo**, và mọi test
  dựa vào transcript đều đỏ. `MediaRevisionLifecycleTest` không tự set
  `queue.default` nên phụ thuộc hoàn toàn vào env; các suite có set (kể cả suite
  Video mới) không bị ảnh hưởng.
* **Ảnh hưởng:** không phải lỗi production, nhưng nó tạo ra **evidence sai**: cùng
  một class xanh khi chạy riêng (12 tests, 48 assertions trên MariaDB) và đỏ trong
  harness. Ở lượt review Audio tôi đã quy nhầm các lỗi này thành "nợ fixture";
  đính chính đã ghi vào
  [LF-Audio-Processing-Final-Code-Review](LF-Audio-Processing-Final-Code-Review.md)
  § 10.
* **Cách sửa:** gỡ **đúng tập biến mà `phpunit.xml` định nghĩa** khỏi environment
  của tiến trình con, đọc trực tiếp từ chính file đó (`simplexml_load_file`) thay
  vì một danh sách chép tay — thêm một `<env>` mới về sau sẽ tự động được phủ.
  Áp dụng cho cả hai harness.
* **Bằng chứng tái hiện** (SQLite, vài giây, không cần MariaDB) — cùng một test,
  chỉ khác đúng một biến môi trường:

  ```bash
  QUEUE_CONNECTION=redis php vendor/bin/phpunit \
    --filter=test_a_new_processing_version_archives_the_previous_ready_revision \
    tests/Feature/MediaRevisionLifecycleTest.php
  # FAILURES! Failed asserting that null is identical to 'ready'

  php vendor/bin/phpunit \
    --filter=test_a_new_processing_version_archives_the_previous_ready_revision \
    tests/Feature/MediaRevisionLifecycleTest.php
  # OK (1 test, 4 assertions)
  ```

* **Ghi chú trung thực:** lần chẩn đoán đầu của tôi chỉ gỡ tám biến `MEDIA_*` và
  **không** sửa được lỗi; `QUEUE_CONNECTION` mới là biến quyết định. Bản ghi này
  là chẩn đoán đã kiểm chứng, không phải giả thuyết đầu tiên.

### Findings còn mở trong phạm vi Video local

**Không có.**

---

## 10. Commands đã chạy và kết quả thật

| Command | Kết quả |
| --- | --- |
| `php artisan test` | **981 passed, 3 skipped, 9.854 assertions**, 189,73s |
| `php artisan test --filter=VideoTranscriptCaptionLocalReviewTest` | **6 passed, 116 assertions** — gồm FFmpeg thật + Whisper thật + VTT thật |
| `vendor/bin/pint --test` trên mọi file đã sửa/thêm | **PASS** |
| `php artisan docs:lint` | **PASS** |
| `php artisan schema:drift --docs-only` | **PASS** |
| `php artisan schema:drift --fresh` trên MariaDB 11.4.12 | **PASS** |
| `php tests/Support/video-mariadb-review.php` trên MariaDB 11.4.12 | **Xanh toàn bộ, 0 failure**: `VideoTranscriptCaptionLocalReviewTest` **OK (6 tests, 116 assertions)** 5:06 — FFmpeg + Whisper + VTT thật; `MediaRevisionLifecycleTest` **OK (12 tests, 48 assertions)** 4:09 — gồm hai case mới của V1/V2; `MediaCaptionProvenanceMariaDbTest` **OK (5 tests, 9 assertions)** 3:47 — CHECK vật lý `chk_mc_transcript_provenance` đọc từ `information_schema` |

### Lệnh tái lập

```bash
php artisan test tests/Feature/VideoTranscriptCaptionLocalReviewTest.php
php artisan test
php artisan docs:lint
php artisan schema:drift --docs-only
```

Hai lệnh MariaDB cần một instance **>= 10.5**; XAMPP hiện là `10.4.21` nên bị
version floor guard từ chối — đó là guard đúng thiết kế, không phải lỗi. Dựng
instance dùng một lần trước:

```bash
RT=/tmp/lf-video-mariadb; rm -rf "$RT"; mkdir -p "$RT/data" \
  && /usr/local/opt/mariadb@11.4/bin/mariadb-install-db --datadir="$RT/data" --auth-root-authentication-method=normal \
  && /usr/local/opt/mariadb@11.4/bin/mariadbd --datadir="$RT/data" --socket="$RT/server.sock" \
       --skip-networking --innodb_flush_log_at_trx_commit=2 --pid-file="$RT/server.pid" &
```

```bash
DB_SOCKET=/tmp/lf-video-mariadb/server.sock DB_HOST=localhost DB_USERNAME=root DB_PASSWORD='' DB_URL='' php tests/Support/video-mariadb-review.php
```

```bash
/usr/local/opt/mariadb@11.4/bin/mariadb-admin --socket=/tmp/lf-video-mariadb/server.sock -u root shutdown && rm -rf /tmp/lf-video-mariadb
```

Ghi chú về phép đo thời gian: máy review chạy ở `CPU_Speed_Limit = 20` (`pmset -g therm`) trong phần lớn lượt này, đúng hiện tượng DOC-CONFLICT-0027 đã ghi. Mọi con số thời gian ở trên vì thế là cận trên, không phải đặc tính của runtime.

### Môi trường local đã dùng

PHP 8.3.33; Laravel 12.61.0; PHPUnit 11.5.55; Python 3.11.16; faster-whisper
model `small`, `compute_type=int8`, CPU, diarization `off`; FFmpeg/ffprobe
7.1.1 tại `/usr/local/bin`; MariaDB 11.4.12.

MariaDB 11.4.12 chạy **riêng**, data directory và socket trong
`/tmp/lf-video-mariadb`, `--skip-networking`. Không sửa server XAMPP và không sửa
`.env`. Harness tạo `lf_video_review_<random>`, chạy PHPUnit, in bằng chứng CHECK
vật lý của `media_captions`, rồi `DROP` trong `finally`; có production guard và
version floor guard.

Fixture video là **tổng hợp**: nền màu + giọng nói tổng hợp, dựng tại chỗ bằng
`say` + FFmpeg, không PII, không commit, không byte nào rời khỏi máy — Phase 1
chỉ dùng provider local.

---

## 11. Files thay đổi

### Sửa lỗi

| File | Thay đổi |
| --- | --- |
| `app/Jobs/ProcessMediaProcessingJob.php` | V1 — kiểm lại transcript revision `ready` trong transaction persist của caption |
| `app/Services/MediaReadService.php` | V2 — `caption_asset` → job type `caption` cho mã lỗi có tên |
| `app/Services/MediaProcessingOrchestrator.php` | V3 — guard độ dài `processing_version` cho nhánh video STT |
| `app/Services/FakeMediaProcessingProvider.php` | V4 — caption fake khai `transcript_processing_version` |

### Test và harness

| File | Thay đổi |
| --- | --- |
| `tests/Feature/VideoTranscriptCaptionLocalReviewTest.php` | Mới — 6 case closure Video, fixture video tự tổng hợp, E2E FFmpeg + STT + VTT thật |
| `tests/Feature/MediaRevisionLifecycleTest.php` | +2 case: late caption callback (V1), caption chưa ready (V2) |
| `tests/Support/video-mariadb-review.php` | Mới — harness MariaDB disposable cho Video |

---

## 12. Gate table

| Gate | Kết quả | Cơ sở |
| --- | --- | --- |
| Contract Gate | **PASS** | Matrix § 4 phủ từng requirement của Amendment 2.19/2.21, DOC-CONFLICT-0024/0025 và `media_captions.md`; 4 sai lệch đã sửa; không có conflict mở chặn Video local |
| Schema Gate | **PASS** | `media_transcripts`/`media_captions` khớp doc; CHECK vật lý đọc từ `information_schema`; `schema:drift --docs-only` và `--fresh` pass; không sửa migration lịch sử và không cần forward migration |
| Code Gate | **PASS** | Pint và `php -l` pass trên mọi file đã chạm; sửa nằm đúng phạm vi Video; không mở Product/production/AI/Document |
| Local Runtime Gate | **PASS** | E2E thật: Course usage + opt-in → FFmpeg → faster-whisper → transcript timespan → caption VTT → Media Read cả hai content type → audit |
| Test Gate | **PASS** | 981 passed / 9.854 assertions (SQLite); suite Video 6/6; mọi negative case bắt buộc của mandate đều có test |
| Product/Production Gate | **NOT EVALUATED — DEFERRED** | Qualification evidence, soak/benchmark, deployment gate, entitlement/quota/billing, external provider, retention orchestration: ngoài phạm vi theo mandate; `MEDIA_VIDEO_STT_ENABLED` giữ mặc định `false` |

---

## 13. Kết luận

**PASS_LOCAL_VIDEO_TRANSCRIPT_AND_CAPTION.**

Ba lỗi thực tế đã được tìm, sửa và khoá lại bằng test: caption có thể publish một
phụ đề dựng từ transcript đã bị thay thế (V1), Media Read nói sai lý do khi caption
chưa sẵn sàng (V2), và identity của video STT tràn cột `processing_version` khi
deployment khai một chuỗi ffmpeg dài (V3) — lỗi cuối chỉ lộ ra trên MariaDB thật.
Cộng thêm một fixture fake sinh row vi phạm CHECK vật lý (V4).

Capability này được đóng **trên local**. Nó không tuyên bố gì về Media nói chung,
Audio-only STT, Document Processing, AI Knowledge, Product hay production, và
không đổi `Implementation Status` của toàn miền Media.
