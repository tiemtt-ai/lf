# Table: core_assessment_question_options

Document Path: database/assessment/core_assessment_question_options.md

## Purpose

Lưu lựa chọn authoring cho câu hỏi choice/true-false.

## Relationships

`Question 1 → N Options`; `Customer 1 → N Options`.

## Business Rules

* Option và Question phải cùng tenant.
* Dùng cho `single_choice`, `multiple_choice`, `true_false`.
* `is_correct` là authoring answer key; không đọc trực tiếp để audit Attempt cũ.
* Mỗi Question/locale có `sort_order` duy nhất.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| question_id | BIGINT UNSIGNED NOT NULL | Question nguồn. |
| locale | VARCHAR(10) NOT NULL | Locale lựa chọn. |
| option_text | TEXT NOT NULL | Nội dung lựa chọn. |
| option_label | VARCHAR(50) NULL | Nhãn A/B/C hoặc tương đương. |
| is_correct | BOOLEAN NOT NULL DEFAULT false | Đáp án đúng authoring. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự. |
| feedback | TEXT NULL | Feedback theo lựa chọn. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, question_id);
INDEX (customer_id, question_id, locale);
UNIQUE (customer_id, question_id, locale, sort_order);
```

## Sample Data

`id=1201, customer_id=1, question_id=100, locale=vi, option_text=Đáp án A, option_label=A, is_correct=true, sort_order=1`

## Design Notes

Quiz Question snapshot giữ option set; Answer snapshot label/text đã chọn để chống authoring drift.
