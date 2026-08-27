# Table: media_processing_jobs

Version: 2.5

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-27

Document Path: database/media/media_processing_jobs.md

Related ADR:

* [ADR-0004 — Media Foundation](../../adr/ADR-0004-Media-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0019 — Media Structured Extraction Boundary](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Amendment v1.2 Approved

Related Specification:
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)

---

# Amendment Record — Version 2.5

Amendment Status: **Approved by Architecture Owner, 2026-08-27.** Vocabulary mới
đã được duyệt nhưng **chưa migrate**, nên Implementation Status của tài liệu này
là `Partial` cho tới khi CHECK vật lý được xác minh trên MariaDB.

## Thay đổi đã duyệt

Theo [ADR-0019 Amendment v1.2](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md)
và [Processing Contract v2.1](../../platform/LF-Media-Processing-Contract.md):

```sql
CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text',
                    'caption','virus_scan','compress','structured_extraction'));
```

`output_type` nhận thêm `extracted_region` và `extracted_table`. `output_id`
của một job `structured_extraction` trỏ tới **điểm vào** của revision — region
có `reading_order = 1`, hoặc table có `sequence = 1` khi nguồn là spreadsheet.
`chk_mpj_ready` không đổi hình dạng: job `ready` vẫn phải có `output_id`.

Migration mang thay đổi này chịu **Gate M** như hai migration structured đã
viết: không apply trước khi có test đọc CHECK vật lý từ
`information_schema.CHECK_CONSTRAINTS` và chạy xanh trên MariaDB 11.4.3.

## Cảnh báo phải giải quyết trước khi viết migration đó

Đối chiếu ngày 2026-08-27 giữa § Keys của tài liệu này và
`LF-SCHEMA-CONTRACT.json` (dựng lại từ migration) cho thấy **bốn CHECK được ghi
ở đây không tồn tại vật lý**:

* `CHECK (output_type IS NULL OR output_type IN (...))`
* `CHECK (job_type <> 'virus_scan' OR (output_type IS NULL AND output_id IS NULL))`
* `CHECK (completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at)`
* `CHECK ((billable_units IS NULL AND billable_unit_type IS NULL) OR (...))`

Hệ quả trực tiếp cho amendment này: **không có CHECK `output_type` nào để "mở"**.
Migration hoặc phải tạo mới CHECK đó với đủ sáu giá trị, hoặc § Keys phải được
sửa cho khớp thực tế. Hai đường dẫn tới hai schema khác nhau, nên đây là quyết
định, không phải chi tiết triển khai. Ghi là **DOC-CONFLICT-0020**.

**Quyết định này vẫn mở sau ngày 2026-08-27.** Owner duyệt amendment vocabulary,
không duyệt chọn đường cho DOC-CONFLICT-0020; § Keys bên dưới **chưa** được sửa
vì hai đường dẫn tới hai § Keys khác nhau.

---


## Purpose

Mỗi row là **một lần thực thi** một tác vụ xử lý trên một Media File: ai chạy,
bằng phiên bản nào, trên nội dung nào, ra kết quả gì. Job không phải trạng thái
hiện tại của file — nó là bản ghi lịch sử của một lần chạy.

## Relationships

```text
media_files 1 → N media_processing_jobs
media_processing_jobs 1 → 0..1 output row (transcript | caption | extracted text | variant)
media_processing_jobs 1 → 0..1 job kế nhiệm (retry) qua supersedes_job_id
```

## Business Rules

### Vocabulary trạng thái

* Allowed `status`: `pending`, `processing`, `ready`, `failed`, `cancelled`.
* `ready` thay cho `completed` của Version 1.0. Media File, transcript, caption
  và extracted text đều đã dùng `ready`; job là bảng duy nhất nói khác, và sự
  khác biệt đó không mang thông tin nào.
* Chuyển trạng thái hợp lệ, không có đường nào khác:

```text
pending ──→ processing ──→ ready
   │            │
   │            └────────→ failed
   └────────────────────→ cancelled
```

* `processing` không quay lại `pending`. Một lần chạy đã bắt đầu thì kết thúc ở
  `ready`, `failed` hoặc không kết thúc; nó không được tái sử dụng.
* Job ở trạng thái kết thúc (`ready`, `failed`, `cancelled`) là **bất biến** trừ
  các cột audit; sửa kết quả một lần chạy đã xong là viết lại lịch sử.

### Retry

* Retry **luôn tạo row mới**, không bao giờ sửa row cũ.
* Row mới trỏ về row bị thay bằng `supersedes_job_id` và tăng `attempt`.
* Một chuỗi retry vì thế đọc được đầy đủ: mỗi lần gọi provider có tính phí đều
  có đúng một row, kể cả lần thất bại.
* Retry chain, giới hạn, backoff eligibility và `attempt` cao nhất được scope
  theo `(customer_id, media_file_id, job_type, source_fingerprint,
  processing_version, output_profile_hash)`. Không được nhóm chỉ theo job type.
* Retry của profile này không tiêu hao attempt, không chặn enqueue và không làm
  profile khác failed. Mỗi scope có tối đa 3 attempt độc lập.

### Quan hệ với `media_files.status`

* Job **không** tự ghi `media_files.status`.
* `media_files.status` phản ánh binary deliverability: `virus_scan` pending làm
  file `processing`, clean làm file `ready`, infected làm file `failed`.
* Required profile không materialize được do thiếu canonical locale/configuration
  làm file fail-closed; đây là configuration gate, không phải derived readiness.
* OCR/STT/caption/variant required hay optional đều có readiness riêng. Hết 3
  attempt làm chính output đó `failed`, không làm binary file mất `ready`.
* Tập job bắt buộc theo `file_type` do
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
  quy định, không quy định ở đây.

### Ranh giới

* Job không cập nhật Course Progress, Assessment Result, LiveClass Attendance
  hoặc bất kỳ AI business output nào.
* Job không tạo Learning Evidence và không chạm Mastery.
* Allowed `job_type`: `transcode`, `thumbnail`, `ocr`, `speech_to_text`,
  `caption`, `virus_scan`, `compress`.

### Job có sinh asset và job không sinh asset

| Nhóm | `job_type` | Khi `ready` |
| --- | --- | --- |
| Sinh asset | `ocr`, `speech_to_text`, `caption`, `transcode`, `thumbnail`, `compress` | **Bắt buộc** có `output_type` và `output_id` |
| Không sinh asset | `virus_scan` | `output_type` và `output_id` phải NULL |

`virus_scan` là validation side-effect, không phải derived asset: nó trả lời
"nội dung này có an toàn để phục vụ không", và câu trả lời "có" không tạo ra
hàng hoá nào. Bắt nó phải có output row sẽ buộc phải bịa ra một bảng chỉ để chứa
chữ "sạch".

Kết quả scan vẫn có dấu vết đầy đủ: quét sạch thì job `ready`; phát hiện nhiễm
thì job `failed` với `error_code = infected_source`, và Media File chuyển
`failed` vì `virus_scan` là job bắt buộc cho mọi `file_type`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | File được xử lý. |
| job_type | VARCHAR(50) NOT NULL | Loại processing. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái lần chạy này. |
| attempt | INT UNSIGNED NOT NULL DEFAULT 1 | Lần thử thứ mấy trong chuỗi retry. |
| supersedes_job_id | BIGINT UNSIGNED NULL | Job mà lần chạy này thay thế. |
| idempotency_key | VARCHAR(191) NOT NULL | Khóa chống trùng; xem Constraints. |
| correlation_id | CHAR(36) NOT NULL | Truy vết xuyên service và log. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn tại thời điểm chạy. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/provider/model/cấu hình. |
| output_profile | VARCHAR(191) NOT NULL | Tham số quyết định output: locale, định dạng, cấu hình extractor. |
| output_profile_hash | CHAR(64) NOT NULL | SHA-256 của `output_profile` đã chuẩn hoá. |
| provider | VARCHAR(100) NOT NULL | Worker/provider abstraction. |
| output_type | VARCHAR(50) NULL | `transcript`, `caption`, `extracted_text`, `variant`. |
| output_id | BIGINT UNSIGNED NULL | Id của row output tương ứng. |
| billable_units | DECIMAL(18,6) NULL | Lượng đã tiêu thụ (giây, trang, ký tự). |
| billable_unit_type | VARCHAR(50) NULL | Đơn vị của `billable_units`. |
| started_at | TIMESTAMP(6) NULL | Thời điểm bắt đầu. |
| completed_at | TIMESTAMP(6) NULL | Thời điểm kết thúc. |
| error_code | VARCHAR(100) NULL | Mã lỗi chuẩn hoá, dùng để quyết định retry. |
| error_message | TEXT NULL | Lỗi an toàn để audit; không chứa credential. |
| metadata | JSON NULL | Request/result metadata; **không** chứa nội dung output. |
| created_by | BIGINT UNSIGNED NULL | Actor kích hoạt, NULL nếu do hệ thống. |
| created_at | TIMESTAMP(6) NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP(6) NULL | Thời điểm cập nhật. |

`source_fingerprint` và `processing_version` là cột thật, không nằm trong
`metadata`: chúng quyết định output có `stale` hay không, và một quy tắc nghiệp
vụ không được sống trong JSON tự do.

## Constraints And Indexes

```sql
UNIQUE (customer_id, idempotency_key);
UNIQUE (customer_id, media_file_id, job_type, source_fingerprint,
        processing_version, output_profile_hash, attempt);
UNIQUE (customer_id, supersedes_job_id);
INDEX  (customer_id, media_file_id, job_type, status);
INDEX  (customer_id, status, created_at);
INDEX  (customer_id, correlation_id);
INDEX  (customer_id, output_type, output_id);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (supersedes_job_id, customer_id)
    REFERENCES media_processing_jobs (id, customer_id) RESTRICT;
FOREIGN KEY (created_by, customer_id)
    REFERENCES users (id, customer_id) RESTRICT;

CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text',
                    'caption','virus_scan','compress',
                    'structured_extraction'));   -- v2.5, chưa migrate
CHECK (status IN ('pending','processing','ready','failed','cancelled'));
CHECK (attempt >= 1);
-- DOC-CONFLICT-0020: CHECK dưới đây KHÔNG tồn tại vật lý. v2.5 duyệt thêm
-- 'extracted_region' và 'extracted_table' vào vocabulary, nhưng việc CHECK này
-- được tạo mới hay dòng này bị xoá là quyết định chưa đóng.
CHECK (output_type IS NULL OR output_type IN
       ('transcript','caption','extracted_text','variant'));
CHECK ((output_type IS NULL AND output_id IS NULL)
    OR (output_type IS NOT NULL AND output_id IS NOT NULL));
CHECK (status <> 'ready' OR completed_at IS NOT NULL);
CHECK (job_type <> 'virus_scan' OR (output_type IS NULL AND output_id IS NULL));
CHECK (status <> 'ready' OR job_type = 'virus_scan' OR output_id IS NOT NULL);
CHECK (status <> 'failed' OR (completed_at IS NOT NULL AND error_code IS NOT NULL));
CHECK (completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at);
CHECK ((billable_units IS NULL AND billable_unit_type IS NULL)
    OR (billable_units IS NOT NULL AND billable_unit_type IS NOT NULL));
```

### Khóa nào chặn cái gì

Ba unique key làm ba việc khác nhau, và không key nào thay được key kia:

| Key | Chặn |
| --- | --- |
| `(customer_id, idempotency_key)` | Cùng một message được queue giao hai lần |
| `(customer_id, media_file_id, job_type, source_fingerprint, processing_version, output_profile_hash, attempt)` | Enqueue trùng ở **cùng một attempt** — với `attempt = 1` đây chính là khóa chặn duplicate initial enqueue |
| `(customer_id, supersedes_job_id)` | Hai retry cùng phân nhánh từ một parent |

`UNIQUE (customer_id, supersedes_job_id)` **không** chặn được initial enqueue
trùng: MariaDB cho phép nhiều `NULL` trong unique index, và mọi job đầu tiên đều
có `supersedes_job_id = NULL`. Việc đó thuộc về key thứ hai, qua `attempt = 1`.

`output_profile_hash` nằm trong key vì `job_type` một mình không mô tả đủ output.
Cùng một video, cùng nội dung, cùng model vẫn sinh ra những output khác nhau:
transcript `vi` khác transcript `ko`; caption `vi` VTT khác `vi` SRT; OCR với
profile layout khác cho kết quả khác. Thiếu nó thì mọi locale thứ hai và mọi
định dạng thứ hai đều bị từ chối như hàng trùng lặp.

`source_fingerprint` cố ý **không** chứa locale hay tham số output. Nó trả lời
"nội dung nguồn là gì"; `output_profile_hash` trả lời "đã yêu cầu output nào".
Trộn hai câu hỏi vào một hash làm mất khả năng nhận ra hai job đang đọc cùng một
nội dung.

Chính key thứ hai cũng định nghĩa retry scope vật lý. Vì
`output_profile_hash` đứng trước `attempt`, duplicate cùng profile/cùng attempt
bị database chặn, trong khi `vi` và `ko`, hoặc `vi-VTT` và `vi-SRT`, có chuỗi
attempt độc lập. Canonicalization và required/default profile Phase 1 thuộc
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md);
worker không được tự chọn default.

Media không tạo foreign key sang Course, Assessment, LiveClass hay AI. Ba khóa
ngoại trên đều nằm trong Media hoặc trỏ `users`, đúng ranh giới ADR-0004.

## Sample Data

```text
id=400, customer_id=1, media_file_id=100, job_type=ocr, status=ready, attempt=1,
idempotency_key=ocr:100:9f2c…:tesseract-5.3.0:4d1a…:1, correlation_id=8f1e…,
source_fingerprint=9f2c…, processing_version=tesseract-5.3.0,
provider=internal_ocr, output_type=extracted_text, output_id=700,
billable_units=12.000000, billable_unit_type=page
```

## Design Notes

Version 1.0 để idempotency, correlation và retry count trong `metadata` như
Foundation placeholder. Version 2.0 kéo chúng ra thành cột vì pipeline thật cần
chống trùng ở tầng database: OCR và speech-to-text là lời gọi ngoài có tính phí,
và một unique key trong JSON không ngăn được lần gọi thứ hai.

Bảng này cố ý **không** có cột "trạng thái hiện tại của file". Binary
deliverability đọc từ `media_files.status`; readiness của output dẫn xuất đọc từ
row output và retry scope tương ứng, không suy ra từ một job mới nhất toàn cục.
