# Table Name

`media_captions`

## Purpose

Lưu metadata và storage locator của caption/subtitle asset.

## Relationships

`Media File 1 → N Captions`; `Customer 1 → N Captions`.

## Business Rules

* Caption và Media File phải cùng tenant.
* Allowed `caption_type`: `vtt`, `srt`, `ass`.
* Allowed `status`: `pending`, `processing`, `ready`, `failed`, `archived`.
* Caption binary không lưu trong database; `storage_key` là canonical locator.

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
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, locale);
INDEX (customer_id, status);
UNIQUE (customer_id, media_file_id, locale, caption_type);
UNIQUE (customer_id, storage_key);
```

## Sample Data

`id=600, customer_id=1, media_file_id=100, locale=vi, caption_type=vtt, storage_key=tenants/1/captions/lesson-1-vi.vtt, status=ready`

## Design Notes

Caption thuộc Media processing/delivery; Course/LiveClass chỉ tham chiếu Media File hoặc Usage.
