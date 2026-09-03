# Table: media_processing_job_locales

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-03

Document Path: database/media/media_processing_job_locales.md

Related ADR: [ADR-0019](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)

## Purpose

Lưu tập 1–3 locale canonical của một Document processing job. Đây là processing
profile, không phải metadata sư phạm của Media/Course.

## Fields and constraints

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | Identity. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| processing_job_id | BIGINT UNSIGNED NOT NULL | Job owner. |
| ordinal | TINYINT UNSIGNED NOT NULL | Canonical order 1..3. |
| locale | VARCHAR(20) NOT NULL | Canonical BCP 47 locale. |
| created_at / updated_at | TIMESTAMP(6) NULL | Audit timestamps. |

```sql
UNIQUE (customer_id, processing_job_id, ordinal);
UNIQUE (customer_id, processing_job_id, locale);
FOREIGN KEY (processing_job_id, customer_id)
  REFERENCES media_processing_jobs (id, customer_id) CASCADE;
CHECK (ordinal BETWEEN 1 AND 3);
```

Rows phải đúng thứ tự canonical trong `output_profile`. Thiếu/thừa/khác locale
làm job invalid trước provider call. Job legacy không có row được đọc từ
`locale=<value>` như profile một locale; không backfill bằng phỏng đoán.
