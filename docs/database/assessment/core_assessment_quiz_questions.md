# Table: core_assessment_quiz_questions

## Purpose

Map Question vào Quiz Section và đóng băng nội dung/scoring cho lịch sử.

## Relationships

`Quiz 1 → N Quiz Questions`; `Section 1 → N Quiz Questions`; `Question 1 → N Quiz Questions`.

## Business Rules

* Quiz, Section và Question phải cùng tenant; Section phải thuộc Quiz.
* `question_snapshot`, `options_snapshot`, `correct_answer_snapshot` được tạo khi publish và immutable.
* `points >= 0`; `sort_order` duy nhất trong Section.
* Snapshot là source lịch sử của Attempt, không ghi ngược về authoring Question.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Quiz. |
| quiz_section_id | BIGINT UNSIGNED NOT NULL | Section. |
| question_id | BIGINT UNSIGNED NOT NULL | Authoring lineage. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự. |
| points | DECIMAL(8,2) NOT NULL DEFAULT 0.00 | Điểm đóng băng. |
| required | BOOLEAN NOT NULL DEFAULT true | Bắt buộc trả lời. |
| question_snapshot | JSON NOT NULL | Prompt/content/media context snapshot. |
| options_snapshot | JSON NULL | Options label/text snapshot. |
| correct_answer_snapshot | JSON NULL | Answer key snapshot. |
| metadata | JSON NULL | Cấu hình mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, quiz_id);
INDEX (customer_id, quiz_section_id);
INDEX (customer_id, question_id);
UNIQUE (customer_id, quiz_section_id, sort_order);
```

## Sample Data

`id=2200, customer_id=1, quiz_id=2000, quiz_section_id=2100, question_id=100, sort_order=1, points=1.00, required=true, question_snapshot={"type":"single_choice","prompt":"..."}, options_snapshot=[{"label":"A","text":"..."}], correct_answer_snapshot={"labels":["A"]}`

## Design Notes

Snapshot fields là intentional denormalization với source là authoring Question tại publish time; chỉ Assessment runtime/audit dùng.
