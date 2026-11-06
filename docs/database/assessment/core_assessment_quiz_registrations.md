# Table: core_assessment_quiz_registrations

Version: 1.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_quiz_registrations.md

## Purpose

Ghi danh một học viên vào một đề: nguồn quyền làm bài khi đề là sản phẩm riêng,
theo [ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D7, D8.
Trang "Đề thi của tôi" đọc từ bảng này.

## Relationships

`Quiz 1 → N Registrations`; `Student 1 → N Registrations`;
`Registration 1 → N Attempts`.

## Business Rules

* Registration, Quiz, học viên và người cấp phải cùng tenant. Học viên phải có
  vai trò `student` (kiểm ở ứng dụng).
* Allowed `source`: `self_registered` (học viên tự đăng ký miễn phí khi đề
  `registration_open`), `granted` (admin hoặc giáo viên cấp). `purchase` và
  nguồn từ khoá học thêm sau.
* `granted_by` có giá trị khi và chỉ khi `source = granted`.
* Chỉ ghi danh vào đề có `current_version_id`. Giáo viên chỉ cấp đề do mình tạo;
  admin cấp mọi đề.
* Mỗi học viên có tối đa một ghi danh `active` cho mỗi đề.
* Số lượt: mỗi ghi danh có `max_attempts` của phiên bản hiện hành tại lúc bắt
  đầu lần làm bài. Bắt đầu lần làm bài khoá dòng ghi danh, kiểm tra
  `attempts_used < max_attempts`, tăng `attempts_used`, cùng một transaction.
  Dùng hết lượt (và không còn lần làm bài `in_progress`) thì chuyển `exhausted`.
* Huỷ đặt `status = cancelled`, `cancelled_at`, `cancelled_by`; chặn bắt đầu lần
  làm bài mới; không xoá hay đổi lần làm bài đã có. Không xoá cứng ghi danh.
* Allowed `status`: `active`, `exhausted`, `cancelled`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Đề được ghi danh. |
| student_id | BIGINT UNSIGNED NOT NULL | Học viên. |
| source | VARCHAR(30) NOT NULL | Nguồn ghi danh. |
| granted_by | BIGINT UNSIGNED NULL | Người cấp khi `source = granted`. |
| status | VARCHAR(20) NOT NULL DEFAULT 'active' | Trạng thái. |
| attempts_used | INT UNSIGNED NOT NULL DEFAULT 0 | Số lần làm bài đã bắt đầu. |
| registered_at | TIMESTAMP NOT NULL | Thời điểm ghi danh. |
| cancelled_at | TIMESTAMP NULL | Thời điểm huỷ. |
| cancelled_by | BIGINT UNSIGNED NULL | Người huỷ. |
| note | VARCHAR(500) NULL | Ghi chú nội bộ của người cấp. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |
| active_flag | TINYINT STORED GENERATED | `1` khi `status = 'active'`, ngược lại NULL; chỉ để làm khoá duy nhất. |

## Constraints

```sql
CHECK (source IN ('self_registered','granted'));
CHECK ((source = 'granted') = (granted_by IS NOT NULL));
CHECK (status IN ('active','exhausted','cancelled'));
CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL));
```

## Indexes

```sql
INDEX (customer_id, quiz_id, status);
INDEX (customer_id, student_id, status);
INDEX (customer_id, granted_by);
UNIQUE (customer_id, quiz_id, student_id, active_flag);
UNIQUE (id, customer_id);
```

`active_flag` theo cùng mẫu `media_file_shares`: MariaDB không có unique index
một phần, NULL không xung đột, nên có nhiều ghi danh cũ và tối đa một ghi danh
`active`.

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (quiz_id, customer_id) REFERENCES core_assessment_quizzes(id, customer_id);
FOREIGN KEY (student_id) REFERENCES users(id);
FOREIGN KEY (granted_by) REFERENCES users(id);
FOREIGN KEY (cancelled_by) REFERENCES users(id);
```

## Sample Data

`id=2700, customer_id=1, quiz_id=2000, student_id=100, source=self_registered, granted_by=NULL, status=active, attempts_used=1`
`id=2701, customer_id=1, quiz_id=2000, student_id=101, source=granted, granted_by=20, status=exhausted, attempts_used=2`

## Design Notes

Không phải Course Enrollment. Khi đề hoặc gói đề được bán hay gắn vào khoá học,
nguồn mới được thêm vào `source` hoặc lần làm bài dùng `enrollment_id`; luồng
thanh toán dùng chung với khoá học, không lưu trong bảng này.
