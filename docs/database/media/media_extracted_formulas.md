# Table: media_extracted_formulas

Version: 1.2

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-04

Document Path: database/media/media_extracted_formulas.md

Related ADR: [ADR-0019](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)

## Purpose

Lưu evidence công thức quan sát được. Row này không khẳng định công thức đúng
và không chứa lời giải.

## Compatibility boundary after formula-normalization jobs

`raw_text` và quan hệ region tiếp tục là authority của evidence nguồn. Các cột
`normalized_format`, `normalized_value`, `normalization_status` và
`confidence_score` là legacy normalization snapshot: giữ nguyên để đọc revision
đã xuất bản, nhưng producer mới luôn ghi status `unavailable` và ba giá trị còn
lại NULL. Normalization mới chỉ ghi vào `media_formula_normalizations`.

Consumer ưu tiên output hiện hành ở bảng mới; chỉ fallback snapshot cũ khi chưa
có output mới và snapshot tự mang status `ready`. Migration
`2026_09_04_000200` được giữ để dựng lại/đọc legacy `ready + NULL confidence`;
nó không cấp quyền cho producer mới ghi normalization vào bảng evidence.

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
        AND normalized_value IS NOT NULL)
    OR (normalization_status <> 'ready' AND normalized_value IS NULL));
```

Persistence phải xác nhận region cha có `role=formula`, bbox và crop. Producer
structured mới chỉ ghi raw evidence ở trạng thái `unavailable`; lỗi của job
normalization không rollback page text hoặc region evidence.
