# Table: media_processing_jobs

Document Path: database/media/media_processing_jobs.md

## Purpose

Theo dõi tác vụ xử lý Media độc lập với business Domain sử dụng asset.

## Relationships

`Media File 1 → N Processing Jobs`; `Customer 1 → N Processing Jobs`.

## Business Rules

* Job và Media File phải cùng tenant.
* Allowed `job_type`: `transcode`, `thumbnail`, `ocr`, `speech_to_text`, `caption`, `virus_scan`, `compress`.
* Allowed `status`: `pending`, `processing`, `completed`, `failed`, `cancelled`.
* `completed_at` không trước `started_at`; error chỉ mô tả processing failure.
* Job không cập nhật Course Progress, Assessment Result hoặc LiveClass Attendance.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | File được xử lý. |
| job_type | VARCHAR(50) NOT NULL | Loại processing. |
| provider | VARCHAR(100) NOT NULL | Worker/provider abstraction. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái. |
| started_at | TIMESTAMP NULL | Thời điểm bắt đầu. |
| completed_at | TIMESTAMP NULL | Thời điểm kết thúc. |
| error_message | TEXT NULL | Lỗi xử lý an toàn để audit. |
| metadata | JSON NULL | Request/result/retry metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, job_type);
INDEX (customer_id, provider);
INDEX (customer_id, status);
INDEX (customer_id, created_at);
```

## Sample Data

`id=400, customer_id=1, media_file_id=100, job_type=transcode, provider=aws_media_convert, status=completed, started_at=2026-07-01T02:00:00Z, completed_at=2026-07-01T02:08:00Z`

## Design Notes

Retry có thể tạo Job mới; correlation/retry count nằm trong metadata cho Foundation.
