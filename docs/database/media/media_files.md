# Table: media_files

Document Path: database/media/media_files.md

## Purpose

Bảng trung tâm lưu identity, metadata, storage locator và lifecycle của Digital Asset.

## Relationships

`Customer 1 → N Media Files`; `Category 1 → N Media Files`; `User 1 → N Uploaded Files`; `Media File 1 → N Usages / Variants / Jobs / Transcripts / Captions / Access Logs`.

## Business Rules

* File, Category và uploader phải cùng tenant.
* Database không lưu binary; `storage_key` là canonical locator.
* Binary immutable sau upload. Thay đổi nội dung phải tạo Media File mới.
* Không hard-delete khi còn active Usage; dùng lifecycle `deleted`/`archived`.
* Allowed `file_type`: `image`, `video`, `audio`, `document`, `subtitle`, `transcript`, `archive`, `other`.
* Allowed `status`: `uploading`, `processing`, `ready`, `failed`, `deleted`, `archived`.
* Không lưu permanent public URL làm canonical Media data. Protected tenant
  Media dùng signed delivery khi access.
* `cdn_url` nếu có chỉ là optional delivery metadata; không phải storage
  identity và không thay authorization.
* `file_size_bytes`, duration/dimensions/page count không âm.

### Content Hash Principle

* `checksum` đại diện cho Content Identity.
* File name không đại diện cho Content Identity.
* Nếu nội dung thay đổi phải upload Media File mới.
* Không replace binary cũ.

### Duplicate Detection

Media Files are the canonical physical files of LearnForge. Every upload entry
point must use the same duplicate detection strategy, including Media Library,
Course Category, Course Template, Course Product, Lesson, Assessment, Live
Class, AI and future modules. Business modules are upload entry points only;
duplicate detection belongs to the Media Platform.

When a file is uploaded:

1. Calculate the file checksum.
2. Search existing Media Files within the same tenant by `customer_id` and
   `checksum`. File size and MIME type may be used as additional validation to
   avoid checksum collision.

If an identical file already exists:

* Do not upload another physical copy.
* Do not create another `media_files` record.
* Create only a new `media_file_usage` record for the current owner.

If no identical file exists:

* Store the physical file.
* Create a new `media_files` record.
* Create the corresponding `media_file_usage` record.

Duplicate detection is tenant-scoped only. Files belonging to different tenants
must never be shared. `media_files` owns the physical file;
`media_file_usages` owns the relationship between a Media File and business
modules. Multiple usages may reference the same Media File. Removing one usage
must not delete the Media File while other usages still exist. Physical file
deletion is allowed only when the Media File has no remaining usages and the
platform retention policy allows deletion.

# Upload Strategy

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

## 2. Centralized Media Management

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

One Media File may have multiple usages.

Removing one usage must not delete the Media File while other usages still
exist. Physical file deletion is allowed only when:

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
mode, but this document only defines the policy, not the implementation.

## 6. Tenant Isolation

Duplicate detection is tenant-scoped. Media Files must never be shared across
tenants.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| category_id | BIGINT UNSIGNED NULL | Business category. |
| uploaded_by | BIGINT UNSIGNED NOT NULL | User upload. |
| file_type | VARCHAR(50) NOT NULL | Loại asset chuẩn hóa. |
| mime_type | VARCHAR(255) NOT NULL | MIME type. |
| original_name | VARCHAR(255) NOT NULL | Tên upload gốc. |
| display_name | VARCHAR(255) NOT NULL | Tên hiển thị. |
| extension | VARCHAR(32) NULL | Extension chuẩn hóa. |
| storage_disk | VARCHAR(50) NOT NULL DEFAULT 's3' | Storage adapter. |
| storage_bucket | VARCHAR(255) NOT NULL | Bucket vật lý. |
| storage_region | VARCHAR(100) NULL | Storage region. |
| storage_key | VARCHAR(1024) NOT NULL | Canonical object key. |
| storage_class | VARCHAR(100) NULL | S3/storage class. |
| cdn_url | TEXT NULL | Delivery/CDN reference. |
| public_url | TEXT NULL | Deprecated for protected tenant Media; không dùng làm canonical storage hoặc access identity. |
| checksum | VARCHAR(128) NULL | Content checksum, ưu tiên SHA-256. |
| file_size_bytes | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Kích thước binary. |
| duration_seconds | BIGINT UNSIGNED NULL | Thời lượng audio/video. |
| width | INT UNSIGNED NULL | Chiều rộng pixels. |
| height | INT UNSIGNED NULL | Chiều cao pixels. |
| page_count | INT UNSIGNED NULL | Số trang document. |
| language | VARCHAR(20) NULL | Ngôn ngữ asset. |
| visibility | VARCHAR(50) NOT NULL DEFAULT 'private' | `private`, `organization`, `public`. |
| status | VARCHAR(50) NOT NULL DEFAULT 'uploading' | Processing lifecycle. |
| metadata | JSON NULL | Metadata mở rộng, không chứa binary. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật metadata/state. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, category_id);
INDEX (customer_id, uploaded_by);
INDEX (customer_id, file_type);
INDEX (customer_id, visibility);
INDEX (customer_id, status);
INDEX (customer_id, checksum);
UNIQUE (customer_id, storage_disk, storage_bucket, storage_key);
```

## Sample Data

`id=100, customer_id=1, category_id=1, uploaded_by=200, file_type=video, mime_type=video/mp4, original_name=lesson.mp4, display_name=Lesson 1, extension=mp4, storage_disk=s3, storage_bucket=lf-shared, storage_region=ap-southeast-1, storage_key=tenants/1/course/activities/9001/video/01JXXX.mp4, checksum=sha256:abc, file_size_bytes=104857600, duration_seconds=900, visibility=private, status=ready`

## Design Notes

`storage_key` là source of truth cho object location. `checksum` là Content
Identity; original/display name chỉ là metadata. `cdn_url` là optional delivery
reference có thể được tái tạo và không phải ownership/progress data. Permanent
`public_url` không dùng cho protected tenant Media.
