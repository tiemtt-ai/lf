# Table: ai_vision_interpretations

Version: 1.0

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-09-08

Document Path: database/ai/ai_vision_interpretations.md

---

## Purpose

Lưu diễn giải AI có provenance về vùng hình ảnh Media. Đây là derived AI data,
không phải OCR/evidence quan sát và không bao giờ ghi vào bảng `media_*`.

## Relationships

Interpretation thuộc một tenant, một authorized owner-context Media revision và
một `ai_model_runs` đã qua provider execution gate.

## Business Rules

* Media anchor gồm owner type/id, usage type, media file provenance, content
  type, locale, fingerprint, processing version và locator.
* `model_run_id` bắt buộc; một run blocked/failed không được có interpretation.
* Bbox là all-NULL hoặc đủ bốn giá trị chuẩn hóa trong `[0,1]` và có kích thước
  dương. Timespan dùng milliseconds với end lớn hơn start.
* Interpretation mới của revision khác tạo row mới; row cũ thành `stale`, không
  bị ghi đè.
* Retrieval luôn tái kiểm owner authorization và Media revision.
* Lifecycle: `ready|failed → stale`; `ready|failed|stale → deletion_pending →
  deleted`; deleted terminal. Khi deleted, raw interpretation được erase nhưng
  provenance/audit tombstone giữ lại.
* Rollback migration fail-closed khi còn row.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Primary key. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| interpretation_uuid | CHAR(36) NOT NULL | Stable identity. |
| model_run_id | BIGINT UNSIGNED NOT NULL | Provider execution provenance. |
| source_type | VARCHAR(100) NOT NULL | `course_activity|course_version_activity`. |
| source_id | BIGINT UNSIGNED NOT NULL | Owner-context id. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Citation provenance only. |
| usage_type | VARCHAR(50) NOT NULL | `document|video`. |
| content_type | VARCHAR(50) NOT NULL | `region|video_frame_text`. |
| locale | VARCHAR(20) NULL | Observed/request locale. |
| source_fingerprint | CHAR(64) NOT NULL | Exact Media revision fingerprint. |
| processing_version | VARCHAR(100) NOT NULL | Exact Media processing version. |
| locator_type | VARCHAR(20) NOT NULL | `page|timespan|region`. |
| locator_start | VARCHAR(50) NOT NULL | Exact unit locator. |
| bbox_x, bbox_y, bbox_width, bbox_height | DECIMAL(9,6) NULL | Normalized bbox. |
| timespan_start_ms | BIGINT UNSIGNED NULL | Video start. |
| timespan_end_ms | BIGINT UNSIGNED NULL | Video end. |
| interpretation | LONGTEXT NULL | Model interpretation; erased on delete. |
| interpretation_hash | CHAR(64) NOT NULL | Output integrity hash. |
| status | VARCHAR(50) NOT NULL DEFAULT 'ready' | Derived lifecycle. |
| deletion_requested_at | TIMESTAMP NULL | Delete request. |
| deleted_at | TIMESTAMP NULL | Tombstone completion. |
| metadata | JSON NULL | Schema/prompt provenance without secrets. |
| created_at, updated_at | TIMESTAMP NULL | Audit timestamps. |

## Indexes And Constraints

```sql
UNIQUE (customer_id, interpretation_uuid);
UNIQUE (id, customer_id);
UNIQUE (customer_id, source_type, source_id, usage_type, content_type,
        source_fingerprint, processing_version, locator_type, locator_start,
        model_run_id);
INDEX (customer_id, source_type, source_id, status);
INDEX (customer_id, media_file_id, status);
INDEX (customer_id, model_run_id);

FOREIGN KEY (model_run_id, customer_id)
    REFERENCES ai_model_runs (id, customer_id) RESTRICT;

CHECK (source_type IN ('course_activity','course_version_activity'));
CHECK (usage_type IN ('document','video'));
CHECK (content_type IN ('region','video_frame_text'));
CHECK (locator_type IN ('page','timespan','region'));
CHECK (status IN ('ready','failed','stale','deletion_pending','deleted'));
CHECK ((bbox_x IS NULL AND bbox_y IS NULL AND bbox_width IS NULL AND bbox_height IS NULL)
    OR (bbox_x BETWEEN 0 AND 1 AND bbox_y BETWEEN 0 AND 1
        AND bbox_width > 0 AND bbox_height > 0
        AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1));
CHECK ((timespan_start_ms IS NULL AND timespan_end_ms IS NULL)
    OR timespan_end_ms > timespan_start_ms);
CHECK (status = 'deleted' OR interpretation IS NOT NULL);
CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL);
CHECK (status <> 'deleted' OR deleted_at IS NOT NULL);
```

## Design Notes

AI owns interpretation; Media remains owner of observable text/crop/frame.
Authorization never trusts `media_file_id` alone. Schema creation does not
activate a vision provider.
