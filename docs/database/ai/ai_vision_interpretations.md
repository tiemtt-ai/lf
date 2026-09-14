# Table: ai_vision_interpretations

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-14

Document Path: database/ai/ai_vision_interpretations.md

---

## Amendment v1.1 — Owner approved 2026-09-14

Nguồn: kiểm kê tiền triển khai Bước 6 (Vision Interpretation) đối chiếu v1.0 với
[ADR-0020](../../adr/ADR-0020-AI-Vision-Interpretation-Boundary.md), schema Media thật
và các finding đã từng bắt ở bảng AI khác; cùng observation N-7, N-9 của
[LF-AI-Foundation-Media-Consumer-Database-Architecture-Review](../../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md).

**Quyết định của Owner:**

| Mã | Quyết định |
| --- | --- |
| F4 | Phạm vi v1.1 chỉ `document` / `region`. v1.0 cho phép `video` + `video_frame_text` + `timespan`, đi trước ADR-0020 D7 — nơi "diễn giải video theo khung hình" nằm **ngoài phạm vi** vì chưa có vocabulary locator. Muốn có video phải amend ADR-0020 D7 trước, rồi mới amend bảng này. |
| F5 | Bỏ status `failed`. v1.0 vừa nói run failed không được có interpretation, vừa buộc mọi row chưa `deleted` phải có nội dung — nên một row `failed` không có nghĩa nhất quán. Output không hợp lệ thì không tạo row; lỗi nằm ở `ai_model_runs`. |
| F6 | Mỗi unit tối đa **một** row `ready`. Unique key v1.0 chứa `model_run_id`, nên chạy lại bằng model/prompt khác sẽ sinh thêm row `ready` và retrieval không biết chọn row nào. Ép bằng unique trên cột sinh `active_slot`. |
| F8 | Redaction hoãn có điều kiện. Media hiện chưa có redacted derivative; phải bổ sung định danh derivative trước khi Media hỗ trợ redaction. |

**Vá trong doc:**

| Mã | Vá |
| --- | --- |
| F1 | `media_file_id` có FK ghép tenant tới `media_files (id, customer_id)`. v1.0 không có, nên row tenant A có thể mang id file tenant B — cùng lỗi P1-1 đã bắt ở `ai_knowledge_sources`. |
| F2 | CHECK ghép bộ `(usage_type, content_type, locator_type)` thay cho ba danh sách độc lập — cùng loại lỗi đã sửa bằng `chk_aks_usage_pair`. |
| F3 (= N-7) | Locator khớp đúng Media: region Media luôn có `locator_type = 'region'` (`chk_mer_locator`). Thêm cột `page`, vì Media tách `page` khỏi `locator_value`. bbox **không** bắt buộc, vì bbox của region Media được phép NULL toàn bộ (`chk_mer_bbox`); bbox chỉ được sao chép từ region nguồn. Bỏ hai cột timespan theo F4. |
| F7 (= N-9) | Ghi rõ nghĩa vụ ADR-0020 D5 "xoá Media nguồn thì xoá mọi diễn giải", kèm đường xoá idempotent giữ mốc thời gian gốc. |

Bổ sung nhằm tránh lỗi đã gặp ở Bước 5: retrieval tái kiểm qua Media Read
`currentRevision()` (quyền owner **cộng** usage active, đúng file, Media chưa xoá, revision
hiện hành), và CHECK buộc xoá nội dung khi `deleted`.

Amendment này **không** cấp quyền migration. `AGENTS.md` § Database Rule vẫn đòi Architecture
Review PASS cho packet này (hoặc quyết định miễn trừ riêng của Owner); schema contract được
populate cùng migration.

**Cập nhật 2026-09-14:** Owner **miễn trừ** điều kiện `Architecture Review passed` cho packet
này — miễn trừ, không phải review PASS. Migration
`2026_09_14_000100_create_ai_vision_interpretations.php` đã được tạo và kiểm tại Gate
Migration; `LF-SCHEMA-CONTRACT.json` đã được populate từ schema vật lý. Migration **chưa**
được apply lên `learnforge_db`. Service `AiVisionInterpretationService` đã được triển khai theo
các business rule dưới đây, nhưng **chưa** có provider vision nào được kích hoạt. Bằng
chứng và hai lỗi bắt được tại gate ghi ở
[LF-AI-Vision-Interpretation-Implementation-Review](../../quality/LF-AI-Vision-Interpretation-Implementation-Review.md).

---

## Purpose

Lưu diễn giải AI có provenance về một vùng hình ảnh trong tài liệu Media. Đây là derived AI
data, không phải OCR hay evidence quan sát, và **không bao giờ** được ghi vào bảng `media_*`.

OCR, Frame OCR và structured extraction là evidence quan sát được của Media. Vision
Interpretation là kết quả suy luận của AI và có thể sai.

## Relationships

Interpretation thuộc một tenant, một Media revision có owner context được phép đọc, và
đúng một `ai_model_runs` đã `completed` qua provider execution gate.

## Business Rules

### Phạm vi

* v1.1 chỉ nhận `usage_type = 'document'`, `content_type = 'region'`,
  `locator_type = 'region'` — region của structured extraction (ADR-0019), điển hình là
  role `figure`.
* Diễn giải video theo khung hình nằm ngoài phạm vi (ADR-0020 D7). Không row video nào được
  ghi trước khi ADR-0020 D7 và bảng này cùng được amend.
* Diễn giải của bản redacted derivative nằm ngoài phạm vi (xem § Redaction).

### Neo nguồn

* Anchor gồm owner type/id, usage type, content type, locale, source fingerprint, processing
  version và locator (`locator_type`, `locator_start`, `page`, bbox). Mọi giá trị được sao chép
  **chính xác** từ unit mà Media Read trả cho revision hiện hành; không tự suy ra hay tự điền.
* `media_file_id` là citation provenance, **không** bao giờ là authorization (ADR-0006
  v1.0.3).
* bbox là all-NULL khi region nguồn không có bbox; khi có thì là bốn giá trị chuẩn hoá trong
  `[0,1]` với kích thước dương. bbox chỉ được sao chép từ region nguồn.
* Ảnh đầu vào chỉ được lấy qua Media Read (`includeCrop` cho `region`), không đọc storage
  trực tiếp. Bytes ảnh và signed URL **không** được lưu vào bảng này, `metadata` hay
  `ai_model_runs`.

### Tạo row

* Row chỉ được tạo sau khi `AiProviderExecutionGate::execute()` trả `allowed` và run đã
  `completed`, trong cùng luồng xử lý kết quả của chính run đó.
* Output được kiểm hợp lệ **bên trong** adapter. Output không hợp lệ làm adapter ném lỗi;
  gate ghi run `failed` với mã đã duyệt `AI_PROVIDER_CALL_FAILED`, và **không** row nào được
  tạo. Run `blocked`, `failed` hay `cancelled` không bao giờ có interpretation.
* `model_run_id` bắt buộc. `run_uuid` lấy qua FK. Mọi trích dẫn phải kèm `run_uuid`, model và
  `processing_version` của unit nguồn (ADR-0020 D4).
* Interpretation không phải Source Of Truth, không trở thành business state, và mọi đường vào
  business state phải qua review của con người theo ADR-0017.

### Một row `ready` cho mỗi unit

* Unit slot = `(source_type, source_id, usage_type, content_type, source_fingerprint,
  processing_version, locator_type, locator_start)` trong một tenant. Mỗi slot có tối đa một
  row `ready`.
* Ghi một row `ready` mới: trong **một** transaction, chuyển row `ready` hiện có của slot sang
  `stale`, rồi insert. Unique `(customer_id, active_slot)` là trọng tài cuối cùng; nếu vi
  phạm unique, lặp lại bước stale-rồi-insert **một** lần. Không bao giờ có hai row `ready`
  cùng slot.
* Khi một revision mới hơn của cùng owner/usage/content được diễn giải, các row `ready` thuộc
  revision cũ chuyển `stale`. Row cũ không bị ghi đè.

### Lifecycle

* Status: `ready`, `stale`, `deletion_pending`, `deleted`.
* Transitions: `ready → stale`; `ready|stale → deletion_pending → deleted`. `deleted`
  terminal. Không có `failed`.
* Khi `deleted`: `interpretation` bị xoá (NULL); provenance, `interpretation_hash` và tombstone
  audit được giữ lại.

### Xoá theo Media nguồn

* Xoá Media nguồn phải xoá mọi interpretation dựng từ nó (ADR-0020 D5). Khi Media File được
  yêu cầu xoá, mọi row cùng tenant có `media_file_id` đó chuyển `deletion_pending`.
* Yêu cầu xoá là idempotent: chỉ row `ready|stale` chuyển sang `deletion_pending`; row đã
  `deletion_pending` giữ nguyên `deletion_requested_at` gốc. Liệt kê theo tên, không dùng
  "mọi status trừ `deleted`".
* Đường xoá thuộc AI domain và được gọi bởi orchestration xoá; Media không ghi bảng này.
* **Triển khai (thiết kế Owner duyệt 2026-09-14):** Media phát
  `MediaFileDeleted` sau khi xoá (`LF-Media-Processing-Contract.md` amendment v2.46). Listener
  AI queued sau commit ngoài cùng (`ShouldQueueAfterCommit`), tái kiểm tombstone qua Media rồi chuyển `ready|stale →
  deletion_pending → deleted` ngay trong một lượt — cùng nhịp với việc Media purge nội dung dẫn
  xuất của chính nó. Event bị lỡ được `ai:vision-reconcile-media-deletion` bắt lại. Diễn giải
  ghi xong trong lúc Media đang bị xoá được kiểm lại sau insert và xoá ngay. Trong lúc chờ dọn,
  retrieval từ chối row qua Media Read `currentRevision()`.
* FK `media_file_id` là RESTRICT, như mọi tham chiếu cross-domain khác tới `media_files`:
  purge vật lý Media File là thao tác có điều phối theo chuỗi retention của ADR-0018.

### PII, redaction và retention

* Diễn giải của ảnh có PII cũng là dữ liệu có PII, với cùng access, retention và audit như
  nguồn; không được coi là metadata vô hại vì do máy sinh (ADR-0020 D5).
* **Redaction (hoãn có điều kiện):** v1.1 chỉ neo Media revision gốc; Media hiện chưa có
  redacted derivative. **Trước khi** Media hỗ trợ redacted derivative, bảng này phải được
  amend để có định danh derivative trong anchor, unique và `active_slot`. Diễn giải bản
  redact trước amendment đó bị cấm.
* Retention duration, legal hold và purge orchestration là quyết định triển khai riêng theo
  ADR-0018; cho tới khi chốt, việc mở production hoặc tenant thật vẫn bị gate.

### Retrieval

* Mọi lần đọc tái kiểm qua Media Read `currentRevision()` — cùng tiêu chuẩn với knowledge
  retrieval: quyền owner context, usage **active**, đúng một usage, usage trỏ đúng
  `media_file_id` của anchor, Media chưa bị xoá, và revision hiện hành khớp anchor. Không
  đạt thì không trả về. Quyền vào owner context một mình **không** đủ.
* Retrieval là sự kiện truy cập nội dung dẫn xuất từ Media: ghi audit qua service thuộc Media
  theo cùng cơ chế của `media_access_logs` § Retrieval audit amendment, fail-closed.

### Migration

* Rollback fail-closed khi còn row.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Primary key. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| interpretation_uuid | CHAR(36) NOT NULL | Stable identity. |
| model_run_id | BIGINT UNSIGNED NOT NULL | Run `completed` đã sinh diễn giải; `run_uuid` qua FK. |
| source_type | VARCHAR(100) NOT NULL | `course_activity|course_version_activity`. |
| source_id | BIGINT UNSIGNED NOT NULL | Owner-context id. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Citation provenance only; FK ghép tenant. |
| usage_type | VARCHAR(50) NOT NULL | `document` (v1.1). |
| content_type | VARCHAR(50) NOT NULL | `region` (v1.1). |
| locale | VARCHAR(20) NULL | Locale của unit nguồn. |
| source_fingerprint | CHAR(64) NOT NULL | Exact Media revision fingerprint. |
| processing_version | VARCHAR(100) NOT NULL | Exact Media processing version. |
| locator_type | VARCHAR(20) NOT NULL | `region` (v1.1); bằng `locator_type` của region Media. |
| locator_start | VARCHAR(50) NOT NULL | Bằng `locator_value` của region Media. |
| page | INT UNSIGNED NOT NULL | Bằng `page` của region Media; ≥ 1. |
| bbox_x, bbox_y, bbox_width, bbox_height | DECIMAL(9,6) NULL | Sao chép từ region nguồn; all-NULL khi nguồn không có. |
| interpretation | LONGTEXT NULL | Model interpretation; bị xoá khi `deleted`. |
| interpretation_hash | CHAR(64) NOT NULL | Output integrity hash. |
| status | VARCHAR(50) NOT NULL DEFAULT 'ready' | Derived lifecycle. |
| active_slot | VARCHAR(64) NULL, STORED GENERATED | Hash của unit slot khi `ready`, NULL cho mọi status khác. |
| deletion_requested_at | TIMESTAMP NULL | Mốc bắt đầu cửa sổ xoá; không bị ghi đè bởi yêu cầu lặp. |
| deleted_at | TIMESTAMP NULL | Tombstone completion. |
| metadata | JSON NULL | Output-schema/prompt provenance; không secret, không raw interpretation, không PII, không signed URL. |
| created_at, updated_at | TIMESTAMP NULL | Audit timestamps. |

v1.0 có `timespan_start_ms` và `timespan_end_ms`; v1.1 bỏ theo F4.

## Indexes And Constraints

```sql
active_slot VARCHAR(64) GENERATED ALWAYS AS (
    CASE WHEN status = 'ready' THEN SHA2(CONCAT_WS('|',
        source_type, source_id, usage_type, content_type,
        RTRIM(source_fingerprint),
        CHAR_LENGTH(processing_version), processing_version,
        locator_type,
        CHAR_LENGTH(locator_start), locator_start), 256)
    END) STORED;

UNIQUE (customer_id, interpretation_uuid);
UNIQUE (id, customer_id);
UNIQUE (customer_id, source_type, source_id, usage_type, content_type,
        source_fingerprint, processing_version, locator_type, locator_start,
        model_run_id);
UNIQUE (customer_id, active_slot);
INDEX (customer_id, source_type, source_id, status);
INDEX (customer_id, media_file_id, status);
INDEX (customer_id, model_run_id);

FOREIGN KEY (model_run_id, customer_id)
    REFERENCES ai_model_runs (id, customer_id) RESTRICT;
FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;

CHECK (source_type IN ('course_activity','course_version_activity'));
CHECK (usage_type = 'document' AND content_type = 'region' AND locator_type = 'region');
CHECK (page >= 1);
CHECK (status IN ('ready','stale','deletion_pending','deleted'));
CHECK ((bbox_x IS NULL AND bbox_y IS NULL AND bbox_width IS NULL AND bbox_height IS NULL)
    OR (bbox_x IS NOT NULL AND bbox_y IS NOT NULL
        AND bbox_width IS NOT NULL AND bbox_height IS NOT NULL
        AND bbox_x BETWEEN 0 AND 1 AND bbox_y BETWEEN 0 AND 1
        AND bbox_width > 0 AND bbox_height > 0
        AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1));
CHECK (status = 'deleted' OR interpretation IS NOT NULL);
CHECK (status <> 'deleted' OR interpretation IS NULL);
CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL);
CHECK (status <> 'deleted' OR deleted_at IS NOT NULL);
```

CHECK bbox lặp lại `IS NOT NULL` ở vế thứ hai **có chủ ý**. Biểu thức của v1.0 thiếu điều đó:
với bbox thiếu một giá trị (ví dụ `bbox_width` NULL), `bbox_width > 0` là UNKNOWN, `FALSE OR
UNKNOWN` là UNKNOWN, và CHECK cho qua khi UNKNOWN — nên bbox dở dang lọt vào. Lỗi được test vật
lý bắt trên cả MariaDB 11.4.12 và 10.4.21 tại Gate Migration.

CHECK ghép `(usage_type, content_type, locator_type)` được viết thành **một** bộ đóng thay vì
ba danh sách độc lập, để việc mở rộng về sau (ví dụ video) buộc phải sửa có chủ ý chính cặp
giá trị, không thể vô tình cho qua một tổ hợp lệch.

NULL không va chạm trong UNIQUE, nên `active_slot` NULL cho mọi status khác `ready` là điều
khiến unique chỉ ràng buộc các row `ready`.

**Vì sao có tiền tố độ dài.** `processing_version` và `locator_start` là chuỗi tự do và có thể
chứa `|`. Nối thẳng bằng `CONCAT_WS('|', …)` không phải mã hoá đơn ánh: version `v1|region|a` +
locator `b` và version `v1` + locator `a|region|b` cho cùng một chuỗi, nên hai slot khác nhau bị
coi là trùng và một diễn giải hợp lệ bị từ chối oan. Bản đầu của v1.1 mắc đúng lỗi này; nó được
phát hiện và tái lập trên cả MariaDB 11.4.12 và 10.4.21 tại Gate Migration. Tiền tố
`CHAR_LENGTH` trước mỗi trường tự do làm mã hoá đơn ánh, vì mọi trường còn lại là từ vựng đóng
(được CHECK ép) hoặc số, không chứa `|`.

Không dùng `JSON_ARRAY`: cột sinh `STORED` không được tính lại khi nâng cấp engine, nên nếu định
dạng đầu ra của `JSON_ARRAY` khác nhau giữa các phiên bản, row cũ và row mới của cùng một slot sẽ
có hash khác nhau. `CONCAT_WS` và `CHAR_LENGTH` có ngữ nghĩa ổn định.

SQLite không có `SHA2`: migration dùng cùng mã hoá tiền tố độ dài bằng `||` và `length()`, không
băm. Ngữ nghĩa unique giống hệt; giá trị lưu khác nhau giữa hai driver.

## Design Notes

AI owns interpretation; Media remains owner of observable text, crop and region evidence.
Authorization never trusts `media_file_id` alone. Schema creation does not activate a vision
provider, approve a purpose, or authorize sending images outside the tenant boundary
(ADR-0018, ADR-0020 D6).

**Phải kiểm chứng tại Gate Migration** — không được khẳng định trước:

* Cột sinh `active_slot` và unique của nó tạo được và hoạt động đúng trên MariaDB **server
  11.4** và trên engine XAMPP **10.4.21** nơi `learnforge_db` sống. Đã có tiền lệ hai engine xử
  lý khác nhau cùng một biểu thức sinh.
* `source_fingerprint` là `CHAR`: biểu thức sinh dùng `RTRIM()` (MariaDB 11.4 từ chối cột
  `CHAR` trong generated column, `ERROR 1901`). Biểu thức **không** tham chiếu `id`
  (`AUTO_INCREMENT` bị cấm trong generated column).
* SQLite không có `SHA2`/`CONCAT_WS` và bỏ qua CHECK: migration phải tách theo driver, và mọi
  khẳng định về constraint chỉ chứng minh được trên MariaDB.
* Mọi cột `TIMESTAMP` khai báo `NULL` tường minh, để schema không phụ thuộc
  `explicit_defaults_for_timestamp` (khác nhau giữa 10.4 và 11.4).
* Trọng tài một-row-`ready` được chứng minh bằng hai connection thật, không bằng lập luận.
* Probe `information_schema.CHECK_CONSTRAINTS` lấy chuỗi thật khi populate
  `LF-SCHEMA-CONTRACT.json`.
