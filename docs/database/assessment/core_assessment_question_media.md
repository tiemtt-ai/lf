# Table: core_assessment_question_media

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_question_media.md

## Purpose

Gắn Media File vào câu hỏi hoặc ngữ liệu, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D4.

## Relationships

`Question 1 → N Question Media`; `Passage 1 → N Question Media`;
`Media File 1 → N Question Media`.

## Business Rules

* Mỗi dòng gắn vào **đúng một** trong `question_id` hoặc `passage_id`.
* Question/Passage và Media File phải cùng tenant. Người gắn phải được phép dùng
  Media đó theo [ADR-0021](../../adr/ADR-0021-Media-Sharing-Within-Tenant.md).
* Allowed `media_role`: `prompt_image`, `prompt_audio`, `prompt_video`,
  `attachment`, `explanation_media`. Ngữ liệu chỉ dùng `prompt_*`.
* Gắn và gỡ ghi `media_file_usages` với `owner_type = assessment_question` hoặc
  `assessment_passage`. Phiên bản đề có usage riêng
  (`assessment_quiz_version`), nên gỡ Media khỏi câu không làm hỏng đề đã xuất
  bản.
* `usage_type` khi ghi `media_file_usages`: `prompt_image` → `image`,
  `prompt_audio` → `audio`, `prompt_video` → `video`, `attachment` và
  `explanation_media` → `attachment`.
* Binary, storage, delivery và processing thuộc Media Domain.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| question_id | BIGINT UNSIGNED NULL | Câu hỏi. |
| passage_id | BIGINT UNSIGNED NULL | Ngữ liệu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File. |
| media_role | VARCHAR(50) NOT NULL | Vai trò. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự hiển thị. |
| metadata | JSON NULL | Caption / cấu hình phát. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK ((question_id IS NULL) <> (passage_id IS NULL));
CHECK (media_role IN ('prompt_image','prompt_audio','prompt_video','attachment','explanation_media'));
CHECK (passage_id IS NULL OR media_role IN ('prompt_image','prompt_audio','prompt_video'));
```

## Indexes

```sql
INDEX (customer_id, question_id);
INDEX (customer_id, passage_id);
INDEX (customer_id, media_file_id);
UNIQUE (customer_id, question_id, media_file_id, media_role);
UNIQUE (customer_id, passage_id, media_file_id, media_role);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (question_id, customer_id) REFERENCES core_assessment_questions(id, customer_id) ON DELETE CASCADE;
FOREIGN KEY (passage_id, customer_id) REFERENCES core_assessment_passages(id, customer_id) ON DELETE CASCADE;
FOREIGN KEY (media_file_id, customer_id) REFERENCES media_files(id, customer_id);
```

## Sample Data

`id=1101, customer_id=1, question_id=NULL, passage_id=51, media_file_id=7001, media_role=prompt_audio, sort_order=1`
`id=1102, customer_id=1, question_id=100, passage_id=NULL, media_file_id=7002, media_role=prompt_image, sort_order=1`

## Design Notes

Hai unique index có cột NULL vẫn đúng: dòng của câu hỏi có `passage_id` NULL nên
không đụng index ngữ liệu và ngược lại.
