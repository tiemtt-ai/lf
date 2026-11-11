# Table: core_assessment_attempts

Version: 2.1

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-11

Document Path: database/assessment/core_assessment_attempts.md

## Purpose

Một lần học viên làm một phiên bản đề, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D7, D8, D10.

## Relationships

`Quiz Version 1 → N Attempts`; `Registration 1 → N Attempts`;
`Attempt 1 → N Answers / Gradings`.

## Business Rules

* Mọi bảng liên quan cùng tenant; `user_id` là học viên của nguồn quyền.
* **Đúng một nguồn quyền.** Giai đoạn 1: `quiz_registration_id` có giá trị,
  `enrollment_id` NULL. Các cột `enrollment_id`, `product_id`,
  `template_version_id`, `version_activity_id` dành cho lúc gắn khoá học; khi
  dùng, quy tắc Enrollment / Version Activity của ADR-0003 áp dụng cho chúng.
* **Bắt đầu** trong một transaction: khoá dòng ghi danh; ghi danh `active`; nếu
  đã có lần làm `in_progress` thì trả lại lần đó (làm tiếp); nếu không, kiểm
  `attempts_used < max_attempts` của phiên bản hiện hành, tạo attempt với
  `quiz_version_id` = phiên bản hiện hành, `attempt_no = attempts_used + 1`,
  `started_at` và `deadline_at` theo giờ server, tăng `attempts_used`.
* Một ghi danh có tối đa một lần làm `in_progress`.
* `deadline_at = started_at + duration_minutes` của phiên bản; NULL khi không
  giới hạn. Sau hạn (cộng khoảng trễ mạng ngắn theo cấu hình), server không nhận
  câu trả lời mới và kết thúc lần làm với `submit_reason = timeout` khi học viên
  quay lại hoặc khi tác vụ định kỳ `assessment:close-overdue` (mỗi phút) quét.
  Lần làm kết thúc vì hết giờ có `submitted_at = deadline_at` (không phải lúc
  đóng) và `time_spent_seconds` bằng thời lượng đề (ADR-0025 Amendment 1.3).
* **Nộp**: `submitted_at`, tự chấm các câu tự chấm từ snapshot; nếu không có câu
  cần chấm tay thì `status = graded`, `grading_status = not_required`; ngược lại
  `status = submitted`, `grading_status = pending`.
* Khi câu chấm tay cuối cùng có điểm: `status = graded`,
  `grading_status = completed`, `graded_at`.
* `score` = tổng `answers.score`; `max_score` = `total_points` của phiên bản;
  `score_percentage` = `score / max_score × 100` (0 khi `max_score = 0`);
  `passed` chỉ tính khi `status = graded` và phiên bản có ngưỡng đạt. Chấm lại
  tính lại cả bốn giá trị.
* Allowed `status`: `in_progress`, `submitted`, `graded`. Allowed
  `grading_status`: `not_required`, `pending`, `completed`. Allowed
  `submit_reason`: `student`, `timeout`.
* Học viên chỉ đọc attempt của chính mình. Giáo viên đọc attempt của đề mình
  tạo hoặc ghi danh mình cấp; `customer_admin` đọc mọi attempt của tenant.
* Score / passed là bằng chứng đánh giá; không tự đổi trạng thái miền khác.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Đề (lineage, để lọc). |
| quiz_version_id | BIGINT UNSIGNED NOT NULL | Phiên bản đã khoá. |
| quiz_registration_id | BIGINT UNSIGNED NULL | Nguồn quyền: ghi danh. |
| enrollment_id | BIGINT UNSIGNED NULL | Nguồn quyền: khoá học (sau này). |
| product_id | BIGINT UNSIGNED NULL | Ngữ cảnh khoá học (sau này). |
| template_version_id | BIGINT UNSIGNED NULL | Ngữ cảnh khoá học (sau này). |
| version_activity_id | BIGINT UNSIGNED NULL | Ngữ cảnh khoá học (sau này). |
| user_id | BIGINT UNSIGNED NOT NULL | Học viên. |
| attempt_no | INT UNSIGNED NOT NULL | Lần làm thứ mấy trong nguồn quyền. |
| status | VARCHAR(20) NOT NULL DEFAULT 'in_progress' | Trạng thái. |
| grading_status | VARCHAR(20) NOT NULL DEFAULT 'not_required' | Trạng thái chấm. |
| submit_reason | VARCHAR(20) NULL | Học viên nộp / hết giờ. |
| started_at | TIMESTAMP NOT NULL | Bắt đầu (giờ server). |
| deadline_at | TIMESTAMP NULL | Hạn nộp (giờ server). |
| submitted_at | TIMESTAMP NULL | Thời điểm nộp; hết giờ thì là `deadline_at`. |
| graded_at | TIMESTAMP NULL | Thời điểm chấm xong. |
| time_spent_seconds | INT UNSIGNED NULL | `submitted_at - started_at`. |
| score | DECIMAL(10,2) NULL | Tổng điểm. |
| max_score | DECIMAL(10,2) NOT NULL | Điểm tối đa của phiên bản. |
| score_percentage | DECIMAL(5,2) NULL | Phần trăm 0–100. |
| passed | BOOLEAN NULL | Đạt / không đạt. |
| metadata | JSON NULL | Audit (user agent, IP rút gọn...). |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |
| in_progress_flag | TINYINT STORED GENERATED | `1` khi `status = 'in_progress'`, ngược lại NULL. |

## Constraints

```sql
CHECK ((quiz_registration_id IS NOT NULL) + (enrollment_id IS NOT NULL) = 1);
CHECK (status IN ('in_progress','submitted','graded'));
CHECK (grading_status IN ('not_required','pending','completed'));
CHECK (submit_reason IS NULL OR submit_reason IN ('student','timeout'));
CHECK ((status = 'in_progress') = (submitted_at IS NULL));
CHECK (attempt_no >= 1);
CHECK (score_percentage IS NULL OR (score_percentage >= 0 AND score_percentage <= 100));
```

## Indexes

```sql
INDEX (customer_id, quiz_id, status);
INDEX (customer_id, quiz_version_id);
INDEX (customer_id, user_id, status);
INDEX (customer_id, grading_status);
INDEX (customer_id, status, deadline_at);
UNIQUE (customer_id, quiz_registration_id, attempt_no);
UNIQUE (customer_id, quiz_registration_id, in_progress_flag);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (quiz_id, customer_id) REFERENCES core_assessment_quizzes(id, customer_id);
FOREIGN KEY (quiz_version_id, customer_id) REFERENCES core_assessment_quiz_versions(id, customer_id);
FOREIGN KEY (quiz_registration_id, customer_id) REFERENCES core_assessment_quiz_registrations(id, customer_id);
FOREIGN KEY (user_id) REFERENCES users(id);
```

Khoá ngoại tới Enrollment / Product / Template Version / Version Activity thêm
khi làm phần gắn khoá học. Phiên bản thuộc đúng `quiz_id` do ứng dụng kiểm.

## Sample Data

`id=3000, customer_id=1, quiz_id=2000, quiz_version_id=2500, quiz_registration_id=2700, user_id=100, attempt_no=1, status=submitted, grading_status=pending, submit_reason=student, started_at=2026-11-10T02:00:00Z, deadline_at=2026-11-10T03:00:00Z, submitted_at=2026-11-10T02:45:00Z, time_spent_seconds=2700, score=62.50, max_score=100.00`

## Design Notes

So với ADR-0003: Attempt khoá `quiz_version_id` thay vì `quiz_id` + `quiz_snapshot`
(phiên bản đã bất biến nên không cần chép lại); nguồn quyền mở rộng sang ghi danh;
thêm `deadline_at`, `submit_reason`, `max_score`; bỏ trạng thái `expired`,
`cancelled` (hết giờ là `submitted`/`graded` với `submit_reason = timeout`).
