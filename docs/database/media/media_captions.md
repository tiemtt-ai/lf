# Table: media_captions

Version: 1.1

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-23

Document Path: database/media/media_captions.md

## Purpose

Lưu metadata và storage locator của caption/subtitle asset.

## Relationships

`Media File 1 → N Captions`; `Customer 1 → N Captions`.

## Business Rules

* Caption và Media File phải cùng tenant.
* Allowed `caption_type`: `vtt`, `srt`, `ass`.
* Allowed `status`: `pending`, `processing`, `ready`, `failed`, `archived`.
* Caption binary không lưu trong database; `storage_key` là canonical locator.

* Output neo vào một lần chạy cụ thể qua `processing_job_id`, và mang
  `processing_version` cùng `source_fingerprint` của lần chạy đó.
* Chạy lại **không ghi đè**: nội dung hoặc phiên bản xử lý đổi thì sinh bộ row
  mới, bộ cũ chuyển `archived`. Quy tắc stale đầy đủ nằm trong
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md).
* Locator theo hợp đồng chung: `locator_type = 'timespan'`, `locator_value` là
  `<start_ms>-<end_ms>`. Mọi nội dung trả cho consumer phải kèm locator.
* Chỉ row `ready` được Media Read Service trả ra.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File nguồn. |
| locale | VARCHAR(20) NOT NULL | Locale caption. |
| caption_type | VARCHAR(20) NOT NULL | VTT/SRT/ASS. |
| storage_key | VARCHAR(1024) NOT NULL | Canonical object key. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Processing state. |
| metadata | JSON NULL | Provider/timing metadata. |
| processing_job_id | BIGINT UNSIGNED NULL | Lần chạy đã tạo ra row này. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/model/cấu hình. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn khi xử lý. |
| locator_type | VARCHAR(20) NOT NULL | `timespan`. |
| locator_value | VARCHAR(50) NOT NULL | `<start_ms>-<end_ms>`. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints And Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, locale);
INDEX (customer_id, status);
UNIQUE (customer_id, media_file_id, locale, caption_type);
UNIQUE (customer_id, storage_key);
```


```sql
UNIQUE (customer_id, media_file_id, locale, caption_type, processing_version);
UNIQUE (id, customer_id);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (caption_type IN ('vtt','srt','ass'));
CHECK (locator_type IN ('timespan'));
CHECK (status <> 'ready' OR storage_key IS NOT NULL);
```

## Sample Data

`id=600, customer_id=1, media_file_id=100, locale=vi, caption_type=vtt, storage_key=tenants/1/captions/lesson-1-vi.vtt, status=ready`

## Design Notes

Caption thuộc Media processing/delivery; Course/LiveClass chỉ tham chiếu Media File hoặc Usage.
