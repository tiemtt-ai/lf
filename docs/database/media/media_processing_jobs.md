# Table: media_processing_jobs

Version: 2.11

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-31

Document Path: database/media/media_processing_jobs.md

Related ADR:

* [ADR-0004 — Media Foundation](../../adr/ADR-0004-Media-Foundation.md)
* [ADR-0017 — AI-Assisted Learning Authoring](../../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)
* [ADR-0019 — Media Structured Extraction Boundary](../../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) — Amendment v1.2 Approved

Related Specification:
[LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)

---

## D1–D6 amendment — Approved 2026-08-31

Owner approval trong task Document Processing. D6: job dispatch_generation unsigned >=1 default1; unique profile/attempt thêm generation. Explicit authorized reattach sau cancelled tạo successor generation+1, giữ attempt/correlation, supersedes_job_id trỏ cancelled. Failed retry tăng attempt nhưng giữ generation, trần3 xuyên generation. Redelivery/on-demand không mở generation. Gen1 giữ key cũ; gen>1 SHA256 full tuple gồm customer_id và generation. Terminal rows không hồi sinh; output identity không đổi vì cancellation chưa có output.
D3 canonical_processing_job_id nằm trong metadata đã có; không thêm FK/cột input. D5 structured billable_unit_type page/sheet theo nguồn; monotonic completed-unit checkpoint. Rollback generation chỉ được phép khi mọi row generation=1; nếu có generation>1 phải abort, không xoá lịch sử.

Migration forward mới sau review; preflight báo count và IDs vi phạm rồi abort, không tự fill/delete. Approval thiết kế không phải evidence schema đã deployed.

---

# Amendment Record — Version 2.5

Amendment Status: **Approved by Architecture Owner, 2026-08-27.** Tại thời điểm amendment 2.5, vocabulary chưa migrate. Migration
`2026_08_26_000200_open_media_processing_job_structured_identity.php` hiện đã
có trong repository và được xác minh trên MariaDB disposable; trạng thái runtime
hiện hành xem Document Final Code Review, không suy ra production deployment.

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

## Quyết định vật lý Version 2.6

Đối chiếu ngày 2026-08-27 giữa § Keys của tài liệu này và
`LF-SCHEMA-CONTRACT.json` (dựng lại từ migration) cho thấy **bốn CHECK được ghi
ở đây không tồn tại vật lý**:

* `CHECK (output_type IS NULL OR output_type IN (...))`
* `CHECK (job_type <> 'virus_scan' OR (output_type IS NULL AND output_id IS NULL))`
* `CHECK (completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at)`
* `CHECK ((billable_units IS NULL AND billable_unit_type IS NULL) OR (...))`

Owner chốt ngày 2026-08-27: migration job identity phải tạo vật lý **cả bốn**
CHECK, không hạ hợp đồng xuống schema drift hiện tại. `output_type` dùng vocabulary
sáu giá trị; ba invariant còn lại được thi hành đúng như § Keys.

Migration phải preflight toàn bộ row hiện có và fail-closed, kèm số row vi phạm,
trước khi ALTER. Không tự sửa output, timestamp hay billing pair của lịch sử.
Rollback phải từ chối khi có row dùng `structured_extraction`,
`extracted_region` hoặc `extracted_table`. Quyết định này đóng
**DOC-CONFLICT-0020**; hiệu lực vật lý vẫn chịu Gate M/MariaDB evidence.

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
  `caption`, `virus_scan`, `compress`, `structured_extraction`.

### Job có sinh asset và job không sinh asset

| Nhóm | `job_type` | Khi `ready` |
| --- | --- | --- |
| Sinh asset | `ocr`, `speech_to_text`, `caption`, `transcode`, `thumbnail`, `compress`, `structured_extraction` | **Bắt buộc** có `output_type` và `output_id` |
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
| dispatch_generation | INT UNSIGNED NOT NULL DEFAULT 1 | Dispatch generation; >=1; D6 reattach only. |
| attempt | INT UNSIGNED NOT NULL DEFAULT 1 | Lần thử thứ mấy trong chuỗi retry. |
| supersedes_job_id | BIGINT UNSIGNED NULL | Job mà lần chạy này thay thế. |
| idempotency_key | VARCHAR(320) NOT NULL | Khóa chống trùng; xem Constraints. Nới từ 191 ngày 2026-08-30 — xem ghi chú dưới. |
| correlation_id | CHAR(36) NOT NULL | Truy vết xuyên service và log. |
| source_fingerprint | CHAR(64) NOT NULL | Vân tay nội dung nguồn tại thời điểm chạy. |
| processing_version | VARCHAR(100) NOT NULL | Phiên bản extractor/provider/model/cấu hình. |
| output_profile | VARCHAR(191) NOT NULL | Tham số quyết định output: locale, định dạng, cấu hình extractor. |
| output_profile_hash | CHAR(64) NOT NULL | SHA-256 của `output_profile` đã chuẩn hoá. |
| provider | VARCHAR(100) NOT NULL | Worker/provider abstraction. |
| output_type | VARCHAR(50) NULL | `transcript`, `caption`, `extracted_text`, `variant`, `extracted_region`, `extracted_table`. |
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
        processing_version, output_profile_hash, dispatch_generation, attempt);
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
                    'structured_extraction'));   -- v2.5, migration 2026_08_26_000200
CHECK (status IN ('pending','processing','ready','failed','cancelled'));
CHECK (attempt >= 1);
CHECK (dispatch_generation >= 1);
-- v2.6: CHECK vật lý trong migration job identity 2026_08_26_000200.
CHECK (output_type IS NULL OR output_type IN
       ('transcript','caption','extracted_text','variant',
        'extracted_region','extracted_table'));
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
| `(customer_id, media_file_id, job_type, source_fingerprint, processing_version, output_profile_hash, dispatch_generation, attempt)` | Enqueue trùng ở **cùng một attempt** — với `attempt = 1` đây chính là khóa chặn duplicate initial enqueue |
| `(customer_id, supersedes_job_id)` | Hai retry cùng phân nhánh từ một parent |

### Vì sao `idempotency_key` nới từ 191 lên 320 — 2026-08-30

Key là `job_type : media_file_id : source_fingerprint : processing_version :
output_profile_hash : attempt`. Hai thành phần đã chiếm 64 ký tự mỗi cái
(fingerprint và profile hash), nên phần còn lại rất hẹp: key của audio STT dài
**181 ký tự — 95% của trần 191**.

[Amendment Record 2.19](../../platform/LF-Media-Processing-Contract.md) buộc
`processing_version` của video STT chứa cả canonical ffmpeg extraction profile.
Version dài 75 ký tự thay vì 31, và key thành **225** — tràn cột.

Độ rộng mới được tính từ **biên của schema**, không từ key dài nhất đang có:

| Thành phần | Độ rộng |
| --- | ---: |
| `job_type` VARCHAR(50) | 50 |
| `media_file_id` BIGINT UNSIGNED | 20 |
| `source_fingerprint` CHAR(64) | 64 |
| `processing_version` VARCHAR(100) | 100 |
| `output_profile_hash` CHAR(64) | 64 |
| `attempt` INT UNSIGNED | 10 |
| 5 dấu phân cách | 5 |
| **Tổng** | **313** |

**255 không đủ** — nó chỉ chứa được `processing_version` tới 42 ký tự, chưa tới
một nửa độ rộng cột. Chọn **320**: phủ biên 313 và còn dư. `320 × 4 = 1.280 byte`,
xa trần 3.072 byte của index InnoDB.

Bản đầu của phép kiểm này chỉ khẳng định key E2E dài 225 lọt vào 255 — **đo một
trường hợp rồi gọi là biên**. Test hiện tại tính biên từ `information_schema` và
ghi thử một key dài tối đa để chứng minh nó lưu được nguyên vẹn.

Không hash lại key: hash sẽ đổi **mọi** key đang tồn tại và tách đôi mọi retry
chain đang chạy. Con số 191 là lựa chọn cũ từ thời index utf8mb4 giới hạn 767
byte.

Lỗi này chỉ lộ ra khi chạy thật: hợp đồng đã freeze không thể thoả được với cột
cũ, và không phép kiểm tài liệu nào phát hiện được điều đó.

#### Gate M — ĐÃ ĐÓNG 2026-08-30

Migration `2026_08_30_000000_widen_processing_job_idempotency_key` được apply lên
`learnforge_db` **trong lúc gỡ một lỗi runtime**, không qua Gate M. Nói thẳng:
một phát hiện lúc chạy **không tự cấp quyền tạo migration**.

Bằng chứng đã có đủ và Database Owner đã xác nhận trực tiếp ngày 2026-08-30.
Đây là **Owner attestation sau khi migration đã được apply**, không phải bằng
chứng rằng thứ tự ban đầu đúng và không được dùng làm tiền lệ để bỏ qua Gate M
cho migration khác.

| Yêu cầu Gate M | Trạng thái |
| --- | --- |
| Schema contract | `LF-SCHEMA-CONTRACT.json` không ghi độ dài varchar nên không cần sửa; `schema:drift --connection` xanh |
| Test đọc độ rộng **vật lý** từ `information_schema` | `tests/Integration/MediaProcessingJobKeyWidthMariaDbTest.php` — 3 test: biên schema, ghi key dài tối đa, và UNIQUE index phủ cả cột |
| `schema:drift --fresh` | ✅ |
| Đã nối vào CI | ✅ |
| **Chữ ký Owner hoặc Architecture Review** | ✅ Owner attestation trực tiếp 2026-08-30 |

Test kiểm ba thứ: cột phủ được **biên** mà schema cho phép, một key dài tối đa
thật sự **lưu được nguyên vẹn** (độ rộng khai báo không chứng minh được gì nếu
chưa ghi thử), và UNIQUE index vẫn phủ **cả cột** chứ không phải prefix — index
bị rút thành prefix sẽ khiến hai chain khác nhau trùng khoá, tệ hơn tràn độ rộng.

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

Key thứ hai chặn duplicate cùng profile/generation/attempt. Retry budget vẫn
được tính xuyên generation theo profile, không reset về 1 khi reattach; trong khi `vi` và `ko`, hoặc `vi-VTT` và `vi-SRT`, có chuỗi
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
