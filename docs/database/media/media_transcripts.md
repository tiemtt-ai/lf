# Table Name

`media_transcripts`

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
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, locale);
INDEX (customer_id, status);
UNIQUE (customer_id, media_file_id, locale);
```

## Sample Data

`id=500, customer_id=1, media_file_id=100, locale=vi, provider=aws_transcribe, status=ready, text=Nội dung bài giảng..., confidence_score=94.20`

## Design Notes

Foundation giữ một canonical transcript mỗi file/locale; version/provider alternatives là future consideration.
