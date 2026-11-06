# Table: core_assessment_question_options

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_question_options.md

## Purpose

Lựa chọn của câu trắc nghiệm / đúng-sai và danh sách đáp án chấp nhận của câu
`short_answer`.

## Relationships

`Question 1 → N Options`.

## Business Rules

* Option và Question phải cùng tenant; `locale` trùng locale của content.
* Điều kiện để câu chuyển `published`
  ([ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D5):

  | `question_type` | Options |
  |---|---|
  | `single_choice` | ≥ 2 lựa chọn, đúng 1 `is_correct` |
  | `multiple_choice` | ≥ 2 lựa chọn, ≥ 1 `is_correct` |
  | `true_false` | đúng 2 lựa chọn (Đúng / Sai), đúng 1 `is_correct` |
  | `short_answer` | ≥ 1 dòng, tất cả `is_correct = true`; mỗi dòng là một đáp án chấp nhận, không hiện cho học viên |
  | `essay`, `speaking` | không có option |

* `option_text` của lựa chọn là HTML làm sạch ở server; của `short_answer` là
  văn bản thuần.
* `sort_order` duy nhất theo câu và locale; nhãn A/B/C do giao diện sinh theo
  thứ tự nếu `option_label` trống.
* `is_correct` là đáp án soạn thảo, không bao giờ gửi cho học viên; bài đã làm
  đọc `correct_answer_snapshot` của phiên bản đề.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| question_id | BIGINT UNSIGNED NOT NULL | Câu hỏi. |
| locale | VARCHAR(10) NOT NULL | Locale. |
| option_text | TEXT NOT NULL | Nội dung lựa chọn hoặc đáp án chấp nhận. |
| option_label | VARCHAR(50) NULL | Nhãn A/B/C nếu muốn đặt tay. |
| is_correct | BOOLEAN NOT NULL DEFAULT false | Đáp án đúng. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự. |
| feedback | TEXT NULL | Nhận xét khi chọn lựa chọn này (hiện theo `show_result_mode`). |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id, question_id, locale);
UNIQUE (customer_id, question_id, locale, sort_order);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (question_id, customer_id) REFERENCES core_assessment_questions(id, customer_id) ON DELETE CASCADE;
```

## Sample Data

`id=1201, customer_id=1, question_id=100, locale=vi, option_text=<p>Thời tiết mùa thu</p>, is_correct=true, sort_order=1`
`id=1210, customer_id=1, question_id=102, locale=vi, option_text=서울, is_correct=true, sort_order=1` (short_answer)

## Design Notes

Dùng chung bảng cho đáp án chấp nhận của `short_answer` để mọi đáp án tự chấm
đi một đường snapshot. Ảnh trong lựa chọn để giai đoạn sau.
