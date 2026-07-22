# Table: core_assessment_gradings

## Purpose

Lưu grading evidence cho Attempt hoặc Answer, gồm AI suggestion và final grading.

## Relationships

`Attempt 1 → N Gradings`; `Answer 1 → N Gradings`; `Assignment 1 → N Gradings`; `Rubric 1 → N Gradings`.

## Business Rules

* Attempt, optional Answer/Assignment/Rubric và grader phải cùng tenant.
* Answer phải thuộc Attempt; Assignment phải đúng scope.
* Allowed `grading_type`: `teacher`, `ai_suggestion`, `system_auto`.
* AI output chỉ là suggestion; không được tự trở thành final grade.
* Teacher/final grader quyết định `final_decision`; objective auto-grading theo approved rules có thể dùng `system_auto`.
* Khi bắt đầu grading, phải snapshot Rubric và toàn bộ Rubric Items.
* Rubric thay đổi sau đó không được ảnh hưởng bài đã chấm; historical grading
  luôn đọc `rubric_snapshot`, không đọc Rubric authoring hiện tại.
* `confidence_score` chỉ áp dụng khi `grading_type = ai_suggestion`, phải từ
  `0.00` đến `100.00`; các grading type khác để `NULL`.
* Teacher không bắt buộc theo AI; `confidence_score` không phải final score và
  không thay đổi quyền quyết định final grade.
* Final Assessment result được cập nhật qua Assessment service; không update Course Progress trực tiếp.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| attempt_id | BIGINT UNSIGNED NOT NULL | Attempt được chấm. |
| answer_id | BIGINT UNSIGNED NULL | Answer scope. |
| grading_assignment_id | BIGINT UNSIGNED NULL | Assignment nguồn. |
| grader_user_id | BIGINT UNSIGNED NULL | Human grader; NULL cho system/AI. |
| grading_type | VARCHAR(50) NOT NULL | Teacher/AI/system type. |
| rubric_id | BIGINT UNSIGNED NULL | Rubric lineage. |
| rubric_snapshot | JSON NULL | Criteria/weights snapshot. |
| rubric_result | JSON NULL | Điểm/nhận xét từng criterion. |
| score | DECIMAL(10,2) NULL | Điểm đề xuất/final. |
| score_percentage | DECIMAL(5,2) NULL | Phần trăm 0–100. |
| confidence_score | DECIMAL(5,2) NULL | Mức độ tự tin của AI grading, 0–100. |
| feedback | TEXT NULL | Feedback. |
| ai_suggestion | JSON NULL | AI score/feedback/audit suggestion. |
| final_decision | VARCHAR(50) NOT NULL DEFAULT 'pending' | `pending`, `accepted`, `overridden`, `rejected`. |
| graded_at | TIMESTAMP NULL | Thời điểm chấm. |
| metadata | JSON NULL | Provider/model/audit metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, attempt_id);
INDEX (customer_id, answer_id);
INDEX (customer_id, grading_assignment_id);
INDEX (customer_id, grader_user_id);
INDEX (customer_id, grading_type);
INDEX (customer_id, rubric_id);
INDEX (customer_id, final_decision);
```

## Sample Data

`id=3400, customer_id=1, attempt_id=3000, answer_id=3101, grading_assignment_id=3300, grader_user_id=201, grading_type=teacher, rubric_id=4000, rubric_snapshot={"items":[...]}, rubric_result={"total":18}, score=18.00, score_percentage=90.00, confidence_score=NULL, final_decision=accepted, graded_at=2026-07-02T08:00:00Z`

## Design Notes

AI audit should retain provider/model/prompt-version provenance; canonical final-selection rule needs implementation review.
