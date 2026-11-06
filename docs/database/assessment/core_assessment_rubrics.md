# Table: core_assessment_rubrics

Version: 2.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_rubrics.md

## Purpose

Bộ tiêu chí chấm dùng lại được ("Tiêu chí chấm") cho câu tự luận và câu nói,
theo [ADR-0026](../../adr/ADR-0026-Assessment-Rubric.md) D1–D2. Đây là nguồn
soạn thảo; chấm và xem lại đọc `quiz_version_questions.rubric_snapshot`.

## Relationships

`Customer 1 → N Rubrics`; `Rubric 1 → N Rubric Items`;
`Rubric 1 → N Questions`; `User 1 → N Rubrics` (người tạo).

## Business Rules

* Rubric, Item và người tạo cùng tenant.
* Allowed `status`: `active`, `archived`. Chỉ rubric `active` gắn mới được vào
  câu hỏi; câu đã gắn rubric `archived` vẫn giữ và vẫn xuất bản được.
* Lưu rubric phải có ít nhất một tiêu chí; `total_points` = tổng `max_points`
  của các tiêu chí, service tính lại mỗi lần lưu trong cùng transaction.
* Admin (`customer_admin`) xem, sửa, lưu trữ mọi rubric của tenant. Giáo viên
  sửa rubric do mình tạo; chọn được rubric của mình và rubric có người tạo là
  `customer_admin`. Sửa không đổi `created_by`.
* Rubric đang được câu hỏi tham chiếu không xoá cứng được (khoá ngoại
  `RESTRICT`), chỉ lưu trữ.
* Sửa rubric không ảnh hưởng phiên bản đề đã xuất bản và bài đã chấm.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| created_by | BIGINT UNSIGNED NOT NULL | Người tạo, quyết định quyền sửa và ai dùng được. |
| updated_by | BIGINT UNSIGNED NULL | Người sửa gần nhất. |
| title | VARCHAR(255) NOT NULL | Tên rubric. |
| description | TEXT NULL | Mô tả, hướng dẫn chung. |
| skill_type | VARCHAR(50) NULL | Kỹ năng áp dụng (để lọc). |
| total_points | DECIMAL(10,2) NOT NULL | Tổng điểm tối đa các tiêu chí. |
| status | VARCHAR(20) NOT NULL DEFAULT 'active' | Trạng thái. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (status IN ('active','archived'));
CHECK (skill_type IS NULL OR skill_type IN ('reading','listening','writing','speaking','other'));
CHECK (total_points > 0);
```

## Indexes

```sql
INDEX (customer_id, status);
INDEX (customer_id, created_by);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (created_by) REFERENCES users(id);
FOREIGN KEY (updated_by) REFERENCES users(id);
```

## Sample Data

`id=4000, customer_id=1, created_by=1, title=Viết TOPIK II câu 54, skill_type=writing, total_points=50.00, status=active`

## Design Notes

So với ADR-0003: bỏ `draft` (tiêu chí kiểm khi lưu) và `metadata`; thêm
`created_by`, `updated_by` để phân quyền như câu hỏi. Snapshot không nằm ở bảng
này mà ở phiên bản đề (ADR-0026 D4).
