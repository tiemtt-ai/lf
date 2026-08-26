# LF-Media-Processing-Contract.md

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-25

Document Path: platform/LF-Media-Processing-Contract.md

Related ADR:

* [ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)
* [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved

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

## Fan-out theo file type

| `file_type` | Job bắt buộc | Job tuỳ chọn |
| --- | --- | --- |
| `document` | `virus_scan`, `ocr` | `thumbnail` |
| `audio` | `virus_scan`, `speech_to_text` | — |
| `video` | `virus_scan`, `speech_to_text`, `caption` | `transcode`, `thumbnail` |
| `image` | `virus_scan` | `thumbnail` |

MIME không nằm trong tập được hỗ trợ thì không sinh job nào và không đánh dấu
file `failed` — nó chỉ không có output, và Read Service trả mã lỗi
`unsupported_source`.

### "Bắt buộc" nghĩa là bắt buộc với cái gì

Cột trên nói job nào phải chạy, **không** nói job nào chặn việc phục vụ file.
Hai câu hỏi đó tách bạch:

| Câu hỏi | Ai đọc | Job quyết định |
| --- | --- | --- |
| File này phục vụ được chưa? | `media_files.status` — delivery, preview, media picker | **Chỉ `virus_scan`** |
| Output dẫn xuất dùng được chưa? | status của chính row output; Media Read Service | `ocr`, `speech_to_text`, `caption`, `transcode`, `thumbnail` |

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

Attach một document/audio/video mà thiếu `processing_locale`, có locale không
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
| `document` | `virus_scan` profile rỗng; `ocr`: `layout=preserve;locale=<canonical-locale>` | `thumbnail` |
| `audio` | `virus_scan` profile rỗng; `speech_to_text`: `diarization=off;locale=<canonical-locale>` | Additional transcript locale/profile |
| `video` | `virus_scan` profile rỗng; `speech_to_text`: `diarization=off;locale=<canonical-locale>`; `caption`: `format=vtt;locale=<canonical-locale>` | `transcode`, `thumbnail`, additional transcript/caption locale hoặc format |
| `image` | `virus_scan` profile rỗng | `thumbnail` |

Phase 1 chỉ có **một** required locale cho OCR/speech-to-text và chỉ có **một**
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
  `provider_unavailable`, `rate_limited`). Lỗi vĩnh viễn
  (`unsupported_source`, `corrupt_source`, `quota_exceeded`) không retry.
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
| `xlsx` | đọc trực tiếp OOXML: `xl/workbook.xml`, `xl/_rels`, `xl/sharedStrings.xml`, từng `xl/worksheets/sheetN.xml`; một unit mỗi worksheet | `embedded_text` — xem ghi chú vocabulary bên dưới |
| PDF scan; Office cần conversion (`doc`, `xls`, `ppt`, `pptx`); DOCX không có text; `xlsx` không có cell nào đọc được | LibreOffice headless → PDF khi cần; Poppler render **từng trang thiếu text layer** → Tesseract theo locale canonical | `ocr` nếu fallback OCR, nếu PDF chuyển đổi có text layer thì `embedded_text` |

Hai điểm đã đổi so với mô tả trước và phải đọc đúng:

* **PDF trộn quyết định theo từng trang.** Trang có text layer dùng
  `embedded_text`, trang không có được render và OCR riêng. Hình thức cũ trả về
  các trang có text layer ngay khi tồn tại ít nhất một trang như vậy, và bỏ im
  lặng mọi trang scan của một tài liệu trộn.
* **`xlsx` không còn đi qua LibreOffice** khi workbook có cell đọc được. Render
  worksheet thành ảnh rồi OCR sẽ xoá sạch sheet, hàng và cột. LibreOffice chỉ còn
  là fallback khi không unit nào sinh ra được.

Ghi chú vocabulary: đọc cell trực tiếp hiện persist `embedded_text` vì
`media_extracted_texts` chỉ mở hai giá trị `('ocr','embedded_text')`. Giá trị đó
làm mất phân biệt giữa "lớp text của một PDF" và "đọc cấu trúc nguồn". Mâu thuẫn
với `spreadsheet_cells` của
[media_extracted_tables](../database/media/media_extracted_tables.md) đã được ghi
là **DOC-CONFLICT-0017** và chờ Owner quyết.

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

## Structured extraction resource controls

Owner freeze ngày 2026-08-25. Các giá trị dưới đây là **contract** theo § 3, không
phải tuning tuỳ ý; một provider đọc namespace khác sẽ khởi động không giới hạn nào.

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
| `max_regions_per_page` | `50` | Trần theo từng trang |
| `max_regions_per_document` | `5000` | Trần toàn tài liệu |
| `max_table_cells_per_document` | `200000` | Đếm **row cell thực persist**; merged cell chỉ tính một row |

Hai trần region phải freeze **đồng thời**. Chỉ có trần tổng thì một trang vẫn sinh
được 5.000 region; chỉ có trần trang thì 100 trang sinh được 5.000 mà không ai
chặn ở mức tài liệu.

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
