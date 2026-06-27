# Table Name

`core_assessment_question_contents`

## Purpose

Lưu prompt, explanation và correct-answer authoring text theo locale.

## Relationships

`Question 1 → N Localized Contents`; `Customer 1 → N Question Contents`.

## Business Rules

* Content và Question phải cùng tenant.
* Mỗi Question có tối đa một Content cho một locale; locale theo LF conventions.
* Đây là authoring data và phải được snapshot khi đưa vào Quiz.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| question_id | BIGINT UNSIGNED NOT NULL | Question nguồn. |
| locale | VARCHAR(10) NOT NULL | Locale nội dung. |
| title | VARCHAR(255) NULL | Tiêu đề. |
| prompt | LONGTEXT NOT NULL | Nội dung yêu cầu. |
| explanation | LONGTEXT NULL | Giải thích đáp án. |
| correct_answer_text | TEXT NULL | Đáp án text authoring nếu áp dụng. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, question_id);
INDEX (customer_id, locale);
UNIQUE (customer_id, question_id, locale);
```

## Sample Data

`id=1001, customer_id=1, question_id=100, locale=vi, title=Chọn đáp án, prompt=Chọn cấu trúc đúng, correct_answer_text=NULL`

## Design Notes

Không dùng Content hiện tại để render lại Attempt cũ; dùng Quiz Question snapshot.
