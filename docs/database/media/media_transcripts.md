# Table: media_transcripts

Version: 1.4

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-24

Document Path: database/media/media_transcripts.md

## Purpose

Lưu transcript được tạo từ audio/video Media File, theo **từng đoạn trích dẫn**.

Một row là một `timespan`, không phải toàn bộ transcript — cùng nguyên tắc với
`media_extracted_texts` nơi một row là một trang. Ghép các đoạn lại là việc rẻ;
tách một khối văn bản liền thành đoạn có mốc thời gian sau khi đã mất ranh giới
thì không làm được.

## Relationships

```text
media_files 1 → N media_transcripts       (nhiều locale, nhiều đoạn, nhiều revision)
media_processing_jobs 1 → N media_transcripts
```

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
* Mỗi locale/diarization profile có retry chain độc lập. Một transcript locale
  hết retry không chặn enqueue/ready/retry của locale khác và không làm binary
  Media File mất `ready`; Media Read Service trả readiness của transcript riêng.

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

`UNIQUE (customer_id, media_file_id, locale)` của Version 1.0 đã **bị loại bỏ**.
Nó cho phép đúng một transcript cho mỗi file và locale, mâu thuẫn trực tiếp với
hai điều Version 1.2 cam kết: một row là một đoạn, và processing version mới
sinh revision mới thay vì ghi đè.

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

Version 1.0 giữ một canonical transcript cho mỗi file/locale và coi
version/provider alternatives là chuyện tương lai. Processing versioning đã biến
"tương lai" thành hợp đồng hiện hành, nên giả định đó không còn đúng: nhiều
revision cùng tồn tại, bản cũ `archived`, và bản `archived` phải đọc được vĩnh
viễn để một trích dẫn cũ vẫn trỏ đúng chỗ.
