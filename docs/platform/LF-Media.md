# LF-Media.md

Version: 1.1

Status: Foundation Approved

Last Updated: 2026-07

---

# LF Media Architecture

Media là Platform Domain dùng chung cho toàn bộ LearnForge.

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
* Course Thumbnail
* Teacher Avatar
* Student Avatar

Future implementations may configure upload behavior per field using an upload
mode, but this policy does not define that implementation.

## 6. Tenant Isolation

Duplicate detection is tenant-scoped. Media Files must never be shared across
tenants.

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

# Final Statement

Media Foundation là shared Digital Asset Platform của LearnForge.

Media biết asset, storage, processing và usage mapping. Media không biết hoặc
quyết định Lesson, Quiz, Attendance, Certificate, Progress hoặc AI Result.

Media Foundation Version 1.0 đã được phê duyệt. Thay đổi kiến trúc sau freeze
phải được review bằng ADR mới hoặc amendment được owner chấp thuận.

---

End of LF-Media
