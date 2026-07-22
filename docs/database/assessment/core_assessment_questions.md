# Table: core_assessment_questions

## Purpose

Đơn vị câu hỏi gốc trong Question Bank.

## Relationships

`Bank 1 → N Questions`; `Question 1 → N Contents / Media / Options`; `Question N ↔ N Topics`.

## Business Rules

* Question, Bank và creator phải cùng tenant.
* Allowed `question_type`: `single_choice`, `multiple_choice`, `true_false`, `short_answer`, `essay`, `speaking`, `listening`, `file_upload`.
* `default_points >= 0`. Allowed `status`: `draft`, `published`, `archived`.
* `draft` cho phép Teacher chỉnh sửa; chỉ `published` Question được phép dùng
  cho Quiz mới.
* `archived` Question không được dùng cho Quiz mới.
* Published Question vẫn là mutable authoring source và không cần Question
  Version.
* Question là mutable authoring source; thay đổi không được làm đổi Quiz/Attempt lịch sử.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| question_bank_id | BIGINT UNSIGNED NOT NULL | Bank chứa Question. |
| created_by | BIGINT UNSIGNED NOT NULL | User tạo. |
| question_type | VARCHAR(50) NOT NULL | Loại câu hỏi. |
| skill_type | VARCHAR(50) NULL | Listening/speaking/reading/writing hoặc skill khác. |
| difficulty_level | VARCHAR(50) NULL | Mức độ khó. |
| default_points | DECIMAL(8,2) NOT NULL DEFAULT 0.00 | Điểm mặc định. |
| status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Lifecycle `draft`, `published`, `archived`. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, question_bank_id);
INDEX (customer_id, created_by);
INDEX (customer_id, question_type);
INDEX (customer_id, skill_type);
INDEX (customer_id, status);
```

## Sample Data

`id=100, customer_id=1, question_bank_id=10, created_by=200, question_type=single_choice, skill_type=reading, difficulty_level=beginner, default_points=1.00, status=published`

## Design Notes

Không cần Question Version. `core_assessment_quiz_questions` snapshot
Question/options/correct answer; vì vậy thay đổi Published Question không ảnh
hưởng Quiz hoặc Attempt lịch sử.
