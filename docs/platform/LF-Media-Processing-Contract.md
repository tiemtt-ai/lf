# LF-Media-Processing-Contract.md

Version: 2.37

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-09-05

Document Path: platform/LF-Media-Processing-Contract.md

Related ADR:

* [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved
* [ADR-0019 — Media Structured Extraction Boundary](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Approved v1.5

---

## Audio/Video multilingual STT amendment — Approved for implementation 2026-09-05

Sau Owner approval và provider qualification, Audio/Video upload có thể nhận
`processing_locales[]` gồm 1–3 giá trị trong `vi`, `ko`, `en` khi actor bật STT.
Một locale giữ profile legacy `diarization=off;locale=<locale>`; từ hai locale
trở lên dùng `diarization=off;locales=<canonical-csv>` và persist các row profile
trong `media_processing_job_locales`.

Profile nhiều locale chạy **một** STT job không ép locale duy nhất. Provider phải
trả timeline duy nhất và evidence locale theo từng segment; không được chạy N
transcript toàn file rồi chọn/ghép hậu kỳ. Output transcript/caption dùng selector
`locale=mul`; Media Read chọn revision bằng `language_profile`, còn evidence
segment cho biết locale thực tế. Evidence không chắc chắn để rỗng, không đoán từ
profile.

UI dùng checkbox chỉ khi STT được bật, yêu cầu ít nhất 1 và tối đa 3 lựa chọn.
Video caption vẫn deferred sau transcript `ready`, tạo một VTT `mul` giữ nguyên
ngôn ngữ nguồn. Không dịch, không tách track theo ngôn ngữ và không thay đổi
deliverability của Media File.

Implementation and activation gates:

1. Database Docs cho segment-language evidence và selector `mul` được approve.
2. Faster Whisper/runtime qualification trên corpus audio/video thật là gate
   mở production, không còn là gate viết code theo Owner instruction 2026-09-05;
   threshold accuracy, omission, timestamp và resource cost vẫn phải được Owner
   freeze trước production activation.
3. Media Read `language_profile` và citation timespan có regression/mutation test.
4. UI, validation, retry, detach/reattach, caption stale cascade và tenant scope
   có full regression. Revision cũ không backfill.

Runtime và UI local/test được triển khai sau database/review gate. Production
tiếp tục fail-closed cho tới khi qualification gate đạt; revision một locale
Phase 1 vẫn đọc nguyên trạng và không backfill.

---

## Document Latin/OCR-quality amendment — Approved 2026-09-05

Áp dụng theo ADR-0019 v1.13 và Owner approval ngày 2026-09-05:

* `Latn` resolve locale từ các candidate Latin-family trong profile: 0 candidate
  trả NULL; 1 candidate dùng candidate đó; nhiều candidate chỉ chọn `vi` khi có
  dấu hiệu chữ Việt, còn lại trả NULL;
* OCR candidate có `symbol_ratio >= 0.20` bị từ chối trước language enrichment;
  crop và text trước OCR vẫn giữ;
* text provider/text-layer không bị xóa; chỉ ghi `metadata.text_quality = low`
  khi `symbol_ratio >= 0.20` và có dưới 10 chữ cái. Câu có đủ bằng chứng chữ,
  kể cả nhiều dấu ngày tháng, là negative control và giữ `normal`;
* Media Read biểu diễn cờ vắng mặt là `text_quality = normal` và cờ hiện diện là
  `low`; đây là evidence, không phải ranking;
* structured crop Tesseract map `vi → vie`, `ko → kor`, `en → eng`; profile
  nhiều locale flatten/deduplicate đúng các locale được chọn, không tự thêm
  `eng` khi profile không có `en`;
* threshold, ngưỡng chữ, normalization rule và Tesseract language pack là output-affecting
  config. Thay chúng bắt buộc bump structured `processing_version`; không chạy
  semantics mới dưới identity cũ và không backfill revision lịch sử.

Không có schema migration: `metadata` hiện có chứa cờ revision-bound. Gate trước
implementation closure gồm unit test locale 0/1/nhiều candidate, OCR accept và
reject control, Media Read serialization, Tesseract pack canonicalization,
revision supersession, full Document regression và mutation test trên chính
candidate bị từ chối.

---

## Phase 1 Audio/Video implementation closure — 2026-09-05

Audio transcript, Video transcript, Video caption VTT và đường Media Read tương
ứng được đóng ở mức **implementation + local runtime**. Independent re-validation
trên source HEAD `c51b5e8fb0bbdb4cb6c62566e632ae5bdf410723` chạy FFmpeg/Faster Whisper thật
và đạt 28 tests, 357 assertions, 0 failed.

Closure này không thay đổi contract và không mở production. Video STT tiếp tục
mặc định tắt; production vẫn cần qualification evidence, soak/sizing trên đúng
hardware, timeout/queue/worker parity, monitoring, retention/purge, PII và
external-processing approval. AI Knowledge tiếp tục là consumer riêng, chịu
Architecture Review, Freeze và migration gate riêng.

---

## Document language profile and STEM evidence — Approved 2026-09-03

Document OCR/structured jobs nhận `locales` là tập 1–3 locale thuộc
`media.processing.document.locales`. Canonical form chuẩn hóa BCP 47, từ chối
input rỗng, duplicate, quá ba hoặc không hỗ trợ, rồi sắp xếp tăng dần. Profile
canonical dùng `locales=<csv>`; profile cũ `locale=vi` được đọc tương đương tập
một locale. Provider không được tự đổi tập locale.

Danh sách canonical tham gia `output_profile`, `output_profile_hash`,
`processing_version` và idempotency key. Job mới ghi 1–3 child row
`media_processing_job_locales`. Retry, recovery, detach/reattach và callback
muộn giữ profile của job, không đọc lại lựa chọn hiện tại trên Activity.

Page text là canonical fallback và không có bbox giả. Region mang page, reading
order, bbox, role, text quan sát được, detected locale/script nullable và crop
khi đủ điều kiện. Signal không chắc chắn phải là NULL/`undetermined`.

Mỗi formula region có thể có một `media_extracted_formulas` row. Raw text và
crop là evidence; normalized LaTeX/MathML chỉ `ready` khi provider khai format,
value và confidence đạt ngưỡng cấu hình. Nếu không có hoặc không đạt, formula
row là `unavailable`/`failed` trong khi page text và region vẫn `ready`. Media
không giải công thức và không diễn giải chart/diagram/geometry.

Error codes mới: `document_language_profile_invalid`,
`document_language_profile_unsupported`, `formula_normalization_invalid`.

---

## Document remediation D1–D6 — Approved

Owner duyệt D1–D6 ngày 2026-08-31 trong task Document Processing. Phạm vi chỉ khắc phục các finding của [review](../quality/LF-Document-Processing-Final-Code-Review.md); approval không đồng nghĩa runtime đã nghiệm thu hoặc production đã được mở.

* D1: explicit timestamp default và CHECK OCR provider, boolean header, crop all-or-none; preflight abort nếu dữ liệu cũ vi phạm, không sửa dữ liệu lịch sử.
* D2: XLS chuyển XLSX bằng LibreOffice, rồi đọc worksheet/cell trực tiếp như XLSX; `sheet` / `spreadsheet_cells`, không fallback PDF. Structured spreadsheet dùng `structure=cells`, PDF dùng `structure=layout`. Version mới; bản cũ vẫn đọc được qua citation archived.
* D3: OCR độc lập, không chờ structured. Structured chỉ materialize khi canonical OCR revision tương ứng ready; metadata `canonical_processing_job_id` ghi immutable input, processing_version hash full extractor version + canonical identity (SHA-256, không truncate). Opt-in đã ghi trên active usage được materialize sau OCR commit. On-demand chưa có canonical ready không gọi provider. Khi OCR mới ready, archive structure thuộc canonical cũ; giữ terminal jobs, citation, crop. Validate tổng canonical+region+cell <=500000 dưới Media lock; vượt cap chỉ fail structured, không truncate OCR.
* D4: PDF hỗn hợp giữ mọi page locator, kể cả text rỗng/char_count=0; extraction_method theo đường thực chạy. Toàn trắng fail no_extractable_text, không persist revision. pages_with_text chỉ tính char_count>0. Version output mới bảo vệ citation cũ.
* D5: completed-unit checkpoint monotonic: OCR page (worksheet tính một unit trong OCR); structured PDF page, structured spreadsheet sheet. Chỉ ghi khi hoàn tất quan sát unit; Docling batch hoàn tất conversion mới chứng minh các page đã xử lý, crash trước mốc này không đoán số page. Crop/validation lỗi sau đó giữ checkpoint. Checkpoint là số tuyệt đối monotonic trong từng job; retry đo riêng work thực tế, không cộng checkpoint lặp. Không suy ra tiền, quota hay SaaS aggregation.
* D6: job dispatch_generation unsigned >=1 default1; unique profile/attempt thêm generation. Explicit authorized reattach sau cancelled tạo successor generation+1, giữ attempt/correlation, supersedes_job_id trỏ cancelled. Failed retry tăng attempt nhưng giữ generation, trần3 xuyên generation. Redelivery/on-demand không mở generation. Gen1 giữ key cũ; gen>1 SHA256 full tuple gồm customer_id và generation. Terminal rows không hồi sinh; output identity không đổi vì cancellation chưa có output.

Document output revision sử dụng suffix `+document-v2` cho local OCR để áp dụng D2/D4 kể cả configured base version cũ; base+suffix quá100 ký tự dùng SHA256 full tuple. Job local OCR pending từ trước D2/D4 không được chạy semantics mới dưới version cũ: fail-closed `unsupported_output_profile`, operator materialize version mới; không sửa ready/archived history. Không đổi identity Audio/Video. Structured input metadata được validate cùng tenant/media/fingerprint/locale và ready OCR job ở claim/persistence. Input stale không được publish output.


---

# Amendment Record — Version 2.28

Amendment Status: **Approved by Architecture Owner, 2026-08-30.** Video upload
chuyển sang opt-in giống Document structured extraction: checkbox chỉ ghi nhận
yêu cầu STT/caption; không tick thì video chỉ được lưu, quét an toàn và phát.

Production có thêm qualification gate độc lập với feature gate. Một deployment
chỉ được enqueue Video STT khi evidence JSON:

* có `schema_version = 1`, `verdict = PASS` và `expires_at` còn hạn;
* mang đúng `processing_version` hiện hành;
* mang đúng hash snapshot của duration cap, provider/extraction timeout,
  queue `retry_after` và caption budgets.

Đổi model, ffmpeg inventory, extraction/STT execution profile hoặc resource
control làm evidence cũ mất hiệu lực. Request giả mạo checkbox không được vượt
guard ở orchestrator/provider. Nếu qualification không đạt, source video vẫn
được upload và deliver; không tạo STT/caption job, metadata giữ lại intent và UI
phải báo lý do có tên thay vì hiển thị `pending` giả.

Local/test được phép chạy correctness E2E không có production evidence và phải
hiển thị rõ đây không phải production approval. Production mặc định yêu cầu
evidence qua `MEDIA_VIDEO_STT_QUALIFICATION_REQUIRED=true`; kiểm trạng thái bằng:

```bash
php artisan media:video-stt-qualification --json
```

Lệnh chỉ kiểm và xuất identity/snapshot. Kết quả benchmark/soak trên hardware
production-like là đầu vào để deployment tạo evidence candidate; Owner/deployment
approval vẫn là bước mở gate, không được tự động suy từ một lần máy đang rảnh.

---

# Amendment Record — Version 2.21

Amendment Status: **Approved by Architecture Owner, 2026-08-30.** Hiệu chỉnh
Amendment 2.19 sau khi đo STT trên video thật. Hai resource cap của 2.19 bị chính
phép đo bác bỏ và được thay:

| Giá trị | 2.19 | 2.21 | Cơ sở |
| --- | --- | --- | --- |
| Thời lượng video | `max_duration_seconds = 7.200` (dùng chung với audio) | **`max_video_duration_seconds = 5.400`** | RTF 0,48 trên video thật ⇒ 7.200s cần 3.432s xử lý, vượt provider deadline 3.300s |
| Caption cue | `max_caption_cues = 5.000` | **`10.000`** | Video thật 23,1 cue/phút, gấp 3–5 lần mẫu audio tiếng Hàn |

Bốn giá trị còn lại của 2.19 không đổi. Audio giữ nguyên `max_duration_seconds =
7.200`; trần `5.400` **chỉ** áp cho video.

Ở tài liệu v2.20 hai giá trị này được đánh dấu `SUSPENDED` trong thời gian chờ
Owner quyết định; v2.21 thay chúng bằng giá trị đã chốt. Chi tiết ở
[DOC-CONFLICT-0026](../quality/LF-Documentation-Conflicts.md).

Approval này **không** thay đổi phần semantics đã duyệt ở 2.19: ordering STT →
caption, revision identity gồm canonical ffmpeg extraction profile, và quy tắc
VTT.

---

# Amendment Record — Version 2.19

Amendment Status: **Approved by Architecture Owner, 2026-08-30.** Amendment này
chốt contract để triển khai `Video → transcript + caption asset` và đóng
[DOC-CONFLICT-0025](../quality/LF-Documentation-Conflicts.md) theo phương án chỉ
materialize caption sau khi STT commit transcript `ready`.

Approval này chốt semantics và resource controls; nó không tuyên bố runtime đã
implemented, không tự cấu hình provider `caption`, và không bỏ gate nghiệm thu
video STT/caption thật trước production.

## 1. Phạm vi dùng chung với Audio STT

Video dùng lại engine, model, locale, profile, segment validation, revision,
tenant boundary, retention và Media Read của Audio STT. Khác biệt nằm ở bước
chuẩn bị source:

```text
Audio source ─────────────────────────────┐
                                         ├→ faster-whisper → media_transcripts
Video source → ffmpeg → audio tạm ───────┘
```

Provider hiện tại **chưa hỗ trợ nhánh video**: nó chặn `file_type <> audio`, và
allowlist STT chỉ có MIME audio. Trước khi code phải mở có chủ đích cả hai cổng;
không được chỉ bỏ một guard rồi để MIME gate tiếp tục làm video fail.

### Feature gate — bắt buộc, mặc định TẮT

Video STT phải có gate riêng (`MEDIA_VIDEO_STT_ENABLED`, mặc định `false`), kiểm
ở **hai tầng**: lúc materialize required set, và trong provider.

Không có gate thì deploy code sẽ **tự động bật** Video STT trên mọi hệ thống đang
bật STT audio — trái Temporary Safety Rule của
[DOC-CONFLICT-0027](../quality/LF-Documentation-Conflicts.md), vì trần thời lượng
hiện là provisional.

Kiểm ở tầng provider là bắt buộc chứ không thừa: một job đã nằm trong hàng đợi từ
trước không được lọt qua sau khi gate bị tắt. Job bị chặn fail bằng
`video_stt_disabled`.

### Revision identity của nhánh video

`source_fingerprint` tiếp tục định danh binary video gốc và không được thay đổi
thành fingerprint của audio tạm. Nhưng `processing_version` của STT video phải
định danh **cả engine STT lẫn phép biến đổi video → audio**. Nếu không, đổi
sample rate, channel, codec, filter hoặc binary ffmpeg có thể sinh transcript
khác mà idempotency vẫn coi là cùng revision.

Identity tối thiểu phải được dựng xác định từ:

```text
STT engine + model + compute type
ffmpeg binary/version
output codec + sample format + sample rate + channels
canonical filter/normalization profile
```

Hình dạng canonical phải mang đủ các thành phần sau; literal/version builder do
implementation sinh xác định từ inventory và canonical config:

```text
faster-whisper-1.2.1-small-int8
+ffmpeg-<version>
+pcm-s16le-ar16000-ac1
+<canonical-extraction-config-hash>
```

Không ghi một nhãn tay không truy được về cấu hình thật. Canonical extraction
profile/hash phải được tạo từ chính argument set truyền cho `Process`, theo thứ
tự ổn định. Thay đổi bất kỳ input nào ở trên phải sinh `processing_version` mới,
idempotency key mới, transcript revision mới và kích hoạt stale lifecycle của
revision cũ.

Runtime hiện dùng `versionFor(jobType)` và vì thế không biết source là audio hay
video. Implementation phải hoặc dùng version builder nhận Media + extraction
profile, hoặc có video-STT version namespace riêng; không được làm thay đổi
identity của audio STT hiện hành một cách ngầm định.

#### Version ffmpeg là inventory, không phải kết quả probe

`ffmpeg version` trong `processing_version` phải đến từ **cấu hình deployment**
(`MEDIA_FFMPEG_VERSION`), **không** từ việc chạy `ffmpeg -version` lúc tạo job.

Lý do là ranh giới tiến trình: job được tạo trong web request, có thể trên node
**không có** ffmpeg. Node đó sẽ ghi một giá trị "không khả dụng" vào
`processing_version`, rồi worker **có** ffmpeg xử lý bằng binary thật — output
được lưu dưới một identity nói rằng ffmpeg không tồn tại. Transcript vẫn `ready`;
không có tín hiệu nào. Cache kết quả probe còn làm nặng thêm: thay binary tại
cùng đường dẫn trong một worker sống lâu sẽ không làm version đổi.

Đổi lại, **worker phải kiểm binary thật khớp inventory trước khi xử lý** — đó là
nơi duy nhất được phép probe, vì đó là tiến trình sẽ thực sự chạy lệnh. Lệch thì
fail-closed bằng `extraction_profile_mismatch`; inventory chưa khai thì
`provider_unavailable`.

Mọi **transformation input** trong `media.processing.video_audio.*` phải thực sự
đi vào argument set và vào hash: binary/version, codec, sample format, sample
rate, channels và filters. Một transformation input được khai mà không truyền
vào lệnh là cấu hình trông như có tác dụng nhưng không có. Runtime controls
(`timeout_seconds`, `max_output_bytes`, `workspace_root`) không đổi nội dung và
không được làm sinh revision mới.

Riêng video STT, execution profile còn phải định danh `compute_type` và
`threads`. Hai giá trị này chỉ được nối vào identity của **video**; audio giữ
identity hiện hành cho tới khi có amendment/migration plan riêng. Đây là
configuration identity, không phải lời hứa output tái lập byte-for-byte: evidence
198/182/199/168 segment trên cùng input cho thấy non-determinism vẫn chưa được
giải thích và tiếp tục thuộc DOC-CONFLICT-0027.

## 2. Resource boundary của video — Owner freeze 2026-08-30, hiệu chỉnh 2026-08-30

Trần `1 GB` hiện kiểm trên binary
audio nguồn và **không được áp nguyên trạng lên video nguồn**: một video hợp lệ
có thể lớn hơn nhiều dù audio đã tách nhỏ và thời lượng vẫn trong giới hạn.

| Giới hạn | Trạng thái | Semantics bắt buộc |
| --- | --- | --- |
| `max_video_source_bytes = 1.073.741.824` (1 GiB) | Frozen | Áp lên binary video trước ffmpeg; bằng upload limit hiệu lực hiện hành |
| `max_video_duration_seconds = 5.400` (90 phút) | **PROVISIONAL / local-test only** — Owner xác nhận 2026-08-30; chưa phải production-safe | Áp lên thời lượng video nguồn. **Chỉ cho video**; audio giữ nguyên `max_duration_seconds = 7.200` |
| `max_extracted_audio_bytes = 268.435.456` (256 MiB) | Frozen | Áp lên PCM tạm sau ffmpeg |
| `video_audio_extraction_timeout_seconds = 600` | Frozen | Nhỏ hơn provider deadline 3.300s và job timeout 3.600s |

### Vì sao 5.400 chứ không phải 7.200

Đo STT trên video thật (media 10, tiếng Việt, 514s) cho **RTF 0,48** — chậm 2,5
lần audio bài giảng (0,19–0,28). Chiếu lên hai mốc:

| Thời lượng | Xử lý | So với provider deadline 3.300s |
| ---: | ---: | --- |
| 7.200s (120 phút) | 3.432s | **105% — vượt 132 giây** |
| **5.400s (90 phút)** | **2.592s** | **79% — dự phòng 708s (21,5%)** |

Ở 7.200s, kiểu hỏng là tệ nhất có thể: đốt gần một giờ worker rồi chết bằng
`provider_timeout`, không ra output nào. Và không test nào bắt được, vì fixture
test đều ngắn.

Phương án nâng deadline riêng cho video-STT bị loại ở Phase 1: nó kéo theo job
timeout, worker timeout, `retry_after`, supervisor termination và queue
visibility timeout — năm mục phải đổi đồng bộ để mua thêm 30 phút.

**Hệ quả phải công bố:** media 6 (`5.795s`, 96,6 phút) **không đủ điều kiện video
STT**. Video vẫn upload và phát được bình thường nếu virus scan đạt; chỉ
STT/caption bị từ chối bằng `video_limit_exceeded`. Từ chối trước khi chạy khác
hẳn với chạy 57 phút rồi chết.

#### PROVISIONAL — RTF không ổn định trên máy đo

Nghiệm thu end-to-end ngày 2026-08-30 cho thấy con số 5.400s **không đứng vững**.
Cùng một file, cùng model, cùng `compute_type`:

| Lần đo | Cấu hình | RTF |
| --- | --- | ---: |
| Benchmark ban đầu, máy nguội | `cpu_threads=8` | 0,48 |
| Chạy qua pipeline thật | `cpu_threads=0` (mặc định runtime) | 0,81 |
| Đo lại | `cpu_threads=0` | 2,22 |
| Đo lại | `cpu_threads=8` | 1,55 |

Dao động **4,6 lần**. Nguyên nhân đo được: `pmset -g therm` báo
`CPU_Speed_Limit = 20` — CPU chạy ở 20% tốc độ danh định do throttling nhiệt,
cộng load average 6,04.

Hai hệ quả phải nói rõ:

* Benchmark 0,48 dùng `cpu_threads=8`, còn runtime truyền `--threads 0`. **Cấu
  hình được đo không phải cấu hình được chạy.** `threads=8` thật sự nhanh hơn
  (1,55 so với 2,22), nhưng chênh lệch đó không giải thích được khoảng cách với
  0,48 — phần lớn là throttling.
* Máy throttle **theo thời gian chạy liên tục**, nên RTF **xấu đi theo độ dài
  job**. Suy trần cho job 90 phút từ RTF đo trên clip 8 phút vì thế sai một cách
  **hệ thống**, luôn lạc quan, và lạc quan nhiều hơn khi job dài hơn.

`5.400` vì thế là **provisional, đo trên dev**. Nó phải được đo lại trên hardware
class của production trước khi thành giá trị production. Xem
[DOC-CONFLICT-0027](../quality/LF-Documentation-Conflicts.md) cho câu hỏi sâu hơn:
buộc một trần *thời lượng* vào một *deadline thời gian* qua một hằng số RTF chỉ
đúng khi RTF là hằng số.

**Owner decision 2026-08-30:** Phase hiện tại giữ `5.400` làm cap bảo thủ cho
development/test, đồng bộ `threads` giữa benchmark và runtime khi đo lại, và
giữ feature gate Video STT mặc định **tắt**. Không được mở provider Video STT
hoặc Caption ở production cho tới khi có phép đo soak trên đúng hardware class
production và chốt đồng bộ duration cap, provider deadline, worker timeout,
`retry_after` cùng queue visibility timeout. Quyết định này đóng mâu thuẫn tài
liệu; nó **không** chứng nhận con số 5.400 là production-safe.

**Giới hạn khác của bằng chứng:** RTF đến từ **một** video, giọng đọc liên tục.
Video hội thoại nhiều người hoặc thu âm kém có thể chậm hơn nữa.

Với trần 5.400s, PCM tạm tối đa là `5.400 × 32.000 = 164,8 MiB`; trần
`max_extracted_audio_bytes = 256 MiB` vẫn rộng và giữ đúng vai trò phát hiện
cấu hình bất thường.

Không dùng `audio_limit_exceeded` cho video nguồn quá lớn. Error vocabulary
**được freeze**:

```text
video_limit_exceeded
audio_extraction_limit_exceeded
audio_extraction_failed
```

Không được truncate video hoặc audio tạm: vượt trần làm fail cả STT revision để
consumer không nhầm "hết nội dung" với "bị cắt".

### Evidence cho resource limits

Đo local trên hai video thật trong hệ thống:

| Media | Video source | Thời lượng | PCM s16le 16 kHz mono | Thời gian tách |
| ---: | ---: | ---: | ---: | ---: |
| 10 | 172.448.123 byte (~164,5 MiB) | 515s | ~15 MiB | 1s |
| 6 | 958.267.742 byte (~913,9 MiB) | 5.795s | ~176 MiB | 8s |

PCM s16le 16 kHz mono có tốc độ cố định `32.000 byte/s`; ở trần 7.200s cần
`230.400.000 byte` (~219,7 MiB). Trần 256 MiB phủ toàn bộ duration cap và header;
vượt trần này là tín hiệu duration/config validation đã lệch, không phải tình
huống cần truncate.

Upload limit hiệu lực là `min(upload_max_filesize, post_max_size) = 1 GiB`, nên
`max_video_source_bytes` lớn hơn 1 GiB hiện không mở thêm input hợp lệ. Nâng cap
này về sau bắt buộc đổi PHP/web-server upload limits trước hoặc đồng thời, rồi
review lại benchmark/resource impact; không chỉ sửa một dòng Media config.

### Namespace cấu hình ffmpeg bắt buộc

`media.ffprobe_binary` chỉ đọc metadata và không thay thế được binary tách
audio. Trước implementation phải có namespace tường minh, tối thiểu:

```text
media.processing.video_audio.ffmpeg_binary
media.processing.video_audio.timeout_seconds
media.processing.video_audio.codec
media.processing.video_audio.sample_format
media.processing.video_audio.sample_rate
media.processing.video_audio.channels
media.processing.video_audio.filters
media.processing.video_audio.max_output_bytes
media.processing.video_audio.workspace_root
```

Preflight phải kiểm binary tồn tại và executable, workspace tạo được, argument
profile thuộc vocabulary đã freeze và timeout nhỏ hơn job timeout. Config chỉ
định sai phải fail `provider_unavailable`; không tự tìm một ffmpeg khác trên
`PATH`, vì làm vậy phá revision identity và local/production parity.

## 3. Audio tạm từ video

Audio tạm là workspace nội bộ của một job/attempt, không phải Media asset:

* không tạo `media_files` hoặc `media_file_usages`;
* không upload object storage và không có signed URL;
* đường dẫn phải deterministic theo tenant, Media, job và attempt, không dùng
  tên file client;
* permission local phải giới hạn cho process benchmark/runtime;
* dọn khi success, exception, timeout và `failed()`;
* worker chết trước `finally` không được biến audio tạm thành dữ liệu ngoài
  retention: đường `failed()` phải suy ra được workspace để dọn, và deployment
  phải có runbook dọn residue cho process bị kill trước callback.

## 4. Dependency STT → caption — Owner resolved 2026-08-30

Required set hiện tạo và dispatch `speech_to_text` cùng `caption`, trong khi
caption bắt buộc đọc một transcript revision `ready`. Không có dependency state
hoặc ordering guarantee trong runtime hiện tại. Conflict này được đóng về mặt
contract tại [DOC-CONFLICT-0025](../quality/LF-Documentation-Conflicts.md).

Semantics được freeze:

```text
video upload
→ materialize virus_scan + speech_to_text
→ STT persist transcript và commit ready
→ materialize caption idempotently
→ caption persist VTT
```

Không dùng retry/backoff hiện có làm dependency ngầm. Chờ dependency không phải
provider failure và không được tiêu attempt. Nếu STT fail vĩnh viễn thì caption
không được materialize; trạng thái UI phải suy ra từ STT failure, không tạo thêm
một caption failure giả.

Quy tắc này áp dụng cho **mọi transcript revision mới**, không chỉ upload đầu
tiên. Khi STT video chạy lại thành công:

```text
transcript v2 commit ready
→ archive transcript v1
→ stale cascade archive caption dựng từ transcript v1
→ materialize caption job cho transcript v2
```

Không được dừng sau stale cascade: nếu không materialize revision mới, video sẽ
vĩnh viễn không có caption `ready` tương ứng với transcript hiện hành. Việc tạo
caption mới phải idempotent theo Media, locale, source fingerprint, transcript
processing version, caption processing version và format; nếu identity đó đã có
job/output thì tái sử dụng thay vì tạo bản trùng.

## 5. Bất biến caption persistence

Trước khi tạo asset hoặc ghi row, caption persistence phải chọn đúng **một**
transcript revision `ready` thỏa tất cả:

```text
customer_id
media_file_id
locale
source_fingerprint
processing_version = transcript_processing_version được ghi vào caption
```

Không tìm thấy hoặc tìm thấy mơ hồ → fail-closed, không tạo file và không ghi
row. CHECK vật lý chỉ bắt `transcript_processing_version` có giá trị; nó không
chứng minh revision có thật, nên test tầng persist là bắt buộc.

Runtime hiện tại đã cưỡng chế bất biến này: provider chọn đúng một transcript
revision `ready`, và đường persist ghi chính revision đó vào
`transcript_processing_version`. Không tìm thấy hoặc có nhiều revision `ready`
thì job fail bằng mã có tên; không viết một cơ chế stale thứ hai.

## 6. VTT Phase 1 — Owner freeze 2026-08-30

Các luật serialization được freeze:

* một transcript segment sinh đúng một VTT cue;
* giữ nguyên timespan nửa mở `[start_ms, end_ms)`; không ghép, chia hoặc đoán lại;
* file bắt đầu bằng `WEBVTT`, UTF-8 không BOM, newline LF;
* timestamp dùng `HH:MM:SS.mmm`, làm từ millisecond nguyên đã persist, không dùng
  float;
* cue không cần sequence/id; thứ tự cue đúng thứ tự segment;
* text giữ nguyên nội dung transcript, chuẩn hóa line ending; text có dòng chứa
  token `-->` hoặc ký tự điều khiển không hợp lệ phải fail toàn revision thay vì
  sinh VTT mơ hồ;
* asset được ghi atomically; database chỉ chuyển `ready` sau khi object tồn tại
  và được xác minh;
* persistence fail sau khi ghi object phải dọn object; cleanup fail phải để lại
  retry evidence, không nuốt lỗi;
* storage key phải chứa tenant, Media, locale, source fingerprint, caption
  processing version và format để revision không ghi đè nhau.

### Trạng thái triển khai — 2026-08-30

**Serialization đã có runtime.** `TranscriptVttSerializer` hiện thực toàn bộ luật
trên: thuần deterministic, không đọc DB, không ghi storage, không chạy model —
cùng một đầu vào luôn cho cùng một chuỗi byte.

Nó được viết **trước** phần materialize có chủ ý: serialization không phụ thuộc
`max_video_duration_seconds` (DOC-CONFLICT-0027) lẫn độ rộng `idempotency_key`
(DOC-CONFLICT-0028), nên hai gate đó không chặn nó và nó test được đầy đủ ngay.

**Cả ba phần còn lại nay đã có runtime** sau khi DOC-CONFLICT-0027 và 0028 đóng:

* `TranscriptVttCaptionProvider` — chọn đúng **một** transcript revision `ready`,
  fail-closed bằng `transcript_unavailable` hoặc `ambiguous_source`;
* `CaptionAssetStorage` — ghi rồi **xác minh** object tồn tại và đúng độ dài trước
  khi trả về; `put()` trả `true` không chứng minh object đã nằm trên storage;
* trigger post-STT materialize caption ngay sau khi transcript commit `ready`, và
  persist ghi `transcript_processing_version` làm provenance.

#### E2E local/test — PASS 2026-08-30

Chạy trên một bản remux riêng của video thật 514 giây, không dùng Media đang gắn
Activity production-like:

Inventory và execution identity đã dùng trong đúng lần chạy này (evidence được
ghi tại đây vì `.env` là file local bị Git ignore, không phải nguồn bằng chứng):

| Input | Giá trị nghiệm thu |
| --- | --- |
| `MEDIA_FFMPEG_BINARY` | `/usr/local/bin/ffmpeg` |
| `MEDIA_FFMPEG_VERSION` | `7.1.1` |
| Binary probe | `ffmpeg version 7.1.1 Copyright (c) 2000-2025 the FFmpeg developers` |
| Extraction profile | `pcm_s16le`, `sample_fmt=s16`, `ar=16000`, `ac=1`, không filter |
| STT execution | `faster-whisper 1.2.1`, model `small`, `int8`, `threads=0` |
| Job `processing_version` | `faster-whisper-1.2.1-small-int8+ffmpeg-7.1.1+pcm_s16le-ar16000-ac1+d61e370f+stt-2a28d603` |

Đây là snapshot inventory của lần nghiệm thu, không phải hướng dẫn lấy identity
từ tài liệu. Mỗi deployment vẫn phải khai inventory trong cấu hình của chính nó
và worker phải probe binary thật theo luật fail-closed ở trên.

```text
upload → virus_scan ready
→ Video STT ready: 213 transcript segment
→ post-STT caption ready: 1 VTT asset / 213 cue
→ Media Read: 213 transcript unit + 1 caption asset, audit allowed;
  caption locator/text/structure = null, delivery_url được ký
→ STT revision 2: caption v1 archived, caption v2 ready, hai storage key riêng
→ detach + delete Media: source, transcript rows, caption rows và cả hai VTT
  object đều bằng 0; processing jobs và access logs được giữ làm audit
```

Caption v1 mang `processing_version = transcript-vtt-v1+from-fb8833d1`; caption
v2 mang `transcript-vtt-v1+from-72c9c0d6`, chứng minh transcript revision tham
gia identity và lần materialize thứ hai không bị dedupe. VTT thật bắt đầu bằng
`WEBVTT`, dài 14.917 byte và có đúng 213 timing arrow.

Failure evidence tách biệt cũng bắt buộc: job giữ nguyên mã
`ambiguous_source`; nếu provider đã ghi VTT nhưng transaction persist rollback,
object mới bị purge trong khi object/row có sẵn không bị đụng tới.
`include_crop` bị từ chối cho `caption_asset`: caption là asset cấp file, không
có crop và không được bịa locator cue-level.

Đường từ chối cũng được đo trên dữ liệu thật mà không chạy model: Media 6 dài
5.795 giây (96,6 phút) vượt cap provisional 5.400 giây và provider trả đúng
`video_limit_exceeded` trước ffmpeg/STT.

Evidence này cho phép tuyên bố luồng Video STT + Caption **hoàn tất ở local/test**.
Nó không mở production: feature gate Video STT vẫn mặc định tắt và điều kiện soak
hardware production của DOC-CONFLICT-0027 vẫn còn hiệu lực.

#### Identity của caption gồm cả revision nguồn

`processing_version` của caption phải chứa transcript revision đã dùng. Nếu không,
caption dựng từ transcript v1 và v2 có **cùng** version, **cùng** idempotency key —
lần materialize thứ hai bị dedupe. Hậu quả: caption cũ bị stale cascade archive,
caption mới không bao giờ được tạo, và video **mất phụ đề vĩnh viễn**.

Lỗi này chỉ lộ ra khi đếm số chain sau một lần STT chạy lại.

Resource caps:

```text
max_caption_cues  = 10.000
max_caption_bytes = 1.048.576 (1 MiB)
```

`max_caption_cues` nâng từ `5.000` lên `10.000` ngày 2026-08-30. Số cũ chốt trên
mẫu chỉ gồm audio tiếng Hàn (4,5–7,0 cue/phút); video thật cho **23,1 cue/phút**,
dày gấp 3–5 lần. Ở trần 5.400s là ~2.079 cue, nên `10.000` cho biên **4,8×** —
đủ rộng cho đối thoại ngắn liên tục, nhiều người nói, hoặc provider đổi cách chia
segment. Số cũ chỉ còn biên `2,4×`, quá chật khi mới có một fixture video.

`max_caption_bytes = 1 MiB` **không đổi**: 1.527 byte/phút ⇒ 134 KB ở 90 phút,
biên `7,6×`.

Evidence video thật, media 10 (`video/mp4`, 514s, tiếng Việt tự phát hiện 0,97),
chạy engine trực tiếp trong thư mục tạm, không tạo row nào:

```text
RTF 0,48   |   198 cue   |   5.113 ký tự   |   VTT 13.088 byte
=> 23,1 cue/phút, 1.527 byte/phút, 177/197 cặp giáp ranh, 0 chồng lấn
```

Lần chạy này **chỉ** chứng minh hiệu năng model, cue density và kích thước VTT.
Nó **không** chứng minh orchestration, cleanup audio tạm, persistence, caption
dependency, Media Read hay deletion lifecycle — vì thế nó đủ để chỉnh resource
contract, không phải nghiệm thu end-to-end.

Evidence cũ từ hai transcript tiếng Hàn dài khoảng 3,3 phút: 4,5–7,0
cue/phút và 449–519 byte/phút. Ngoại suy 120 phút là tối đa khoảng 840 cue và
62 KB trên mẫu này; caps tương ứng khoảng 6× và 16×. Đây là resource safety cap,
không phải SLA chất lượng. Mẫu hẹp (tiếng Hàn, audio ngắn) phải được ghi trong
acceptance report; STT video thật vẫn phải nghiệm thu trước production.

## 7. Điều kiện trước khi cấu hình provider và mở production

Thiết kế và resource caps ở trên đã đủ để **bắt đầu code**. Bốn mục dưới là điều
kiện để **mở provider**, không phải điều kiện để viết dòng đầu tiên — mục 1 và 2
chính là công việc implementation, nên đặt chúng sau một cổng "trước
implementation" là tự mâu thuẫn.

1. Implement canonical video-STT revision identity và ffmpeg extraction profile;
   test chứng minh đổi một extraction parameter sinh revision mới.
2. Test plan phủ ordering, dependency failure, transcript existence, VTT escape,
   atomic asset/database persistence, re-materialize caption sau STT rerun và
   cleanup sau worker failure.
3. Chạy nghiệm thu thật trên ít nhất một video: audio extraction, STT, caption
   VTT, Media Read delivery, deletion/cleanup và resource evidence.
4. Provider `caption` giữ `unconfigured` cho tới khi các implementation gates
   trên chạy xanh.

---

# Amendment Record — Version 2.18

Amendment Status: **Approved by Architecture Owner, 2026-08-29.** Với Activity
audio, `speech_to_text` chuyển từ required profile mặc định sang lựa chọn tường
minh của người upload. UI mặc định bật lựa chọn để ưu tiên audio có thể trở thành
nguồn nội dung cho AI, nhưng actor được phép bỏ chọn nếu chỉ cần phát/nghe file.

- Có chọn: bắt buộc locale Phase 1 (`vi`, `ko`, `en`), tạo job
  `speech_to_text`, lưu transcript theo `timespan`.
- Không chọn: chỉ tạo `virus_scan`; không yêu cầu locale, không tạo transcript,
  và UI phải ghi rõ audio chưa thể dùng làm nguồn nội dung cho AI.
- Quyết định được lưu trong metadata của `media_file_usages`; “không yêu cầu”
  phải khác “đã yêu cầu nhưng không có job”.
- STT thất bại không làm mất deliverability của audio. Actor có thể khởi tạo STT
  sau mà không upload lại, theo luồng được authorize và khóa row Media.

Thay đổi này chỉ áp dụng cho `audio`. Required set của `video` vẫn gồm STT và
caption; Docling structured extraction của document vẫn là opt-in độc lập.

---

# Amendment Record — Version 2.14

Amendment Status: **Approved by Architecture Owner, 2026-08-29.** Media audio
legacy được tạo trước khi locale canonical trở thành bắt buộc có thể khởi tạo
STT lần đầu, nhưng chỉ bằng thao tác tường minh của actor đã được authorize trên
Course Activity.

Đây là phép **điền lần đầu**, không phải sửa locale hay retry:

- Media phải `ready`, thuộc đúng tenant, có `file_type = audio`,
  `processing_locale IS NULL` và chưa từng có job `speech_to_text`;
- actor phải chọn tường minh một locale Phase 1 (`vi`, `ko`, `en`);
- khóa row Media, ghi locale và tạo job đầu tiên trong cùng transaction;
- nếu locale đã có hoặc đã từng có STT job, thao tác fail-closed. Job `failed`
  dùng retry chain; `pending`/`processing` không được tạo trùng; `ready` tái sử
  dụng output hiện có.

Sự vắng mặt của job phải hiển thị là trạng thái `absent`/“Chưa có tác vụ”, không
được suy diễn thành `pending`. Quy tắc này áp dụng tương tự cho optional job
`structured_extraction`; amendment này không cấp action phục hồi Docling mới.

---

# Amendment Record — Version 2.1

Amendment Status: **Approved by Architecture Owner, 2026-08-27.** Mục này có hiệu
lực; tài liệu chuyển sang Version 2.1. § 4 đọc theo vocabulary mới kể từ ngày
này.

Nguồn: đối chiếu tài liệu ↔ source ngày 2026-08-27 phát hiện tài liệu này đã có
mục *Structured extraction resource controls* nói về region và cell, trong khi
§ 4 vẫn chỉ liệt kê `page` và `timespan`, và ADR-0019 không nằm trong Related
ADR. Mâu thuẫn nội bộ trong một tài liệu Approved, ghi là **DOC-CONFLICT-0018**.

Amendment này sửa ba chỗ:

1. **§ 4 nhận `sheet` và `region`** đúng theo ADR-0019 § D1. Hình dạng locator
   không đổi; chỉ vocabulary mở.
2. **§ 2 nhận `job_type = 'structured_extraction'`** cùng `output_type` tương
   ứng. Đây là khoảng trống chưa tài liệu nào chạm tới, ghi là
   **DOC-CONFLICT-0019**: CHECK hiện đóng ở bảy giá trị và `output_type` chỉ có
   `transcript | caption | extracted_text | variant`, nên **không giá trị nào
   chứa được** một revision region/table/cell. Owner chọn hướng job riêng ngày
   2026-08-27.
3. **`structured_extraction_too_large` được xếp nhóm lỗi vĩnh viễn**, không
   retry.

Quan sát kèm theo, **không** sửa trong amendment này: `extracted_text_too_large`
và `page_limit_exceeded` cùng bản chất vĩnh viễn nhưng chưa bao giờ được liệt kê
ở § 2. Chúng cần một quyết định riêng — chỗ này chỉ ghi lại, không tự thêm.

Amendment này **không** phê duyệt provider nào và không mở lại hồ sơ A0.

**Bổ sung Version 2.7, Approved 2026-08-28.** Structured job phải ghi coverage
giữa canonical text pages và structured region pages vào job metadata. Coverage
được chọn theo source identity và canonical OCR revision, không ghép nhầm OCR
version với Docling version. Đây là observability evidence; thiếu region không
tự làm optional job thất bại và không thay đổi readiness của Media File.

**Bổ sung Version 2.8, Owner-directed 2026-08-28.** Nghiệm thu thật trên PDF
tiếng Hàn 100 trang cho thấy figure ở trang scan có thể nhận text chỉ một ký tự,
làm điều kiện cũ `text = null` bỏ qua OCR crop. Phase hiện tại coi text vùng có
ít hơn `crop_ocr_min_text_characters = 2` ký tự là chưa đủ bằng chứng nội dung và
cho Tesseract chạy với locale canonical. Kết quả OCR chỉ thay text hiện có khi
dài hơn; text Docling có ý nghĩa không bị ghi đè. Provenance của kết quả thay thế
vẫn phải ghi `ocr_engine` và `ocr_language`.
Thay đổi output này bắt buộc dùng processing version
`docling-2.119.0-layout-v5`; revision `layout-v4` là lịch sử bất biến và không
được ghi đè hoặc tái diễn giải.

**Bổ sung Version 2.6, Approved 2026-08-28.** Architecture Owner nâng
`max_regions_per_page` từ `50` lên `100`, giữ nguyên
`max_regions_per_document = 5000`. Quyết định dựa trên nghiệm thu local bằng
tài liệu PDF tiếng Hàn 100 trang: Docling sinh 1.924 region toàn tài liệu, nhưng
riêng trang 15 có 61 region hợp lệ và làm revision fail toàn phần dưới trần cũ.
Trần `100` nhận tài liệu này mà vẫn giới hạn một trang; trần toàn tài liệu
`5000` tiếp tục chặn output phân mảnh quá mức. Đây là resource-policy amendment,
không cho phép truncate, không đổi `max_pages`, không đổi ngân sách ký tự/cell và
không tự hợp thức hoá revision đã fail dưới processing version cũ.

**Bổ sung Version 2.5, Approved 2026-08-27.** Architecture Owner đã mở quyết
định A1 và phê duyệt provider `docling_local` cho structured extraction PDF.
Provider chạy offline bằng Python 3.11 + Docling 2.119, chỉ lấy layout/table;
text/OCR canonical vẫn do Poppler/Tesseract tạo. Profile PDF là
`locale=<canonical-locale>;structure=layout`. Việc phê duyệt provider này không
tự biến output optional thành required và không cho phép external processing.

**Bổ sung Version 2.4, Approved 2026-08-27.** Mục *Provider, version và resource
namespace* ở § 2 đóng ba mảnh còn thiếu để **triển khai** được: cặp
provider/version của job mới, namespace resource control riêng, và deadline.
Không có ba thứ này thì một job `structured_extraction` khởi động mà không có
provider để gọi và không có giới hạn nào.

**Bổ sung Version 2.3, Approved 2026-08-27.** Mục *Lộ trình chuyển sang
required* ở § 1 chốt cách đưa structured extraction từ optional lên required mà
không rơi vào bẫy bất biến của required output profile set.

**Bổ sung Version 2.2, Approved 2026-08-27.** Amendment v2.1 chốt job identity
nhưng không nói job đó được sinh ra khi nào. Mục *Phạm vi áp dụng của structured
extraction* ở § 1 đóng khoảng trống đó bằng **một quy tắc cờ**: cùng cổng kích
hoạt với mọi job khác, không cổng mới, và thuộc nhóm optional/on-demand. Mức chi
tiết hơn — có bắt buộc riêng cho spreadsheet hay không — cố ý để mở và hỏi lại
Owner lúc implement.

---

# Scope

Hợp đồng cho **substrate xử lý Media**: khi nào một tác vụ được kích hoạt, nó
chạy ra sao, kết quả sống ở đâu, và khi nào kết quả hết hiệu lực.

Tài liệu này **không** định nghĩa Media Read Contract cho AI consumer. Đó là một
tài liệu riêng, phụ thuộc tài liệu này. Tách ra để substrate không bị hợp đồng
consumer chặn — substrate là đường dài nhất và không cần AI để bắt đầu.

Không thuộc phạm vi: AI Proposal persistence, Proposal review workflow, ghi
Learning Node/Mapping, và automatic publish. Tất cả vẫn bị gate theo ADR-0017.

## PII processing policy

Section này có hiệu lực theo ADR-0018 được Architecture Owner approve ngày
2026-08-25. Approval cho policy PII không phải approval cho external provider.

`PII_PRESENT` phân loại nội dung, không phải job status hay error code. Nó không
được tự động làm Media File/job `failed` hoặc `cancelled`, từ chối enqueue, hay
chặn OCR deterministic/local. Local/self-hosted processing được phép khi actor
đã authorize trên owner context, tenant isolation được giữ và provider nằm trong
boundary đã approved.

Ba thuộc tính phải tách biệt:

| Thuộc tính | Ý nghĩa | Không được suy ra |
| --- | --- | --- |
| Source có PII | Source/output có dữ liệu cá nhân | Không suy ra processing failure |
| Redacted derivative | Asset riêng đã qua quy trình redaction | Không suy ra source gốc đã bị sửa hoặc output khác đã redact |
| External-processing eligibility | Provider/purpose cụ thể được phép rời tenant boundary | Không suy ra từ local OCR eligibility |

Không được tự redact hay sửa source gốc. Redaction tạo derivative riêng với
fingerprint của chính bytes derivative, processing version và provenance riêng;
source gốc giữ nguyên.

Mọi external provider call, gồm Docling cloud nếu có, Bedrock, Textract,
OpenAI, Claude, Gemini, OpenRouter, vision và provider ngoài tenant boundary,
cần policy/approval riêng trước khi gửi source, crop hoặc derived content. Hợp
đồng này không approve provider/runtime nào trong số đó.

PII policy không nới resource controls. Source 101/121 trang vẫn
`page_limit_exceeded` vì vượt `max_pages = 100`, kể cả khi corpus/source có
approval PII đầy đủ.

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

Ngoại lệ duy nhất là `virus_scan`: đây là binary deliverability gate nên được
materialize ngay sau commit của upload cho mọi Media File, trước khi có usage.
OCR/STT/caption/variant fan-out vẫn chỉ bắt đầu từ active authorized usage theo
điều kiện trên. Không có ngoại lệ này, file ngoài Course sẽ ở `processing` vô hạn.

Tập `owner_type` được phép xử lý trong Phase 1: `course_activity`.

`course_version_activity` là usage của **published Version Activity** và là bản
bất biến. Nó không kích hoạt job mới: nội dung nhị phân giống hệt file gốc, nên
`source_fingerprint` giống nhau và output đã có sẵn được dùng lại. Đây là lý do
fingerprint neo vào nội dung chứ không neo vào usage.

Không kích hoạt xử lý cho `avatar`, `marketing`, `certificate` hoặc bất kỳ
`owner_type` nào ngoài tập trên. Mở rộng tập này là amendment có review.

## Phạm vi áp dụng của structured extraction

Version 2.2, Approved 2026-08-27. Một quy tắc, không ngoại lệ:

```text
structured_extraction dùng ĐÚNG cổng kích hoạt ở trên.
Nó không mở thêm cổng nào, và không có ngoại lệ kiểu virus_scan.
```

| | |
| --- | --- |
| **Áp dụng cho** | usage `course_activity` đang `active` và đã authorize, với `file_type = 'document'` |
| **Không áp dụng cho** | `avatar`, `marketing`, `certificate`, `course_category` và mọi `owner_type` khác — giống hệt `ocr` |
| **Không áp dụng cho** | `image`, `audio`, `video`, `subtitle`, `transcript`, `archive`, `other` |
| **Nhóm** | **optional / on-demand**, không nằm trong required output profile set |
| **Deliverability** | không chặn. `media_files.status` không chờ nó; chỉ Media Read Service trả `not_ready` cho `content_type` `region`/`table` |
| **Published Version Activity** | `course_version_activity` không kích hoạt job mới, đúng như mọi job khác: cùng `source_fingerprint` thì dùng lại output đã có |

Lý do đặt ở nhóm optional chứ không phải required: required output profile set
được materialize **một lần tại trigger và bất biến** cho source đó. Đặt
structured vào required hôm nay nghĩa là mọi PDF đều sinh một job không có engine
để chạy, và không rút lại được. Ở nhóm optional thì nhánh spreadsheet chạy được
ngay mà không hứa gì thay cho nhánh PDF.

Hệ quả cho consumer, nói tường minh để không ai phải đoán: **không được giả định
mọi document đều có revision cấu trúc.** Thiếu structured revision là trạng thái
hợp lệ, không phải lỗi; Read Service trả `missing` theo hợp đồng sẵn có.


### Lộ trình chuyển sang required

Approved 2026-08-27. Mong muốn "document gắn vào Activity thì **phải** được xử lý
cấu trúc" là mong muốn hợp lệ, nhưng không đạt được bằng cách đặt required ngay
hôm nay.

Required output profile set **materialize một lần tại trigger và bất biến** cho
source đó. Đặt structured vào required bây giờ nghĩa là mọi PDF attach vào
Activity đều sinh một job không có engine để chạy — kết quả là `failed`, và
required set đã materialize thì không rút lại được.

Đường đi đã chốt:

```text
Hôm nay        optional/on-demand, không hứa gì cho PDF
     ↓
Engine layout được duyệt (Tech Stack amendment)
     ↓
Nâng lên required ĐỒNG THỜI với một processing_version mới
     ↓
File cũ: chạy lại dưới revision mới, revision cũ giữ `archived`
File mới: required set materialize đã gồm structured
```

Điểm mấu chốt là hai việc phải đi **cùng một lúc**. Nâng required mà không đổi
`processing_version` thì file đã attach trước đó giữ nguyên required set cũ và
vĩnh viễn không có revision cấu trúc, trong khi hợp đồng nói là bắt buộc — một
mâu thuẫn không có tín hiệu nào phát hiện.

**Ngoài phạm vi hợp đồng này:** nếu cần một bảo đảm ở mức nghiệp vụ — "Activity
chưa có structured revision thì chưa được publish" — thì chỗ đặt luật đó là điều
kiện publish của Course, không phải required set của Media. Media trả trạng thái;
Course quyết định trạng thái nào đủ để publish. Hướng này **chưa được quyết** và
cần review riêng của Domain Owner (Course); ghi ở đây để không ai nhầm nó là hệ
quả tự nhiên của mục này.

### Điểm cố ý để mở

Có nên bắt buộc structured extraction riêng cho spreadsheet hay không — XLSX luôn
sinh được ô mà không cần engine, PDF thì không — **chưa quyết**. Bảng fan-out
khoá theo `file_type`, mà `file_type` không có giá trị `spreadsheet`, nên trả lời
"có" sẽ kéo theo hoặc một `file_type` mới, hoặc một dòng khoá theo mime — tức một
ngoại lệ trong hợp đồng. Quyết định này thuộc lúc implement lô spreadsheet;
người viết code **phải hỏi Owner**, không được tự chọn.

## Fan-out theo file type

| `file_type` | Job bắt buộc | Job tuỳ chọn |
| --- | --- | --- |
| `document` | `virus_scan`, `ocr` | `thumbnail`, `structured_extraction` |
| `audio` | `virus_scan` | `speech_to_text` khi actor bật tự động phiên âm |
| `video` | `virus_scan`; `speech_to_text` khi Video STT gate bật; `caption` **required nhưng deferred** | `transcode`, `thumbnail` |
| `image` | `virus_scan` | `thumbnail` |

**`caption` là required nhưng deferred.** Nó vẫn là output bắt buộc của video —
không phải optional — nhưng **không** nằm trong initial required set và không
được materialize cùng lúc với STT. Nó chỉ được tạo sau khi STT commit một
transcript revision `ready`, theo quyết định
[DOC-CONFLICT-0025](../quality/LF-Documentation-Conflicts.md).

Hai hệ quả phải đọc đúng:

* Video vừa upload có required set **chưa đầy đủ** một cách hợp lệ. Đó là trạng
  thái trung gian đã được thiết kế, không phải thiếu sót.
* Khi Video STT gate tắt, video **không** sinh `speech_to_text` lẫn `caption`.
  Tạo caption trong trạng thái đó sẽ để lại một job không bao giờ thoả được: nó
  chờ một transcript mà chính hệ thống đã quyết định không sinh ra.

MIME không nằm trong tập được hỗ trợ thì không sinh job nào và không đánh dấu
file `failed` — nó chỉ không có output, và Read Service trả mã lỗi
`unsupported_source`.

### "Bắt buộc" nghĩa là bắt buộc với cái gì

Cột trên nói job nào phải chạy, **không** nói job nào chặn việc phục vụ file.
Hai câu hỏi đó tách bạch:

| Câu hỏi | Ai đọc | Job quyết định |
| --- | --- | --- |
| File này phục vụ được chưa? | `media_files.status` — delivery, preview, media picker | **Chỉ `virus_scan`** |
| Output dẫn xuất dùng được chưa? | status của chính row output; Media Read Service | `ocr`, `speech_to_text`, `caption`, `transcode`, `thumbnail`, `structured_extraction` |

`virus_scan` là gate hợp lệ của deliverability: không phục vụ nội dung chưa
quét. OCR và speech-to-text thì không — không có lý do gì một video phải chờ
phiên âm xong mới phát được.

Điều này không phải tinh chỉnh ngữ nghĩa. `MediaFileDeliveryController` từ chối
404 với bất kỳ file nào có `status <> 'ready'`, và `CourseActivityMediaPresenter`
cùng `CourseActivityMediaPreviewAuthorizer` cũng gate như vậy. Nếu
`media_files.status` chờ speech-to-text, tác giả upload video rồi bấm Submit sẽ
thấy video 404 trong suốt thời gian phiên âm.

## Locale canonical và required output profiles Phase 1

Với usage `course_activity`, locale canonical của processing là
`media_files.processing_locale`. Course service ghi field này khi
active usage đầu tiên kích hoạt processing, từ locale nội dung do actor đang
attach Media khai báo tường minh và đã được authorize trên Activity. Sau khi
required set được materialize, field là bất biến cho source đó; attach lại cùng
Media File phải dùng đúng locale đã lưu hoặc bị từ chối. Giá trị
phải là language tag BCP 47 canonical. Internationalization default `vi`, browser
locale, user locale và model/provider language detection **không** phải source of
truth và không được dùng làm fallback.

Ngoại lệ duy nhất là phép điền lần đầu cho Media audio legacy theo Amendment
2.14: field còn `NULL`, chưa từng có STT job, actor chọn locale tường minh và
toàn bộ thay đổi được khóa/ghi nguyên tử. Ngoại lệ này không cho phép đổi locale
đã ghi và không thay thế retry semantics.

Attach một document/video, hoặc audio **đã bật STT**, mà thiếu `processing_locale`, có locale không
hợp lệ, hoặc xung đột với locale canonical đã lưu thì orchestration fail-closed:

```text
media_files.status = 'failed'
media_files.processing_error_code = 'required_profile_configuration_missing'
```

Không enqueue job phụ thuộc locale trong trường hợp này, và không để file treo
`processing`. `virus_scan` vẫn có thể chạy độc lập; kết quả scan không biến cấu
hình required profile bị thiếu thành hợp lệ.

Required output profile set Phase 1 được materialize một lần tại trigger, từ
`file_type` và locale canonical nói trên:

| `file_type` | Required profile chính xác | Optional/on-demand |
| --- | --- | --- |
| `document` | `virus_scan` profile rỗng; `ocr`: `layout=preserve;locale=<canonical-locale>` | `thumbnail`; PDF `structured_extraction`: `locale=<canonical-locale>;structure=layout`; spreadsheet `structured_extraction`: `locale=<canonical-locale>;structure=cells` |
| `audio` | `virus_scan` profile rỗng | Khi actor bật STT: `speech_to_text`: `diarization=off;locale=<canonical-locale>`; additional transcript locale/profile |
| `video` | `virus_scan` profile rỗng; khi gate bật, `speech_to_text`: `diarization=off;locale=<canonical-locale>`. `caption`: `format=vtt;locale=<canonical-locale>` — required nhưng **deferred tới sau transcript `ready`**, không nằm trong initial set | `transcode`, `thumbnail`, additional transcript/caption locale hoặc format |
| `image` | `virus_scan` profile rỗng | `thumbnail` |

Khi OCR/STT được yêu cầu, Phase 1 chỉ có **một** locale canonical cho output đó và chỉ có **một**
required caption format (`vtt`). Locale/format/profile bổ sung là
optional/on-demand; chúng không được tự động nhập vào required set và không được
làm file ở `processing` mãi.

Actor đã được authorize để sửa `course_activity`, thông qua Course service, được
yêu cầu thêm locale/format sau attach. System operator có quyền Media processing
cũng được yêu cầu retry hoặc materialize một profile đã được actor yêu cầu.
Thêm một profile nằm trong vocabulary hiện có là optional/on-demand operation,
không phải amendment. Đổi default Phase 1, biến profile optional thành required,
thêm key/profile vocabulary hoặc mở thêm actor/service là amendment có review.

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
* Retry chain, giới hạn 3 attempt, backoff eligibility và phép chọn `attempt` cao
  nhất đều scope theo `(customer_id, media_file_id, job_type,
  source_fingerprint, processing_version, output_profile_hash)`.
* Chỉ retry khi `error_code` thuộc nhóm tạm thời (`provider_timeout`,
  `provider_unavailable`, `rate_limited`, `transcript_invalid`). Lỗi vĩnh viễn
  (`unsupported_source`, `corrupt_source`, `quota_exceeded`,
  `structured_extraction_too_large`, `audio_limit_exceeded`) không retry. Một revision vượt trần tài
  nguyên sẽ vượt lại y hệt ở lần thử sau: input không đổi, giới hạn không đổi,
  nên retry chỉ tiêu attempt và thời gian worker mà không đổi kết quả.
* Retry của một output profile không tiêu hao attempt, không chặn enqueue và
  không ảnh hưởng backoff của profile khác. Hết attempt giữ chính required hoặc
  optional/on-demand output đó ở `failed`; derived output failure không làm
  binary file mất `ready`. Không có dead-letter queue riêng — mỗi chuỗi profile **là**
  dead-letter record, đọc được bằng SQL và không hết hạn.

Riêng `virus_scan`, `provider_unavailable` là terminal đối với deliverability
của lần upload hiện tại: job và `media_files` chuyển `failed` ngay với cùng mã
lỗi, không để file treo `processing`. Operator vẫn có thể tạo retry chain sau
khi provider được cấu hình; file chỉ trở lại `ready` khi một scan thật trả
`clean`. Không có feature bypass phục vụ binary chưa scan.

## Structured extraction job

Approved 2026-08-27 theo quyết định Owner. Structured extraction chạy dưới
`job_type` riêng:

```text
job_type = 'structured_extraction'
```

**Không** tái sử dụng `ocr`. Lý do là lý do đã dùng để bác `page` cho sheet ở
ADR-0019 § D1: nhánh spreadsheet đọc thẳng ô bằng `extraction_method =
'spreadsheet_cells'` và không gọi OCR lần nào, nên gán nhãn `ocr` cho nó là một
job_type nói dối về việc đã làm. Hệ quả kèm theo đều là hệ quả mong muốn: chuỗi
retry, `output_profile_hash`, `billable_units` và quota của structured extraction
tách khỏi OCR text, nên một revision cấu trúc fail không tiêu attempt của text và
chi phí hai loại đo được riêng.

`output_profile` của job này gồm `locale` và `structure`. Giá trị Phase 1 là
`structure=layout` cho PDF và `structure=cells` cho nhánh spreadsheet đọc trực
tiếp. Profile canonical khi locale là `vi` lần lượt là
`locale=vi;structure=layout` và `locale=vi;structure=cells`.

### Provider A1 cho PDF: `docling_local`

Version 2.5, Approved 2026-08-27.

`docling_local` là provider **local/offline** qua process JSON boundary. Nó dùng
Python 3.11 và Docling 2.119 để nhận diện `role`, `reading_order`, page, bbox và
cấu trúc bảng. Nó không gọi OCR riêng: text/OCR canonical tiếp tục thuộc job
`ocr` Poppler/Tesseract, tránh hai engine đưa ra hai nguồn text cạnh tranh.

Provider chỉ phát vocabulary đã được ADR-0019 phê duyệt. Ảnh, biểu đồ và sơ đồ
đều là `role=figure`; không diễn giải ngữ nghĩa ảnh, không suy luận mũi tên hoặc
quan hệ giữa node. Các khả năng đó thuộc AI Vision phase riêng. Provider không
được gọi mạng, external API hoặc tải model trong lúc xử lý job.

Trong giai đoạn acceptance, `structured_extraction` vẫn optional/on-demand.
Không tự enqueue cho mọi upload và không đưa vào required output set cho tới khi
Gate R có test persistence/resource/rollback xanh và acceptance fixture xác
nhận các vùng/bảng mong đợi. Triển khai AWS còn yêu cầu parity tuyệt đối của
Python, dependency lock, model inventory/hash, binary/config và worker sizing;
approval local không tự authorize AWS deployment.

### Provider, version và resource namespace

Version 2.4, Approved 2026-08-27.

```dotenv
MEDIA_STRUCTURED_EXTRACTION_PROVIDER=
MEDIA_STRUCTURED_EXTRACTION_VERSION=
```

Cặp này theo đúng luật fail-closed của § 2: bỏ trống là `unconfigured`, và job
`structured_extraction` khi đó **không được enqueue** — không để file treo
`processing`, và không làm `media_files` mất `ready` vì đây là job optional.

`config('media.processing.providers')` và `.versions` khoá theo `job_type`, nên
hai key mới là `structured_extraction`.

#### Resource namespace riêng

Theo § 3, một provider đọc namespace khác sẽ khởi động với **không giới hạn
nào**. Namespace của job này là `media.processing.structured_extraction.*` và
phải áp lại tường minh, kể cả các trần trùng giá trị với `local_document`:

| Key | Giá trị freeze | Env |
| --- | ---: | --- |
| `max_pages` | `100` | `MEDIA_STRUCTURED_MAX_PAGES` |
| `max_extracted_characters` | `500000` | `MEDIA_STRUCTURED_MAX_EXTRACTED_CHARACTERS` |
| `max_regions_per_page` | `100` | `MEDIA_STRUCTURED_MAX_REGIONS_PER_PAGE` |
| `max_regions_per_document` | `5000` | `MEDIA_STRUCTURED_MAX_REGIONS_PER_DOCUMENT` |
| `max_table_cells_per_document` | `200000` | `MEDIA_STRUCTURED_MAX_TABLE_CELLS_PER_DOCUMENT` |
| `max_processing_seconds` | `3300` | `MEDIA_STRUCTURED_MAX_PROCESSING_SECONDS` |
| `command_timeout_seconds` | `900` | `MEDIA_STRUCTURED_COMMAND_TIMEOUT_SECONDS` |

Bốn trần giữa là các giá trị đã freeze ngày 2026-08-25; bảng này chỉ nói chúng
**đọc từ đâu**, không đặt lại giá trị.

`max_processing_seconds = 3300` giữ nguyên bất biến
`deadline 3300 < worker 3600 < queue 3900`. `command_timeout_seconds = 900` cao
hơn mức `300` của `local_document` vì một engine bố cục xử lý cả tài liệu trong
một lần gọi chứ không phải từng trang một; `900` là mức của LibreOffice đã dùng
trong `local_document`, không phải một con số mới.

Sizing đo được ở A0 — p95 `4.01` giây/trang — cho 100 trang là khoảng `400` giây,
nằm trong deadline. Nếu engine được chọn có p95 cao hơn `33` giây/trang thì
deadline này không đủ và phải được xem lại **trước** khi deploy, không phải sau
lần `provider_timeout` đầu tiên.

### Output identity của một job structured

Một revision structured sinh row ở nhiều bảng, nên `output_id` không thể trỏ
tới "row output" theo nghĩa cũ. Quy tắc:

| `job_type` | `output_type` | `output_id` |
| --- | --- | --- |
| `structured_extraction`, nguồn document | `extracted_region` | Id của region có `reading_order = 1` trong revision |
| `structured_extraction`, nguồn spreadsheet | `extracted_table` | Id của table có `sequence = 1` trong revision |

Row được trỏ tới là **điểm vào** của revision, không phải toàn bộ output. Nó tồn
tại để `chk_mpj_ready` giữ nguyên hình dạng — job `ready` phải có output —
và để một job truy được về đúng revision nó đã tạo. Consumer không đọc structured
data qua `output_id`; đường đọc duy nhất vẫn là Media Read Service theo
[LF-Media-Read-Contract](LF-Media-Read-Contract.md).

Job chỉ chuyển `ready` khi **atomic readiness** ở § 3 đã đạt: region, table và
cell của revision cùng `ready`. Job `ready` mà revision còn `pending` là trạng
thái không hợp lệ, không phải trạng thái trung gian.

### Amendment vật lý kèm theo

Quyết định này **cần một migration** trên bảng đang chạy — mở
`chk_mpj_job_type` và vocabulary `output_type` của `media_processing_jobs`. Nó
là migration thứ ba của đợt structured extraction, tách khỏi hai migration đã
viết, và chịu cùng Gate M: không apply trước khi có test đọc CHECK vật lý từ
`information_schema.CHECK_CONSTRAINTS` và chạy xanh trên MariaDB.

## Deployment precondition

Runtime này **không được deploy** trước khi đồng thời đạt cả hai điều kiện:

1. forward migration Media Processing đã apply và có trong migration ledger;
2. `MEDIA_VIRUS_SCAN_PROVIDER` trỏ tới provider production đã được
   approved/configured và có credential/runtime contract hợp lệ.

Không được ship với giá trị rỗng hoặc `unconfigured` rồi cấu hình provider sau:
khi đó mọi upload mới lập tức `failed/provider_unavailable` và delivery trả 404.
OCR/STT/caption provider chưa có chỉ chặn derived capability tương ứng, nhưng
virus scan provider là điều kiện bắt buộc của toàn bộ upload path.

## Điểm dispatch

Job **không được** enqueue bên trong transaction tạo Activity. Đường ghi
(`CourseTemplateActivityController::storeActivity` và `updateActivity`) bọc tạo
Activity, upload và `attachUsage` trong một `DB::transaction`. Nếu một bước sau
đó ném lỗi, transaction rollback — nhưng message đã vào queue thì không, và
worker sẽ đi tìm một usage không tồn tại.

Enqueue phải xảy ra **sau commit**, qua `DB::afterCommit()` hoặc queue
connection đặt `after_commit = true`.

Điểm enqueue nằm ở **service dùng chung**, không nằm trong controller: hôm nay
đã có hai entry point (`storeActivity` và `updateActivity`, cùng gọi
`attachUploadedMedia`), và một đường quên dispatch sẽ biểu hiện thành "một số
Activity có OCR, một số thì không" mà không ai truy ra được vì sao.

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

## Trạng thái output dẫn xuất

Required output profile set quyết định output nào orchestration phải tạo, không
quyết định binary deliverability. Readiness của từng output xét row `attempt`
cao nhất trong retry scope đầy đủ của chính profile đó:

```text
job cao nhất của profile ở 'ready'                         → output ready
job cao nhất ở 'failed', hết retry                         → output failed
profile chưa kết thúc hoặc chưa materialize                → output pending/processing
required profile configuration thiếu/không hợp lệ          → file fail-closed như ma trận bên dưới
```

Additional/on-demand profile cũng độc lập: nó có thể `pending`/`processing`/
`failed` khi file vẫn `ready`. Retry failure của transcript
`vi` không tiêu hao attempt, không chặn enqueue và không làm file failed cho
transcript `ko`, hoặc ngược lại.

Vocabulary đã hợp nhất về **từ ngữ**, không phải về phạm vi. Ba tầng dùng chung
`processing` / `ready` / `failed`, nhưng mỗi tầng nói về một thứ khác nhau:

| Tầng | `ready` nghĩa là |
| --- | --- |
| Processing Job | Một lần chạy đã thành công |
| Output row (transcript, caption, extracted text, variant) | Chính artifact đó dùng được |
| Media File | Binary đã qua deliverability gate `virus_scan` và cấu hình required hợp lệ |

Hệ quả cụ thể: `thumbnail`, OCR hoặc transcript thất bại **không** làm video mất
`ready`; Media Read Service trả lỗi cho output tương ứng. `completed` của Version
1.0 bị loại bỏ.

## Ma trận trạng thái tổng hợp

`media_files.status` chỉ phản ánh **deliverability**, và chỉ `virus_scan` cùng
cấu hình required profile ảnh hưởng nó:

| `virus_scan` | Cấu hình required profile | `media_files.status` |
| --- | --- | --- |
| `ready` | hợp lệ | `ready` — phục vụ được ngay, kể cả khi OCR/STT còn chạy |
| `pending` / `processing` | bất kỳ | `processing` |
| `failed` (`infected_source`) | bất kỳ | `failed` |
| bất kỳ | thiếu hoặc không hợp lệ | `failed` + `required_profile_configuration_missing` |

Job dẫn xuất **không** xuất hiện trong bảng này. `ocr` hay `speech_to_text`
`failed` không làm file mất `ready`; hệ quả nằm ở phía consumer — Media Read
Service trả `failed` cho chính output đó, còn file vẫn phát và tải được.

Readiness của output dẫn xuất đọc từ status của row output tương ứng
(`media_extracted_texts`, `media_transcripts`, `media_captions`,
`media_variants`), không đọc từ `media_files`.

## Ranh giới tác dụng phụ

Job completion không ghi Course Progress, Assessment Result, LiveClass
Attendance, Learning Evidence, Mastery hay bất kỳ AI business output nào. Media
sản xuất Digital Asset; diễn giải thuộc về Domain tiêu thụ.

---

# 3. Source fingerprint và processing version

## Phase 1 local document provider

Provider runtime `local_document` hiện thực riêng capability `ocr` cho
`file_type=document`; nó không phải fake adapter và không thay thế virus scan.
Worker phải lấy source qua `storage_disk`/`storage_key` bằng stream, nên cùng
implementation chạy được với local disk và S3 mà không giả định source có local
path. Ba đường xử lý deterministic là:

| Source | Đường xử lý | `extraction_method` persist |
| --- | --- | --- |
| `txt`, DOCX có text | đọc UTF-8 hoặc `word/document.xml` | `embedded_text` |
| PDF có text layer | Poppler `pdftotext -layout`, tách theo page break; **từng trang** quyết định riêng | `embedded_text` |
| `xlsx`, `xls` | XLS chuyển XLSX; đọc OOXML worksheet/cell, giữ sheet và merged ranges | `spreadsheet_cells`, locator `sheet` |
| PDF scan; Office (`doc`, `ppt`, `pptx`); DOCX không có text | LibreOffice → PDF khi cần; từng trang thiếu text layer dùng Tesseract | `ocr` hoặc `embedded_text` theo từng trang |

Spreadsheet không fallback PDF. Đọc cell không có text toàn revision trả `no_extractable_text`. DOC-CONFLICT-0029 được đồng bộ theo D2 đã duyệt; các quyết định 0017/0018 vẫn được bảo toàn.

Locale Tesseract Phase 1 được map tường minh `vi → vie+eng`, `ko → kor+eng`,
`en → eng`; locale khác fail-closed bằng `unsupported_source`, không language
detection. Output rỗng fail bằng `no_extractable_text`; source hỏng/không hỗ trợ,
timeout và giới hạn text có error code riêng. Không job nào được đánh `ready` nếu
không có ít nhất một unit text.

Runtime bắt buộc có `pdftotext`, `pdftoppm`, `pdfinfo`, Tesseract language data
và LibreOffice. Local và AWS worker phải ship cùng binary/config version; thay
binary, language data, DPI hoặc conversion config có ảnh hưởng output phải tăng
`MEDIA_OCR_VERSION`. Provider giới hạn text dẫn xuất bằng
`MEDIA_MAX_EXTRACTED_CHARACTERS`; giới hạn upload 1 GB không có nghĩa worker
được phép giữ toàn bộ source hay output không giới hạn trong memory.

Resource controls Phase 1 là contract, không phải tuning tuỳ ý:

* job timeout `3.600s`, provider deadline `3.300s`; timeout command đơn lẻ tối
  đa `300s`, LibreOffice tối đa `900s` và luôn bị co lại theo deadline còn lại;
* queue visibility/retry-after phải lớn hơn job timeout; default Redis,
  database queue và Beanstalkd là `3.900s`. Với SQS, deployment phải đồng thời
  chứng minh `visibility timeout > 3.600s`, supervisor `--timeout >= 3.600s`
  và message retention lớn hơn visibility timeout; không được giữ các giá trị
  này như runbook ngầm;
* PDF tối đa `100` trang cho một OCR revision; vượt giới hạn fail trước
  `pdftotext`/render/OCR bằng `page_limit_exceeded`;
* `word/document.xml` tối đa `8.000.000` expanded bytes. Provider kiểm ZIP
  metadata trước copy và vẫn đếm byte trong vòng copy để không tin metadata;
* text dẫn xuất tối đa `500.000` ký tự cho một document revision. Vượt giới hạn
  fail toàn transaction bằng `extracted_text_too_large`, không persist output
  cắt cụt.

Worker termination/timeout nằm ngoài `handle()` phải gọi lifecycle `failed()`.
Callback này chỉ đổi row cùng tenant/job còn ở `processing` sang
`failed/provider_timeout`; row đã `ready` không bị ghi đè. Nhờ vậy stale
`processing` không giữ concurrency guard vĩnh viễn và retry profile-scoped vẫn
dùng được. Supervisor `--timeout`, job `$timeout` và queue visibility phải giữ
thứ tự `provider deadline < worker timeout < visibility timeout`.

`failed()` chỉ đóng được timeout có kiểm soát khi Laravel worker còn sống để
chạy callback. `SIGKILL`, OOM kill, container eviction hoặc host failure có thể
để row ở `processing` mà không có callback. Phase AWS phải có stale-processing
sweeper định kỳ: tìm row `processing` có `started_at` cũ hơn job timeout cộng
safety margin, chuyển có điều kiện sang `failed/provider_timeout`, để retry chain
profile-scoped tiếp tục. Sweeper phải dùng tenant/job identity, không ghi đè row
đã đổi trạng thái, và mỗi lần transition phải có operational evidence. Đây là
deployment/runtime amendment cần review trước khi production activation; Phase
local hiện tại chưa implement sweeper.

Đây là provider self-hosted. Trên AWS, queue worker cần ephemeral disk đủ cho
source + PDF/image trung gian và IAM read source object; browser/admin không chạy
binary. Production activation vẫn chịu R1/R2 và deployment/provider gates trong
Architecture Review.

## Speech-to-text resource controls — Phase 1

Owner freeze ngày 2026-08-29. Cùng hạng với structured extraction resource
controls bên dưới: đây là **contract**, không phải tuning.

### Provider Phase 1

Phase 1 dùng adapter local `faster_whisper_local`, engine `faster-whisper 1.2.1`,
model `small`, `compute_type=int8`, CPU và diarization tắt. Python/model nằm trong
namespace `runtime/stt/` riêng, không dùng chung venv với Docling. Model được gọi
bằng đường dẫn local và ba biến offline được ép trong process; runtime không tự
tải model.

`MEDIA_SPEECH_TO_TEXT_PROVIDER` mặc định
`unconfigured`; provider chưa cấu hình thì job fail `provider_unavailable`, không
im lặng bỏ qua và không tự rơi sang đường khác.

**Không gọi provider ngoài trong Phase 1.** Đường external fail-closed. PII
presence **không** làm job fail — giữ nguyên luật ADR-0018 — nhưng cũng không cấp
quyền gửi source ra ngoài.

Mở một provider ngoài (ví dụ AWS Transcribe) cần ba thứ, và **không thứ nào nằm
trong contract này**:

1. quyết định riêng cho đúng provider đó theo
   [ADR-0018](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) —
   ADR đó ghi rõ *"Mọi provider ngoài boundary cần quyết định riêng; ADR này
   không phải blanket consent"*;
2. retention/deletion có evidence triển khai;
3. audit coverage có evidence triển khai.

### Định dạng và trần

| Ràng buộc | Giá trị |
| --- | --- |
| MIME được nhận | `audio/mpeg`, `audio/mp3`, `audio/wav`, `audio/x-wav`, `audio/ogg`, `audio/webm`, `audio/mp4` |
| Dung lượng tối đa | 1 GB |
| Thời lượng tối đa | 120 phút |
| Provider deadline | 3.300 giây |

MIME ngoài allowlist → `unsupported_source`.

**Vượt trần dung lượng hoặc thời lượng → fail cả job** với `audio_limit_exceeded`.
**Không cắt bớt.** Transcript của 120 phút đầu trên một file ba giờ khiến consumer
không phân biệt được "hết nội dung" với "bị cắt", trong khi mọi citation vẫn hợp
lệ — sai không lộ ra ở đâu cả. Cùng nguyên tắc với
`structured_extraction_too_large`.

### Segmentation

Một row là một segment. Locator theo hợp đồng chung: `locator_type = 'timespan'`,
`locator_value = '<start_ms>-<end_ms>'`, đơn vị **millisecond**.

Trong một revision, các segment phải **sắp xếp tăng dần theo `start_ms`** và
**không chồng lấn**. Vi phạm → `transcript_invalid`, fail cả revision, không ghi
row nào.

**`timespan` là khoảng nửa mở `[start_ms, end_ms)`.** Luật chính xác là
`start_ms >= prev.end_ms`, **không** phải `>`. Khảo sát exploratory trên output
faster-whisper cho thấy phần lớn segment giáp ranh chính xác
(`start_ms == prev.end_ms`) và không chồng lấn thật. Tỷ lệ cụ thể không được
freeze trong contract cho tới khi raw per-segment artefact được lưu và phép đếm
được tái lập; với `N` segment chỉ có tối đa `N-1` cặp liền kề.

Segment độ dài 0 (`start_ms == end_ms`) không hợp lệ.

Mọi segment Audio hoặc Video phải nằm hoàn toàn trong Media nguồn:
`end_ms <= media_files.duration_seconds * 1000`. Provider trả timestamp vượt
thời lượng làm toàn revision fail bằng `transcript_invalid`; không cắt, clamp
hoặc persist citation trỏ ra ngoài audio/video. `duration_seconds` là số giây nguyên
do `MediaMetadataProbe` ghi, nên phép so dùng đúng upper bound đã persist.

Luật này được cưỡng chế ở **tầng persist**, cùng cách `reading_order` được cưỡng
chế cho region. Lý do phải nói rõ:

* `UNIQUE (… locator_value …)` chặn trùng **chính xác**, nhưng `0-1000` và
  `500-1500` là hai giá trị khác nhau nên **overlap vẫn lọt**.
* `media_transcripts` không có cột `start_ms`, `end_ms` hay `sequence` — chỉ một
  VARCHAR. Không có gì sắp xếp được bằng SQL, và sắp xếp chuỗi thì `'1000-2000'`
  đứng trước `'500-1500'`.
* Media Read trả transcript theo thứ tự `id`. Kiểm ở tầng persist khiến thứ tự
  insert **trùng** thứ tự thời gian, nên thứ tự đọc trở thành bảo đảm thay vì
  tình cờ.

### Locale

Phase 1 nhận đúng `vi`, `ko`, `en`. Ép tường minh theo `processing_locale`;
**không auto-detect**, không dùng browser/user locale làm fallback — đúng § Locale
canonical ở trên. Locale ngoài allowlist → `locale_unavailable`.

Ràng buộc này cần thiết vì `MediaOutputProfile::canonicalLocale()` chỉ validate cú
pháp BCP 47; không có allowlist thì `fr` đi lọt tới provider.

### Diarization

`off` trong Phase 1. Đây là ghi nhận nguồn quyết định cho giá trị **đã là** profile
canonical, không phải hạng mục triển khai mới.

### Concurrency và quota

Tối đa **một STT job đang `processing` trên mỗi Media** — đã được cưỡng chế ở
orchestration theo `(customer_id, media_file_id, job_type)`.

Quota theo phút audio: **chưa triển khai**. Đây là gate mở, không phải giá trị đã
chốt.

### Mã lỗi mới của STT

`audio_limit_exceeded` là lỗi vĩnh viễn: input không đổi thì trần không đổi.
`transcript_invalid` được retry tối đa theo chain vì model có thể sinh biên
timespan không ổn định. Media 48/job 126 đã fail lần đầu nhưng cùng runtime và
source sinh 35 segment hợp lệ khi chạy lại; row lỗi cũ vẫn giữ nguyên làm audit.

Cả hai phải được đăng ký vào danh sách mã lỗi ổn định của job runner. Mã không
đăng ký sẽ rơi về `processing_failed`, và khi đó operator không phân biệt được
"file quá dài" với "provider chết" — trong khi hai thứ đó cần hai hành động khác
hẳn nhau.

### Đo thời lượng

Trần 120 phút đo bằng `media_files.duration_seconds`, đã có sẵn do
`MediaMetadataProbe` ghi bằng `ffprobe` lúc upload.

Không đo được thời lượng (`duration_seconds IS NULL`) → `corrupt_source`, **không**
mặc định cho qua. Cho qua một file không đo được là tự bỏ chính cái trần vừa đặt.

### Acceptance

Fixture `vi`, `ko`, `en`; đo WER, biên timestamp, p50/p95. **Không freeze ngưỡng
cho tới khi có dry-run** trên fixture thật.

Fixture phải là audio **không chứa PII**, hoặc có approval riêng — cùng luật đã áp
cho fixture PDF của structured extraction.

Harness tái lập nằm tại `runtime/stt/benchmark/`. Fixture thật, ground truth và
result có thể chứa nội dung học liệu nên bị gitignore. Một engine/model chỉ được
freeze sau khi report máy đọc được chứa source hash, model hash, dependency
inventory, raw segment timing và metric theo từng fixture.

Runtime Phase 1 được phép hoạt động trước khi quality threshold được freeze,
nhưng UI phải mô tả đây là transcript máy tạo và job có readiness riêng. Dry-run
trên audio người thật tiếng Hàn dài 196,807 giây đã chứng minh đường local tạo 21
segment, 0 overlap và 0 segment độ dài 0; chưa có ground truth nên CER vẫn
`unavailable`, không được diễn giải thành quality pass.

**Owner acceptance, 2026-08-29:** trạng thái CER `unavailable` của fixture này
được chấp nhận cho Phase 1 local. Đây là chấp nhận giới hạn evidence, không phải
quality threshold, SLA hoặc quyền mở external provider. Khi có ground truth được
duyệt, benchmark phải báo lại CER thay vì kế thừa acceptance này.

### Retention khi xoá Media

**Owner approved, 2026-08-29:** xoá Media phải purge nội dung dẫn xuất cùng tenant
và Media, gồm extracted text, transcript, region, structured table/cell, caption
asset và variant asset. Processing job và access log được giữ làm provenance/audit
nhưng không được giữ bản sao nội dung đã purge.

Nội dung chỉ nằm trong database được xoá trong cùng transaction tạo tombstone
`media_files.status = 'deleted'`. Output có object storage chỉ xoá row sau khi
object được xác minh đã biến mất; nếu storage lỗi, row còn lại là retry marker cho
lần dọn thủ công kế tiếp. Pending processing job bị `cancelled`, và job đã chạy
không được persist output mới sau khi Media thành `deleted`.

Approval này đóng quyết định của DOC-CONFLICT-0023. Nó không cấp quyền gọi
provider ngoài; external processing vẫn cần approval provider/purpose riêng theo
ADR-0018.

## Caption dựng từ transcript — Owner quyết định 2026-08-29

Caption **không** chạy model riêng trên binary. Nó được dựng từ transcript đã
`ready` của cùng Media và cùng locale.

Lý do loại bỏ hướng độc lập: hai đường chạy riêng sẽ cho hai nội dung khác nhau
trên cùng một video — người học đọc một bản, AI đọc bản khác — và tốn gấp đôi chi
phí model để tạo ra sự bất nhất đó.

Hệ quả bắt buộc:

* Job `caption` **phụ thuộc** một transcript revision `ready`. Chưa có transcript
  thì chưa dựng được caption; đây là phụ thuộc thật giữa hai job của cùng Media,
  không phải hai đường song song.
* `media_captions.transcript_processing_version` ghi transcript revision đã dùng.
  `source_fingerprint` **không** thay thế được: nó là vân tay của binary gốc nên
  không đổi khi transcript sinh revision mới.
* **Stale dây chuyền:** transcript revision chuyển `archived` thì mọi caption
  dựng từ nó cũng chuyển `archived`, trong cùng transaction.

Đây là hiệu ứng **liên output type** đầu tiên của Media: một job STT chạm row
caption của chính Media đó. Nó nằm trong ranh giới Media — không chạm Course,
Assessment hay AI output — nên không vi phạm § Ranh giới tác dụng phụ. Vì là lần
đầu, nó được viết ra ở đây thay vì để ngầm hiểu.

Phase 1 caption cùng locale với transcript nguồn. Dịch sang locale khác là quyết
định riêng, chưa duyệt.

### Bất biến phải cưỡng chế ở tầng persist

CHECK trong schema chỉ bắt caption do job sinh ra **có khai** một transcript
version; nó không chứng minh version đó có thật. FK không dùng được vì
`media_transcripts` không có khoá nào đại diện cho *một revision* — UNIQUE của nó
gồm `locator_value`, do một revision là nhiều row segment.

Trước khi ghi caption row, persistence **phải** xác nhận tồn tại transcript
`ready` cùng `customer_id`, `media_file_id`, `locale`, `source_fingerprint` và
`processing_version = transcript_processing_version`. Không thoả thì fail cả
revision caption, không ghi row nào.

### Trạng thái triển khai

Luật stale dây chuyền **đã có runtime**: đường persist của STT archive caption
dựng từ transcript vừa bị thay thế, trong cùng transaction, và có test kèm
mutation. Caption không do job sinh ra không bị đụng tới.

Migration provenance đã qua **Gate M**, đóng 2026-08-29 bằng Owner attestation
trên bằng chứng ghi ở
[media_captions](../database/media/media_captions.md) § Gate M. Không có
independent Architecture Review; phạm vi đóng đúng bằng migration và schema.

Bất biến kiểm-tồn-tại **đã có runtime** trong `TranscriptVttCaptionProvider` và
được kiểm lại ở đường job/persist. Provider chỉ được cấu hình
`transcript_vtt` trong local/test đã bật gate; production vẫn giữ gate Video STT
tắt cho tới khi hoàn tất soak evidence của DOC-CONFLICT-0027.

## Structured extraction resource controls

Owner freeze ngày 2026-08-25. Các giá trị dưới đây là **contract** theo § 3, không
phải tuning tuỳ ý; một provider đọc namespace khác sẽ khởi động không giới hạn nào.

### Structured coverage observability

Structured extraction `ready` không đồng nghĩa mọi trang canonical text đều có
region. Khi hoàn tất một structured revision, job phải ghi
`metadata.structure_coverage` gồm:

```text
pages_with_text
pages_with_regions
pages_text_without_structure[]
```

`pages_with_text` và danh sách thiếu phải lấy từ **canonical ready text revision**
cùng `customer_id`, `media_file_id`, `locale` và `source_fingerprint`; không được
ghép theo `processing_version`, vì OCR và structured extraction có version độc
lập. `pages_with_regions` lấy từ output của chính structured job. Coverage là
observability evidence, không làm optional structured profile thành required và
không tự chuyển job sang `failed`.

Trang có canonical text nhưng không có region phải được ghi vào danh sách thiếu.
Không được diễn giải sự vắng mặt đó thành trang trắng hoặc thành kết luận không có
cấu trúc. Quy tắc consumer fallback/error có tên thuộc Media Read Contract riêng.

### Ngân sách ký tự

`max_extracted_characters = 500000` giữ nguyên giá trị đã freeze, nhưng **phạm vi
áp dụng mở rộng**. Nó tính trên tổng của cả ba nguồn text trong một revision:

```text
SUM(text của media_extracted_texts)      -- theo page hoặc sheet
+ SUM(text của media_extracted_regions)  -- theo region
+ SUM(text của media_table_cells)        -- theo cell
<= 500000
```

Bỏ text cell ra khỏi phép tính là lỗ hổng thật: một workbook 199.000 cell × 20 ký
tự vẫn dưới trần cell nhưng vượt xa ngân sách ký tự. Text trùng lặp có chủ ý giữa
cấp trang và cấp region vẫn **được tính theo dung lượng thực persist**, không
được trừ đi vì lý do "cùng một nội dung".

### Trần số lượng

| Key | Giá trị | Ghi chú |
| --- | --- | --- |
| `max_regions_per_page` | `100` | Trần theo từng trang |
| `max_regions_per_document` | `5000` | Trần toàn tài liệu |
| `max_table_cells_per_document` | `200000` | Đếm **row cell thực persist**; merged cell chỉ tính một row |

Table payload PDF mang bbox chuẩn hóa trên từng cell khi provider quan sát được
và `quality_status = complete|incomplete|undetermined` đã tính trước khi persist.
Một vị trí thiếu cell là `incomplete` chỉ khi có text-layer trong bbox suy từ
band hàng/cột; band thiếu làm trạng thái `undetermined`. Spreadsheet không có
hình học trang nên luôn `undetermined`. Persistence không tính lại evidence này.
Nếu khoảng giữa tâm hai band cột lớn hơn gấp đôi biên neo ngoài lớn hơn của
region bảng, topology được coi là chưa đo vì provider có thể đã làm sụp cột
trống khỏi `column_count`; trạng thái cũng là `undetermined`.

Hai trần region phải freeze **đồng thời**. Chỉ có trần tổng thì một trang vẫn sinh
được 5.000 region; chỉ có trần trang thì 100 trang có thể sinh tới 10.000 region
mà không ai chặn ở mức tài liệu. Với giá trị hiện hành, trần tài liệu `5000` là
một lớp chặn độc lập, không phải tích số suy ra từ hai trần còn lại.

**Không có `max_tables_per_document`.** Số bảng đã bị chặn sẵn ở hai đường: bảng
trong document neo 0..1 vào một region có `role = 'table'` nên bị trần region
chặn; bảng của spreadsheet bằng số sheet nên bị `max_pages = 100` chặn. Thêm một
trần thứ ba là thêm một con số phải bảo trì mà không chặn thêm gì.

`max_table_cells_per_document = 200000` là số duy nhất trong nhóm này không suy ra
được từ một giới hạn đã freeze khác. Nó được chọn cùng bậc độ lớn với ngân sách
500.000 ký tự và **phải được xem lại khi có workbook thật đầu tiên**.

### Error semantics

Vượt bất kỳ giới hạn nào ở trên: error code `structured_extraction_too_large`,
**fail toàn revision, không truncate**. Theo đúng tiền lệ của
`extracted_text_too_large`. Truncate tạo ra một tài liệu thiếu vùng, thiếu bảng
hoặc thiếu ô mà consumer không phân biệt được với một tài liệu thật sự có ít
vùng, ít bảng hoặc có ô trống.

### Atomic readiness

Region, table và cell của một revision phải **cùng** `ready` hoặc **cùng không**.
Một revision có bảng `ready` nhưng thiếu cell là một bảng nói dối về nội dung của
chính nó, và consumer không có cách nào phát hiện.

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

Nếu checksum NULL/rỗng, orchestration fail-closed trước khi tạo job với
`source_fingerprint`; tuyệt đối không hash chuỗi rỗng vì nhiều source khác nhau
sẽ có chung identity.

## Output profile

Tham số quyết định output đi trong một trường riêng:

```text
output_profile      = ASCII `key=value` pairs, key tăng dần lexicographic, nối bằng `;`
output_profile_hash = SHA-256( output_profile )
```

Canonicalization chính xác:

* key dùng lowercase ASCII, không khoảng trắng; value không percent-encode và
  không chứa `=` hoặc `;`;
* locale là BCP 47 canonical (`vi`, `ko`, `en-US`, không dùng underscore), với
  language lowercase, Script Title Case và region uppercase;
* enum/value khác dùng lowercase ASCII; boolean Phase 1 dùng `on`/`off`;
* mọi default phải được ghi tường minh, không được bỏ key; không có key thừa;
* profile rỗng là chuỗi UTF-8 độ dài 0; hash SHA-256 tính trên đúng bytes UTF-8,
  xuất lowercase hexadecimal 64 ký tự.

| `job_type` | `output_profile` gồm |
| --- | --- |
| `ocr` | `locale`, `layout` |
| `speech_to_text` | `locale`, `diarization` |
| `caption` | `locale`, `format` (`vtt`/`srt`/`ass`) |
| `transcode` | `preset` |
| `thumbnail` | `size` |
| `virus_scan`, `compress` | rỗng, hash của chuỗi rỗng |

Giá trị default Phase 1 là `layout=preserve`, `diarization=off`, và
`format=vtt`. Vì key luôn sort, các profile canonical lần lượt là
`layout=preserve;locale=vi`, `diarization=off;locale=vi` và
`format=vtt;locale=vi` khi locale canonical là `vi`.

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
| `sheet` | extracted text (spreadsheet) | Chỉ số sheet theo thứ tự workbook, ≥ 1, text thập phân |
| `region` | region và table trên một trang | `<page>#<ordinal>`, cả hai ≥ 1 |

`sheet` thay cho việc gán chỉ số sheet vào `page`. Đây là sửa một cách dùng sai
đang tồn tại, không phải mở rộng phạm vi; revision cũ giữ `page` và chuyển
`archived`, không backfill tại chỗ.

`region` cố ý **không** mang toạ độ. Bounding box là dữ liệu quan sát, đổi theo
extractor và DPI; nhét vào locator thì mọi citation cũ vỡ mỗi lần đổi
`processing_version`. Locator chỉ định danh *vùng thứ mấy trên trang nào*, hình
học nằm ở cột riêng.

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
