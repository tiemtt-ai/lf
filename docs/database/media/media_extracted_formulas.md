# Table: media_extracted_formulas

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-03

Document Path: database/media/media_extracted_formulas.md

Related ADR: [ADR-0019](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)

## Purpose

Lưu evidence công thức quan sát được và normalization tùy chọn. Row này không
khẳng định công thức đúng và không chứa lời giải.

## Ownership and provenance

Mỗi region `role=formula` có tối đa một row. Formula kế thừa tenant, Media,
processing job, language profile, source fingerprint, processing version,
page/bbox/locator và crop từ region cha. FK composite ngăn trỏ chéo revision.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | Identity. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Source Media. |
| processing_job_id | BIGINT UNSIGNED NOT NULL | Structured run. |
| region_id | BIGINT UNSIGNED NOT NULL | Formula region nguồn. |
| raw_text | LONGTEXT NULL | OCR/text quan sát được. |
| normalized_format | VARCHAR(20) NULL | `latex` hoặc `mathml`. |
| normalized_value | LONGTEXT NULL | Representation do provider tạo. |
| normalization_status | VARCHAR(20) NOT NULL | `unavailable`, `ready`, `failed`. |
| confidence_score | DECIMAL(5,2) NULL | Confidence 0–100. |
| metadata | JSON NULL | Provenance không chứa diễn giải. |
| created_at / updated_at | TIMESTAMP(6) NULL | Audit timestamps. |

```sql
UNIQUE (customer_id, region_id);
FOREIGN KEY (region_id, customer_id, media_file_id, processing_job_id)
  REFERENCES media_extracted_regions
    (id, customer_id, media_file_id, processing_job_id) CASCADE;
CHECK (normalized_format IS NULL OR normalized_format IN ('latex','mathml'));
CHECK (normalization_status IN ('unavailable','ready','failed'));
CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100);
CHECK ((normalization_status = 'ready' AND normalized_format IS NOT NULL
        AND normalized_value IS NOT NULL AND confidence_score IS NOT NULL)
    OR (normalization_status <> 'ready' AND normalized_value IS NULL));
```

Persistence phải xác nhận region cha có `role=formula`, bbox và crop. Normalized
confidence dưới ngưỡng cấu hình được lưu `failed`, không `ready`. Provider chưa
có normalization được lưu `unavailable`. Lỗi
normalization không rollback page text hoặc region evidence.
