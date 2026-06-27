# Table Name

`media_file_usages`

## Purpose

Generic mapping giữa Media File và owner record ở Domain sử dụng.

## Relationships

`Media File 1 → N Usages`; một external owner có thể có nhiều Media Files.

## Business Rules

* Usage và Media File phải cùng tenant; calling Domain phải validate owner tenant.
* Không tạo hard foreign key tới Course, Assessment, LiveClass, AI hoặc domain khác.
* `owner_type + owner_id` là generic reference; Media không diễn giải business state của owner.
* Allowed `owner_type`: `course_activity`, `assessment_question`, `assessment_answer`, `liveclass_recording`, `certificate`, `avatar`, `ai_knowledge`, `marketing`.
* Một file có thể được nhiều Domain sử dụng; một owner có thể dùng nhiều file.
* Một primary Usage cho mỗi owner/usage type được enforce bằng service transaction.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu mapping. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File. |
| owner_type | VARCHAR(100) NOT NULL | Loại owner generic. |
| owner_id | BIGINT UNSIGNED NOT NULL | ID owner trong Domain nguồn. |
| usage_type | VARCHAR(100) NOT NULL | Vai trò file với owner. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự. |
| is_primary | BOOLEAN NOT NULL DEFAULT false | Usage chính trong cùng scope. |
| metadata | JSON NULL | Caption/context không phải business state. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, owner_type, owner_id);
INDEX (customer_id, owner_type, owner_id, usage_type);
INDEX (customer_id, is_primary);
UNIQUE (customer_id, media_file_id, owner_type, owner_id, usage_type);
```

## Sample Data

`id=200, customer_id=1, media_file_id=100, owner_type=course_activity, owner_id=9001, usage_type=primary_video, sort_order=1, is_primary=true`

## Design Notes

Generic reference tránh coupling schema. Domain owner vẫn là source of truth và tự quyết định authorization/lifecycle.
