# Table: core_assessment_grader_scopes

Version: 0.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-07

Document Path: database/assessment/core_assessment_grader_scopes.md

## Purpose

Giáo viên chấm theo danh mục hoặc theo đề ("Giao chấm đề"), theo
[ADR-0027](../../adr/ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md)
D1–D2. Một dòng cho một giáo viên quyền xem và chấm mọi bài làm thuộc phạm vi đó.

## Relationships

`Category 1 → N Grader Scopes`; `Quiz 1 → N Grader Scopes`;
`User 1 → N Grader Scopes` (giáo viên chấm, người gắn).

## Business Rules

* Scope, danh mục / đề, giáo viên và người gắn cùng tenant (service kiểm
  `users.customer_id`).
* Mỗi dòng đúng một phạm vi: `category_id` hoặc `quiz_id`.
* ADR-0027 Amendment 1.2 (2026-11-07): ứng dụng chỉ ghi và đọc dòng `quiz_id`.
  Dòng `category_id` cũ được `assessment:copy-category-graders` chép thành dòng
  `quiz_id` rồi giữ làm lịch sử; schema chưa đổi, dọn cột cần database docs riêng.
* `user_id` phải là `teacher` đang hoạt động khi gắn. Đổi vai trò hoặc khoá tài
  khoản sau đó thì dòng còn nhưng không có hiệu lực (quyền xét lúc truy cập).
* Phạm vi danh mục có hiệu lực cho đề thuộc danh mục đó và mọi danh mục con
  (service đi theo cây `parent_id`).
* Phạm vi danh mục: chỉ `customer_admin` thêm / xoá. Phạm vi đề: `customer_admin`
  và người tạo đề (`quizzes.created_by`). Gỡ là xoá dòng; lịch sử chấm nằm ở
  `core_assessment_gradings`, không mất.
* Xoá cứng danh mục hoặc đề (khi luật của chúng cho phép) xoá luôn các dòng này.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| category_id | BIGINT UNSIGNED NULL | Danh mục; NULL khi gắn theo đề. |
| quiz_id | BIGINT UNSIGNED NULL | Đề; NULL khi gắn theo danh mục. |
| user_id | BIGINT UNSIGNED NOT NULL | Giáo viên chấm. |
| created_by | BIGINT UNSIGNED NOT NULL | Người đã gắn (admin hoặc người tạo đề). |
| created_at | TIMESTAMP NULL | Thời điểm gắn. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK ((category_id IS NULL) <> (quiz_id IS NULL));
```

## Indexes

```sql
UNIQUE (customer_id, category_id, user_id);
UNIQUE (customer_id, quiz_id, user_id);
INDEX (customer_id, user_id);
```

Hai unique trên không chặn trùng ở cột NULL; vì CHECK bắt đúng một cột có giá
trị nên mỗi dòng luôn bị đúng một unique ràng buộc.

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (category_id, customer_id) REFERENCES core_assessment_categories(id, customer_id) ON DELETE CASCADE;
FOREIGN KEY (quiz_id, customer_id) REFERENCES core_assessment_quizzes(id, customer_id) ON DELETE CASCADE;
FOREIGN KEY (user_id) REFERENCES users(id);
FOREIGN KEY (created_by) REFERENCES users(id);
```

## Sample Data

`id=4500, customer_id=1, category_id=10, quiz_id=NULL, user_id=201, created_by=1`
`id=4501, customer_id=1, category_id=NULL, quiz_id=2000, user_id=202, created_by=1`

## Design Notes

Một bảng cho hai phạm vi thay vì hai bảng giống hệt nhau (ADR-0027 Alternatives
Rejected). Không có cờ trạng thái: gỡ là xoá, như các bảng gắn quan hệ khác.
