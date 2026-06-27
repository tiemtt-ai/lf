# ADR-0004

Media Foundation

---

## Status

Approved

---

## Date

2026-06-27

---

## Related ADRs

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md)
* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md)

---

## Context

LearnForge cần một shared Digital Asset foundation cho Course, LiveClass,
Assessment, AI, Certificate, Avatar và Marketing.

Nếu mỗi Domain tự quản lý binary/storage/processing, hệ thống sẽ lặp hạ tầng,
khó bảo vệ tenant isolation và tạo nhiều asset identities. Nếu Media hiểu
Lesson, Quiz hoặc Certificate business state, Media sẽ coupling với các Domain
và vi phạm Domain Responsibility Principle.

Foundation cần chốt:

* Asset identity và immutability.
* Storage locator.
* Generic cross-domain usage.
* Derived variants.
* Processing, transcripts, captions và access audit.
* Ranh giới trách nhiệm với business Domains.

---

## Decision

Media được thiết kế là:

```text
Platform Domain
```

Media chỉ quản lý Digital Assets. Media không phải File Manager, Course Domain,
LiveClass Domain, Assessment Domain hoặc AI Domain.

Mọi Media business record phải tenant-scoped bằng `customer_id`.

---

## Platform Domain

Media sở hữu:

* Digital Asset identity and metadata
* Storage locator
* Processing jobs
* Derived variants
* Transcripts
* Captions
* Generic usage mappings
* Access audit

Media không sở hữu hoặc quyết định:

* Course Progress/Completion
* Assessment Result
* LiveClass Attendance
* Certificate issuance
* AI Result
* Owner Domain authorization/business state

---

## Architecture

```text
Course / LiveClass / Assessment / AI / Other Domain

↓

media_file_usages

↓

media_files

↓

Variants / Processing Jobs / Transcripts / Captions

↓

Storage + Delivery
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

## Storage Principle

Database không lưu binary.

Database chỉ lưu metadata, bucket, region, canonical `storage_key`, checksum,
dimensions và processing state.

```text
storage_key = canonical object locator
```

Business Category không phải storage folder. Không tạo `media_folders`.

---

## Immutable File Principle

Media File binary immutable sau upload.

```text
Change Content

↓

Upload New Media File
```

Không silent-replace binary cũ.

`checksum` đại diện cho Content Identity. File name không đại diện cho Content
Identity.

Không hard-delete Media File còn active Usage. Lifecycle/retention/purge được
thực hiện theo policy.

---

## Variant Principle

```text
Original Media File

↓ processing

Derived Variant
```

Variant luôn là Derived Asset, không phải Original Asset.

Variant:

* Không update Original Asset.
* Không thay đổi original identity/checksum.
* Có thể regenerate từ Original Asset.
* Có storage key và processing lifecycle riêng.

---

## Generic Usage Mapping

Cross-domain association dùng:

```text
media_file_id

owner_type

owner_id

usage_type
```

Media không tạo hard foreign key sang Course, LiveClass, Assessment, AI,
Certificate hoặc Domain khác.

Calling/owner Domain phải validate owner existence, tenant và authorization.
Media chỉ quản lý generic usage reference.

---

## S3 Storage Principle

AWS S3 là default storage.

Tenant-aware object paths nằm trong `storage_key`, ví dụ:

```text
tenants/{customer_id}/courses/...

tenants/{customer_id}/assessment/...

tenants/{customer_id}/liveclass/...
```

`storage_disk`, bucket và region hỗ trợ shared/dedicated/BYOC storage mà không
thay đổi asset ownership model.

CDN/public URLs là delivery references, không phải canonical identity và không
thay authorization. Protected content ưu tiên signed delivery.

---

## Domain Responsibility

Media chỉ cập nhật state thuộc Media Domain.

Media processing/access events không được trực tiếp:

* Complete Course.
* Set Assessment Result.
* Mark LiveClass Attendance.
* Issue Certificate.
* Produce canonical AI Result.

Owner/consumer Domain đọc Media reference, evidence hoặc event rồi tự quyết
định business state của mình.

---

## Integration

### Course

Course/Version Activity tham chiếu `media_file_id` hoặc Usage mapping. Course
sở hữu learning context, Progress và Completion.

### LiveClass

LiveClass Recording tham chiếu Media File. Media sở hữu recording binary,
variants, transcript, caption, storage và delivery. LiveClass sở hữu
Session/Attendance/Replay.

### Assessment

Question media, uploaded answers, speaking recordings và essay files tham
chiếu Media. Assessment sở hữu Question/Attempt/Answer/Grading evidence.

### AI

AI Knowledge có thể dùng Media/Transcript qua Usage mapping. AI sở hữu
embedding, summary, recommendation và other intelligence results.

### Certificate And Other Domains

Certificate, Avatar và Marketing dùng generic Usage mapping. Media không quyết
định issuance, identity authorization hoặc marketing lifecycle.

---

## Foundation Decisions

* Media là Platform Domain.
* Media không phải File Manager hoặc business Domain.
* Mọi Media data thuộc `customer_id`.
* Database không lưu binary.
* `storage_key` là canonical object locator.
* `checksum` là Content Identity.
* File name không phải Content Identity.
* Content change tạo Media File mới.
* Không replace original binary.
* Media File binary immutable sau upload.
* Variant là Derived Asset.
* Variant không update Original Asset và có thể regenerate.
* Business Category không phải storage folder.
* Không tạo `media_folders`.
* AWS S3 là default storage.
* Generic cross-domain association dùng `media_file_usages`.
* Không hard FK generic owner sang Domain khác.
* Transcript text nằm trong field riêng, không trong metadata.
* Caption/variant binary được định vị bằng `storage_key`.
* Access Log chỉ phục vụ audit, không tính progress/result.
* Media không quyết định business state của consumer Domain.

---

## Future Considerations

Các hạng mục sau là future scope, không phải Foundation defects:

* Advanced DRM
* Multipart upload sessions
* Asset replacement/version lineage
* Multiple transcript revisions/providers
* Advanced rendition profiles
* Lifecycle automation and legal hold
* Storage replication
* Enterprise BYOC
* Generic Usage orphan cleanup contracts

Future changes phải giữ Platform/Domain Responsibility boundaries hoặc được
phê duyệt bằng ADR mới/amendment.

---

## Consequences

### Positive

* Một canonical Digital Asset foundation dùng chung.
* Tenant/storage/security boundaries rõ.
* Business Domains không lặp binary processing infrastructure.
* Original assets không drift khi variants regenerate.
* Generic mapping giảm schema coupling.

### Trade-offs

* Generic Usage không có hard referential integrity tới owner Domain.
* Snapshot/version/replacement workflow cần policy riêng khi use case xuất hiện.
* Signed delivery, purge/legal hold, rendition profiles và transcript revisions
  cần implementation contracts.

---

## Result

```text
Foundation Ready

YES
```
