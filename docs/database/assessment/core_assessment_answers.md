# Table Name

`core_assessment_answers`

## Purpose

Câu trả lời cho một Quiz Question trong Attempt.

## Relationships

`Attempt 1 → N Answers`; `Quiz Question 1 → N Answers`; `Answer 1 → N Files / Gradings`.

## Business Rules

* Answer, Attempt và Quiz Question phải cùng tenant; Quiz Question thuộc Attempt Quiz.
* Một Attempt có tối đa một Answer cho mỗi Quiz Question.
* `selected_options_snapshot` giữ label/text đã chọn; không dựa vào mutable authoring Options.
* File/audio nằm ở Answer Files và Media Domain.
* Answer score/grading là evaluation evidence, không ghi Course Progress trực tiếp.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| attempt_id | BIGINT UNSIGNED NOT NULL | Attempt cha. |
| quiz_question_id | BIGINT UNSIGNED NOT NULL | Frozen Quiz Question. |
| question_id | BIGINT UNSIGNED NOT NULL | Authoring lineage. |
| answer_type | VARCHAR(50) NOT NULL | Option/text/audio/file/json. |
| selected_option_ids | JSON NULL | IDs được chọn phục vụ lineage. |
| selected_options_snapshot | JSON NULL | Label/text được chọn tại answer time. |
| answer_text | LONGTEXT NULL | Câu trả lời text. |
| answer_json | JSON NULL | Structured answer. |
| score | DECIMAL(10,2) NULL | Điểm evidence. |
| max_score | DECIMAL(10,2) NOT NULL | Điểm tối đa snapshot. |
| is_correct | BOOLEAN NULL | Correctness nếu áp dụng. |
| grading_status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái chấm. |
| feedback | TEXT NULL | Feedback cuối hiện hành. |
| answered_at | TIMESTAMP NULL | Thời điểm trả lời. |
| metadata | JSON NULL | Audit metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, attempt_id);
INDEX (customer_id, quiz_question_id);
INDEX (customer_id, question_id);
INDEX (customer_id, grading_status);
UNIQUE (customer_id, attempt_id, quiz_question_id);
```

## Sample Data

`id=3100, customer_id=1, attempt_id=3000, quiz_question_id=2200, question_id=100, answer_type=option, selected_option_ids=[1201], selected_options_snapshot=[{"label":"A","text":"Đáp án A"}], score=1.00, max_score=1.00, is_correct=true, grading_status=not_required`

## Design Notes

Question context lấy từ immutable Quiz Question snapshot; selected answer snapshot bảo toàn audit.
