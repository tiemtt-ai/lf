# Table: core_assessment_quiz_versions

Version: 1.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_quiz_versions.md

## Purpose

Phiên bản xuất bản bất biến của một đề, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D6. Mỗi lần
bấm *Xuất bản* tạo một dòng mới. Lần làm bài khoá vào một phiên bản.

## Relationships

`Quiz 1 → N Quiz Versions`; `Quiz Version 1 → N Quiz Version Questions`;
`Quiz Version 1 → N Attempts`.

## Business Rules

* Version và Quiz phải cùng tenant.
* Bất biến sau khi tạo: không update, không delete. Sửa đề là sửa bản nháp và
  xuất bản lại.
* `version_no` tăng dần từ 1 trong mỗi đề.
* Xuất bản trong một transaction: khoá dòng đề, kiểm tra bản nháp hợp lệ (có ít
  nhất một câu; mọi câu và ngữ liệu đang `published`; câu cùng ngữ liệu nằm liền
  nhau; câu lựa chọn có đáp án đúng hợp lệ), tạo version và toàn bộ
  `quiz_version_questions`, rồi đặt `core_assessment_quizzes.current_version_id`.
* Chính sách (`duration_minutes`, `max_attempts`, `pass_score_percentage`,
  `show_result_mode`) được chép từ bản nháp tại lúc xuất bản.
* `total_points` = tổng `points` của các câu trong version.
* Media được snapshot (câu hỏi và ngữ liệu) được ghi `media_file_usages` với
  `owner_type = assessment_quiz_version` để không bị xoá khi tác giả gỡ khỏi câu
  hỏi.
* Allowed `assessment_type`: `exercise`, `exam`. Allowed `show_result_mode`:
  `immediately`, `after_grading`, `score_only`, `hidden`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Đề nguồn. |
| version_no | INT UNSIGNED NOT NULL | Số phiên bản trong đề. |
| title | VARCHAR(255) NOT NULL | Tên đề tại lúc xuất bản. |
| description | TEXT NULL | Giới thiệu đề tại lúc xuất bản. |
| assessment_type | VARCHAR(20) NOT NULL | Nhãn bài tập / đề thi. |
| duration_minutes | INT UNSIGNED NULL | Giới hạn thời gian; NULL = không giới hạn. |
| max_attempts | INT UNSIGNED NOT NULL | Số lượt cho mỗi ghi danh. |
| pass_score_percentage | DECIMAL(5,2) NULL | Ngưỡng đạt; NULL = không xét đạt. |
| show_result_mode | VARCHAR(20) NOT NULL | Cách hiện kết quả cho học viên. |
| total_points | DECIMAL(10,2) NOT NULL | Tổng điểm. |
| question_count | INT UNSIGNED NOT NULL | Số câu. |
| sections_snapshot | JSON NOT NULL | Danh sách phần: `[{"key":1,"title":"Đọc","description":null,"skill_type":"reading"}]`. |
| passages_snapshot | JSON NULL | Ngữ liệu theo id: `{"50":{"title":"...","content":"...","media":[{"media_file_id":7001,"media_role":"prompt_audio"}]}}`. |
| published_by | BIGINT UNSIGNED NOT NULL | Người xuất bản. |
| published_at | TIMESTAMP NOT NULL | Thời điểm xuất bản. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật (không đổi sau tạo). |

## Constraints

```sql
CHECK (version_no >= 1);
CHECK (assessment_type IN ('exercise','exam'));
CHECK (duration_minutes IS NULL OR duration_minutes >= 1);
CHECK (max_attempts >= 1);
CHECK (pass_score_percentage IS NULL OR (pass_score_percentage >= 0 AND pass_score_percentage <= 100));
CHECK (show_result_mode IN ('immediately','after_grading','score_only','hidden'));
CHECK (total_points >= 0);
```

## Indexes

```sql
INDEX (customer_id, quiz_id);
UNIQUE (customer_id, quiz_id, version_no);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (quiz_id, customer_id) REFERENCES core_assessment_quizzes(id, customer_id);
FOREIGN KEY (published_by) REFERENCES users(id);
```

## Sample Data

`id=2500, customer_id=1, quiz_id=2000, version_no=2, title=TOPIK I - Đề 01, assessment_type=exam, duration_minutes=60, max_attempts=2, pass_score_percentage=70.00, show_result_mode=after_grading, total_points=100.00, question_count=40, published_by=20`

## Design Notes

Thay cho việc ADR-0003 coi `core_assessment_quizzes` là đối tượng bất biến gắn
với một Version Activity. Snapshot nằm ở version và các câu của version; bản nháp
đề không bao giờ là nguồn chấm hay hiển thị bài đã làm.
