# Table: media_variants

Version: 1.1

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-23

Document Path: database/media/media_variants.md

## Purpose

Lưu metadata và storage locator của asset phái sinh từ Media File gốc.

## Relationships

`Media File 1 → N Variants`; `Customer 1 → N Variants`.

## Business Rules

* Variant và original Media File phải cùng tenant.
* Variant không phải file gốc và không thay đổi identity/checksum của original.
* Allowed `variant_type`: `thumbnail`, `preview`, `compressed`, `720p`, `1080p`, `hls`, `webp`.
* Allowed `status`: `processing`, `ready`, `failed`, `archived`.
* Database không lưu binary; `storage_key` định vị output.
* Variant storage cũng private by default; delivery dùng authorized/signed
  access giống Media File gốc.

### Variant Principle

* Variant luôn là Derived Asset, không phải Original Asset.
* Variant không được update Original Asset.
* Variant có thể regenerate từ Original Asset.
* Variant neo vào lần chạy đã tạo ra nó qua `processing_job_id`, và mang
  `processing_version` cùng `source_fingerprint` của lần chạy đó.
* Chạy lại **không ghi đè**: processing version mới sinh variant mới, bản cũ
  chuyển `archived`. Quy tắc stale đầy đủ nằm trong
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md).
* Variant **không** ảnh hưởng `media_files.status`. `transcode` và `thumbnail`
  là job tuỳ chọn; variant `failed` không làm file mất `ready`.
* Variant không mang citation locator: nó là asset thay thế của cùng nội dung,
  không phải một đoạn trích dẫn được.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Original Media File. |
| variant_type | VARCHAR(50) NOT NULL | Loại variant. |
| storage_key | VARCHAR(1024) NOT NULL | Object key của variant. |
| cdn_url | TEXT NULL | Delivery reference. |
| mime_type | VARCHAR(255) NOT NULL | MIME output. |
| width | INT UNSIGNED NULL | Chiều rộng. |
| height | INT UNSIGNED NULL | Chiều cao. |
| bitrate | INT UNSIGNED NULL | Bitrate output. |
| file_size_bytes | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Kích thước output. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra variant này. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản encoder/cấu hình. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi tạo variant. |
| status | VARCHAR(50) NOT NULL DEFAULT 'processing' | Processing state. |
| metadata | JSON NULL | Codec/manifest metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints And Indexes

`UNIQUE (customer_id, media_file_id, variant_type)` của Version 1.0 đã **bị loại
bỏ**: nó cho phép đúng một variant cho mỗi loại, chặn cơ chế revision mà
processing versioning cam kết.

```sql
UNIQUE (customer_id, media_file_id, variant_type, processing_version);
UNIQUE (customer_id, storage_key);
UNIQUE (id, customer_id);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);
INDEX  (customer_id, processing_job_id);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('processing','ready','failed','archived'));
CHECK (variant_type IN ('thumbnail','preview','compressed','720p','1080p','hls','webp'));
CHECK (status <> 'ready' OR storage_key IS NOT NULL);
CHECK (file_size_bytes >= 0);
```

## Sample Data

`id=300, customer_id=1, media_file_id=100, variant_type=720p, storage_key=tenants/1/course/activities/9001/video/variants/01JXXX-720p.mp4, mime_type=video/mp4, width=1280, height=720, bitrate=2500000, file_size_bytes=52428800, status=ready`

## Design Notes

Variant là disposable/regenerable derived output; original Media File vẫn là
source. Nếu cần nhiều renditions cùng một `variant_type`, phase implementation
phải mở rộng uniqueness bằng profile key.
