# Table: core_assessment_quiz_questions

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_quiz_questions.md

## Purpose

Câu được chọn vào bản nháp đề, kèm thứ tự và điểm, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D6. Không
chứa snapshot.

## Relationships

`Quiz 1 → N Quiz Questions`; `Section 1 → N Quiz Questions`;
`Question 1 → N Quiz Questions`.

## Business Rules

* Quiz, Section và Question phải cùng tenant; Section thuộc Quiz.
* Ghép đề giai đoạn 1 là thủ công: chọn câu lẻ, hoặc chọn ngữ liệu để thêm mọi
  câu con `published` của ngữ liệu theo thứ tự `passage_sort_order`. Có thể bỏ
  bớt câu con.
* Chỉ thêm câu `published`, chưa có trong đề (một câu tối đa một lần mỗi đề).
* `points` mặc định lấy `default_points` của câu; giáo viên sửa được. `points >= 0`.
* `sort_order` duy nhất trong phần. Câu cùng ngữ liệu phải nằm liền nhau trong
  cùng một phần; kiểm tra khi xuất bản.
* Giáo viên chỉ thêm câu mình được xem (câu của mình; admin thì mọi câu).
* Sửa bảng này cập nhật `core_assessment_quizzes.content_updated_at`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Đề. |
| quiz_section_id | BIGINT UNSIGNED NOT NULL | Phần. |
| question_id | BIGINT UNSIGNED NOT NULL | Câu hỏi. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự trong phần. |
| points | DECIMAL(8,2) NOT NULL | Điểm của câu trong đề. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (points >= 0);
```

## Indexes

```sql
INDEX (customer_id, quiz_id);
INDEX (customer_id, question_id);
UNIQUE (customer_id, quiz_section_id, sort_order);
UNIQUE (customer_id, quiz_id, question_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (quiz_id, customer_id) REFERENCES core_assessment_quizzes(id, customer_id) ON DELETE CASCADE;
FOREIGN KEY (quiz_section_id, customer_id) REFERENCES core_assessment_quiz_sections(id, customer_id) ON DELETE CASCADE;
FOREIGN KEY (question_id, customer_id) REFERENCES core_assessment_questions(id, customer_id);
```

Việc phần thuộc đúng đề do ứng dụng kiểm tra trong cùng transaction.

## Sample Data

`id=2200, customer_id=1, quiz_id=2000, quiz_section_id=2100, question_id=100, sort_order=1, points=2.50`

## Design Notes

So với ADR-0003: bỏ `required`, `question_snapshot`, `options_snapshot`,
`correct_answer_snapshot`, `metadata`. Snapshot chuyển sang
`core_assessment_quiz_version_questions`.
