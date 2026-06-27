# Table Name

`media_files`

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
* `public_url` chỉ dùng khi policy cho phép; URL không thay authorization.
* `file_size_bytes`, duration/dimensions/page count không âm.

### Content Hash Principle

* `checksum` đại diện cho Content Identity.
* File name không đại diện cho Content Identity.
* Nếu nội dung thay đổi phải upload Media File mới.
* Không replace binary cũ.

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
| public_url | TEXT NULL | Public delivery URL nếu policy cho phép. |
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

`id=100, customer_id=1, category_id=1, uploaded_by=200, file_type=video, mime_type=video/mp4, original_name=lesson.mp4, display_name=Lesson 1, extension=mp4, storage_disk=s3, storage_bucket=lf-shared, storage_region=ap-southeast-1, storage_key=tenants/1/courses/lesson-1.mp4, checksum=sha256:abc, file_size_bytes=104857600, duration_seconds=900, visibility=private, status=ready`

## Design Notes

`storage_key` là source of truth cho object location. `checksum` là Content
Identity; original/display name chỉ là metadata. `cdn_url`/`public_url` là
delivery references có thể được tái tạo và không phải ownership/progress data.
