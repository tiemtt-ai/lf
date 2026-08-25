# Table: media_captions

Version: 1.4

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-24

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
* Caption **không mang locator**. Một row là **một file asset** VTT/SRT/ASS, và
  một file caption chứa nhiều cue với nhiều mốc thời gian khác nhau. Gán một
  `timespan` duy nhất cho cả file là một mốc bịa, và một trích dẫn sai chỗ còn
  tệ hơn không có trích dẫn.
* Trích dẫn theo thời gian dùng `media_transcripts`, nơi một row đã là một đoạn
  có `timespan` thật. Nếu Media Read Contract về sau cần trích dẫn ở mức cue của
  chính caption asset, đó là một derived contract riêng — mỗi cue một row với
  `{timespan, text}` — không phải một cột nhét thêm vào bảng này.
* Chỉ row `ready` được Media Read Service trả ra.
* Mỗi locale/format profile có retry chain độc lập. `vi-VTT` hết retry không
  chặn enqueue/ready/retry của `vi-SRT` và không làm binary Media File mất
  `ready`; Media Read Service trả readiness của caption riêng.

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
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints And Indexes

`UNIQUE (customer_id, media_file_id, locale, caption_type)` của Version 1.0 đã
**bị loại bỏ**: nó cho phép đúng một caption cho mỗi file/locale/định dạng, chặn
đúng cơ chế revision mà processing versioning cam kết.

```sql
UNIQUE (customer_id, media_file_id, locale, caption_type, processing_version);
UNIQUE (customer_id, storage_key);
UNIQUE (id, customer_id);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (caption_type IN ('vtt','srt','ass'));
CHECK (status <> 'ready' OR storage_key IS NOT NULL);
```

## Sample Data

`id=600, customer_id=1, media_file_id=100, locale=vi, caption_type=vtt, storage_key=tenants/1/captions/lesson-1-vi.vtt, status=ready`

## Design Notes

Caption thuộc Media processing/delivery; Course/LiveClass chỉ tham chiếu Media File hoặc Usage.
