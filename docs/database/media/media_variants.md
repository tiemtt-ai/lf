# Table: media_variants

Version: 1.0

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
| status | VARCHAR(50) NOT NULL DEFAULT 'processing' | Processing state. |
| metadata | JSON NULL | Codec/manifest metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, variant_type);
INDEX (customer_id, status);
UNIQUE (customer_id, media_file_id, variant_type);
UNIQUE (customer_id, storage_key);
```

## Sample Data

`id=300, customer_id=1, media_file_id=100, variant_type=720p, storage_key=tenants/1/course/activities/9001/video/variants/01JXXX-720p.mp4, mime_type=video/mp4, width=1280, height=720, bitrate=2500000, file_size_bytes=52428800, status=ready`

## Design Notes

Variant là disposable/regenerable derived output; original Media File vẫn là
source. Nếu cần nhiều renditions cùng một `variant_type`, phase implementation
phải mở rộng uniqueness bằng profile key.
