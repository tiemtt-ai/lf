# Table: media_processing_job_locales

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-03

Document Path: database/media/media_processing_job_locales.md

Related ADR: [ADR-0019](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)

## Purpose

Lưu tập 1–3 locale canonical của một Document processing job. Amendment Audio/
Video multilingual STT đề xuất tái sử dụng bảng này cho `speech_to_text`; cho
tới khi amendment được approve và implemented, runtime hiện hành vẫn chỉ ghi
Document profile. Đây là processing profile, không phải metadata sư phạm của
Media/Course.

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

## STT extension — Approved 2026-09-05

Cho phép `job_type=speech_to_text` sở hữu 2–3 row khi output profile dùng
`locales=<csv>`. Tập locale chỉ là candidate set cho model; language evidence
thật thuộc từng transcript segment. Không đổi schema bảng này, nhưng cần cập
nhật validation/persistence và Database Review trước implementation.
