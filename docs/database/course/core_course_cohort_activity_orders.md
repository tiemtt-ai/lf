# Table: core_course_cohort_activity_orders

Version: 1.2

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-11-05

Document Path: database/course/core_course_cohort_activity_orders.md

Governing ADR: [ADR-0023 — Cohort Content Order](../../adr/ADR-0023-Cohort-Content-Order.md) (Approved)

---

## Purpose

Lưu thứ tự Activity riêng của một Cohort. Phạm vi sắp xếp: Activity trong cùng Lesson.

Lớp không có dòng nào trong một phạm vi thì dùng thứ tự của Version. Bảng không
thay đổi nội dung, quyền truy cập hay tiến độ.

---

## Columns

| Column | Type | Null | Ghi chú |
|--------|------|------|---------|
| id | bigint unsigned | No | Khóa chính |
| customer_id | bigint unsigned | No | Tenant sở hữu |
| cohort_id | bigint unsigned | No | Lớp sở hữu thứ tự |
| version_activity_id | bigint unsigned | No | Activity của Version mà lớp đã khóa |
| sort_order | int unsigned | No | Vị trí trong phạm vi cùng cấp |
| updated_by | bigint unsigned | Yes | Người sắp xếp gần nhất; `NULL` khi tài khoản đã bị xóa |
| created_at | timestamp | Yes | |
| updated_at | timestamp | Yes | |

---

## Constraints

* Unique `uk_ccao_cohort_item`: `customer_id, cohort_id, version_activity_id`.
* `fk_ccao_customer`: `customer_id` → `saas_customers.id`, restrict.
* `fk_ccao_cohort`: `cohort_id, customer_id` → `core_course_cohorts(id, customer_id)`
  qua `uk_core_course_cohorts_id_customer`, cascade. Đây là ngoại lệ có chủ đích: chỉ lớp nháp
  rỗng mới bị xóa cứng, và dòng thứ tự không tính là record của lớp.
* `fk_ccao_item`: `version_activity_id` → `core_course_template_version_activities.id`, restrict. Bảng Version chưa có khóa kép, nên
  việc mục thuộc đúng Version và tenant do backend kiểm tra.
* `fk_ccao_updated_by`: `updated_by` → `users.id`, set null.
* Không dùng unique trên `sort_order`, để có thể ghi lại cả phạm vi trong một
  transaction. Thứ tự bằng nhau được phân định bằng `version_activity_id`.

---

## Rules

* Mỗi lần sắp xếp ghi đủ toàn bộ mục cùng cấp trong một transaction có khóa dòng
  Cohort.
* Activity phải thuộc Version của lớp và cùng `customer_id`; backend kiểm tra, không tin
  dữ liệu từ client.
* Quy tắc khóa theo ADR-0023 mục 4; backend kiểm tra Lesson và Activity bị khóa.
* Không có bản ghi lịch sử; ai đổi gần nhất lưu ở `updated_by`.

---

## Index

* Unique ở trên phục vụ tra cứu theo lớp.
* `idx_ccao_item`: `customer_id, version_activity_id`.
