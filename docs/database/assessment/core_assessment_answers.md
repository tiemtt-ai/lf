# Table: core_assessment_answers

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_answers.md

## Purpose

Câu trả lời cho một câu của phiên bản đề trong một lần làm bài, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D10, D11.

## Relationships

`Attempt 1 → N Answers`; `Quiz Version Question 1 → N Answers`;
`Answer 1 → N Answer Files / Gradings`.

## Business Rules

* Answer, Attempt và Version Question cùng tenant; Version Question phải thuộc
  `quiz_version_id` của Attempt.
* Một Attempt có tối đa một Answer cho mỗi câu; lưu theo từng câu trong lúc làm
  (upsert), nên mở lại thì làm tiếp.
* Chỉ ghi khi Attempt `in_progress` và chưa quá `deadline_at` (cộng khoảng trễ
  mạng ngắn).
* `answer_type` theo dạng câu: `option` (single/multiple/true_false), `text`
  (short_answer, essay), `audio` (speaking).
* `selected_option_keys` là `key` trong `options_snapshot`; server bỏ key không
  tồn tại. `selected_options_snapshot` chép nhãn và nội dung đã chọn khi nộp.
* **Tự chấm khi nộp** từ `correct_answer_snapshot`:
  * `single_choice`, `true_false`: đúng khi chọn đúng một key đúng.
  * `multiple_choice`: đúng khi tập key chọn **bằng đúng** tập key đúng.
  * `short_answer`: đúng khi câu trả lời, sau khi bỏ khoảng trắng đầu cuối, gộp
    khoảng trắng thừa và không phân biệt hoa thường, bằng một đáp án chấp nhận.
  * Đúng thì `score = max_score`, sai hoặc bỏ trống thì `0`; không có điểm từng
    phần. `grading_status = not_required`.
* Câu `essay`, `speaking`: `grading_status = pending` khi nộp (kể cả bỏ trống);
  điểm lấy từ grading hiện hành.
* Câu không trả lời vẫn có dòng khi nộp (`answer_type` theo dạng câu, nội dung
  rỗng) để thống kê và chấm thống nhất.
* `score` luôn trong `0..max_score`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| attempt_id | BIGINT UNSIGNED NOT NULL | Lần làm bài. |
| quiz_version_question_id | BIGINT UNSIGNED NOT NULL | Câu đã đóng băng. |
| question_id | BIGINT UNSIGNED NOT NULL | Lineage tới câu nguồn. |
| answer_type | VARCHAR(20) NOT NULL | `option`, `text`, `audio`. |
| selected_option_keys | JSON NULL | Key lựa chọn đã chọn. |
| selected_options_snapshot | JSON NULL | Nhãn và nội dung đã chọn. |
| answer_text | LONGTEXT NULL | Câu trả lời văn bản (làm sạch HTML nếu essay dùng editor). |
| score | DECIMAL(10,2) NULL | Điểm hiện hành. |
| max_score | DECIMAL(10,2) NOT NULL | `points` của câu trong phiên bản. |
| is_correct | BOOLEAN NULL | Đúng / sai với câu tự chấm. |
| grading_status | VARCHAR(20) NOT NULL DEFAULT 'not_required' | `not_required`, `pending`, `graded`. |
| feedback | TEXT NULL | Nhận xét hiện hành của giáo viên. |
| answered_at | TIMESTAMP NULL | Lần lưu gần nhất. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (answer_type IN ('option','text','audio'));
CHECK (grading_status IN ('not_required','pending','graded'));
CHECK (max_score >= 0);
CHECK (score IS NULL OR (score >= 0 AND score <= max_score));
```

## Indexes

```sql
INDEX (customer_id, attempt_id);
INDEX (customer_id, quiz_version_question_id);
INDEX (customer_id, question_id);
INDEX (customer_id, grading_status);
UNIQUE (customer_id, attempt_id, quiz_version_question_id);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (attempt_id, customer_id) REFERENCES core_assessment_attempts(id, customer_id);
FOREIGN KEY (quiz_version_question_id, customer_id) REFERENCES core_assessment_quiz_version_questions(id, customer_id);
FOREIGN KEY (question_id, customer_id) REFERENCES core_assessment_questions(id, customer_id);
```

## Sample Data

`id=3100, customer_id=1, attempt_id=3000, quiz_version_question_id=2600, question_id=100, answer_type=option, selected_option_keys=[1], selected_options_snapshot=[{"key":1,"label":"A","text":"..."}], score=2.50, max_score=2.50, is_correct=true, grading_status=not_required`

## Design Notes

So với ADR-0003: tham chiếu `quiz_version_question_id`; lựa chọn lưu theo `key`
của snapshot thay vì id option soạn thảo; bỏ `answer_json`, `metadata`. `score`
và `feedback` là bản sao của grading hiện hành để đọc nhanh; lịch sử ở
`core_assessment_gradings`.
