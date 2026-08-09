# Table: media_file_usages

Version: 1.1

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/media/media_file_usages.md

## Purpose

Generic mapping giữa Media File và owner record ở Domain sử dụng.

Media File Usage là mapping thuộc Media Platform Domain. Mapping này cho phép
Course, Assessment, LiveClass, Certificate, AI hoặc các Domain khác tham chiếu
Media File mà không lưu storage path và không biết file đang nằm ở local disk
hay S3.

## Relationships

`Media File 1 → N Usages`; một external owner có thể có nhiều Media Files.

## Business Rules

* Usage và Media File phải cùng tenant.
* Media validate tenant và Media File ownership.
* Owner Domain validate owner existence, owner tenant và authorization.
* Không tạo hard foreign key tới Course, Assessment, LiveClass, AI hoặc domain khác.
* `owner_type + owner_id` là generic reference; Media không diễn giải business state của owner.
* Allowed `owner_type`: `course_template`, `course_template_version`, `course_product`, `course_activity`, `course_cohort`, `assessment_question`, `assessment_answer`, `liveclass_recording`, `certificate`, `avatar`, `ai_knowledge`, `marketing`.
* Allowed `usage_type`: `intro_image`, `intro_video`, `intro_document`, `cover_image`, `thumbnail`, `video`, `audio`, `document`, `attachment`, `recording`, `certificate_pdf`, `avatar_image`, `source_material`.
* Course Template and immutable Course Template Version introduction usages use
  only `intro_image`, `intro_video`, and `intro_document`. Embedded video URLs
  create no Media usage.
* Allowed `status`: `active`, `detached`, `archived`.
* Một file có thể được nhiều Domain sử dụng; một owner có thể dùng nhiều file.
* Attach creates usage hoặc reactivates existing detached/archived usage.
* Attach phải idempotent theo `customer_id + media_file_id + owner_type + owner_id + usage_type`.
* Detach không xóa row; chỉ set `status = detached`.
* Active usage blocks hard delete/storage delete của `media_files`.
* Archived usage là historical và không được xem là active usage.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu mapping. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File. |
| owner_type | VARCHAR(100) NOT NULL | Loại owner generic. |
| owner_id | BIGINT UNSIGNED NOT NULL | ID owner trong Domain nguồn. |
| usage_type | VARCHAR(100) NOT NULL | Vai trò file với owner. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Lifecycle: `active`, `detached`, `archived`. |
| metadata | JSON NULL | Caption/context không phải business state. |
| created_by | BIGINT UNSIGNED NULL | User tạo mapping. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật lifecycle/metadata. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, owner_type, owner_id);
INDEX (customer_id, owner_type, owner_id, usage_type);
INDEX (customer_id, status);
UNIQUE (customer_id, media_file_id, owner_type, owner_id, usage_type);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (media_file_id) REFERENCES media_files(id);
FOREIGN KEY (created_by) REFERENCES users(id);
```

Không tạo foreign key cho `owner_type` / `owner_id`.

## Sample Data

`id=200, customer_id=1, media_file_id=100, owner_type=course_activity, owner_id=9001, usage_type=video, status=active, created_by=10`

## Design Notes

Generic reference tránh coupling schema. Domain owner vẫn là source of truth
và tự quyết định owner existence, authorization và business relationship.
Media chỉ quản lý mapping lifecycle, tenant boundary và Media File ownership.
