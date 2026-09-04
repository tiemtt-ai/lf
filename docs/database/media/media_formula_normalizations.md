# Table: media_formula_normalizations

Version: 1.0

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-09-04

Document Path: database/media/media_formula_normalizations.md

Related ADR: [ADR-0019](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)

## Purpose

Lưu output bất biến của job `formula_normalization`. Output được dựng từ một
formula evidence thuộc structured revision đã xuất bản; không sửa ngầm
`media_extracted_formulas`.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | Identity. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media nguồn. |
| processing_job_id | BIGINT UNSIGNED NOT NULL | Job normalization sở hữu output. |
| formula_id | BIGINT UNSIGNED NOT NULL | Formula evidence nguồn. |
| source_processing_job_id | BIGINT UNSIGNED NOT NULL | Structured revision nguồn. |
| normalized_format | VARCHAR(20) NOT NULL | `latex` hoặc `mathml`. |
| normalized_value | LONGTEXT NOT NULL | Output do provider phát. |
| confidence_score | DECIMAL(5,2) NULL | NULL khi provider không phát confidence. |
| provider | VARCHAR(100) NOT NULL | Provider thực thi. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản normalization. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay binary nguồn. |
| metadata | JSON NULL | Provenance kỹ thuật, không chứa diễn giải. |
| created_at / updated_at | TIMESTAMP(6) NULL | Audit timestamps. |

```sql
UNIQUE (customer_id, processing_job_id, formula_id);
UNIQUE (id, customer_id);
FOREIGN KEY (processing_job_id, customer_id)
  REFERENCES media_processing_jobs (id, customer_id) RESTRICT;
FOREIGN KEY (formula_id, customer_id, media_file_id, source_processing_job_id)
  REFERENCES media_extracted_formulas
    (id, customer_id, media_file_id, processing_job_id) RESTRICT;
CHECK (normalized_format IN ('latex','mathml'));
CHECK (normalized_value <> '');
CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100);
```

## Lifecycle invariants

Job chỉ được tạo khi revision structured hiện hành có ít nhất một formula row
đủ bbox/crop. Một job xử lý toàn bộ crop của đúng một source processing job và
load model một lần. Tài liệu không có formula không tạo job và không load model.

Khi persist, source job và formula vẫn phải `ready`, cùng tenant/media,
fingerprint và đúng structured revision hiện hành. Callback muộn hoặc source đã
stale bị từ chối nguyên tử. Retry tạo processing job/output mới; output đã xuất
bản không bị update. Xóa/purge tuân retention của revision nguồn, không cascade
âm thầm qua một callback.

## Current selector

Với một formula/source structured revision, output hiện hành là row thuộc job
`ready` có `processing_job_id` lớn nhất. Mỗi job có tối đa một output cho một
formula. Retry tạo job mới, không update output cũ. Khi consumer chọn explicit
structured `processing_version`, selector chỉ xét output có
`source_processing_job_id` đúng job nguồn đó.
