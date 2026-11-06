# Table: core_assessment_question_topics

Document Path: database/assessment/core_assessment_question_topics.md

> ⏸ **Hoãn theo [ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md)**
> (Approved 2026-11-06): bảng này không nằm trong giai đoạn 1 của module Kiểm
> tra và không có migration. Nội dung dưới đây là thiết kế đích của ADR-0003,
> phải review lại trước khi triển khai.

## Purpose

Mapping many-to-many giữa Questions và Topics.

## Relationships

`Question N ↔ N Topic`; mỗi mapping thuộc một Customer.

## Business Rules

* Question và Topic phải cùng `customer_id`.
* Không tạo mapping trùng.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| question_id | BIGINT UNSIGNED NOT NULL | Question. |
| topic_id | BIGINT UNSIGNED NOT NULL | Topic. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, question_id);
INDEX (customer_id, topic_id);
UNIQUE (customer_id, question_id, topic_id);
```

## Sample Data

`id=1301, customer_id=1, question_id=100, topic_id=20`

## Design Notes

Mapping là authoring metadata; historical Quiz snapshot không phụ thuộc mapping hiện tại.
