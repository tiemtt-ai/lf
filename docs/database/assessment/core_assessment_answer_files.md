# Table: core_assessment_answer_files

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_answer_files.md

> **Thay đổi đề xuất — [ADR-0027](../../adr/ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md) D5 (Approved 2026-11-07, migration
> `2026_11_07_000100`):**
>
> * `file_role` thêm `essay_attachment`:
>   `CHECK (file_role IN ('speaking_recording','essay_attachment'))`.
> * Bỏ `UNIQUE (customer_id, answer_id, file_role)`, thay bằng
>   `UNIQUE (customer_id, answer_id, media_file_id)`. Service giữ: tối đa một
>   `speaking_recording` mỗi câu nói; tối đa 3 `essay_attachment` mỗi câu tự
>   luận, chỉ khi `question_snapshot.allow_file_answer = true`.
> * `essay_attachment` ghi `media_file_usages` với `usage_type = attachment`;
>   ảnh (JPG, PNG, WEBP, HEIC), PDF, DOCX; không OCR / trích xuất / AI
>   (ADR-0018). Học viên xoá được tệp khi Attempt còn `in_progress`.

## Purpose

Liên kết file bài làm của học viên (ghi âm Speaking) với câu trả lời.

## Relationships

`Answer 1 → N Answer Files`; `Media File 1 → N Answer Files`.

## Business Rules

* Answer và Media File cùng tenant; Media File do chính học viên của Attempt tải
  lên trong lúc Attempt `in_progress`.
* File gắn theo `answer_id`, không theo thứ tự câu (tránh lỗi lệch file của
  question_bank).
* Allowed `file_role` giai đoạn 1: `speaking_recording`. `uploaded_answer`,
  `essay_attachment`, `evidence` để sau (`file_upload`).
* Giai đoạn 1 mỗi câu Speaking có tối đa một bản ghi âm; ghi lại thì thay bản cũ
  trong lúc làm bài.
* Ghi `media_file_usages` với `owner_type = assessment_answer`, `usage_type = recording`. Binary, storage,
  delivery, retention thuộc Media Domain; giáo viên nghe file qua quyền đọc của
  Attempt, không qua thư viện Media.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| answer_id | BIGINT UNSIGNED NOT NULL | Câu trả lời. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File. |
| file_role | VARCHAR(50) NOT NULL | Vai trò file. |
| metadata | JSON NULL | Thời lượng, định dạng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (file_role IN ('speaking_recording'));
```

## Indexes

```sql
INDEX (customer_id, answer_id);
INDEX (customer_id, media_file_id);
UNIQUE (customer_id, answer_id, file_role);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (answer_id, customer_id) REFERENCES core_assessment_answers(id, customer_id);
FOREIGN KEY (media_file_id, customer_id) REFERENCES media_files(id, customer_id);
```

## Sample Data

`id=3200, customer_id=1, answer_id=3101, media_file_id=7100, file_role=speaking_recording`

## Design Notes

Unique `(answer_id, file_role)` thay cho unique theo `media_file_id` của ADR-0003
vì giai đoạn 1 chỉ một bản ghi âm mỗi câu; mở rộng nhiều file khi làm
`file_upload`.
