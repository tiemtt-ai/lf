# Table: core_assessment_passages

Version: 1.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_passages.md

## Purpose

Ngữ liệu dùng chung: một đoạn đọc, bài nghe hoặc video có nhiều câu hỏi con,
theo [ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D4.
Trên giao diện gọi là **Ngữ liệu**.

## Relationships

`Customer 1 → N Passages`; `Category 1 → N Passages`; `Passage 1 → N Questions`;
`Passage 1 → N Question Media` (media của ngữ liệu).

## Business Rules

* Passage, Category và người tạo phải cùng tenant.
* Allowed `status`: `draft`, `published`, `archived`. Chỉ ngữ liệu `published`
  được thêm vào đề; `archived` không thêm vào đề mới.
* Giáo viên chỉ xem và sửa ngữ liệu do mình tạo; `customer_admin` xem và sửa mọi
  ngữ liệu của tenant. Sửa không đổi `created_by`.
* Ngữ liệu có câu con nằm trong bất kỳ phiên bản đề nào chỉ được lưu trữ, không
  xoá cứng.
* `content` là HTML từ editor, được làm sạch ở server trước khi lưu.
* Media (ảnh, audio, video) gắn qua `core_assessment_question_media` với
  `passage_id`; file thuộc Media Domain.
* Thứ tự câu con nằm ở `core_assessment_questions.passage_sort_order`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| category_id | BIGINT UNSIGNED NULL | Danh mục. |
| created_by | BIGINT UNSIGNED NOT NULL | Người tạo, quyết định quyền sửa của giáo viên. |
| updated_by | BIGINT UNSIGNED NULL | Người sửa gần nhất. |
| title | VARCHAR(255) NOT NULL | Tiêu đề hiển thị trong danh sách. |
| content | LONGTEXT NULL | Nội dung đoạn đọc hoặc lời dẫn / transcript. |
| skill_type | VARCHAR(50) NULL | `reading`, `listening`, `writing`, `speaking`, `other`. |
| difficulty_level | VARCHAR(20) NULL | `easy`, `medium`, `hard`. |
| status | VARCHAR(20) NOT NULL DEFAULT 'draft' | Lifecycle. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (status IN ('draft','published','archived'));
CHECK (skill_type IS NULL OR skill_type IN ('reading','listening','writing','speaking','other'));
CHECK (difficulty_level IS NULL OR difficulty_level IN ('easy','medium','hard'));
```

## Indexes

```sql
INDEX (customer_id, category_id);
INDEX (customer_id, created_by);
INDEX (customer_id, status);
INDEX (customer_id, skill_type);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (category_id, customer_id) REFERENCES core_assessment_categories(id, customer_id);
FOREIGN KEY (created_by) REFERENCES users(id);
FOREIGN KEY (updated_by) REFERENCES users(id);
```

## Sample Data

`id=50, customer_id=1, category_id=3, created_by=20, title=Đoạn đọc: Mùa thu Seoul, skill_type=reading, difficulty_level=medium, status=published`

## Design Notes

Ngữ liệu là nguồn soạn thảo sửa được. Phiên bản đề giữ snapshot ngữ liệu trong
`core_assessment_quiz_versions.passages_snapshot`, nên sửa ngữ liệu không làm
đổi đề đã xuất bản hay bài đã làm.
