# Table: core_assessment_question_contents

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_question_contents.md

## Purpose

Nội dung soạn thảo của câu hỏi: đề bài, giải thích và đáp án mẫu.

## Relationships

`Question 1 → N Contents` (giai đoạn 1: đúng một).

## Business Rules

* Content và Question phải cùng tenant.
* Giai đoạn 1 ([ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md)
  D5): mỗi câu có đúng một content, `locale` = locale mặc định của tenant; giao
  diện không hiện chọn ngôn ngữ. Cột `locale` giữ để mở rộng.
* `prompt` và `explanation` là HTML từ editor, được làm sạch ở server trước khi
  lưu.
* `reference_answer` là đáp án mẫu cho người chấm `essay` / `speaking`; không
  dùng để tự chấm. Đáp án chấp nhận của `short_answer` nằm ở
  `core_assessment_question_options`.
* Đây là dữ liệu soạn thảo; được snapshot vào phiên bản đề khi xuất bản.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| question_id | BIGINT UNSIGNED NOT NULL | Câu hỏi. |
| locale | VARCHAR(10) NOT NULL | Locale nội dung. |
| title | VARCHAR(255) NULL | Tiêu đề ngắn hiển thị trong danh sách. |
| prompt | LONGTEXT NOT NULL | Đề bài. |
| explanation | LONGTEXT NULL | Giải thích đáp án. |
| reference_answer | LONGTEXT NULL | Đáp án mẫu cho người chấm. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id, question_id);
UNIQUE (customer_id, question_id, locale);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (question_id, customer_id) REFERENCES core_assessment_questions(id, customer_id) ON DELETE CASCADE;
```

## Sample Data

`id=1001, customer_id=1, question_id=100, locale=vi, title=Ý chính đoạn văn, prompt=<p>Ý chính của đoạn văn là gì?</p>, explanation=<p>...</p>`

## Design Notes

So với ADR-0003: `correct_answer_text` đổi thành `reference_answer` vì cột này
chỉ hỗ trợ người chấm; đáp án tự chấm nằm ở options để snapshot cùng một đường.
