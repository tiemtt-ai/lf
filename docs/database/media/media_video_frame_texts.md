# media_video_frame_texts

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-08

Document Path: database/media/media_video_frame_texts.md

---

# Purpose

Lưu text OCR quan sát được trên khung hình video. Bảng không lưu diễn giải AI,
không thay transcript và không suy luận nội dung hình ảnh.

# Ownership and lifecycle

Mỗi row thuộc `customer_id`, một Media video và đúng một job `frame_ocr`. Row
ready là immutable evidence của revision. Revision mới archive revision cũ cùng
language profile; xóa Media purge row, processing job giữ làm provenance.

# Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | Khóa chính |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant |
| media_file_id | BIGINT UNSIGNED NOT NULL | Video nguồn |
| processing_job_id | BIGINT UNSIGNED NOT NULL | Job `frame_ocr` |
| locale | VARCHAR(20) NOT NULL | `mul` cho profile nhiều locale |
| detected_locale | VARCHAR(20) NULL | Locale quan sát hoặc NULL |
| script | CHAR(4) NULL | ISO 15924 hoặc NULL |
| locator_type/value | VARCHAR | `timespan`, `<start_ms>-<end_ms>` |
| reading_order | INT UNSIGNED NOT NULL | Thứ tự trong frame |
| bbox_x/y/width/height | DECIMAL(9,6) NOT NULL | Bbox chuẩn hóa 0..1 |
| frame_width/frame_height | INT UNSIGNED NOT NULL | Kích thước frame OCR |
| text | LONGTEXT NOT NULL | Text quan sát nguyên bản |
| confidence_score | DECIMAL(5,2) NULL | 0..100 nếu provider có |
| provider | VARCHAR(100) NULL | Provider OCR |
| processing_version | VARCHAR(100) NOT NULL | Revision semantics |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay video nguồn |
| status | VARCHAR(50) NOT NULL | `ready` hoặc `archived` |
| metadata | JSON NULL | Evidence phụ |
| created_at/updated_at | TIMESTAMP(6) NULL | Audit time |

# Constraints

Unique revision locator, tenant-aware FK tới Media/job, `locator_type=timespan`,
confidence 0..100, bbox trong 0..1, kích thước frame dương và text không rỗng.
Migration rollback fail-closed khi bảng còn bất kỳ row ready hoặc archived nào;
evidence phải được xóa có chủ đích qua lifecycle của Media sở hữu trước khi
rollback.

# Persist quality

Provider loại trước persist line dưới confidence threshold, line không có bất
kỳ chữ hoặc số Unicode nào, và bbox co về kích thước 0 tại precision
`DECIMAL(9,6)`. Không áp dụng độ dài tối thiểu vì một chữ Hàn hoặc một chữ số có
thể là evidence hợp lệ. Các rule này thuộc revision identity.

# Read contract

Đọc qua Media Read Service bằng `content_type=video_frame_text`; AI không query
bảng trực tiếp.
