# LF-Media.md

Version: 1.10

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-09-05

Document Path: platform/LF-Media.md

---

# LF Media Architecture

Media là Platform Domain dùng chung cho toàn bộ LearnForge.

## Capability closure — Phase 1 Audio/Video derived content

**Closed for implementation and local runtime, 2026-09-05.** Phạm vi đóng gồm:

* Audio → transcript theo `timespan`;
* Video → transcript theo `timespan`;
* Video → caption VTT dựng từ transcript revision `ready`;
* Media Read cho transcript và caption asset theo tenant, owner context, usage
  slot, locale và revision, kèm access audit.

Independent re-validation tại HEAD `c51b5e8fb0bbdb4cb6c62566e632ae5bdf410723`
chạy FFmpeg và Faster Whisper thật: **28 tests, 357 assertions, 0 failed**.
`docs:lint`, `schema:drift --docs-only` (93 migrations), `schema:drift --fresh`,
Pint và `git diff --check` đều PASS. Lượt `--fresh` hiện hành chạy trên MariaDB
10.4.21; bằng chứng 11.4.12 vẫn là evidence lịch sử trong hai final review và
phải được independent rerun trước một migration/production gate mới.

Closure này được ghi chính xác là:

```text
PHASE_1_AUDIO_VIDEO_IMPLEMENTATION: CLOSED
LOCAL_RUNTIME: PASS
PRODUCTION_ACTIVATION: NOT_APPROVED
```

Nó không mở Video STT feature gate, không phê duyệt production provider,
retention/PII/external processing, và không authorize migration hoặc
implementation của AI Knowledge.

## Capability closure — Course Activity document upload

**Closed on development, 2026-08-27.** Phạm vi được đóng là upload/replace/remove
tài liệu tại form `course_activity`, không phải toàn bộ Media Processing và
không phải AI Knowledge.

Evidence hiện hành:

* owner Course authorize thao tác trước khi Media ghi dữ liệu;
* file và `media_file_usages` được ghi theo cùng `customer_id`, với
  `owner_type = course_activity`, `usage_type = document`;
* submit thành công quay lại trang hợp lệ; tài liệu vừa gắn vẫn xuất hiện khi
  mở lại activity;
* replace tạo Media File mới vì binary immutable; remove chỉ detach usage,
  không xoá nhầm file còn consumer khác;
* delivery/preview là tenant-scoped và chỉ phục vụ file `ready`;
* locale processing do actor chọn rõ ràng, không suy luận từ browser/model;
* upload tài liệu kích hoạt substrate sau commit; output OCR là derived output
  và không phải điều kiện để binary tài liệu tiếp tục deliver được.

Capability này giữ trạng thái **Implemented** trong phạm vi development. Điều
kiện production vẫn còn: virus-scan provider thật phải được cấu hình trước khi
deploy upload path. Adapter `fake` chỉ là local/test evidence, không phải
production approval.

Những phần **không** được đóng bởi mục này: structured region/table/cell,
production OCR/STT/caption providers, Media Read cho AI production, AI Knowledge
source/chunk/embedding và AI-assisted authoring.

Media implementation hiện là `Partial`. Cập nhật 2026-08-27 theo source thật:
upload, tenant-scoped file identity, generic Usage, private delivery và Course
Activity/Version Activity integration đã có; substrate xử lý
(`media_processing_jobs`, `media_extracted_texts`, `media_transcripts`,
`media_captions`, `media_variants`, `media_access_logs`) **đã có migration và
runtime** từ `2026_08_24_000000_create_media_processing_substrate.php`, với
provider tài liệu self-hosted `local_document`. Câu trước đó — "chưa có physical
migration/runtime" — là mô tả cũ, không còn đúng.

Structured extraction hiện có runtime local: Docling PDF và native spreadsheet
cells, persistence/read/crop, được kiểm chứng cùng D1–D6 tại
[Document Final Code Review](../quality/LF-Document-Processing-Final-Code-Review.md).
Đây là evidence local/disposable, không phải application database hoặc production
deployment; Media Domain tổng thể vẫn Partial.

Media quản lý Digital Assets và hạ tầng liên quan:

* Asset identity and metadata
* Storage location
* Processing jobs
* Variants
* Transcripts
* Captions
* Usage mappings
* Access audit

Media không phải File Manager, Course Domain, LiveClass Domain, Assessment
Domain hoặc AI Domain.

Mỗi Media object thuộc chính xác một Customer. Media object không được shared
trực tiếp xuyên tenant; cross-tenant reuse nếu có trong tương lai phải tạo
asset identity riêng theo tenant hoặc được phê duyệt bằng architecture review.

---

# Mission

Cung cấp một Digital Asset foundation tenant-aware, storage-agnostic và
reusable cho mọi Domain mà không nhận ownership business state của Domain đó.

---

# Platform Domain Principle

Media chỉ quản lý:

```text
Digital Asset

Metadata

Storage

Processing

Variant

Transcript

Caption
```

Media không quyết định:

```text
Course Progress

Assessment Result

LiveClass Attendance

Certificate

AI Result
```

Các Domain khác chỉ giữ `media_file_id` hoặc tạo
`media_file_usages` mapping. Media không diễn giải Lesson, Quiz, Certificate
hoặc business state của owner.

---

# Resource Ownership Principle

Media owns storage.

Business modules own business relationships.

Media never decides business state.

Business modules never manage storage directly.

Examples:

* Course quyết định cover image nào đang được dùng. Media chỉ lưu file.
* Assessment quyết định speaking submission nào được nộp. Media chỉ lưu audio.
* Certificate quyết định certificate nào được phát hành. Media chỉ lưu PDF.

---

# Media Upload Policy

## 1. Upload At Point Of Use

The default user experience in LearnForge is Upload At Point Of Use. Users
upload files directly from the business form where the asset is needed.

Examples:

* Course Category
* Course Template
* Course Product
* Lesson
* Assessment
* Live Class
* Certificate
* AI
* Future modules

Business forms are upload entry points. Users should never be required to open
Media Library before uploading.

## 2. Media Library Is The Management Center

Regardless of where a file is uploaded, every uploaded asset becomes a managed
Media File. Every uploaded file must automatically appear in Media Library.

Media Library is the centralized place for:

* browsing
* searching
* filtering
* auditing
* lifecycle management
* asset reuse
* usage inspection

Business modules must never create hidden or private uploads outside Media.

Published Course Version Activities use immutable active usages owned by
`course_version_activity`, with canonical purposes `video`, `audio`, or
`document`. They reuse the tenant-owned Media object and prevent physical
deletion while active. Historical Assessment Activities retain only the Quiz
identifier; presenting a mutable current Quiz title as historical data is not
permitted.

## 3. Silent Duplicate Detection

Duplicate detection belongs to the Media Platform. It must happen
transparently without interrupting the user's workflow.

Whenever a file is uploaded from any entry point:

1. Calculate the file checksum.
2. Search existing Media Files within the same tenant by `customer_id` and
   `checksum`. File size and MIME type may be used as additional validation.

If an identical Media File already exists:

* Do not upload another physical file.
* Do not create another `media_files` record.
* Create only a new `media_file_usage` record.

If no identical Media File exists:

* Store the physical file.
* Create a new `media_files` record.
* Create the corresponding `media_file_usage` record.

Users should not need to know whether the uploaded file already existed.

## 4. Ownership

`media_files` owns:

* physical files
* storage
* metadata

`media_file_usages` owns:

* relationships
* business references

One Media File may have multiple usages. Removing one usage must not remove the
Media File while other usages still exist.

Physical deletion is allowed only when:

* no usages remain
* retention policy allows deletion

## 5. Upload Modes

Business forms may configure one of the following upload modes.

`upload_only`

Default mode. Users upload directly from the business form.

`upload_and_library`

Users may either upload a new file or select an existing Media File from Media
Library.

The selected mode is determined by the business use case. The default upload
mode for LearnForge is `upload_only`. Choosing an existing Media File is not
the default behavior.

Business modules should enable Media Library selection only when asset reuse is
expected.

Typical examples include:

* Lesson Videos
* Lesson Documents
* Marketing Assets
* Shared Images

Simple business assets should remain upload-only.

Examples:

* Category Thumbnail
* Category Banner
* Course Cover
* Teacher Avatar
* Student Avatar

Future implementations may configure upload behavior per field using an upload
mode, but this policy does not define that implementation.

## 6. Tenant Isolation

Duplicate detection is tenant-scoped. Media Files must never be shared across
tenants.

---

# Media Upload / Display / Preview / Delete UI Standard

Media UI trong LearnForge phải che giấu chi tiết kỹ thuật storage và trình bày
asset bằng ngôn ngữ nghiệp vụ rõ ràng.

Khi một yêu cầu implementation nói **“áp dụng chuẩn Media LF”**, form hoặc
danh sách đó phải tuân thủ toàn bộ section này. Không được tự tạo một biến thể
upload, preview hoặc remove riêng nếu chưa có architecture/UI review.

## 1. Form Layout

Mỗi media field trên form Create/Edit phải trình bày theo trục dọc:

```text
Business label

Current media tile + Upload/Replace tile

Format and maximum-size hint
```

Rules:

* Business label dùng cùng typography với label thông thường của form; không
  dùng heading đậm chỉ để mô tả media field.
* Current media và Upload/Replace nằm trong cùng một picker row, căn từ trái
  sang phải và wrap khi viewport không đủ rộng.
* Create hoặc field chưa có media chỉ hiển thị Upload tile.
* Edit có media hiển thị Current media tile trước, Upload/Replace tile sau.
* Hint định dạng/dung lượng phải nằm thành dòng riêng bên dưới picker row.
* Field video có source selector vẫn giữ hierarchy: business label, source
  selector, media picker row, hint.
* Không hiển thị native file input như control chính khi shared Media picker
  đã được áp dụng.

Shared implementation contract:

* Current media dùng shared `authoring-media-row`.
* Upload/Replace dùng shared `authoring-media-upload`.
* Thumbnail/fallback dùng shared `media-thumbnail`.
* Không copy markup hoặc tạo CSS riêng theo từng Domain để mô phỏng contract
  này.

## 2. Current Media Tile

Current media tile là summary nhẹ, không phải inline player.

* Image hiển thị authorized thumbnail.
* Video hiển thị poster/thumbnail khi có; nếu không có dùng video icon.
* Audio dùng audio/headphones icon.
* PDF và Office document dùng thumbnail an toàn khi có, nếu không dùng đúng
  file-type icon.
* Không tải full binary chỉ để render list/form tile.

Trên thiết bị có hover, hover hoặc keyboard focus vào Current media tile phải
hiển thị overlay action:

* View
* Remove, khi owner Domain cho phép detach

Trên thiết bị không có hover, action overlay phải luôn nhìn thấy. Icon action
phải có accessible name; không được dùng icon-only control thiếu screen-reader
label. Focus phải mở overlay và có focus state rõ ràng.

Remove trên form:

* Ẩn Current media tile ngay để phản hồi thao tác.
* Chỉ detach usage/domain reference khi form được submit thành công.
* Không xóa vật lý Media File.
* Nếu Remove và valid replacement cùng được gửi, replacement thắng.

## 3. Upload / Replace

User-facing forms must expose media upload in simple business language.

Do not expose the following as user-editable fields:

* storage key
* disk
* bucket
* region
* metadata JSON
* internal media lifecycle fields
* internal media ownership fields

Image/video fields must clearly indicate whether the media is required or
optional.

### Ngôn ngữ nội dung — trường bắt buộc, không được bỏ khỏi form

Amendment v1.7, 2026-08-27. Mục này ghi lại hành vi **đã chạy trong code**; nó
không mở thêm quyết định nào.

Khi upload Media mới thuộc `video`, `audio` hoặc `document`, form phải có một
trường ngôn ngữ nội dung do người dùng **khai báo tường minh**:

| | |
| --- | --- |
| Nhãn hiện tại | *Ngôn ngữ nội dung (BCP 47)*, placeholder `vi, ko, en-US` |
| Bắt buộc khi | có file mới ở một trong ba loại trên |
| Ghi vào | `media_files.processing_locale` |
| Bất biến | Sau khi required output profile set được materialize, attach lại cùng Media File phải dùng đúng locale đã lưu |

Đây **không** phải trường tuỳ chọn cho vui. Theo
[Processing Contract § 1](LF-Media-Processing-Contract.md), thiếu locale, locale
không hợp lệ, hoặc locale xung đột với giá trị đã lưu thì orchestration
fail-closed:

```text
media_files.status = 'failed'
media_files.processing_error_code = 'required_profile_configuration_missing'
```

Không được suy locale từ internationalization default, browser locale, user
locale hay language detection của model. Không cái nào là source of truth.

Bỏ trường này khỏi một form attach mới là tạo ra một đường upload luôn `failed`
mà không có thông báo nào giải thích tại sao — đó là lý do nó được ghi ở đây chứ
không chỉ ở hợp đồng processing.

**Ghi chú kiểm chứng:** validation hiện chỉ kiểm **hình dạng** language tag
(`max:20`, regex subtag), không kiểm chữ hoa/thường. Canonical hoá theo quy tắc
BCP 47 của Processing Contract nằm ở tầng output profile, không ở form.

### Tập MIME và extension được phép

Amendment v1.7, 2026-08-27. Ghi lại danh sách **đang thi hành trong code**; trước
đây nó không tồn tại trong bất kỳ tài liệu nào, kể cả khi Processing Contract § 1
viện dẫn "tập MIME được hỗ trợ".

| `file_type` | Extension | MIME |
| --- | --- | --- |
| `image` | jpg, jpeg, png, gif, webp, svg | image/jpeg, image/png, image/gif, image/webp, image/svg+xml |
| `audio` | mp3, wav, ogg, webm, m4a, aac | audio/mpeg, audio/mp3, audio/wav, audio/x-wav, audio/ogg, audio/webm, audio/mp4 |
| `video` | mp4, webm, mov, avi | video/mp4, video/webm, video/quicktime, video/x-msvideo |
| `document` | pdf, doc, docx, xls, xlsx, ppt, pptx, txt | application/pdf, application/msword, …wordprocessingml.document, application/vnd.ms-excel, …spreadsheetml.sheet, application/vnd.ms-powerpoint, …presentationml.presentation, text/plain |
| `subtitle` | vtt, srt, txt | text/vtt, application/x-subrip, text/plain |
| `transcript` | txt, json | text/plain, application/json |
| `archive` | zip, tar, gz | application/zip, application/x-zip-compressed, application/x-tar, application/gzip |
| `other` | — | — |

Validation yêu cầu **cả hai** khớp: MIME server-trusted **và** extension đã chuẩn
hoá. Chỉ một trong hai khớp là bị từ chối.

Hai điểm phải nói rõ vì chúng dễ gây hiểu nhầm:

* **`file_type = 'other'` bỏ qua toàn bộ kiểm tra này.** Code trả `true` ngay
  không xét MIME lẫn extension. Đây là hành vi hiện tại, ghi lại đúng như nó
  đang là; nó **chưa** được đánh giá xem có phải chủ ý hay không.
* **`document` gồm cả `.txt` và ba định dạng legacy `.doc`, `.xls`, `.ppt`.**
  Legacy đi qua đường LibreOffice; `.txt` đọc thẳng. Vì phạm vi structured
  extraction khoá theo `file_type = 'document'`, `.txt` nằm trong phạm vi trên
  giấy — không thành vấn đề vì nó ở nhóm optional nên không ai yêu cầu.

Giới hạn dung lượng là `MEDIA_MAX_UPLOAD_KILOBYTES`, không theo từng `file_type`.
Xem [LF-Tech-Runtime-Requirements § 10](../tech/LF-Tech-Runtime-Requirements.md)
về sai lệch giữa `.env.example` và mặc định của `config/media.php`.

If only one preview media is allowed, UI must clearly communicate:

```text
Image or Video
```

Do not show two independent required controls that imply both image and video
can be active at the same time.

Upload tile phải:

* dùng dấu cộng và label nghiệp vụ `Tải lên ...` khi chưa có media;
* dùng label `Thay thế ...` khi đã có media;
* hiển thị tên file vừa chọn theo cách compact;
* giữ native file input trong DOM cho keyboard, validation và browser upload,
  nhưng không để browser-specific `Choose File / No file chosen` quyết định
  visual contract;
* giữ accept/type validation phía client và validation authoritative phía
  server.

## 4. List Display

Media lists must stay lightweight.

Image media should render a small thumbnail when a safe signed/private preview
URL or approved thumbnail URL is available.

Video media should render a poster or generated thumbnail when available. If a
poster/thumbnail is not available, render a lightweight video icon or
placeholder.

Do not render inline video players in list rows or cards.

Do not load full-size images or videos in lists.

Hành động `Xem` trên list phải dùng cùng Preview Routing Policy với form; list
không được hardcode tất cả media sang popup hoặc tất cả sang tab mới.

## 5. Preview Routing Policy

Preview mode phải được quyết định bằng canonical media type, normalized provider
identity và server-trusted MIME metadata. Không quyết định chỉ bằng filename
hoặc extension do client gửi.

| Media | View behavior |
| --- | --- |
| Image có authorized preview URL | Standard popup/modal |
| Uploaded video có signed/private delivery | Video player trong popup/modal |
| Uploaded audio có signed/private delivery | Audio player trong popup/modal |
| Trusted normalized video embed | Embed preview trong popup/modal |
| PDF | Mở tab mới; browser quyết định inline view hoặc download |
| DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT và document khác | Mở tab mới; browser quyết định view hoặc download |
| External URL hoặc Live Class URL | Mở tab mới với `noopener noreferrer` |
| Missing, unauthorized, processing hoặc unavailable media | Không tạo View action giả |

Image preview must:

* open inside the modal
* be centered
* scale responsively
* avoid overflowing the viewport

Video preview must:

* open inside the modal
* start playback after the user clicks Preview
* keep browser controls visible
* use signed/private delivery
* never use a raw public storage URL for protected tenant media

On modal close, video preview must:

* pause playback
* clear the video `src`
* call the browser load/reset behavior when needed
* release browser/network resources

Audio popup cũng phải pause và release resource khi đóng. Popup chỉ được tạo
hoặc nạp media sau explicit user action; list/form không autoplay hoặc preload
toàn bộ media.

### Shared Preview Modal Geometry

Mọi popup preview media trong Admin và Teacher phải dùng trực tiếp shared
`media-library-modal`, `media-library-modal-panel`,
`media-library-modal-body`, `media-library-modal-image` và
`media-library-modal-video`.

Kích thước popup, viewport spacing, header, backdrop, image containment và
tỉ lệ video phải được định nghĩa duy nhất trong shared Admin CSS. Domain hoặc
form không được tạo class riêng để thay đổi width, height, aspect ratio hay
padding của popup. Thay đổi thiết kế popup phải cập nhật shared definition và
áp dụng đồng thời cho mọi nơi sử dụng Media LF.

Canonical geometry hiện tại:

* modal max-width: `1280px`;
* modal luôn chừa viewport gutter `24px`;
* image dùng `object-fit: contain`;
* video/embed dùng tỉ lệ `16:9`, `object-fit: contain`;
* chiều cao media không vượt viewport sau khi trừ modal header và spacing.

Document không được ép vào iframe/modal. PDF và các document khác phải dùng
authorized/signed URL trong tab mới để browser quyết định inline rendering hoặc
download dựa trên MIME và `Content-Disposition`.

Media preview/download must not require buckets or files to be public.

## 6. Edit Form Display

Existing attached media should be visible on edit/detail forms.

Image attachments should show a thumbnail.

Video attachments should show a poster, icon, or lightweight placeholder. Edit
forms must not render inline video players by default.

Preview and remove actions phải nằm trên overlay của Current media tile theo
Current Media Tile contract, không tạo một hàng text action rời bên cạnh.

Do not show noisy duplicate labels that repeat the same context without adding
meaning.

Optional media must not show a required marker.

## 7. Remove / Delete

Removing media from an entity only detaches or updates the usage mapping. It
must not delete the underlying Media File or physical storage object.

Deleting from Media Library may physically delete the storage object only when
there are no active usages and the approved lifecycle allows deletion.

If a Media File has active usage, delete must be blocked.

Do not hard-delete media history or usage history unless an approved lifecycle
explicitly allows it.

## 8. Security

Media access must be tenant-scoped.

Preview and download must use signed/private delivery for protected tenant
media.

Do not make buckets or files public to support preview behavior.

`storage_key` is an internal locator. It must not be exposed as user-editable
data.

## 9. Performance

Do not render inline video players in lists or forms.

Load video only after the user clicks Preview.

Clear the video source on modal close.

Avoid browser hangs caused by multiple video elements, full-size media loading,
or automatic video preloading in lists.

---

# Architecture

```text
Course / LiveClass / Assessment / AI / Other Domain

↓ generic usage

media_file_usages

↓

media_files

↓

Variants / Processing Jobs / Transcripts / Captions

↓

Storage + Delivery
```

---

# Database Namespace

```text
media_*
```

Foundation tables:

```text
media_categories

media_files

media_file_usages

media_variants

media_processing_jobs

media_transcripts

media_captions

media_access_logs
```

---

# Storage Principle

Default storage:

```text
AWS S3
```

Private storage là mặc định. Media không lưu permanent public URL trong
database. Delivery URL chỉ là access mechanism tạm thời và không phải asset
identity.

Media database không lưu binary. Database chỉ lưu:

* Metadata
* Storage disk/bucket/region
* Canonical storage key
* Checksum and dimensions
* Processing state
* Delivery references

`storage_key` là canonical object locator.

Object key phải tenant-aware và không dùng original filename làm object key.
Recommended convention:

```text
tenants/{customer_id}/{module}/{entity_type}/{entity_id}/{purpose}/{ulid}.{ext}
```

Examples:

```text
tenants/1/course/templates/10/cover/01JXXX.png

tenants/1/course/activities/200/video/01JXXX.mp4

tenants/1/assessment/questions/55/audio/01JXXX.mp3

tenants/1/assessment/answers/90/speaking/01JXXX.webm
```

Bucket name, region, endpoint và storage provider configuration không được
hardcode trong business Domain. Storage configuration thuộc infrastructure /
environment layer để hỗ trợ shared S3, dedicated tenant storage và future BYOC.

Không tạo:

```text
media_folders
```

Business Category không phải storage folder. Prefix/folder-like organization
trên S3 chỉ là một phần của `storage_key`.

---

# Immutable File Principle

Binary của Media File immutable sau upload.

```text
Change Content

↓

Upload New Media File
```

Metadata và processing state có thể cập nhật theo rule. Nội dung binary,
checksum và canonical storage identity không được silent-replace.

Không hard-delete file còn active Usage. Lifecycle dùng `deleted` hoặc
`archived`, đồng thời storage retention/purge chạy theo policy riêng.

Replace/Delete lifecycle:

```text
Replace Content

↓

Upload New Media File

↓

Move Usage / Domain Reference

↓

Archive Old Media File When Allowed
```

Delete không được phá owner Domain state. Owner Domain phải quyết định detach /
remove business reference; Media chỉ quản lý asset lifecycle, audit và storage
retention/purge.

Orphan cleanup là Media responsibility nhưng phải giữ tenant boundary và không
được hard-delete object còn active Usage.

---

# Content Hash Principle

`checksum` đại diện cho Content Identity.

File name chỉ là metadata và không đại diện cho Content Identity. Nếu nội dung
thay đổi, phải upload Media File mới; không replace binary cũ.

---

# Media Files

`media_files` là bảng trung tâm.

Nó trả lời:

```text
Asset thuộc tenant nào?

Asset type/mime/size là gì?

Binary nằm ở storage key nào?

Processing state hiện tại là gì?
```

Supported file types:

```text
image

video

audio

document

subtitle

transcript

archive

other
```

File lifecycle:

```text
uploading

↓

processing

↓

ready

↓

archived / deleted
```

Upload lifecycle thuộc Media Platform:

```text
Authorize owner Domain intent

↓

Validate media type and tenant context

↓

Generate tenant-aware storage key

↓

Store private object

↓

Create Media identity and usage reference

↓

Process variants / transcripts / captions when needed
```

Owner Domain quyết định user có được upload asset cho business object hay
không. Media quyết định asset identity, storage boundary, processing lifecycle
và delivery policy.

`cdn_url` nếu có chỉ là delivery reference, không thay authorization.
Permanent `public_url` không phải canonical Media data và không được dùng làm
protected content locator. Private/signed delivery là mặc định cho protected
content.

---

# Signed Delivery Principle

Media access dùng signed delivery khi protected content cần được đọc, xem,
stream hoặc download.

Signed URL / signed delivery:

* Được tạo khi cần.
* Có thời hạn ngắn.
* Phải kiểm tra tenant và authorization trước khi phát hành.
* Không được lưu như canonical asset identity.
* Không được log credential, signing secret hoặc full signed query string.

Public bucket/object access không phải default cho tenant media.

---

# Generic Usage Mapping

`media_file_usages` kết nối Media với Domain owner:

```text
media_file_id

owner_type

owner_id

usage_type
```

Supported owner examples:

```text
course_activity

assessment_question

assessment_answer

liveclass_recording

certificate

avatar

ai_knowledge

marketing
```

Không hard foreign key sang Domain khác. Calling Domain phải validate tenant,
authorization và owner existence. Media chỉ biết generic usage reference.

---

# Variants

`media_variants` lưu asset phái sinh:

```text
thumbnail

preview

compressed

720p

1080p

hls

webp
```

Variant có storage key riêng nhưng không thay thế original Media File identity.

---

# Variant Principle

Variant luôn là Derived Asset, không phải Original Asset.

Variant không được update Original Asset và có thể regenerate từ Original
Asset.

---

# Processing

`media_processing_jobs` theo dõi:

```text
transcode

thumbnail

ocr

speech_to_text

caption

virus_scan

compress
```

Processing độc lập với business Domain. Job completion không đồng nghĩa Course
Progress, Assessment Result hoặc LiveClass Attendance.

Heavy processing tuân thủ Async First qua queue/worker.

---

# Structured extraction

Amendment v1.4, Approved 2026-08-27, áp dụng
[ADR-0019](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) đã Approved.

Media không chỉ sản xuất text theo trang. Cấu trúc nhìn thấy được trên trang —
vùng, thứ tự đọc, bảng, ô — là dữ liệu quan sát được và có chủ sở hữu rõ ràng:

```text
media_extracted_regions   một vùng: role, hình học, thứ tự đọc
media_extracted_tables    một bảng, neo vào một region hoặc một sheet
media_table_cells         một ô: row, column, rowspan, colspan, text
```

Ba bảng này là **content type mới**, không phải cột mới của
`media_extracted_texts`. Revision identity nằm ở row sở hữu: region và table
mang `processing_version`/`source_fingerprint`/`status`; cell kế thừa cả ba từ
bảng cha và không thể `archived` một mình.

## Ranh giới

Media ghi lại **những gì có trên trang**: đây là một vùng, role của nó là bảng,
nó có 4 hàng 3 cột, ô (2,1) chứa chuỗi này, thứ tự đọc là thế này.

Media **không** ghi ý nghĩa: bảng này nói về doanh thu, biểu đồ này cho thấy xu
hướng giảm. Diễn giải thuộc AI theo
[ADR-0020](../adr/ADR-0020-AI-Vision-Interpretation-Boundary.md) — mỗi lời gọi
để lại một `ai_model_runs`, quota chặn trước, retention thừa kế ADR-0018. Không
trường diễn giải nào được nằm trong bảng `media_*`.

Một khi Media bắt đầu gán ý nghĩa, nó thành nguồn sự thật cho một business state
nó không sở hữu, và không consumer nào truy được quyết định đó về một model run.

### Với biểu đồ, sơ đồ và ảnh — Media dừng ở vùng

Amendment v1.6, Approved 2026-08-27
([ADR-0019 § D7](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)).

Media ghi bốn thứ: **có một vùng ở đây** (`role = 'figure'`), **vùng nằm ở đâu**
(`bbox`), **chữ và số nằm trong vùng**, và **crop kèm citation**.

Media không ghi quan hệ giữa các khối, hướng mũi tên như một quan hệ, thứ tự liên
kết, loại biểu đồ hay ý nghĩa. Thấy một nét vẽ có đầu mũi tên là quan sát; nói nó
**nối** khối A sang khối B là một quan hệ ngữ nghĩa, và thuộc AI.

`role` cố ý **không** tách `chart` / `diagram` / `image`. Phân biệt "biểu đồ cột"
với "sơ đồ luồng" là phán đoán về nội dung — Media không điền đúng được, nên thêm
vào chỉ tạo dữ liệu sai có vẻ chính xác.

## Nguyên tử

Region, table và cell của một revision cùng `ready` hoặc cùng không. Vượt bất kỳ
trần tài nguyên nào ở
[Processing Contract](LF-Media-Processing-Contract.md) ⇒
`structured_extraction_too_large`, fail toàn revision, **không truncate**. Một
bảng `ready` mà thiếu ô là một bảng nói dối về nội dung của chính nó.

## Phạm vi

Structured extraction dùng **đúng cổng kích hoạt** của mọi job Media khác: usage
`course_activity` đang active và đã authorize, `file_type = 'document'`. Nó không
chạy cho `avatar`, `marketing`, `certificate`, `course_category`, và không chạy
cho ảnh, audio, video.

Nó thuộc nhóm **optional / on-demand**, không nằm trong required output profile
set. Nghĩa là thiếu revision cấu trúc là trạng thái hợp lệ, không phải lỗi, và
`media_files.status` không bao giờ chờ nó — tác giả upload xong vẫn xem được file
ngay. Chi tiết ở
[Processing Contract § 1](LF-Media-Processing-Contract.md).

## Đường đọc

Không có API riêng cho structured data. Consumer đọc qua Media Read Service với
`content_type` là `region` hoặc `table` theo
[LF-Media-Read-Contract](LF-Media-Read-Contract.md) v1.5, kèm trường `structure`.
Không đường nào cho AI đọc thẳng bảng `media_*`.

---

# Transcript And Caption

`media_transcripts` lưu transcript text trong field `text`, không nhét transcript
vào metadata.

`media_captions` lưu locale, format và `storage_key` của VTT/SRT/ASS assets.

AI Domain có thể đọc transcript để tạo Knowledge/Insight nhưng tự sở hữu AI
result. Media không quyết định AI output.

---

# Access Audit

`media_access_logs` là append-only audit cho upload, stream, view, download,
delete và share.

Access Log không được dùng trực tiếp để tính:

* Course Progress
* LiveClass Attendance
* Assessment completion/result

Learning behavior và progress decisions thuộc Track/Course hoặc owning Domain.

---

# Domain Integrations

## Course

Course/Version Activity lưu `media_file_id` hoặc Usage mapping. Course giữ
learning context, Progress và Completion.

## LiveClass

LiveClass Recording tham chiếu Media File. Media giữ binary, variants,
transcript, caption và delivery; LiveClass giữ Session/Attendance/Replay data.

## Assessment

Question media, uploaded answers, speaking recordings và essay files tham
chiếu Media. Assessment giữ Question/Attempt/Answer/Grading evidence.

## AI

AI Knowledge có thể dùng Media/Transcript qua Usage mapping. AI Domain giữ
embedding, summary, recommendation và other intelligence outputs.

## Certificate And Other Domains

Certificate/avatar/marketing assets dùng generic Usage mapping. Media không
quyết định issuance, identity authorization hoặc marketing lifecycle.

---

# Tenant And Security Rules

1. Mọi Media business record phải có `customer_id`.
2. Media File, child records và Usage phải cùng tenant.
3. User Tenant A không được đọc storage/delivery của Tenant B.
4. Mỗi storage object phải nằm trong tenant storage boundary.
5. Storage key phải tenant-aware và bắt đầu bằng `tenants/{customer_id}` hoặc
   equivalent tenant-isolated BYOC prefix.
6. Visibility không thay authorization.
7. Protected Media ưu tiên signed delivery.
8. Owner Domain tự kiểm tra quyền sử dụng asset.
9. Logs và metadata không lưu credential/signing secret.
10. Original filename chỉ là metadata, không phải storage identity.
11. IAM/storage permissions phải theo least privilege và không cho phép
    cross-tenant read/write/delete.

---

# AWS Cost And BYOC Direction

Media Platform phải giữ đủ asset ownership và storage identity để hỗ trợ future
storage usage reporting theo tenant.

Future AWS cost tracking có thể dựa trên:

```text
Customer ownership

Storage provider / bucket / region

Storage key prefix

Object size and lifecycle state

Processing / delivery events
```

Cost tracking không được thay đổi ownership business state của Course,
Assessment, LiveClass, Certificate hoặc AI.

Enterprise BYOC là future-compatible direction. BYOC storage phải giữ cùng Media
identity model, tenant isolation rules, signed delivery principle và owner
Domain integration contract. Business Domain không được biết bucket-specific
implementation details.

---

# Design Rules

## Media Thumbnail UI Standard

Compact authoring media summaries use the shared `media-thumbnail`,
`authoring-media-row`, and `authoring-media-upload` Blade components. Form
layout follows `Business label → Current + Upload/Replace picker row → format
hint`. Current media is represented by a compact tile; View and Remove actions
appear as an accessible overlay on hover/focus and remain visible on devices
without hover.

Images use an authorized thumbnail variant when available, otherwise an
authorized signed/private image URL. Uploaded videos use a ready generated or
stored poster, otherwise the video icon. Trusted embeds derive provider
thumbnails only from normalized identity; failure uses the video icon and must
never load an iframe for thumbnail display. PDFs use an authorized first-page
preview when available, otherwise the PDF icon. Office files use standardized
file-type icons unless an approved safe conversion service exists. Thumbnail
availability does not change Preview Routing Policy: PDF and document View
actions still open a new tab.

Pending, failed, or broken thumbnails fall back to decorative icons. Thumbnail
processing is asynchronous and must not block upload or rendering. Private
variants remain tenant-scoped and require authorized delivery. Lists must
batch resolution to avoid N+1 queries and repeated signed URL generation.

1. Media là Platform Domain.
2. Media chỉ sở hữu Digital Asset data/rules.
3. Database không lưu binary.
4. `storage_key` là canonical locator.
5. Storage key phải tenant-aware.
6. Private storage và signed delivery là default cho protected Media.
7. Không lưu permanent public URL làm canonical Media data.
8. Binary immutable; content change tạo file mới.
9. Không tạo `media_folders`.
10. Cross-domain relationship dùng `media_file_usages`.
11. Không hard FK generic owner sang Domain khác.
12. Variant không phải original file.
13. Transcript text nằm trong field riêng.
14. Access Logs chỉ phục vụ audit.
15. Media không quyết định state của Course, LiveClass, Assessment, Certificate
    hoặc AI.
16. Business modules không quản lý storage trực tiếp.
17. Media phải future-compatible với tenant storage usage reporting và BYOC.

---

# Current Scope

```text
Categories

Files

Usages

Variants

Processing Jobs

Transcripts

Captions

Access Logs
```

---

# Future Scope

```text
Advanced DRM

Multipart Upload Sessions

Asset Version Lineage

Multiple Transcript Revisions

Advanced Rendition Profiles

Lifecycle Automation

Storage Replication

Tenant Storage Usage Reporting

Enterprise BYOC
```

---

# Architecture Decision

Media Foundation được phê duyệt và freeze tại:

[ADR-0004 — Media Foundation](../adr/ADR-0004-Media-Foundation.md)

ADR này là source quyết định cho Platform Domain ownership, content identity,
immutable files, variants, S3 storage và generic usage integration.

---

# Media Là Nguồn Đầu Vào Cho Learning Authoring

Theo [ADR-0017](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md), metadata,
OCR, transcript và caption của Media có thể làm nguồn đầu vào được authorize cho
AI Authoring Proposal, khi Media đã được gắn vào một Course Activity.

Media không sở hữu Learning semantics. Không tồn tại canonical mapping trực tiếp
Media File → Learning Node: lineage bắt buộc đi qua `media_file_usages` rồi
Course Activity. Lý do là cùng một file mang vai trò sư phạm khác nhau tuỳ nơi
dùng — một video có thể `teaches` ở khoá này, `practices` ở khoá khác và
`assesses` ở khoá thứ ba. Chỉ Activity mới cung cấp mục đích đó.

Reprocessing hoặc sửa transcript chỉ làm các Proposal liên quan **stale** hoặc
sinh revision mới. Nó không được sửa âm thầm Proposal đã duyệt, Course snapshot
đã publish, hay canonical Learning Mapping.

---

# Final Statement

Media Foundation là shared Digital Asset Platform của LearnForge.

Media biết asset, storage, processing và usage mapping. Media không biết hoặc
quyết định Lesson, Quiz, Attendance, Certificate, Progress hoặc AI Result.

Media Foundation Version 1.0 đã được phê duyệt. Thay đổi kiến trúc sau freeze
phải được review bằng ADR mới hoặc amendment được owner chấp thuận.

---

End of LF-Media
