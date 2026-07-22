# Table: core_assessment_question_media

## Purpose

Gắn Media File vào Question authoring source.

## Relationships

`Question 1 → N Question Media`; `Media File 1 → N Question Media`.

## Business Rules

* Question và Media File phải cùng tenant.
* Allowed `media_role`: `prompt_image`, `prompt_audio`, `prompt_video`, `attachment`, `explanation_media`.
* Binary, storage, delivery và processing thuộc Media Domain.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu mapping. |
| question_id | BIGINT UNSIGNED NOT NULL | Question sử dụng Media. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Reference tới `media_files`. |
| media_role | VARCHAR(50) NOT NULL | Vai trò Media. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự hiển thị. |
| metadata | JSON NULL | Caption/cấu hình mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, question_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, media_role);
UNIQUE (customer_id, question_id, media_file_id, media_role);
```

## Sample Data

`id=1101, customer_id=1, question_id=100, media_file_id=7001, media_role=prompt_audio, sort_order=1`

## Design Notes

Quiz Question snapshot phải giữ immutable/versioned Media reference cần thiết cho Attempt lịch sử.
