# Table: core_assessment_quizzes

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_quizzes.md

## Purpose

Bản nháp đề / bài tập: tài sản độc lập của tenant, sửa được, dùng lại được, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D6. Học viên
không bao giờ làm bài trực tiếp trên bản nháp; họ làm trên một phiên bản đã xuất
bản (`core_assessment_quiz_versions`).

## Relationships

`Category 1 → N Quizzes`; `Quiz 1 → N Sections / Quiz Questions` (bản nháp);
`Quiz 1 → N Quiz Versions`; `Quiz 1 → N Registrations`.

## Business Rules

* Quiz, Category và người tạo phải cùng tenant.
* Đề và bài tập là cùng một thực thể. Allowed `assessment_type`: `exercise`,
  `exam` (chỉ là nhãn lọc). `duration_minutes` NULL = không giới hạn thời gian.
* Allowed `show_result_mode`: `immediately`, `after_grading`, `score_only`,
  `hidden`.
* Allowed `status`: `draft` (chưa có phiên bản), `published` (có ít nhất một
  phiên bản), `archived` (không ghi danh mới; ghi danh và bài đã có giữ nguyên).
* Sửa bản nháp (cài đặt, phần, câu) không đổi phiên bản đã có; phải *Xuất bản*
  để tạo phiên bản mới. `content_updated_at` cập nhật mỗi lần sửa bản nháp để
  giao diện báo "có thay đổi chưa xuất bản" khi lớn hơn `published_at` của
  phiên bản hiện hành.
* `registration_open = true` chỉ khi đề có `current_version_id` và không
  `archived`. Đề mở đăng ký hiện ở mục *Assessments* của website tenant chỉ với
  thông tin giới thiệu (tên, mô tả, loại, thời gian, số câu, số lượt), không có
  câu hỏi hay đáp án.
* Giáo viên chỉ xem và sửa đề do mình tạo; `customer_admin` xem và sửa mọi đề
  của tenant. Sửa không đổi `created_by`.
* Đề đã có phiên bản chỉ được lưu trữ, không xoá cứng.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| category_id | BIGINT UNSIGNED NULL | Danh mục. |
| created_by | BIGINT UNSIGNED NOT NULL | Người tạo. |
| updated_by | BIGINT UNSIGNED NULL | Người sửa gần nhất. |
| title | VARCHAR(255) NOT NULL | Tên đề. |
| description | TEXT NULL | Giới thiệu đề. |
| assessment_type | VARCHAR(20) NOT NULL DEFAULT 'exercise' | Bài tập / đề thi. |
| duration_minutes | INT UNSIGNED NULL | Giới hạn thời gian. |
| max_attempts | INT UNSIGNED NOT NULL DEFAULT 1 | Số lượt cho mỗi ghi danh. |
| pass_score_percentage | DECIMAL(5,2) NULL | Ngưỡng đạt; NULL = không xét đạt. |
| show_result_mode | VARCHAR(20) NOT NULL DEFAULT 'after_grading' | Cách hiện kết quả. |
| registration_open | BOOLEAN NOT NULL DEFAULT false | Cho học viên tự đăng ký. |
| current_version_id | BIGINT UNSIGNED NULL | Phiên bản hiện hành. |
| content_updated_at | TIMESTAMP NULL | Lần sửa bản nháp gần nhất. |
| status | VARCHAR(20) NOT NULL DEFAULT 'draft' | Lifecycle. |
| metadata | JSON NULL | Cấu hình mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (assessment_type IN ('exercise','exam'));
CHECK (duration_minutes IS NULL OR duration_minutes >= 1);
CHECK (max_attempts >= 1);
CHECK (pass_score_percentage IS NULL OR (pass_score_percentage >= 0 AND pass_score_percentage <= 100));
CHECK (show_result_mode IN ('immediately','after_grading','score_only','hidden'));
CHECK (status IN ('draft','published','archived'));
CHECK ((status = 'draft') = (current_version_id IS NULL));
CHECK (registration_open = 0 OR (current_version_id IS NOT NULL AND status <> 'archived'));
```

## Indexes

```sql
INDEX (customer_id, category_id);
INDEX (customer_id, created_by);
INDEX (customer_id, status);
INDEX (customer_id, registration_open, status);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (category_id, customer_id) REFERENCES core_assessment_categories(id, customer_id);
FOREIGN KEY (current_version_id, customer_id) REFERENCES core_assessment_quiz_versions(id, customer_id);
FOREIGN KEY (created_by) REFERENCES users(id);
FOREIGN KEY (updated_by) REFERENCES users(id);
```

`current_version_id` và `core_assessment_quiz_versions.quiz_id` tham chiếu vòng;
migration tạo khoá `current_version_id` sau khi tạo bảng versions.

## Sample Data

`id=2000, customer_id=1, category_id=1, created_by=20, title=TOPIK I - Đề 01, assessment_type=exam, duration_minutes=60, max_attempts=2, pass_score_percentage=70.00, show_result_mode=after_grading, registration_open=true, current_version_id=2500, status=published`

## Design Notes

So với ADR-0003: bỏ `product_id`, `template_version_id`, `version_activity_id`
(gắn khoá học làm sau), `total_points` (tính từ câu; giá trị chốt nằm ở phiên
bản), `shuffle_sections`, `shuffle_questions` (không trộn ở giai đoạn 1). Bảng
không còn là snapshot bất biến; vai trò đó chuyển sang
`core_assessment_quiz_versions`.
