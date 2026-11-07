# Table: core_assessment_questions

Version: 2.1

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_questions.md

> **Thay đổi đề xuất — [ADR-0027](../../adr/ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md) D5 (Approved 2026-11-07, migration
> `2026_11_07_000100`):** thêm cột
> `allow_file_answer BOOLEAN NOT NULL DEFAULT false` ("Cho nộp tệp") và
> `CHECK (allow_file_answer = false OR question_type = 'essay')`. Đổi dạng câu
> khỏi `essay` thì service đặt lại `false`. Nhập / Xuất Excel thêm cột "Cho nộp
> tệp"; file thiếu cột coi là không.

## Purpose

Câu hỏi nguồn do giáo viên hoặc admin soạn bằng form. Câu có thể đứng lẻ hoặc
thuộc một ngữ liệu, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D3–D5. Cột
`rubric_id` theo [ADR-0026](../../adr/ADR-0026-Assessment-Rubric.md) D3
(Approved 2026-11-06).

## Relationships

`Category 1 → N Questions`; `Passage 1 → N Questions`; `Rubric 1 → N Questions`;
`Question 1 → 1 Content` (giai đoạn 1); `Question 1 → N Options / Media`;
`Question 1 → N Quiz Questions / Quiz Version Questions` (lineage).

## Business Rules

* Question, Category, Passage và người tạo phải cùng tenant.
* Allowed `question_type`: `single_choice`, `multiple_choice`, `true_false`,
  `short_answer`, `essay`, `speaking`.
* Allowed `skill_type`: `reading`, `listening`, `writing`, `speaking`, `other`.
* Allowed `difficulty_level`: `easy`, `medium`, `hard`.
* Allowed `status`: `draft`, `published`, `archived`. `draft` có thể chưa đầy
  đủ; chuyển `published` phải hợp lệ theo dạng câu (xem
  `core_assessment_question_options`). Chỉ câu `published` được thêm vào đề và
  được xuất bản; `archived` không thêm vào đề mới.
* `passage_id` và `passage_sort_order` cùng có hoặc cùng NULL; thứ tự duy nhất
  trong ngữ liệu.
* Giáo viên chỉ xem và sửa câu do mình tạo; `customer_admin` xem và sửa mọi câu
  của tenant. Sửa không đổi `created_by`.
* Câu đã nằm trong bất kỳ phiên bản đề nào chỉ được lưu trữ, không xoá cứng.
  Câu chưa từng được xuất bản có thể xoá cứng nếu không nằm trong bản nháp đề nào.
* Câu là nguồn soạn thảo sửa được; sửa không làm đổi phiên bản đề hay bài đã làm.
* `rubric_id` chỉ cho `essay`, `speaking`; rubric cùng tenant. Gắn mới chỉ rubric
  `active` mà người sửa được dùng (ADR-0026 D2); giữ nguyên rubric đã gắn dù nó
  bị lưu trữ. Đổi dạng câu sang dạng khác thì service bỏ `rubric_id`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| category_id | BIGINT UNSIGNED NULL | Danh mục. |
| passage_id | BIGINT UNSIGNED NULL | Ngữ liệu chứa câu; NULL = câu lẻ. |
| passage_sort_order | INT UNSIGNED NULL | Thứ tự trong ngữ liệu. |
| created_by | BIGINT UNSIGNED NOT NULL | Người tạo, quyết định quyền sửa của giáo viên. |
| updated_by | BIGINT UNSIGNED NULL | Người sửa gần nhất. |
| question_type | VARCHAR(30) NOT NULL | Dạng câu. |
| skill_type | VARCHAR(50) NULL | Kỹ năng. |
| difficulty_level | VARCHAR(20) NULL | Độ khó. |
| default_points | DECIMAL(8,2) NOT NULL DEFAULT 1.00 | Điểm mặc định khi thêm vào đề. |
| rubric_id | BIGINT UNSIGNED NULL | Tiêu chí chấm (ADR-0026); NULL = chấm một ô điểm. |
| status | VARCHAR(20) NOT NULL DEFAULT 'draft' | Lifecycle. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (question_type IN ('single_choice','multiple_choice','true_false','short_answer','essay','speaking'));
CHECK (skill_type IS NULL OR skill_type IN ('reading','listening','writing','speaking','other'));
CHECK (difficulty_level IS NULL OR difficulty_level IN ('easy','medium','hard'));
CHECK (status IN ('draft','published','archived'));
CHECK (default_points >= 0);
CHECK ((passage_id IS NULL) = (passage_sort_order IS NULL));
CHECK (rubric_id IS NULL OR question_type IN ('essay','speaking'));
```

## Indexes

```sql
INDEX (customer_id, category_id);
INDEX (customer_id, created_by);
INDEX (customer_id, status);
INDEX (customer_id, question_type);
INDEX (customer_id, skill_type, difficulty_level);
INDEX (customer_id, rubric_id);
UNIQUE (customer_id, passage_id, passage_sort_order);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (category_id, customer_id) REFERENCES core_assessment_categories(id, customer_id);
FOREIGN KEY (passage_id, customer_id) REFERENCES core_assessment_passages(id, customer_id);
FOREIGN KEY (rubric_id, customer_id) REFERENCES core_assessment_rubrics(id, customer_id);
FOREIGN KEY (created_by) REFERENCES users(id);
FOREIGN KEY (updated_by) REFERENCES users(id);
```

## Sample Data

`id=100, customer_id=1, category_id=3, passage_id=50, passage_sort_order=1, created_by=20, question_type=single_choice, skill_type=reading, difficulty_level=medium, default_points=2.50, status=published`
`id=101, customer_id=1, category_id=3, passage_id=NULL, created_by=20, question_type=essay, skill_type=writing, difficulty_level=hard, default_points=10.00, rubric_id=4000, status=published`

## Design Notes

So với ADR-0003: bỏ `question_bank_id` (Question Bank hoãn); thêm danh mục,
ngữ liệu, `updated_by`; `listening` chuyển hẳn sang `skill_type`; độ khó cố định
3 mức để lọc và đề động sau này không phải so chuỗi. Không cần Question Version:
`core_assessment_quiz_version_questions` giữ snapshot.
