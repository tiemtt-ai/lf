# Table: core_assessment_gradings

Version: 2.1

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_gradings.md

## Purpose

Lịch sử chấm tay theo từng câu trả lời, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D11. Mỗi lần
chấm hoặc chấm lại là một dòng. Cột `rubric_result` theo
[ADR-0026](../../adr/ADR-0026-Assessment-Rubric.md) D5 (Review, chưa có
migration).

## Relationships

`Attempt 1 → N Gradings`; `Answer 1 → N Gradings`; `User 1 → N Gradings`.

## Business Rules

* Grading, Attempt, Answer cùng tenant; Answer thuộc Attempt.
* Giai đoạn 1 chỉ ghi `grading_type = teacher` cho câu `essay`, `speaking` của
  Attempt đã nộp. Câu tự chấm lưu điểm thẳng trên Answer, không tạo grading.
  `system_auto` dành cho sau này (ví dụ giáo viên sửa điểm câu tự chấm).
* Người chấm được: `customer_admin`; người tạo đề; người đã cấp ghi danh của
  Attempt (khi `source = granted`). Kiểm ở server.
* `0 ≤ score ≤ answers.max_score`, kiểm ở server.
* Ghi một grading mới trong transaction: đặt grading cũ của Answer
  `is_current = false`, thêm dòng mới `is_current = true`, chép `score`,
  `feedback` sang Answer (`grading_status = graded`), tính lại tổng của Attempt
  (xem `core_assessment_attempts`). Không update / delete dòng cũ.
* Một Answer có tối đa một grading hiện hành.
* Câu có `quiz_version_questions.rubric_snapshot`: `rubric_result` bắt buộc, đủ
  mọi `key` của snapshot, mỗi điểm `0 ≤ score ≤ max_points` của tiêu chí; `score`
  = round(tổng điểm tiêu chí ÷ `total_points` × `answers.max_score`, 2). Câu
  không có snapshot: `rubric_result` NULL. Kiểm ở server.
* Không có AI hay phân công chấm.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| attempt_id | BIGINT UNSIGNED NOT NULL | Lần làm bài. |
| answer_id | BIGINT UNSIGNED NOT NULL | Câu trả lời được chấm. |
| grader_user_id | BIGINT UNSIGNED NULL | Người chấm; NULL cho `system_auto`. |
| grading_type | VARCHAR(20) NOT NULL | `teacher`, `system_auto`. |
| score | DECIMAL(10,2) NOT NULL | Điểm. |
| feedback | TEXT NULL | Nhận xét. |
| rubric_result | JSON NULL | Điểm từng tiêu chí: `[{"key":1,"score":10}]`. |
| is_current | BOOLEAN NOT NULL DEFAULT true | Grading hiện hành của Answer. |
| graded_at | TIMESTAMP NOT NULL | Thời điểm chấm. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |
| current_flag | TINYINT STORED GENERATED | `1` khi `is_current`, ngược lại NULL. |

## Constraints

```sql
CHECK (grading_type IN ('teacher','system_auto'));
CHECK ((grading_type = 'teacher') = (grader_user_id IS NOT NULL));
CHECK (score >= 0);
```

Cột JSON trên MariaDB tự có `CHECK (json_valid(rubric_result))`; nội dung
(đủ `key`, điểm trong khoảng) do service kiểm.

## Indexes

```sql
INDEX (customer_id, attempt_id);
INDEX (customer_id, grader_user_id);
UNIQUE (customer_id, answer_id, current_flag);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (attempt_id, customer_id) REFERENCES core_assessment_attempts(id, customer_id);
FOREIGN KEY (answer_id, customer_id) REFERENCES core_assessment_answers(id, customer_id);
FOREIGN KEY (grader_user_id) REFERENCES users(id);
```

## Sample Data

`id=3400, customer_id=1, attempt_id=3000, answer_id=3101, grader_user_id=20, grading_type=teacher, score=8.00, feedback=Bố cục tốt, sai chính tả 3 chỗ., is_current=false`
`id=3401, customer_id=1, attempt_id=3000, answer_id=3101, grader_user_id=20, grading_type=teacher, score=8.50, feedback=Chấm lại sau khi học viên phản hồi., is_current=true`

## Design Notes

So với ADR-0003: bỏ `grading_assignment_id`, `rubric_id`, `rubric_snapshot`,
`confidence_score`, `ai_suggestion`, `final_decision`, `score_percentage`,
`metadata` vì phân công chấm và AI hoãn (ADR-0025), còn rubric đã chụp ở phiên
bản đề nên chỉ cần `rubric_result` (ADR-0026 D4–D5). Khi làm phân công chấm hay
AI, thêm cột bằng migration mới và giữ quy tắc AI chỉ đề xuất của ADR-0003.
`answer_id` bắt buộc vì chấm theo từng câu; "bảng lịch sử, một dòng hiện hành"
thay cho chấm lại ghi đè của question_bank.

Sample có rubric:
`id=3402, customer_id=1, attempt_id=3001, answer_id=3201, grader_user_id=20, grading_type=teacher, score=8.00, rubric_result=[{"key":1,"score":10},{"key":2,"score":10},{"key":3,"score":20}]`
(40/50 điểm tiêu chí, câu 10 điểm trong đề → 8,00).
