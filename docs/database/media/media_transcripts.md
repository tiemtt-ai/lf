# Table: media_transcripts

Version: 1.1

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-23

Document Path: database/media/media_transcripts.md

## Purpose

Lưu transcript text được tạo từ audio/video Media File.

## Relationships

`Media File 1 → N Localized Transcripts`; `Customer 1 → N Transcripts`.

## Business Rules

* Transcript và Media File phải cùng tenant.
* Transcript text phải nằm trong `text`, không nhét vào metadata.
* `confidence_score` từ `0.00` đến `100.00` khi có.
* Allowed `status`: `pending`, `processing`, `ready`, `failed`, `archived`.
* Transcript là Digital Asset output; AI Domain tự quyết định cách dùng cho knowledge/insight.

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
| locale | VARCHAR(20) NOT NULL | Locale transcript. |
| provider | VARCHAR(100) NULL | Speech-to-text provider. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Processing state. |
| text | LONGTEXT NULL | Nội dung transcript. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100. |
| metadata | JSON NULL | Timing/model provenance, không chứa transcript text. |
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
UNIQUE (customer_id, media_file_id, locale);
```


```sql
UNIQUE (customer_id, media_file_id, locale, locator_type, locator_value,
        processing_version);
UNIQUE (id, customer_id);
INDEX  (customer_id, media_file_id, status);
INDEX  (customer_id, source_fingerprint);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (processing_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','processing','ready','failed','archived'));
CHECK (locator_type IN ('timespan'));
CHECK (confidence_score IS NULL
       OR (confidence_score >= 0 AND confidence_score <= 100));
CHECK (status <> 'ready' OR text IS NOT NULL);
```

## Sample Data

`id=500, customer_id=1, media_file_id=100, locale=vi, provider=aws_transcribe, status=ready, text=Nội dung bài giảng..., confidence_score=94.20`

## Design Notes

Foundation giữ một canonical transcript mỗi file/locale; version/provider alternatives là future consideration.
