# Table Name

`core_assessment_quizzes`

## Purpose

Assessment object immutable khi publish, dùng bởi một Course Version Activity.

This table documents the Assessment Phase 2 target contract. It is not evidence
that Course Quiz publishing is currently available; the canonical Course
Content Readiness policy blocks Templates containing Quiz Activities until the
immutable binding is implemented.

## Relationships

`Version Activity 1 → 0..1 Quiz`; `Quiz 1 → N Sections / Quiz Questions / Attempts`; `Product 1 → N Quizzes`.

## Business Rules

* Quiz và Course context phải cùng tenant.
* Không tham chiếu working Template Activity; Version Activity phải có `activity_type = assessment`.
* Allowed `assessment_type`: `quiz`, `exam`, `assignment`, `homework`, `placement_test`, `mock_test`.
* Allowed `grading_mode`: `automatic`, `manual`, `hybrid`.
* Published Quiz và snapshots bất biến cho existing Attempts; thay đổi nội dung cần Quiz mới.
* `pass_score_percentage` từ 0 đến 100; `max_attempts >= 1`.
* Quiz sinh evaluation evidence, không sở hữu Course completion.
* Allowed `status`: `draft`, `published`, `archived`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| product_id | BIGINT UNSIGNED NOT NULL | Course Product context. |
| template_version_id | BIGINT UNSIGNED NOT NULL | Published Version context. |
| version_activity_id | BIGINT UNSIGNED NOT NULL | Version Activity chính. |
| title | VARCHAR(255) NOT NULL | Tên assessment. |
| description | TEXT NULL | Mô tả. |
| assessment_type | VARCHAR(50) NOT NULL | Quiz/exam/assignment type. |
| grading_mode | VARCHAR(50) NOT NULL | Cơ chế chấm. |
| duration_minutes | INT UNSIGNED NULL | Giới hạn thời gian. |
| total_points | DECIMAL(10,2) NOT NULL DEFAULT 0.00 | Tổng điểm snapshot. |
| pass_score_percentage | DECIMAL(5,2) NULL | Ngưỡng pass. |
| max_attempts | INT UNSIGNED NOT NULL DEFAULT 1 | Số Attempt tối đa/Enrollment. |
| shuffle_sections | BOOLEAN NOT NULL DEFAULT false | Trộn sections. |
| shuffle_questions | BOOLEAN NOT NULL DEFAULT false | Trộn questions. |
| show_result_mode | VARCHAR(50) NOT NULL DEFAULT 'after_grading' | Chính sách hiển thị kết quả. |
| status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Lifecycle. |
| metadata | JSON NULL | Cấu hình mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, product_id);
INDEX (customer_id, template_version_id);
INDEX (customer_id, version_activity_id);
INDEX (customer_id, assessment_type);
INDEX (customer_id, status);
UNIQUE (customer_id, version_activity_id);
```

## Sample Data

`id=2000, customer_id=1, product_id=10, template_version_id=30, version_activity_id=9004, title=TOPIK Mock Test, assessment_type=mock_test, grading_mode=hybrid, duration_minutes=60, total_points=100.00, pass_score_percentage=70.00, max_attempts=2, status=published`

## Design Notes

Foundation chọn một Quiz trên một Version Activity. Multi-form/version strategy cần owner review trước implementation.
