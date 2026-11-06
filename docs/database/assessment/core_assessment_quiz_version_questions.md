# Table: core_assessment_quiz_version_questions

Version: 1.1

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_quiz_version_questions.md

## Purpose

Câu hỏi đã đóng băng trong một phiên bản đề: thứ tự, điểm, nội dung, lựa chọn
và đáp án tại lúc xuất bản, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D6. Đây là
nguồn duy nhất để hiển thị đề cho học viên, chấm và xem lại bài. Cột
`rubric_snapshot` theo [ADR-0026](../../adr/ADR-0026-Assessment-Rubric.md) D4
(Approved 2026-11-06).

## Relationships

`Quiz Version 1 → N Version Questions`; `Question 1 → N Version Questions`
(lineage); `Version Question 1 → N Answers`.

## Business Rules

* Version Question và Quiz Version phải cùng tenant.
* Bất biến sau khi tạo; chỉ tạo trong transaction xuất bản.
* `sort_order` là thứ tự toàn đề, duy nhất trong version; `section_key` trỏ tới
  phần trong `quiz_versions.sections_snapshot`.
* `question_id` và `passage_id` chỉ là lineage để thống kê; không đọc lại nguồn
  soạn thảo để hiển thị hay chấm.
* `options_snapshot` gửi cho học viên được; `correct_answer_snapshot` và
  `explanation` trong `question_snapshot` **không bao giờ** gửi cho học viên
  trước khi lần làm bài kết thúc, sau đó chỉ theo `show_result_mode`.
* `requires_manual_grading = true` khi `question_type` là `essay` hoặc
  `speaking`.
* Thứ tự lựa chọn hiển thị theo `options_snapshot`; không trộn ở giai đoạn 1.
* `rubric_snapshot` chụp rubric của câu nguồn trong transaction xuất bản (kể cả
  rubric đã lưu trữ); NULL khi câu không gắn rubric. Chỉ có ở câu chấm tay.
  `key` của tiêu chí là thứ tự 1..n trong snapshot. Gửi cho học viên được
  (không chứa đáp án).

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_version_id | BIGINT UNSIGNED NOT NULL | Phiên bản đề. |
| question_id | BIGINT UNSIGNED NOT NULL | Lineage tới câu hỏi nguồn. |
| passage_id | BIGINT UNSIGNED NULL | Lineage tới ngữ liệu; khoá trong `passages_snapshot`. |
| section_key | INT UNSIGNED NOT NULL | Phần chứa câu. |
| sort_order | INT UNSIGNED NOT NULL | Thứ tự trong đề. |
| question_type | VARCHAR(30) NOT NULL | Dạng câu tại lúc xuất bản. |
| skill_type | VARCHAR(50) NULL | Kỹ năng tại lúc xuất bản. |
| points | DECIMAL(8,2) NOT NULL | Điểm tối đa của câu. |
| requires_manual_grading | BOOLEAN NOT NULL DEFAULT false | Câu cần giáo viên chấm. |
| question_snapshot | JSON NOT NULL | `{"title":...,"prompt":...,"explanation":...,"reference_answer":...,"media":[{"media_file_id":..,"media_role":..}]}`. |
| options_snapshot | JSON NULL | `[{"key":1,"label":"A","text":"..."}]`; NULL với essay/speaking/short_answer. |
| rubric_snapshot | JSON NULL | `{"rubric_id":4000,"title":"...","total_points":50,"items":[{"key":1,"title":"Nội dung","description":"...","max_points":12}]}`. |
| correct_answer_snapshot | JSON NULL | Lựa chọn: `{"option_keys":[1]}`; short_answer: `{"accepted":["seoul"]}`; NULL với essay/speaking. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật (không đổi sau tạo). |

## Constraints

```sql
CHECK (question_type IN ('single_choice','multiple_choice','true_false','short_answer','essay','speaking'));
CHECK (points >= 0);
CHECK (requires_manual_grading = (question_type IN ('essay','speaking')));
CHECK (rubric_snapshot IS NULL OR requires_manual_grading = 1);
```

## Indexes

```sql
INDEX (customer_id, quiz_version_id);
INDEX (customer_id, question_id);
INDEX (customer_id, passage_id);
UNIQUE (customer_id, quiz_version_id, sort_order);
UNIQUE (customer_id, quiz_version_id, question_id);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (quiz_version_id, customer_id) REFERENCES core_assessment_quiz_versions(id, customer_id);
FOREIGN KEY (question_id, customer_id) REFERENCES core_assessment_questions(id, customer_id);
FOREIGN KEY (passage_id, customer_id) REFERENCES core_assessment_passages(id, customer_id);
```

Khoá ngoại tới câu hỏi và ngữ liệu nguồn hợp lệ vì nguồn chỉ được lưu trữ, không
xoá cứng, khi đã nằm trong một phiên bản (ADR-0025 D9).

## Sample Data

`id=2600, customer_id=1, quiz_version_id=2500, question_id=100, passage_id=50, section_key=1, sort_order=1, question_type=single_choice, skill_type=reading, points=2.50, requires_manual_grading=false, options_snapshot=[{"key":1,"label":"A","text":"..."}], correct_answer_snapshot={"option_keys":[1]}`

## Design Notes

`key` của lựa chọn là thứ tự trong snapshot, không phải id của
`core_assessment_question_options`, để câu trả lời không phụ thuộc dữ liệu soạn
thảo. Thay cho các cột snapshot mà ADR-0003 đặt ở `core_assessment_quiz_questions`.
