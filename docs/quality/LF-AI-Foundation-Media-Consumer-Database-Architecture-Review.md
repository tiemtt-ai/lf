# AI Foundation Media-Consumer Database Architecture Review

Version: 1.8

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-09-08

Review Date: 2026-08-25 (Round 1 — author self-assessment), 2026-09-08 (Round 2 — independent review), 2026-09-08 (Round 3 — independent re-review, PASS)

Document Path: quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md

---

# Step 1 — Migration Packet Evidence — 2026-09-08

Packet author: cùng agent đã ký Round 3. **Đây là xung đột vai trò đã biết** —
người ký gate cũng là người soạn DDL, nên bản migration này cần một code review
độc lập trước khi apply lên database thật. Ghi lại ở đây để không ai coi Round 3
là chữ ký cho chính bản DDL bên dưới.

## Step 1 CI Gate — `integration-mysql` on MariaDB 11.4 — 2026-09-08

Job không trigger được từ phiên làm việc này, nên nó được tái lập nguyên vẹn:
instance MariaDB **11.4.12** riêng trên port 3307 với datadir tạm, database
`lf_ci_integration`, user `lf_ci`, đúng biến môi trường của job, và đúng 14 file
theo đúng thứ tự.

### Kết quả

```text
14/14 file PASS — 156 passed (583 assertions) — exit code 0
```

### Hai lỗi bị gate này bắt được

**1. Migration không chạy trên MariaDB 11.4.**

```text
ERROR 1901: Function or expression 'coalesce(`source_fingerprint`,`content_hash`,'')'
cannot be used in the GENERATED ALWAYS AS clause of `identity_fingerprint`
```

`source_fingerprint` là `CHAR(64)`; giá trị CHAR phụ thuộc `sql_mode`
`PAD_CHAR_TO_FULL_LENGTH` nên 11.4 coi biểu thức là không tất định. **10.4 chấp
nhận, 11.4 từ chối** — nên lỗi vô hình trên máy dev và chỉ đỏ ở CI. Probe trên cả
hai server:

| Biểu thức | 11.4 | 10.4 |
| --- | --- | --- |
| `COALESCE(a, b, '')`, `a CHAR(64)` | FAIL | OK |
| `COALESCE(CAST(a AS CHAR(128)), b, '')` | FAIL | OK |
| `COALESCE(RTRIM(a), b, '')` | **OK** | **OK** |
| đổi `a` sang `VARCHAR(64)` | OK | OK |

Chọn `RTRIM` thay vì đổi kiểu cột: giữ `CHAR(64)` đồng bộ với các bảng
fingerprint của Media, và `RTRIM` là no-op trên SHA-256 hex. Có regression test
đọc `information_schema` xác nhận biểu thức thật chứa `rtrim` và fingerprint vẫn
đủ 64 ký tự.

**2. Job đã đỏ trên `main` từ 2026-09-06, trước packet AI hai ngày.**

`CourseTemplateLearningMappingHttpMariaDbTest` assert `Chuẩn đầu ra &amp; năng
lực`. Commit `84c6b4c` (2026-09-06) đổi label thành `Đầu ra & năng lực`, sửa đồng
bộ `resources/lang/vi/lf.php`, `edit.blade.php`,
`learning-mappings.blade.php` và CSS — một đổi tên có chủ đích. Assertion không
được cập nhật theo. Chuỗi `Chuẩn đầu ra` không còn tồn tại ở đâu trong
`resources/` hay `app/`.

Assertion đã được chỉnh về label đang chạy. Audit Level `LOW`: sửa test cho khớp
một label đã ship, không đổi hành vi. Packet AI không đụng `app/` hay
`resources/` (`git diff --stat HEAD -- app resources` rỗng).

### Đính chính so với ghi chép trước

Ghi chép trước của review artifact nói job migrate lại cho từng file và đề xuất
tăng `timeout-minutes`. **Sai.** General log của MariaDB cho thấy `create table
migrations` chỉ xuất hiện một lần cho ba file, và hai dòng khớp là `Prepare` +
`Execute` của cùng một câu lệnh trên cùng connection id. Job chạy **một** tiến
trình PHPUnit và **một** `migrate:fresh`. Không cần đổi testsuite hay timeout vì
lý do đó. Các lần "reset" quan sát trước đó là do một tiến trình phpunit mồ côi
chạy song song sau khi `pkill -f "artisan test"` không khớp tiến trình con.

Đồng thời, mọi bằng chứng gắn nhãn "MariaDB 11.4" ở các mục trước của tài liệu
này thực ra chạy trên **MariaDB 10.4.21** (XAMPP, port 3306); chỉ client là
11.4.12. Contract đã được harvest lại từ 11.4 thật; khác biệt duy nhất so với bản
harvest 10.4 đúng là biểu thức `RTRIM`, không có chênh lệch render nào khác giữa
hai version.

### Gate

| Lệnh | Kết quả |
| --- | --- |
| `integration-mysql` tái lập (MariaDB 11.4.12) | **156 passed, 583 assertions, 14/14 file PASS** |
| `AiFoundationKnowledgePacketMariaDbTest` (11.4) | 20 passed, 29 assertions |
| `php artisan docs:lint` | passed |
| `php artisan schema:drift --docs-only` | passed — 96 migration |
| `./vendor/bin/pint --test` (file đã đổi) | passed |
| `php artisan test` (sqlite) | 1014 passed, 3 skipped, 7 failed — giống baseline |
| `git diff --check` | clean |

### Cảnh báo trạng thái database thật

`learnforge_db` **đã có bốn bảng AI**, apply lúc 2026-09-08 14:45:54, batch 27 —
không do lượt này thực hiện và trước khi ba P1 cùng lỗi 11.4 được vá.
`schema:drift --connection=mysql` cho 8 finding, gồm 1 BLOCKER và 1 HIGH: thiếu
cột `generation`, thiếu khóa ngoại kép `media_file_id`, thiếu unique key
registration identity mới, thiếu CHECK cặp `content_type × usage_type`, thiếu
`CHECK (generation >= 1)`, và `identity_fingerprint` vẫn ở biểu thức cũ không
chạy được trên 11.4.

Cả bốn bảng có **0 row**, batch 27 chỉ chứa đúng migration này, nên khắc phục là
lossless. Việc này cần lệnh của Owner, không phải hành động của reviewer/author.

---

## Step 1 Remediation — Round 1 code review — 2026-09-08

Independent code review của migration packet trả verdict **FAIL / Migration NO**
với 3 P1 và 3 P2. Tất cả đã được vá. Reviewer đó **không** sửa file và không
apply; bản vá dưới đây do packet author thực hiện và cần chính reviewer đó
re-review.

### P1 — đã đóng

| ID | Finding | Vá | Bằng chứng |
| --- | --- | --- | --- |
| P1-1 | `media_file_id` không có tenant-aware FK; test còn dùng id 7001 không tồn tại | Thêm `FOREIGN KEY (media_file_id, customer_id) → media_files (id, customer_id) RESTRICT` cùng `INDEX (customer_id, media_file_id)`. Fixture test nay tạo `media_files` thật | 3 test mới: file không tồn tại, file của tenant khác, và Media không hard-delete được khi còn registration trích dẫn |
| P1-2 | CHECK chỉ kiểm hai vocabulary rời, chấp nhận `formula + audio`, `video_frame_text + document`, `region + video` | `chk_aks_usage_type` thay bằng `chk_aks_usage_pair` enforce đúng từng cặp của Read Contract § 3 | 2 test mới: 5 cặp không đọc được đều bị từ chối; 7 cặp hợp lệ đều được nhận |
| P1-3 | Tombstone terminal khóa vĩnh viễn registration identity | Thêm `generation INT UNSIGNED NOT NULL DEFAULT 1` vào unique key và `CHECK (generation >= 1)` | 1 regression test: delete → reattach → cùng generation vẫn collide, `generation = 2` đăng ký được, row cũ giữ nguyên `deleted` |

Owner chốt cả ba hướng ngày 2026-09-08:

* `formula → document` được thêm vào bảng mapping § 3 của
  [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) (v1.23 → v1.24).
  Đây là giá trị khả dĩ duy nhất: formula evidence dựng từ region cha, mà region
  chỉ tồn tại trên document. Amendment này đóng khoảng trống giữa § 3 và § 5/§ 7,
  không mở content type hay đường đọc mới.
* `generation` thay vì hồi sinh row tombstone. Hồi sinh sẽ phá tuyên bố
  `deleted` terminal mà Owner vừa duyệt, xoá tombstone audit, và tái dùng một
  `source_uuid` mà một Proposal có thể đã trích dẫn cho nội dung cũ.
* Unit Media rỗng không sinh chunk; `CHECK (char_end > char_start)` giữ nguyên.

### P2 — đã đóng

| ID | Finding | Vá |
| --- | --- | --- |
| P2-1 | `char_end > char_start` từ chối unit text rỗng, policy chưa nói | `ai_knowledge_chunks.md` § Business Rules ghi rõ unit rỗng không sinh chunk, `sequence_no` liên tục trên unit có text, độ phủ locator cố ý không phủ unit rỗng |
| P2-2 | ADR-0006 vẫn ghi "11 tables" khi Foundation có 12 | ADR-0006 v1.0.3 → v1.0.4, Editorial Correction: 12 tables / 6 nhóm, thêm nhóm `Vision` vào danh sách nhóm, và sửa chính mệnh đề Foundation Freeze |
| P2-3 | `DOC-CONFLICT-0035/0036` không có trong bảng active register | Hai record được chuyển khỏi § Status Lifecycle (chúng đang cắt đôi section đó) xuống § Resolved Conflict Register, thêm hai dòng vào bảng register, và `0036` được bổ sung field `Sources In Conflict` còn thiếu |

### Tài liệu bị sửa kèm

Ba trong số các bản vá đụng tài liệu **đã được Owner duyệt**, nên được ghi rõ ở
đây thay vì lặng lẽ đi kèm migration:

* `LF-Media-Read-Contract` v1.24 — amendment do Media sở hữu, Owner chốt.
* `ADR-0006` v1.0.4 — editorial, không đổi bảng/boundary/ownership nào.
* `ai_knowledge_sources.md` — `generation`, FK `media_file_id`, CHECK cặp, và
  một dòng supersession cho amendment 2026-08-25 (dòng "bỏ `media_file_id`" nay
  đã bị v1.0.3 thay thế).

### Verification sau vá

| Lệnh | Kết quả |
| --- | --- |
| `php artisan test tests/Integration/AiFoundationKnowledgePacketMariaDbTest.php` (MariaDB 11.4) | **19 passed, 26 assertions** (trước vá: 13) |
| `php artisan schema:drift --fresh` (MariaDB 11.4) | passed — 96 migration, 52 finding đều `INFO`, **0 finding non-INFO** trên bốn bảng packet |
| `php artisan schema:drift --docs-only` | passed — 96 migration |
| `php artisan docs:lint` | passed |
| `./vendor/bin/pint --test` (hai file packet) | passed |
| `php artisan test` (suite mặc định, sqlite) | 1014 passed, 3 skipped, 7 failed — **giống hệt baseline**, cùng ba class môi trường `MediaRevisionLifecycleTest`, `VideoTranscriptCaptionLocalReviewTest`, `AudioProcessingLocalReviewTest` |
| `git diff --check` | clean |

Schema contract được **harvest lại** từ schema mới trên database MariaDB tạm sau
khi vá, không chỉnh tay. So sánh cấu trúc với `HEAD` xác nhận không bảng nào
ngoài bốn bảng packet bị đổi.

### Vẫn chưa apply

Migration chưa chạy lên `learnforge_db`. Cần re-review của reviewer độc lập rồi
mới tới lệnh apply của Owner.

---

## Phạm vi đã tạo

| Artifact | Đường dẫn |
| --- | --- |
| Migration bốn bảng | `database/migrations/2026_09_08_000100_create_ai_foundation_knowledge_tables.php` |
| Physical integration test | `tests/Integration/AiFoundationKnowledgePacketMariaDbTest.php` |
| Đăng ký CI | `.github/workflows/application-tests.yml` job `integration-mysql` |
| Schema contract | `docs/database/LF-SCHEMA-CONTRACT.json` — bốn entry chuyển `implemented` và được populate |
| Doc alignment | `docs/database/ai/ai_model_runs.md` — CHECK `chk_amr_prompt_scope` theo N-3 |

`ai_vision_interpretations` **không** nằm trong packet này; nó chỉ phụ thuộc
`ai_model_runs` và đi ở packet riêng.

## Hai điều kiện bắt buộc của Round 3

| # | Điều kiện | Trạng thái |
| --- | --- | --- |
| N-3 | Sửa CHECK `prompt_scope_customer_id` trước khi viết DDL | **DONE** — DDL dùng dạng `prompt_scope_customer_id IS NOT NULL AND … IN (0, customer_id)`; `ai_model_runs.md` đã sửa cùng lý do; có test phủ cả ba nhánh |
| R2-25 | Populate contract cùng migration | **DONE** — bốn entry được sinh từ chính output của `MySqlSchemaInspector` trên database MariaDB tạm, không viết tay |

Contract được **harvest từ schema thật**, không soạn thủ công: migration chạy
lên một database MariaDB 11.4 tạm, `MySqlSchemaInspector::inspect()` đọc lại,
và output đó là nội dung ghi vào contract. Vì vậy `schema:drift --fresh` so
contract với schema dựng mới cho 0 finding trên cả bốn bảng.

## Quyết định thiết kế trong DDL

* Thứ tự tạo bảng bị ép bởi `ai_embeddings.model_run_id NOT NULL`:
  `ai_model_runs` → `ai_knowledge_sources` → `ai_knowledge_chunks` →
  `ai_embeddings`. `down()` drop theo thứ tự ngược.
* Bốn sentinel `identity_*` là generated column `STORED`, đúng như doc, vì
  MariaDB không cho NULL va nhau trong UNIQUE index.
* Mọi cột TIMESTAMP đều nullable. Đây là cách tránh cái bẫy đã ghi ở
  `2026_08_09_050000_remove_implicit_timestamp_on_update_from_occurrence_columns`:
  TIMESTAMP NOT NULL không default đầu bảng bị MariaDB tự gắn
  `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
* CHECK được thêm bằng `ALTER TABLE` sau `Schema::create`, có guard bỏ qua khi
  driver là sqlite — theo đúng khuôn mẫu của Media substrate.
* `down()` đếm row cả bốn bảng và `throw RuntimeException` khi còn bất kỳ row
  nào, kèm tên bảng và số lượng.

Không đụng gì tới bảng `media_*`, không thêm trigger, không có runtime AI.

## Verification

| Lệnh | Kết quả |
| --- | --- |
| `php artisan test tests/Integration/AiFoundationKnowledgePacketMariaDbTest.php` (MariaDB 11.4) | 13 passed lúc soạn; **19 passed, 26 assertions** sau Step 1 Remediation |
| `php artisan schema:drift --fresh` (MariaDB 11.4) | **passed** — 96 migration, 52 finding đều `INFO`, 0 finding cho bốn bảng packet |
| `php artisan schema:drift --docs-only` | passed — 96 migration |
| `php artisan docs:lint` | passed |
| `./vendor/bin/pint --test` (hai file mới) | passed |
| `php artisan test` (suite mặc định, sqlite) | 1014 passed, 3 skipped, **7 failed** |
| `git diff --check` | clean |

Bảy failure của suite mặc định là nợ môi trường có sẵn, không phải regression.
Chúng nằm trong `MediaRevisionLifecycleTest`, `VideoTranscriptCaptionLocalReviewTest`
và `AudioProcessingLocalReviewTest` (thiếu binary ffmpeg/STT local — ví dụ
assertion `+ffmpeg-` trong `processing_version`). Bằng chứng: chạy đúng ba file
đó **có** và **không có** file migration cho kết quả pass/fail giống hệt nhau, và
toàn bộ output suite không nhắc tới `ai_knowledge*`, `ai_embeddings` hay
`ai_model_runs` lần nào.

## Điều gì đã được chứng minh vật lý

13 test chạy trên MariaDB thật, không phải sqlite (sqlite bỏ qua CHECK nên không
chứng minh được gì ở đây):

* NULL-safe identity chặn đăng ký trùng cùng một revision khi `locale` là NULL;
* `source_fingerprint` khác là một registration mới, không ghi đè;
* transcript slot `audio` và `video` là hai registration tách biệt (R2-01);
* Media source thiếu `media_file_id`, hoặc ở owner context ngoài Read Contract
  § 3, bị từ chối;
* chunk của tenant B không trỏ được sang source của tenant A;
* `sequence_no` không tái sử dụng được; các part của một unit cùng tồn tại nhưng
  `part_index` không lặp;
* `content` chỉ erase được khi row đã là tombstone `deleted`;
* `processing` bị từ chối ở `ai_embeddings.status`; `ready` không có
  `embedded_at` bị từ chối;
* embedding đã `deleted` **vẫn** chặn hard-delete chunk và source (delete
  barrier);
* run `blocked` không có `error_code` bị từ chối; prompt reference không khai
  scope, hoặc khai scope của tenant khác, bị từ chối (N-3);
* `down()` từ chối rollback khi còn row và giữ nguyên bảng.

## Chưa làm — cần quyết định của Owner

Migration **chưa được apply** lên `learnforge_db`. Nó mới chỉ chạy trên database
MariaDB tạm rồi bị drop. Apply là thao tác đổi trạng thái database thật và
`down()` cố ý fail-closed, nên cần Owner ra lệnh riêng.

Sau khi apply, phải cập nhật `Implementation Status` của bốn table doc từ
`Not Implemented` sang trạng thái đúng — hiện vẫn để nguyên vì chưa có database
thật nào chứa bốn bảng này.

---

# Round 3 — Independent Re-Review — 2026-09-08

Reviewer: cùng Architecture Reviewer độc lập đã ký Round 2. Round này **không**
lấy § Round 2 Remediation làm bằng chứng; mỗi finding được mở lại từ file hiện
tại trên đĩa. Round này không sửa ADR, contract, database doc, schema, migration
hay code.

## Kết quả re-validate 18 P1 của Round 2

| ID | Finding Round 2 | Bằng chứng đã kiểm | Kết quả |
| --- | --- | --- | --- |
| R2-01 | Thiếu `usage_type` | `ai_knowledge_sources` § Fields có `usage_type VARCHAR(50) NOT NULL DEFAULT ''`, nằm trong unique key, CHECK `content_type IS NULL OR usage_type IN ('document','audio','video')` | **CLOSED** (dư N-1) |
| R2-02 | Identity thiếu `source_fingerprint` | Unique key dùng `identity_fingerprint` = `COALESCE(source_fingerprint,content_hash,'')` | **CLOSED** |
| R2-03 | NULL trong unique key | Bốn generated column STORED (`identity_content_type`, `identity_locale`, `identity_fingerprint`, `identity_version`) thay NULL bằng sentinel `''` | **CLOSED** |
| R2-04 | Business Rules nói locator chỉ `page\|timespan` | Bullet nay ghi `page`, `timespan`, `sheet` hoặc `region`, khớp CHECK và Processing Contract § 4 | **CLOSED** |
| R2-05 | `sequence_no` không unique | `UNIQUE (customer_id, knowledge_source_id, sequence_no)` tách khỏi `content_hash` | **CLOSED** |
| R2-06 | Không có contract split | `part_index`, `char_start`, `char_end`; `UNIQUE (…, locator_type, locator_start, part_index)`; CHECK `part_index >= 1 AND char_end > char_start`; luật cắt tại paragraph/sentence/whitespace rồi fallback Unicode boundary, zero overlap | **CLOSED** (dư N-2) |
| R2-07 | Thiếu `reading_order` | `reading_order INT UNSIGNED NULL` — "Exact Media unit reading order" | **CLOSED** |
| R2-08 | POLICY_CONFLICT `media_file_id` | Owner chọn lưu: `media_file_id BIGINT UNSIGNED NULL` là provenance, authorization vẫn qua owner context. `LF-AI` § retrieval và § Business Rules nay nói cùng một câu | **CLOSED** |
| R2-09 | `source_text_quality` chưa chốt | ADR-0019 Amendment v1.11 → **Approved 2026-09-08**; Read Contract § Region text quality → **Approved 2026-09-08**; ADR-0006 → **v1.0.3 Approved**; cột và CHECK nay nằm trong § Fields và § Indexes | **CLOSED** |
| R2-10 | Embedding không nối Model Run | `model_run_id BIGINT UNSIGNED NOT NULL` + `FOREIGN KEY (model_run_id, customer_id) REFERENCES ai_model_runs (id, customer_id) RESTRICT` | **CLOSED** |
| R2-11 | Không có transition contract | § Business Rules ghi đúng máy trạng thái canonical `pending → ready\|failed`; `ready\|failed → stale`; `pending\|ready\|failed\|stale → deletion_pending → deleted`; `deleted` terminal; enforce bằng application service under row lock | **CLOSED** |
| R2-12 | Delete barrier / trình tự purge | Chuyển sang tombstone: source và chunk có `deletion_pending`/`deleted` + `deletion_requested_at`/`deleted_at`; không hard-delete nên RESTRICT và audit cùng đúng; `LF-AI` § Knowledge deletion barrier (Approved 2026-09-08) chốt thứ tự embedding → chunk → source | **CLOSED** |
| R2-13 | `blocked` không bắt buộc error_code | `CHECK (status <> 'blocked' OR error_code IS NOT NULL)` + vocabulary `AI_APPROVAL_REQUIRED`, `AI_QUOTA_EXCEEDED`, `AI_SAFETY_BLOCKED` | **CLOSED** |
| R2-14 | Hai deferred FK không khả thi | `ai_assistant_sessions` thêm `UNIQUE (id, customer_id)`; `ai_prompt_templates` dùng generated `scope_customer_id` với `UNIQUE (id, scope_customer_id)`; run mang `prompt_scope_customer_id` | **CLOSED** (dư N-3) |
| R2-15 | External-provider gate không tồn tại | `LF-AI` § Provider execution gate — Approved 2026-09-08: năm điều kiện có thứ tự (allow-list → tenant external-processing setting → Commercial entitlement → atomic usage reservation → safety/data-class), thiếu bất kỳ điều kiện nào thì không gọi provider và vẫn ghi Run `blocked`; credential chỉ resolve trong adapter sau gate | **CLOSED** |
| R2-16 | Thiếu bảng Vision Interpretation | `database/ai/ai_vision_interpretations.md` mới; ADR-0006 § Foundation Tables thêm nhóm `Vision`; `LF-AI` § Database Namespace liệt kê 12 bảng | **CLOSED** (dư N-4) |
| R2-17 | `video_frame_text` không đăng ký được | Read Contract § 7 mở đủ sáu giá trị; § 2 bổ sung `formula` và `video_frame_text`; § 3 có dòng `video_frame_text → video`; AI CHECK mở; chunk thêm `bbox_*`, `frame_width`, `frame_height` | **CLOSED** (dư N-5) |
| R2-18 | Rollback fail-closed | Cả bốn bảng packet và bảng Vision đều ghi nghĩa vụ rollback fail-closed khi còn row | **CLOSED** |

## Re-validate 8 P2 của Round 2

| ID | Kết quả | Bằng chứng |
| --- | --- | --- |
| R2-19 | **CLOSED** | Bốn doc packet + Vision có `Version`/`Document Status`/`Implementation Status`/`Last Updated`; `config/docs-lint.php` giảm 98 → 94 entry, bốn bảng packet đã rời allowlist |
| R2-20 | **CLOSED** | § Relationships nay là "optional Media provenance", đúng với việc `media_file_id` được đưa lại |
| R2-21 | **CLOSED** | `CHECK (source_role IS NULL OR source_role IN ('paragraph','heading','list','table','figure','caption','header','footer','other'))` — khớp `media_extracted_regions` |
| R2-22 | **CLOSED** | `CHECK (status <> 'ready' OR embedded_at IS NOT NULL)` |
| R2-23 | **CLOSED** | `pending` vào vocabulary, `DEFAULT 'pending'`, prose "All chunks begin `pending` in one transaction and become `active` only after the complete revision validates" |
| R2-24 | **CLOSED** | CHECK buộc `content_type IS NOT NULL` ⇒ `media_file_id IS NOT NULL` và `source_type IN ('course_activity','course_version_activity')` |
| R2-25 | **DEFERRED — chấp nhận** | Mười hai entry `ai_*` trong `LF-SCHEMA-CONTRACT.json` vẫn `columns: []`. Đúng thứ tự của `LF-Schema-Drift.md` bước 2 (doc trước, contract cùng migration). Entry `ai_vision_interpretations` đã được thêm. Bắt buộc populate trong Gate Migration |
| R2-26 | **CLOSED** | `ai_prompt_templates` dùng generated `scope_customer_id`; `UNIQUE (scope_customer_id, code, version)` thay biểu thức `COALESCE` không chạy được trên MariaDB |

Đối chiếu Observation Round 2: O-1 đã đóng (Read Contract § 2 nay có `formula`);
O-3 đã đóng (`LF-INDEX.md:390` cập nhật). O-6 và O-7 vẫn đúng: ADR-0006
Amendment v1.1 giữ `Proposed` và không bị encode; `grep "'processing'"` trên
`docs/database/ai/`, `LF-AI.md`, `ADR-0006` vẫn 0 hit.

---

## Findings mới của Round 3

Không có P0. Không có P1.

| ID | Severity | Vị trí | Nội dung | Acceptance criteria |
| --- | --- | --- | --- | --- |
| N-1 | P2 | `ai_knowledge_sources` § Indexes | CHECK chỉ buộc `usage_type IN ('document','audio','video')`, **không** buộc cặp `(content_type, usage_type)` khớp bảng mapping đóng của Read Contract § 3. `content_type='extracted_text'` + `usage_type='video'` vẫn hợp lệ ở schema | Thêm CHECK theo từng dòng mapping § 3, hoặc ghi rõ việc kiểm cặp thuộc application layer |
| N-2 | P2 | `ai_knowledge_chunks` § Indexes | `CHECK (part_index >= 1 AND char_end > char_start)` cấm chunk độ dài 0. Nhưng Read Contract § D2–D4 (Approved) giữ page `extracted_text` có `text` rỗng và `char_count = 0` là unit hợp lệ. Không doc nào nói AI bỏ qua unit rỗng, mà readiness invariant lại "fail toàn ingestion revision" khi vi phạm — nên một PDF có một trang trắng làm hỏng cả revision ingestion | Ghi rõ unit rỗng không được chunk (và hệ quả với `sequence_no`/độ phủ locator), hoặc nới CHECK thành `char_end >= char_start` |
| N-3 | P2 | `ai_model_runs` § Indexes | `CHECK (prompt_template_id IS NULL OR prompt_scope_customer_id IN (0, customer_id))` **pass khi `prompt_scope_customer_id IS NULL`** (logic ba trị của SQL). Composite FK cũng không enforce khi một cột NULL (MATCH SIMPLE). Vậy `prompt_template_id = 900, prompt_scope_customer_id = NULL` lọt qua cả CHECK lẫn FK hoãn — đúng lỗ hổng mà R2-14 định bịt | Sửa thành `CHECK (prompt_template_id IS NULL OR (prompt_scope_customer_id IS NOT NULL AND prompt_scope_customer_id IN (0, customer_id)))`. **CHECK này ship trong chính packet migration, nên phải sửa trước khi viết DDL** |
| N-4 | P2 | `adr/ADR-0006-AI-Foundation.md:246` và `:612` | Vẫn ghi "11 tables thuộc 5 nhóm" và "the 11-table Foundation" trong khi § Foundation Tables của chính ADR liệt kê 12 (thêm nhóm `Vision`) và `LF-AI` § Database Namespace cũng liệt kê 12. Dòng 612 là mệnh đề Foundation Freeze — tức chính điều khoản governance | Cập nhật hai dòng thành 12 tables / 6 nhóm |
| N-5 | P2 | `LF-Media-Read-Contract` § 3 | Bảng mapping `usage_type` được tuyên bố "Mapping Phase 1 đóng" nhưng **không có dòng `formula`**, trong khi § 7 và CHECK của `ai_knowledge_sources` cho phép đăng ký `formula`. Một source `formula` đăng ký được nhưng không đọc lại được theo § 3 | Thêm dòng `formula → document` vào bảng § 3, hoặc bỏ `formula` khỏi § 7 |
| N-6 | P2 | `LF-Documentation-Conflicts.md:94` và `:122` | Hai record `DOC-CONFLICT-0035` và `0036` được chèn **vào giữa § Status Lifecycle**, cắt đôi section đó, và **không xuất hiện thành dòng nào trong bảng Active Conflict Register** — bảng mà chính tài liệu tuyên bố là nguồn sự thật của cột Status. `0036` cũng thiếu field `Sources In Conflict` mà template và `0035` đều có | Chuyển hai record xuống § Resolved Conflict Register, thêm hai dòng vào bảng, bổ sung field thiếu |
| N-7 | Observation | `ai_vision_interpretations` § Indexes | Không có CHECK ghép `content_type` với `locator_type` (`video_frame_text` nên là `timespan`; `region` nên là `region`/`page`) | Cân nhắc CHECK cặp |
| N-8 | Observation | `ai_knowledge_chunks` § readiness invariant | Đoạn invariant revision-copy chỉ nêu `source_role`, `source_quality_status`, `language_evidence`. `reading_order`, `bbox_*`, `frame_*` không được đoạn này phủ (riêng `source_text_quality` đã có invariant riêng ở § Approved và ADR-0006 v1.0.3) | Mở rộng đoạn invariant cho các snapshot mới |
| N-9 | Observation | `ai_vision_interpretations` § Business Rules | Không nhắc lại ADR-0020 § D5 "Xoá source Media phải xoá mọi diễn giải dựng từ nó". Nghĩa vụ tồn tại ở ADR, không ở table doc | Nhắc lại nghĩa vụ purge theo Media source |
| N-10 | Observation | `ai_prompt_templates.md`, `ai_assistant_sessions.md` | Hai bảng bị đổi cấu trúc (generated `scope_customer_id`, `UNIQUE (id, customer_id)`) nhưng vẫn thiếu metadata header và vẫn nằm trong allowlist `docs:lint`. Ngoài packet nên không chặn | Cấp metadata khi hai bảng vào migration của chúng |
| N-11 | Observation | `ADR-0006` § Amendment v1.0.2 mục 4; `ai_embeddings` § amendment 2026-09-05 | Vẫn mô tả "hard-delete parent sau khi mọi embedding con `deleted`", đã được v1.0.3 thay bằng mô hình tombstone không hard-delete. Amendment record là lịch sử append-only nên chấp nhận được; v1.0.3 nói rõ điều thay thế | Không hành động; ghi nhận để người đọc sau không hiểu nhầm |

---

## Verification — Round 3

| Lệnh | Kết quả |
| --- | --- |
| `php artisan docs:lint` | PASS — no issues found; legacy allowlist 98 → 94 |
| `php artisan schema:drift --docs-only` | PASS — 95 migration file |
| `php artisan test --filter=MediaReadDerivedCommandTest` | 6 passed, 24 assertions |
| `git diff --check` | Clean |
| `ls database/migrations \| grep ai_` | 0 kết quả — vẫn chưa có migration AI |
| `grep -rn "'processing'" docs/database/ai/ LF-AI.md ADR-0006` | 0 hit |

Không gọi provider bên ngoài, không tạo embedding, không ghi Qdrant.

---

## Verdict — Round 3

```text
Governance / ADR                        PASS
Database docs                           PASS
Four-table migration packet readiness   PASS
Delete barrier design                   PASS
External-provider gate                  PASS
Vision Interpretation readiness         PASS
AI Action 6 gate                        PASS

Migration authorized                    YES — với hai điều kiện bắt buộc dưới
```

### Điều kiện Gate PASS

| # | Điều kiện | Round 2 | Round 3 |
| --- | --- | --- | --- |
| 1 | Không còn P0/P1 | NO | **YES** — 0 P0, 0 P1 |
| 2 | Bốn bảng có contract đồng nhất | NO | **YES** |
| 3 | `source_text_quality` chốt đầy đủ | NO | **YES** — Approved xuyên ADR-0019 → Read Contract → ADR-0006 v1.0.3 → schema |
| 4 | Embedding vocabulary không có `processing` | YES | **YES** |
| 5 | Delete barrier từ ingestion design | NO | **YES** — tombstone ở cả ba tầng |
| 6 | Provider/quota fail-closed | NO | **YES** — ở mức contract |
| 7 | Vision Interpretation có bảng + provenance | NO | **YES** |

### Hai điều kiện bắt buộc mang vào Gate Migration

Đây **không** phải P1 và không giữ gate lại, nhưng cả hai phải được xử lý trong
chính task migration packet, không phải sau:

1. **N-3** — sửa CHECK `prompt_scope_customer_id` trước khi viết DDL. CHECK này
   ship trong packet; viết nguyên văn như doc hiện tại sẽ tạo lại đúng lỗ hổng
   mà R2-14 đã đóng.
2. **R2-25** — populate mười hai entry `ai_*` trong `LF-SCHEMA-CONTRACT.json`
   đồng thời với migration, theo `LF-Schema-Drift.md` bước 2.

### Ràng buộc composition của migration packet

Bốn bảng packet đủ điều kiện đi cùng một migration. Đồ thị FK vẫn acyclic, và
thứ tự tạo bảng nay **bắt buộc** như sau vì `ai_embeddings.model_run_id` là
`NOT NULL`:

```text
users            (đã có)
  ↓
ai_model_runs
ai_knowledge_sources
  ↓
ai_knowledge_chunks
  ↓
ai_embeddings          ← FK tới cả ai_knowledge_chunks và ai_model_runs
```

`ai_vision_interpretations` chỉ phụ thuộc `ai_model_runs`, nên được phép nằm
trong cùng packet hoặc một packet sau; đây là lựa chọn của task migration, không
phải điều kiện gate.

Hai FK hoãn (`assistant_session_id`, `prompt_template_id`) tiếp tục hoãn sang
migration của `ai_assistant_sessions` và `ai_prompt_templates`; target key cho
cả hai nay đã tồn tại trong doc.

### Phạm vi của chữ ký này

PASS này mở bước Freeze → Migration theo `AGENTS.md` § Architecture Workflow. Nó
**không** thay Foundation Freeze do Owner ký, **không** phê duyệt provider nào,
và **không** cho phép gọi provider, tạo embedding hay ghi Qdrant. Runtime AI vẫn
`Not Implemented` và vẫn chịu gate riêng theo `LF-AI` § Provider execution gate.

Sáu P2 và năm Observation ở trên chuyển sang task Docs/Migration tương ứng;
không mục nào trong đó chặn migration packet.

---

# Round 2 Remediation — 2026-09-08

Implementer: Codex theo quyết định trực tiếp của Architecture Owner. Mục này ghi
bằng chứng remediation, không thay thế verdict độc lập của Round 2 và không tự
cấp Migration PASS. Reviewer Round 2 phải re-validate trước khi bước 1 bắt đầu.

Các P1 đã được xử lý ở tầng thiết kế:

* `ai_knowledge_sources` dùng identity NULL-safe đầy đủ gồm owner, `usage_type`,
  content type, locale, fingerprint và processing version; `media_file_id` được
  giữ làm provenance, không dùng làm authorization.
* `video_frame_text` là textual source hợp lệ; chunk snapshot `reading_order`,
  bbox/frame dimensions, language evidence và `source_text_quality`; split unit
  lớn là deterministic, zero-overlap và có `part_index`/character offsets.
* Source, chunk và embedding dùng lifecycle tombstone. Vector bị xóa trước,
  content chunk chỉ được erase khi row đạt `deleted`; FK `RESTRICT` bảo vệ audit.
* Embedding bắt buộc nối `model_run_id`. Mọi provider attempt, kể cả bị chặn,
  qua fail-closed execution gate và có Model Run audit.
* Deferred FK targets đã có tenant composite keys; global prompt dùng generated
  `scope_customer_id` NULL-safe.
* `source_text_quality` đã Approved xuyên ADR → Media Read → AI → chunk schema.
* Bổ sung bảng AI-owned `ai_vision_interpretations` với exact Media revision,
  locator/bbox/timespan và Model Run provenance.
* Bốn table docs trong packet có metadata chuẩn và đã rời legacy lint allowlist.

P2 còn chủ động hoãn sang Gate Migration: bốn entry tương ứng trong
`LF-SCHEMA-CONTRACT.json` vẫn `not_implemented` và phải được populate đồng thời
với migration packet đã qua re-review. Không migration hoặc runtime AI nào được
tạo trong remediation này.

Remediation tracking: `DOC-CONFLICT-0035`; wording lịch sử Learning Gate 2:
`DOC-CONFLICT-0036`.

---

# Round 2 — Independent Architecture Review — 2026-09-08

Reviewer: Architecture Reviewer độc lập (không phải tác giả packet, không phải
implementer remediation). Round này **không** dựa vào verdict của Round 1 bên
dưới; mọi kết luận được kiểm chứng lại trực tiếp từ ADR, contract, database docs,
`LF-SCHEMA-CONTRACT.json`, migration inventory và source hiện có.

Round này không sửa ADR, contract, database doc, schema, migration hay code.
Mọi finding phải do một task/implementer khác vá.

## Nguồn đã kiểm chứng

| Nguồn | Trạng thái tại thời điểm review |
| --- | --- |
| `adr/ADR-0006-AI-Foundation.md` | v1.0.2 Frozen; Amendment v1.1 `Proposed`; "Proposed Amendment Candidate — Media `text_quality`" chưa duyệt |
| `adr/ADR-0017` | v1.0 Approved, Not Implemented |
| `adr/ADR-0018` | v1.1 Approved, Not Implemented |
| `adr/ADR-0019` | v1.15; Amendment v1.14/v1.15 Approved; **v1.11 (`text_quality`) vẫn `Proposed`** |
| `adr/ADR-0020` | v1.1 Approved |
| `platform/LF-AI.md` | v1.3 Frozen |
| `platform/LF-Media-Read-Contract.md` | v1.22 Approved |
| `platform/LF-Media-Processing-Contract.md` | § 4 đã mở `sheet`/`region` |
| `database/ai/*.md` (4 bảng) | Không có Version/Document Status/Last Updated; nằm trong allowlist `config/docs-lint.php` |
| `database/LF-SCHEMA-CONTRACT.json` | 11 entry `ai_*`, cả 4 bảng packet có `columns: []`, `implementation_status: not_implemented` |
| `database/migrations/` | 95 file, **không có migration `ai_*` nào** — đúng với trạng thái chưa authorize |

---

## A — Requirement Traceability

| # | Requirement | Source document | Schema/table field | Constraint | Verification | Kết quả |
| --- | --- | --- | --- | --- | --- | --- |
| A1 | Tenant ownership | Guardrails § Tenant Rule 1–2 | `customer_id` cả 4 bảng | `NOT NULL` | Đọc 4 doc | PASS |
| A2 | Tenant-aware FK | Guardrails § Tenant Rule 5 | `(knowledge_source_id, customer_id)`, `(knowledge_chunk_id, customer_id)`, `(created_by, customer_id)`, `(user_id, customer_id)` | composite FK RESTRICT + `UNIQUE (id, customer_id)` | Đọc 4 doc | PASS |
| A3 | Index customer-leading | Guardrails; Learning/Media precedent | Toàn bộ INDEX/UNIQUE của 4 bảng | — | Đọc 4 doc | PASS |
| A4 | Owner context, không theo Media File | Read Contract § 3, § 7 | `source_type` + `source_id` | CHECK `source_type IN (...)` | So với Read Contract § 3 | **FAIL** — R2-01, R2-08 |
| A5 | Media revision identity | Read Contract § 4.1 | `source_fingerprint`, `processing_version` | CHECK cặp NOT NULL khi `content_type` NOT NULL | So unique key | **FAIL** — R2-02 |
| A6 | Source không trộn revision | `ai_knowledge_sources` § Indexes | UNIQUE (…, `processing_version`) | UNIQUE | Phân tích va chạm khoá | **FAIL** — R2-02, R2-03 |
| A7 | Stale/rebuild lifecycle | Read Contract § 7; ADR-0006 § Knowledge | `status` | CHECK 5 giá trị | Đọc doc | PASS |
| A8 | `source_text_quality` chốt đầy đủ | ADR-0019 v1.11; ADR-0006 candidate | *(không có trong Fields table)* | CHECK chỉ nằm trong mục `Proposed` | Đọc doc + chuỗi ADR | **FAIL** — R2-09 |
| A9 | Revision-copy invariant của quality | ADR-0006 candidate | *(chưa tồn tại)* | — | — | **FAIL** — R2-09 |
| A10 | Chunk thuộc đúng source/tenant | Guardrails | `(knowledge_source_id, customer_id)` | composite FK | Đọc doc | PASS |
| A11 | Locator quay về Media Read unit | Processing Contract § 4 | `locator_type`/`locator_start`/`locator_end` | CHECK 4 giá trị | So § 4 | PASS trên cột; **FAIL** trên prose — R2-04 |
| A12 | Provenance đủ để rerank | ADR-0006 v1.0.1 § 6; LF-AI § retrieval | `source_role`, `source_quality_status`, `language_evidence` | CHECK một phần | Đối chiếu policy | **FAIL** — `reading_order` thiếu, R2-07 |
| A13 | Deterministic chunking / idempotency | Yêu cầu review; `ai_knowledge_chunks` § Business Rules | `sequence_no`, `content_hash` | UNIQUE (customer_id, source, sequence_no, content_hash) | Phân tích rebuild | **FAIL** — R2-05 |
| A14 | Một Media unit → một chunk | Read Contract § 7 | `locator_start = locator_end` | Prose invariant, không có CHECK | Đọc doc | PARTIAL — R2-06 |
| A15 | Split deterministic khi vượt giới hạn | Yêu cầu review | *(không tồn tại)* | — | — | **FAIL** — R2-06 |
| A16 | Embedding trong cùng packet | Yêu cầu review | `ai_embeddings` | — | Doc tồn tại, không migration | PASS (doc), gated |
| A17 | Embedding vocabulary 6 giá trị | ADR-0006 v1.0.2 | `status` | `CHECK (status IN ('pending','ready','failed','stale','deletion_pending','deleted'))` | grep `'processing'` trên toàn `docs/database/ai/`, `LF-AI.md`, `ADR-0006` → 0 hit | **PASS** |
| A18 | Lifecycle transition canonical | ADR-0006 v1.0.2 § deletion | *(chỉ vocabulary)* | Không có transition table/guard | Đọc doc | **FAIL** — R2-11 |
| A19 | Qdrant derived, DB là SoT | ADR-0006 v1.0.2; `ai_embeddings` § Design Notes | `vector_store` | `CHECK (vector_store = 'qdrant')` | Đọc doc | PASS |
| A20 | Delete barrier trước hard-delete | ADR-0006 v1.0.2 mục 4; ADR-0018 v1.1 | FK RESTRICT + `deletion_*` | RESTRICT | Phân tích trình tự purge | **FAIL** — R2-12 |
| A21 | Mọi provider execution có run row | ADR-0006 § Model Run Audit; ADR-0020 D2 | `ai_model_runs` | `status` CHECK 6 giá trị | Đối chiếu `ai_embeddings` | **FAIL** — R2-10, R2-13 |
| A22 | Hai deferred FK đúng contract | `ai_model_runs` § Indexes note | `assistant_session_id`, `prompt_template_id` | FK hoãn sang migration bảng đích | Kiểm `ai_assistant_sessions.md`, `ai_prompt_templates.md` | **FAIL** — R2-14 |
| A23 | Không FK vòng không rollback được | Yêu cầu review | — | 4 bảng tạo theo chuỗi sources → chunks → embeddings; `ai_model_runs` độc lập | Phân tích đồ thị FK | PASS |
| A24 | Không ghi credential/source text vào audit | ADR-0006; LF-AI § Model Run Audit | `error_code`, `safety_metadata`, `metadata`, `last_error_code` | Prose only | Đọc doc | PASS (prose), không có ràng buộc vật lý — Observation |
| A25 | Provider/purpose/data-class approval | ADR-0018 § External-processing eligibility | *(không tồn tại)* | `provider VARCHAR(50)` không allow-list | grep toàn `docs/` | **FAIL** — R2-15 |
| A26 | Quota chặn trước provider call | ADR-0020 D3 | *(không tồn tại)* | — | grep `quota` toàn `docs/` | **FAIL** — R2-13, R2-15 |
| A27 | Thiếu approval → fail-closed | ADR-0018 | *(không tồn tại)* | — | — | **FAIL** — R2-15 |
| A28 | Tạo schema ≠ kích hoạt provider | ADR-0018; ADR-0020 § Owner Approval | — | — | Không migration, không code AI | PASS |
| A29 | ADR-0020 yêu cầu bảng riêng | ADR-0020 § Consequences | *(không có database doc)* | — | `ls docs/database/ai/` | **FAIL** — R2-16 |
| A30 | Không lưu interpretation vào bảng Media | ADR-0020 D1; ADR-0019 D4 | `media_video_frame_texts.md` ghi rõ "không lưu diễn giải AI" | — | Đọc doc Media | PASS |
| A31 | Provenance của interpretation | ADR-0020 D1, D2, D4 | *(không tồn tại)* | — | — | **FAIL** — R2-16 |
| A32 | ADR-0006 Amendment v1.1 đúng trạng thái | ADR-0006 § Amendment Record v1.1 | `ai_model_runs` § Business Rules đánh dấu "Proposed, chưa có hiệu lực" | — | Đọc ADR + doc | **PASS** |
| A33 | Amendment v1.1 không chặn Knowledge Foundation | ADR-0006 § Amendment Record v1.1 | Không thêm/bớt/đổi tên bảng nào | — | Đọc ADR | **PASS** |
| A34 | Rollback fail-closed khi có evidence | ADR-0019 v1.15 precedent | *(chưa có migration để review)* | — | — | GATED — R2-18 |
| A35 | Schema contract đồng nhất | `database/LF-Schema-Drift.md` | 4 entry rỗng | — | `schema:drift --docs-only` PASS (vacuously) | PARTIAL — R2-25 |

---

## B — Findings

### P0 — tenant / security / data loss

Không có. Cả bốn bảng có `customer_id NOT NULL`, `UNIQUE (id, customer_id)`,
composite FK tenant-aware và mọi index customer-leading. Không tìm thấy đường
cross-tenant nào ở mức schema đã tài liệu hoá.

### P1 — lifecycle / provenance / contract / schema blocker

#### R2-01 — `ai_knowledge_sources` không lưu `usage_type`, nên không tái lập được Media Read request

`docs/platform/LF-Media-Read-Contract.md` § 3 quy định request identity gồm
`(actor_id, owner_type, owner_id, usage_type, content_type, locale)` và nói rõ:

> `usage_type` là một phần của định danh nguồn, không phải bộ lọc tiện dụng.

`docs/database/ai/ai_knowledge_sources.md` § Fields không có cột nào chứa
`usage_type`. Hệ quả cụ thể: `content_type = transcript` hợp lệ với cả
`usage_type = audio` và `usage_type = video` (bảng mapping § 3). Một
`course_activity` có cả audio và video sinh ra hai unit khác nhau nhưng đổ vào
**cùng một khoá** `UNIQUE (customer_id, source_type, source_id, content_type,
locale, processing_version)`.

Tác động: (a) rebuild/verification không dựng lại được đúng lời gọi Read; (b)
hai nguồn khác nhau va nhau và một trong hai bị ghi đè im lặng; (c) citation trỏ
về một unit không xác định được.

Acceptance criteria: thêm cột `usage_type` NOT NULL cho Media source (NULL cho
source ngoài Media), đưa vào unique key, và CHECK cặp `(content_type,
usage_type)` khớp bảng mapping Read Contract § 3.

#### R2-02 — Revision identity thiếu `source_fingerprint`, cho phép trộn revision

Read Contract § 4.1 định nghĩa revision bằng **cặp** `processing_version` +
`source_fingerprint`, và có mã lỗi riêng `revision_mismatch` chính vì một
`processing_version` có thể đi với một `source_fingerprint` khác.

Unique key của `ai_knowledge_sources` chỉ gồm `processing_version`. Khi nguồn
được thay bytes (upload lại bản sửa, hoặc redacted derivative theo ADR-0018 —
"fingerprint riêng cho bytes của derivative") và pipeline không đổi version, một
registration mới va khoá và buộc phải `UPDATE` row cũ.

Sau `UPDATE` đó, row source mang `source_fingerprint` mới trong khi toàn bộ
`ai_knowledge_chunks` con vẫn là snapshot của fingerprint cũ. Đây đúng là điều
doc tự cấm ở chính đoạn giải thích khoá: *"một revision mới … là một registration
mới, không ghi đè bản cũ"*.

Acceptance criteria: đưa `source_fingerprint` vào unique key, hoặc ghi rõ bằng
chứng vì sao `processing_version` một mình là revision identity đủ và cách xử lý
`revision_mismatch` mà không ghi đè.

#### R2-03 — NULL trong unique key vô hiệu hoá chống trùng trên MariaDB

`content_type` và `locale` đều NULLable và đều nằm trong unique key. MariaDB coi
mỗi NULL là khác nhau trong UNIQUE index, nên:

* mọi source ngoài Media (`content_type NULL`) đăng ký được **không giới hạn số
  lần** cho cùng `(source_type, source_id)`;
* region có `locale = NULL` — trạng thái hợp lệ và được ADR-0006 v1.0.1 § 7 bảo
  vệ ("`locale = null` là undetermined, không phải lỗi") — cũng không dedupe
  được.

Mỗi lần trùng nhân bản toàn bộ chunk và embedding phía dưới: trùng kết quả
retrieval, trùng chi phí embedding, trùng point Qdrant.

Acceptance criteria: dùng sentinel NOT NULL (ví dụ `''`/`'-'`) cho hai cột trong
khoá, hoặc tách unique key theo hai nhánh Media / non-Media, và ghi rõ ngữ nghĩa
NULL đã chọn.

#### R2-04 — `ai_knowledge_chunks` § Business Rules mâu thuẫn chính Fields/CHECK của nó

§ Business Rules:

> `locator_type` là `page` hoặc `timespan`, giá trị luôn là text.

§ Fields và § Indexes cùng file:

```sql
CHECK (locator_type IN ('page','timespan','sheet','region'));
```

`LF-Media-Processing-Contract` § 4 (đã kiểm chứng trực tiếp) hiện liệt kê đủ bốn
`page | timespan | sheet | region`. Prose Business Rules là bản trước
DOC-CONFLICT-0018 và chưa được sửa. Người viết migration đọc Business Rules
trước Fields sẽ ship CHECK sai và chặn mọi chunk `region`/`sheet` — tức chặn
đúng phần Media retrieval mà packet này tồn tại để phục vụ.

Acceptance criteria: sửa bullet Business Rules khớp CHECK và § 4 hiện hành.

#### R2-05 — `sequence_no` không unique như Business Rules tuyên bố; rebuild không idempotent

Business Rules: *"`sequence_no` unique trong một source/content version."*

Physical: `UNIQUE (customer_id, knowledge_source_id, sequence_no, content_hash)`.

Vì `content_hash` nằm trong khoá, hai chunk khác nội dung ở **cùng**
`sequence_no` cùng source cùng tồn tại hợp lệ. Một lần rebuild với chunker khác
(hoặc một lần retry ghi một phần) để lại hai row `sequence_no = 1` cùng
`status = 'active'`; retrieval trả cả hai và không có luật nào chọn.

Acceptance criteria: chọn một trong hai — (a) `UNIQUE (customer_id,
knowledge_source_id, sequence_no)` và dùng `content_hash` chỉ để so sánh
idempotent; hoặc (b) giữ khoá hiện tại nhưng sửa Business Rules và bổ sung một
khoá partial/điều kiện bảo đảm tối đa một chunk `active` trên mỗi
`sequence_no`.

#### R2-06 — Không có contract split khi một Media unit vượt giới hạn

Doc chốt "mỗi chunk của Media source chứa đúng một Media Read unit" và
`locator_start = locator_end`, nhưng § Design Notes lại nói *"Chunk overlap, size
and versioning strategy remain owner-review decisions."*

Một `extracted_text` page hoặc một `table` lớn vượt context window của embedding
model không có luật nào xử lý. Nếu split, hai chunk chia nhau đúng một locator và
không có cột nào (`part_index`, `char_offset`) để sắp thứ tự, dedupe hay trích
dẫn phân biệt. Yêu cầu "Split deterministic khi vượt giới hạn" hiện không có
tài liệu nào đáp ứng.

Acceptance criteria: chốt max token/char cho một chunk, luật split deterministic
(điểm cắt, thứ tự, tie-break), cột định danh phần và invariant citation của các
phần thuộc cùng unit.

#### R2-07 — `reading_order` không được snapshot, làm ranking policy đã Approved không thực thi được

ADR-0006 Amendment v1.0.1 § 6 (Approved) yêu cầu context expansion trong phạm vi
`reading_order distance <= 2`. `LF-AI.md` § Media evidence retrieval policy
(Approved) yêu cầu kết quả retrieval mang **`reading order` của từng unit**.

`ai_knowledge_chunks` snapshot `source_role`, `source_quality_status`,
`language_evidence` — **không có `reading_order`**. Locator `region` là
`<page>#<ordinal>`, và `docs/database/media/media_extracted_regions.md:100` nói
rõ hai thứ khác nhau: *"`ordinal` là thứ tự vùng trong một trang; `reading_order`
là thứ tự trong toàn tài liệu"*. Vậy `reading_order` **không** suy ra được từ
locator.

Hệ quả: modifier bắt buộc thứ sáu của một amendment đã Approved chỉ chạy được
nếu mỗi candidate gọi lại Media Read — phá đúng mục đích của snapshot.

Acceptance criteria: thêm `reading_order` (nullable cho content type không có)
vào `ai_knowledge_chunks` cùng invariant revision-copy như ba signal hiện có.

#### R2-08 — POLICY_CONFLICT: `media_file_id` bắt buộc ở LF-AI, bị cấm ở `ai_knowledge_sources`

`LF-AI.md` § Media evidence retrieval policy (Approved 2026-09-05):

> Kết quả retrieval phải mang nguyên `media_file_id`, `source_fingerprint`,
> `processing_version`, locator và reading order của từng unit.

`ai_knowledge_sources.md` § Business Rules (Approved 2026-08-25):

> AI không cầm `media_file_id`: cùng một file có thể phục vụ hai Activity với
> hai mức quyền khác nhau, nên quyền gắn với owner.

Hai nguồn Approved quy định ngược nhau về cùng một field. Đây là quyết định
schema-shape: hoặc `ai_knowledge_sources`/`ai_knowledge_chunks` cần cột
`media_file_id`, hoặc `LF-AI` phải nói rõ `media_file_id` chỉ xuất hiện trong
response của một lời gọi Read tại thời điểm phục vụ và không được lưu.

Acceptance criteria: Owner chọn một nhánh; cả hai tài liệu ghi cùng một câu.

#### R2-09 — `source_text_quality` chưa chốt ở mọi tầng; không có trong Fields/CHECK

Chuỗi phụ thuộc, đã kiểm chứng từng file:

| Tầng | File | Trạng thái |
| --- | --- | --- |
| Producer | `adr/ADR-0019` Amendment v1.11 | **Proposed — pending Owner approval** |
| Read | `platform/LF-Media-Read-Contract` § Region text quality | **Proposed** |
| Consumer ADR | `adr/ADR-0006` § Proposed Amendment Candidate | **Pending** |
| Consumer domain | `platform/LF-AI.md` § Media region text-quality candidate policy | **Proposed** |
| Database | `database/ai/ai_knowledge_chunks.md` § Region text-quality snapshot | **Proposed 2026-09-05** |

Quan trọng hơn trạng thái: cột **không có trong § Fields** và CHECK **không có
trong § Indexes** của `ai_knowledge_chunks.md`. Nó chỉ tồn tại trong một mục
`Proposed` ở đầu file. Một migration dựng từ § Fields sẽ không có cột này.

Revision-copy invariant *đã* được phát biểu đúng trong mục Proposed ("snapshot
trực tiếp từ Media Read unit của đúng `source_fingerprint`/`processing_version`
… AI không được tính lại từ chunk text và không backfill NULL thành `normal`")
nhưng chưa nằm trong § Business Rules hay đoạn readiness invariant.

Điều kiện Gate "`source_text_quality` đã chốt đầy đủ" **chưa đạt**.

Acceptance criteria: ADR-0019 v1.11 được Owner duyệt → Read Contract chuyển
Approved → ADR-0006 amendment candidate được duyệt → cột, CHECK và invariant
được đưa vào § Fields / § Indexes / § Business Rules của `ai_knowledge_chunks`.

#### R2-10 — `ai_embeddings` không có liên kết tới `ai_model_runs`

ADR-0006 § Model Run Audit: *"Every provider/model execution creates
`ai_model_runs` provenance"*. `LF-AI.md` § Model Run Audit: *"Mọi provider call
phải có Model Run"*. Sinh embedding **là** một provider call: `ai_embeddings` có
`provider`, `model`, `dimensions`.

`ai_embeddings` § Fields không có `model_run_id` hay `run_uuid`. Không có đường
join nào từ một embedding về run đã tạo ra nó, nên invariant "mọi provider
execution có run row" không kiểm chứng được và chi phí embedding không quy về
được run nào.

Acceptance criteria: thêm `model_run_id` (composite FK `(model_run_id,
customer_id)` → `ai_model_runs (id, customer_id)`) hoặc `run_uuid`, và ghi rõ
nullability cho row lịch sử.

#### R2-11 — Lifecycle của `ai_embeddings` chỉ có vocabulary, không có transition contract

Doc liệt kê sáu giá trị và mô tả nhánh delete trong ADR-0006 v1.0.2. Không tài
liệu nào phát biểu bảng transition canonical:

```text
pending                                  → ready | failed
ready | failed                           → stale
pending | ready | failed | stale         → deletion_pending
deletion_pending                         → deleted
```

Cụ thể chưa quy định: `pending → stale` có hợp lệ không; `deleted` có phải
terminal không; `deleted → ready` bị cấm ở đâu; `deletion_pending → ready`
(rollback khi huỷ purge) có được phép không. Bốn CHECK hiện có chỉ ràng buộc
timestamp đi kèm, không ràng buộc chuyển trạng thái.

Learning Foundation đã có tiền lệ enforce transition bằng trigger
(`2026_08_13_010000_create_learning_foundation_tables_and_triggers.php`); AI
Foundation chưa quyết định dùng trigger, application guard, hay cả hai.

Acceptance criteria: đưa bảng transition trên vào `ai_embeddings.md` § Business
Rules, tuyên bố `deleted` terminal, và chốt cơ chế enforce (trigger hay guard)
trước khi viết migration.

#### R2-12 — Delete barrier: trình tự purge không xác định; source/chunk không có state purge

Ba tuyên bố hiện đang không tương thích:

1. ADR-0006 v1.0.2 mục 4 + `ai_embeddings` § amendment: *"Parent chunk/source
   không hard-delete trước khi mọi child embedding đã `deleted`."*
2. `ai_knowledge_chunks` § Indexes: `FOREIGN KEY (knowledge_chunk_id,
   customer_id) … RESTRICT` — RESTRICT chặn xoá parent khi **còn bất kỳ row
   con nào**, kể cả row đã `deleted`.
3. Không tài liệu nào nói row `deleted` được purge hay giữ lại làm audit.

Nếu row `deleted` được giữ (đọc tự nhiên của một state terminal có `deleted_at`),
parent **không bao giờ** hard-delete được. Nếu row `deleted` bị xoá vật lý, bằng
chứng đã xoá point Qdrant biến mất — mất chính audit mà ADR-0018 § Retention bắt
phải phủ toàn provenance chain.

Thêm vào đó, `ai_knowledge_sources.status` (`pending|active|stale|archived|
failed`) và `ai_knowledge_chunks.status` (`active|stale|archived|failed`) **không
có** state tương đương `deletion_pending`, và không có `deletion_requested_at` /
`deletion_attempts` / `last_error_code`. Một purge request bị chặn ở barrier
không có chỗ để tồn tại, retry hay reconcile, và retrieval không có cách loại
source đang chờ purge.

`ON DELETE`/`ON UPDATE` là quyết định DDL, không sửa được rẻ sau khi có dữ liệu.

Acceptance criteria: chốt (a) row `deleted` giữ hay purge và ai purge; (b) trình
tự chính xác từ purge request tới hard-delete; (c) state/cột purge cho source và
chunk hoặc bằng chứng vì sao không cần; (d) FK action tương ứng.

#### R2-13 — `ai_model_runs` không phân biệt được quota-blocked với safety-blocked, và `blocked` không bắt buộc `error_code`

ADR-0020 D2 bắt buộc một run row cho **mọi** call kể cả quota-blocked và
safety-blocked. D3 nói *"Vượt quota là một error code có tên"*.

`ai_model_runs` có `status = 'blocked'`, `error_code` và `safety_metadata`,
nhưng CHECK hiện có chỉ là:

```sql
CHECK (status <> 'failed' OR error_code IS NOT NULL);
```

Một run `blocked` với `error_code IS NULL` hợp lệ về mặt schema. Không có cột
nào phân loại lý do block (quota / safety / approval), nên không truy vấn được
"bao nhiêu call bị chặn vì quota trong kỳ này" — chính con số mà D3 tồn tại để
tạo ra.

Acceptance criteria: thêm `CHECK (status <> 'blocked' OR error_code IS NOT
NULL)` và chốt vocabulary `error_code` cho ba nhóm block (ít nhất
quota / safety / approval), hoặc một cột `block_reason` có CHECK.

#### R2-14 — Hai deferred FK không khả thi như doc cam kết

`ai_model_runs.md` khẳng định: *"Hai khóa đó được thêm trong chính migration tạo
ra bảng đích, không phải bỏ quên."* Kiểm chứng bảng đích:

| Bảng đích | `UNIQUE (id, customer_id)` | `customer_id` |
| --- | --- | --- |
| `ai_assistant_sessions.md` § Indexes | **không có** | NOT NULL |
| `ai_prompt_templates.md` § Indexes | **không có** | **NULLABLE** (global prompt) |

Với `ai_assistant_sessions`, composite FK `(assistant_session_id, customer_id)`
không có target key để tham chiếu. Với `ai_prompt_templates`, ngoài việc thiếu
key, `customer_id NULL` cho global prompt làm composite FK **về nguyên tắc không
dùng được**: một run tenant 1 dùng global prompt có `(prompt_template_id, 1)`
trong khi row đích là `(prompt_template_id, NULL)`.

Cam kết deferred FK vì vậy chưa thành lập được. Đây là quyết định thiết kế phải
chốt **trước** khi `ai_model_runs` vào migration, vì shape của bảng đích quyết
định `ai_model_runs` có cần cột phụ trợ hay không.

Acceptance criteria: chốt chiến lược tham chiếu prompt global (ví dụ FK đơn cột
tới `ai_prompt_templates(id)` cộng guard ứng dụng, hoặc sentinel `customer_id`),
bổ sung `UNIQUE (id, customer_id)` vào cả hai bảng đích, và cập nhật note trong
`ai_model_runs.md`.

#### R2-15 — External-provider gate không tồn tại ở mức contract hay schema

ADR-0018 § External-processing eligibility bắt mọi call ra ngoài tenant boundary
phải có approval tường minh cho *provider, purpose, data classes, tenant,
region/storage, retention/deletion, audit/provenance và credential boundary*, và:

> Nếu external-processing approval còn thiếu, external workflow phải fail-closed
> hoặc trả `DECISION_REQUIRED`.

Kiểm chứng toàn `docs/`:

* không có bảng, cột hay contract nào biểu diễn bộ tuple approval trên;
* `ai_model_runs.provider VARCHAR(50)` không có CHECK, không có allow-list,
  không tham chiếu registry nào;
* `external_processing_allowed` chỉ tồn tại như một `identifiers` entry trong
  `LF-DOCUMENTATION-MANIFEST.json` cho ADR-0018 — không có field/table nào mang
  tên đó;
* `quota` trong `docs/` chỉ xuất hiện ở SaaS Commercial/Usage
  (`ADR-0009`, `LF-SaaS-Usage`) với luật "allowed quota/limit thuộc Commercial";
  không có contract nào nối AI provider call với quota check đó.

Vì vậy danh sách ADR-0018 (Docling cloud, Bedrock, Textract, OpenAI, Claude,
Gemini, OpenRouter, vision service, mọi provider bên ngoài) hiện được kiểm soát
**hoàn toàn bằng prose**. Không có cơ chế nào làm cho một provider chưa approve
fail-closed, và không có điểm nào để đặt quota check trước call theo ADR-0020 D3.

Điểm tích cực đã xác nhận: **tạo schema không kích hoạt provider nào**. Không có
migration `ai_*`, không có code đọc/ghi bảng `ai_*` (`grep` toàn `app/`, `tests/`,
`routes/`, `config/`, `database/` → chỉ trúng `config/docs-lint.php` allowlist và
`config/media.php` `owner_type = 'ai_knowledge'`).

Acceptance criteria: một contract (và, nếu Owner chọn, một bảng governance) định
nghĩa approval tuple và điểm enforce trước call, cộng nghĩa vụ ghi
`ai_model_runs` với `status = 'blocked'` khi approval hoặc quota thiếu.

#### R2-16 — Vision Interpretation: bảng bắt buộc theo ADR-0020 chưa tồn tại

ADR-0020 § Consequences: *"Cần thêm bảng lưu diễn giải trong AI domain; hình dạng
của nó thuộc Database Docs, không thuộc ADR này."*

Kiểm chứng: `ls docs/database/ai/` có 11 file, **không có** bảng interpretation.
`grep -rn "ai_vision\|vision_interpretation\|ai_interpretations" docs/database/`
→ 0 hit. `ADR-0006` § Foundation Tables và `LF-AI.md` § Database Namespace vẫn
liệt kê đúng 11 bảng.

Do đó ba yêu cầu provenance của ADR-0020 (Media revision +
locator/page/timespan/bbox + `run_uuid`) chưa có nơi nào để cư trú. Chúng cũng
**không** được phép cư trú trong `ai_knowledge_chunks`: chunk là derived text của
một Media Read unit, còn interpretation là suy luận — trộn hai thứ tái tạo đúng
lỗi mà ADR-0019 D4 và ADR-0020 D1 cấm.

Đã xác nhận đúng ở phía Media: `media_video_frame_texts.md:17` ghi rõ *"Bảng
không lưu diễn giải AI"*; không có bảng `media_*` nào chứa interpretation.

Acceptance criteria (không thiết kế trong review này): (a) một ADR-0006 amendment
hoặc ghi nhận mở rộng Foundation table set theo thẩm quyền của ADR-0020;
(b) một database doc mới với tenant identity, owner-context anchor (không
`media_file_id`), `content_type`, `locale`, `source_fingerprint`,
`processing_version`, locator + bbox/timespan, `run_uuid`/`model_run_id` bắt
buộc, status/stale lifecycle, và delete barrier thừa kế ADR-0018 D5;
(c) cập nhật `LF-AI.md` § Database Namespace.

#### R2-17 — `video_frame_text` là read unit Approved nhưng không đăng ký được

`LF-Media-Read-Contract` § "Video frame OCR read — Approved 2026-09-07" mở
`content_type = video_frame_text` và nói rõ *"AI tự join theo timeline"*. § 2
liệt kê nó trong tập `content_type` của unit. ADR-0019 v1.14 Approved and Frozen.

Nhưng § 7 cùng file vẫn giới hạn textual registration ở năm giá trị
`extracted_text | transcript | region | table | formula`, và
`ai_knowledge_sources` CHECK sao chép đúng năm giá trị đó. Không có đường nào để
AI đăng ký, chunk hay embed frame OCR text.

Thêm nữa, unit frame OCR mang `{detected_locale, script, reading_order, bbox,
frame_width, frame_height}`; `ai_knowledge_chunks` không có cột nào giữ bbox hay
kích thước khung, nên ngay cả khi mở vocabulary thì provenance vẫn không đủ để
quay lại đúng vùng chữ trên khung hình.

Đây là mâu thuẫn nội bộ của Read Contract (§ 2/header amendment vs § 7); doc AI
đang bám § 7 một cách trung thực. Nhưng packet AI vì thế đang chậm một amendment
Media so với ngày hôm nay.

Acceptance criteria: Media contract owner quyết `video_frame_text` có phải
textual knowledge source hay không; nếu có, mở § 7, mở CHECK `content_type`, và
bổ sung provenance bbox/frame cho chunk; nếu không, ghi lý do vào § 7 để AI
không phải suy đoán.

#### R2-18 — Rollback fail-closed chưa có nơi nào phát biểu cho AI Foundation

ADR-0019 v1.15 đặt tiền lệ rõ: *"Rollback migration phải fail-closed khi còn
`media_video_frame_texts`, vì row ready hoặc archived đều là citation-bearing
historical evidence."*

Không tài liệu AI nào phát biểu điều tương đương, dù cả bốn bảng đều mang
evidence lịch sử: `ai_knowledge_sources` (`archived` giữ để Proposal đã trích dẫn
vẫn truy lại được — chính doc nói vậy), `ai_knowledge_chunks` (citation),
`ai_embeddings` (bằng chứng đã xoá point Qdrant), `ai_model_runs` (audit chi phí
và provenance, ADR-0020 D2).

Acceptance criteria: bốn doc ghi nghĩa vụ rollback fail-closed khi bảng còn row,
và migration tương lai phải có test chứng minh `down()` từ chối khi có dữ liệu.

### P2 — maintainability / quality / operational

| ID | Vị trí | Nội dung | Acceptance criteria |
| --- | --- | --- | --- |
| R2-19 | `database/ai/*.md` (cả 4) | Không có `Version` / `Document Status` / `Implementation Status` / `Last Updated`; đang nằm trong allowlist `config/docs-lint.php`. Packet sắp Freeze mà bốn doc không tự khai trạng thái approval | Thêm metadata header, rời allowlist, `docs:lint` vẫn PASS |
| R2-20 | `ai_knowledge_sources.md` § Relationships | Vẫn ghi *"optional Media File reference"* sau khi amendment 2026-08-25 đã bỏ `media_file_id` | Xoá câu đó |
| R2-21 | `ai_knowledge_chunks.md` § Indexes | `source_quality_status` có CHECK vocabulary, `source_role` thì không, dù cả hai đều là snapshot có vocabulary đóng ở Media | Thêm CHECK khớp `media_extracted_regions`: `paragraph, heading, list, table, figure, caption, header, footer, other` |
| R2-22 | `ai_embeddings.md` § Indexes | Có CHECK cho `deletion_pending`/`deleted` nhưng không có `CHECK (status <> 'ready' OR embedded_at IS NOT NULL)` | Thêm CHECK, hoặc ghi lý do bất đối xứng |
| R2-23 | `ai_knowledge_chunks.md` | Invariant *"fail toàn ingestion revision, không publish chunk một phần"* không biểu diễn được: `status` không có state trước-publish, default là `active` | Thêm `pending` vào vocabulary, hoặc ghi rõ ranh giới transaction bảo đảm atomicity |
| R2-24 | `ai_knowledge_sources.md` § Indexes | Không có CHECK nối `content_type IS NOT NULL` với `source_type IN ('course_activity','course_version_activity')` — hai `owner_type` duy nhất Read Contract § 3 nhận. Hiện `source_type='course_version'` + `content_type='region'` là hợp lệ nhưng không đọc lại được | Thêm CHECK, hoặc mở rộng `owner_type` ở Read Contract § 3 |
| R2-25 | `database/LF-SCHEMA-CONTRACT.json` | Bốn entry `ai_*` của packet có `columns: []`, `checks: []`, `foreign_keys: []`. `schema:drift --docs-only` PASS một cách rỗng và không kiểm chứng được gì cho packet này | Populate contract cùng migration theo `LF-Schema-Drift.md` bước 2 |
| R2-26 | `ai_prompt_templates.md` § Indexes | `UNIQUE (COALESCE(customer_id, 0), code, version)` không phải DDL chạy được trên MariaDB 11.4 (không có functional index) | Đổi sang generated column + UNIQUE, hoặc sentinel NOT NULL |

### Observations

| ID | Nội dung |
| --- | --- |
| O-1 | `LF-Media-Read-Contract` § 2 liệt kê `extracted_text \| transcript \| caption_asset \| variant \| region \| table \| video_frame_text` — **thiếu `formula`**, dù § 5 và § 7 đều có. Không ảnh hưởng packet AI nhưng là nguồn hiểu nhầm |
| O-2 | Round 1 § Review Information trích `LF-Media-Read-Contract` v1.19 (nay v1.22) và `ADR-0006` v1.0.1 (nay v1.0.2) |
| O-3 | `docs/LF-INDEX.md:390` mô tả artifact này là *"CHANGES REQUIRED, migration not authorized"* — vẫn đúng về kết luận, cần cập nhật khi Round 2 được ghi nhận |
| O-4 | `LF-Documentation-Conflicts` § Classification Model không liệt kê `DOCUMENT_CONTRADICTION`, nhưng Active Register dùng nhãn đó ở nhiều dòng (0033, 0027, 0026, 0025, 0024, 0022, 0018, 0016, 0017). Taxonomy của register không tự nhất quán |
| O-5 | `external_processing_allowed` là `identifiers` entry của ADR-0018 trong manifest nhưng không tương ứng field/table nào — keyword match không chứng minh contract tồn tại (đúng cảnh báo của `docs/README.md`) |
| O-6 | ADR-0006 Amendment v1.1 được xử lý **đúng**: đánh dấu `Proposed` ở ADR, `LF-AI.md` § Learning Integration và `ai_model_runs.md` § Business Rules; không thêm/bớt bảng; Mastery Profile ghi rõ không đăng ký Knowledge Source, không chunk, không embed. Amendment này **không** chặn migration Knowledge Foundation, và cũng không được migration nào encode |
| O-7 | Embedding vocabulary sạch: `grep 'processing'` trên `docs/database/ai/`, `LF-AI.md`, `ADR-0006` không có hit nào coi `processing` là status embedding |
| O-8 | Không tìm thấy vòng FK. Thứ tự tạo bảng khả thi: `ai_knowledge_sources` → `ai_knowledge_chunks` → `ai_embeddings`; `ai_model_runs` độc lập (chỉ FK tới `users`) |
| O-9 | `ai_model_runs` cấm credential/source text bằng prose (§ Business Rules) nhưng không có ràng buộc vật lý nào trên `metadata`/`safety_metadata`/`error_code`. Chấp nhận được ở mức schema; cần code review gate khi implement |

---

## C — Verdict

```text
Governance / ADR                        PASS
Database docs                           FAIL
Four-table migration packet readiness   FAIL
Delete barrier design                   FAIL
External-provider gate                  FAIL
Vision Interpretation readiness         FAIL
AI Action 6 gate                        FAIL

Migration authorized                    NO
```

Ghi chú từng verdict:

* **Governance/ADR — PASS.** ADR-0006 Frozen với hai amendment Approved đúng
  phạm vi; v1.1 giữ `Proposed` nhất quán ở cả ba nơi và không chặn Knowledge
  Foundation; ADR-0017/0018/0019/0020 Approved và được tham chiếu đúng chiều.
  Chuỗi `text_quality` giữ `Proposed` nhất quán từ ADR-0019 xuống database doc —
  đó là governance đúng, dù nó làm Gate condition không đạt.
* **Database docs — FAIL.** R2-01→R2-09, R2-13, R2-14, R2-17, R2-18 và tám mục
  P2 nằm trong bốn database doc.
* **Four-table migration packet — FAIL.** Bốn quyết định phải chốt **trước** DDL
  vì không sửa rẻ sau khi có dữ liệu: hình dạng unique key (R2-01, R2-02, R2-03),
  FK action và trình tự purge (R2-12), target key của hai deferred FK (R2-14),
  và transition enforcement (R2-11).
* **Delete barrier — FAIL.** Barrier vật lý tồn tại (FK RESTRICT) nhưng trình tự
  purge không xác định và source/chunk không có state purge (R2-12).
* **External-provider gate — FAIL.** Không có contract hay schema nào cho
  approval tuple, quota check trước call, hay fail-closed (R2-15, R2-13).
* **Vision Interpretation — FAIL.** Bảng bắt buộc theo ADR-0020 chưa tồn tại
  (R2-16).
* **AI Action 6 gate — FAIL.** Còn 18 P1.

## D — Điều kiện Gate PASS

| # | Điều kiện | Trạng thái | Chặn bởi |
| --- | --- | --- | --- |
| 1 | Không còn P0/P1 | **NO** | 18 P1 |
| 2 | Bốn bảng có contract đồng nhất | **NO** | R2-04, R2-05, R2-08, R2-14, R2-20 |
| 3 | `source_text_quality` chốt đầy đủ | **NO** | R2-09 (ADR-0019 v1.11 còn Proposed) |
| 4 | Embedding vocabulary không có `processing` | **YES** | — |
| 5 | Delete barrier từ ingestion design | **NO** | R2-12 |
| 6 | Provider/quota fail-closed | **NO** | R2-15, R2-13 |
| 7 | Vision Interpretation có bảng + provenance contract | **NO** | R2-16 |

Không P0 và điều kiện 4 đạt là hai kết quả tích cực duy nhất của round này.

---

## E — Đề xuất entry cho Conflict Register

Round này **không** sửa `LF-Documentation-Conflicts.md`. Các entry dưới đây là
đề xuất để bước Docs đăng ký; ID mới do owner của register cấp theo thứ tự.

| Đề xuất | Title | Classification (taxonomy yêu cầu) | Classification (nhãn register đang dùng) | Impact | Domain |
| --- | --- | --- | --- | --- | --- |
| E-1 | `media_file_id` bắt buộc ở `LF-AI` § retrieval nhưng bị cấm ở `ai_knowledge_sources` | POLICY_CONFLICT | `CONFLICT` | HIGH | AI × Media |
| E-2 | `ai_knowledge_chunks` § Business Rules nói locator chỉ `page\|timespan`, trái Fields/CHECK cùng file và Processing Contract § 4 | DOCUMENT_CONTRADICTION | `DOCUMENT_CONTRADICTION` | HIGH | AI |
| E-3 | `sequence_no` được tuyên bố unique nhưng UNIQUE key gồm `content_hash` | DOCUMENT_CONTRADICTION | `DOCUMENT_CONTRADICTION` | HIGH | AI |
| E-4 | `video_frame_text` là read unit Approved nhưng Read Contract § 7 và AI CHECK không cho đăng ký | MISSING_CONTRACT | `GAP` | HIGH | Media × AI |
| E-5 | ADR-0020 bắt buộc bảng Vision Interpretation; không có database doc và không có trong Foundation table set | MISSING_CONTRACT | `GAP` | BLOCKER | AI |
| E-6 | Không có contract nào cho external-provider approval tuple, quota-before-call và fail-closed | MISSING_CONTRACT | `GAP` | BLOCKER | AI × SaaS |
| E-7 | Trình tự hard-delete parent mâu thuẫn FK RESTRICT; row `deleted` giữ hay purge chưa quyết | MISSING_CONTRACT | `AMBIGUITY` | HIGH | AI |
| E-8 | `ai_model_runs` cam kết hai deferred FK mà bảng đích không có target key; `ai_prompt_templates.customer_id` NULLable | DOCUMENT_CONTRADICTION | `CONFLICT` | HIGH | AI |
| E-9 | Bốn `database/ai/*.md` của packet không có metadata header, vẫn trong allowlist `docs:lint` | IMPLEMENTATION_DRIFT | `GAP` | MEDIUM | AI |
| E-10 | Bốn entry `ai_*` trong `LF-SCHEMA-CONTRACT.json` rỗng nên `schema:drift` PASS vô nghĩa cho packet | SCHEMA_DRIFT | `GAP` | MEDIUM | AI |
| E-11 | `LF-Documentation-Conflicts` § Classification Model không liệt kê `DOCUMENT_CONTRADICTION` dù register dùng rộng rãi | DOCUMENT_CONTRADICTION | `DOCUMENT_CONTRADICTION` | LOW | Governance |
| E-12 | `LF-Media-Read-Contract` § 2 thiếu `formula` trong tập `content_type` | DOCUMENT_CONTRADICTION | `DOCUMENT_CONTRADICTION` | LOW | Media |

---

## F — Verification đã chạy

| Lệnh | Kết quả |
| --- | --- |
| `php artisan docs:lint` | PASS — no issues found (98 file trong legacy allowlist, gồm cả bốn `database/ai/*.md` của packet) |
| `php artisan schema:drift --docs-only` | PASS — mode=docs-only, 95 migration file |
| `php artisan test --filter=MediaReadDerivedCommandTest` | 6 passed, 24 assertions — hợp đồng đọc phía producer của AI còn xanh |
| `git diff --check` | Clean, exit 0 |
| `ls database/migrations \| grep ai_` | 0 kết quả — không có migration AI nào |
| `grep -rn "ai_knowledge\|ai_embeddings\|ai_model_runs" app/ tests/ routes/ database/ config/` | Chỉ `config/docs-lint.php` (allowlist) và `config/media.php` (`owner_type = 'ai_knowledge'`) |

Không có test contract/read nào của AI Foundation tồn tại — đúng với
`Implementation Status: Not Implemented`. Không gọi provider bên ngoài, không tạo
embedding, không ghi Qdrant trong round này.

---

## G — Remediation backlog (chuyển sang task khác)

Reviewer không vá. Thứ tự dưới đây là thứ tự phụ thuộc, không phải mức ưu tiên.

**Task 1 — Owner decision (chặn mọi task còn lại)**

1. R2-08 — chọn nhánh `media_file_id`.
2. R2-12 — chốt row `deleted` giữ hay purge, và trình tự purge.
3. R2-14 — chốt chiến lược tham chiếu prompt global.
4. R2-09 — quyết ADR-0019 Amendment v1.11.
5. R2-16 — xác nhận thẩm quyền mở Foundation table set cho Vision Interpretation.
6. R2-17 — quyết `video_frame_text` có phải textual knowledge source không.

**Task 2 — Media contract owner**

7. R2-17 — mở hoặc đóng `LF-Media-Read-Contract` § 7 kèm lý do; O-1.
8. R2-09 — nếu v1.11 duyệt, chuyển § Region text quality sang Approved.

**Task 3 — AI database docs implementer** (chỉ chạy sau Task 1)

9. R2-01, R2-02, R2-03 — `usage_type`, `source_fingerprint` trong khoá, ngữ nghĩa NULL.
10. R2-04, R2-05, R2-06, R2-07 — locator prose, `sequence_no`, split contract, `reading_order`.
11. R2-09 — đưa `source_text_quality` vào § Fields/§ Indexes/§ Business Rules.
12. R2-10, R2-11 — `model_run_id` và bảng transition canonical.
13. R2-12 — state/cột purge cho source và chunk.
14. R2-13 — CHECK `blocked` và vocabulary lý do block.
15. R2-18 — nghĩa vụ rollback fail-closed cho cả bốn bảng.
16. R2-19 → R2-26.

**Task 4 — External-provider governance owner**

17. R2-15 — contract approval tuple, điểm enforce quota trước call, fail-closed.

**Task 5 — Vision Interpretation database design**

18. R2-16 — ADR amendment nếu cần + database doc mới + cập nhật `LF-AI.md`.

**Task 6 — Docs**

19. E-1 → E-12 vào `LF-Documentation-Conflicts.md`.
20. O-2, O-3 — cập nhật `LF-INDEX.md:390` và version reference của Round 1.

**Task 7 — Rerun independent review**

21. Chạy lại review này sau Task 2–5. Reviewer round này được phép rerun để xác
    nhận, nhưng không được tự vá.

Không migration nào được authorize cho tới khi rerun đó PASS.

---

# Retrieval-policy amendment review — 2026-09-05

Owner-approved ADR-0006 v1.0.1 assigns ranking exclusively to AI and leaves
Media evidence/order immutable. The policy is implementable from existing Media
Read fields and needs no Media schema or migration. It explicitly preserves
Jamo paragraph evidence, formula-region fallback, table `undetermined`, image
crop and per-unit citations.

**Scoped verdict: PASS for contract boundary.** Overall database verdict below
remains `CHANGES REQUIRED`; this amendment does not authorize AI migrations,
embedding population or production retrieval before F-1–F-7 are resolved.

### Pending Docling 8 text-quality follow-up — 2026-09-05

ADR-0019 v1.11 proposes a new Media Read signal `region.text_quality`. Candidate
AI design snapshots it as nullable `ai_knowledge_chunks.source_text_quality` and
uses `low` only as a post-relevance rank modifier. This follow-up is **not yet
reviewed or approved** and does not change the overall `CHANGES REQUIRED` /
Migration `NO` verdict. Independent rerun must include the new field, CHECK,
revision-copy invariant and NULL semantics before Freeze.

Database-doc follow-up records that F-1–F-5 already have Owner-approved
amendments dated 2026-08-25. The 2026-09-05 amendment additionally aligns
`ai_knowledge_sources.content_type` with `region|table|formula`, aligns chunk
locator vocabulary with `region|sheet`, requires one Media unit per chunk, and
adds immutable snapshots `source_role`, `source_quality_status`,
`language_evidence`. This closes the schema-shape gap introduced by the newer
Media Read contract without weakening tenant or citation identity.

Migration remains unauthorized for two independent reasons: this document is
still not an independent rerun, and F-6/F-7 still require an approved retention
deletion mechanism plus a vector-store/adapter decision. Lexical-only fallback
or a specific vector product must not be invented inside a migration.

---

# F-6/F-7 closure — Owner decision 2026-09-05

F-6 closed by the relational-first `deletion_pending` state machine, exact UUID
+ tenant-filter remote delete, acknowledgment, retry/reconciliation and parent
purge barrier. F-7 closed by Qdrant self-hosted >=1.11 inside the LF-managed
boundary; MariaDB 11.4 remains relational only. Point payload excludes raw text,
PII and signed URLs; every vector hit is post-validated against relational and
Media authorization/revision state.

These decisions close the two architecture questions. **Migration remains NO**
until an independent reviewer reruns the full revised database packet and gives
a PASS; Owner approval cannot substitute for that separate gate.

---

# Round 1 — Author Self-Assessment — 2026-08-25

Mọi mục từ đây tới hết tài liệu là Round 1. Round 2 ở trên không dựa vào
kết luận của Round 1.

# Review Information

| Field | Value |
| --- | --- |
| Domain | AI × Media |
| Parent ADR | [ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md) v1.0.1 — Approved, Frozen |
| Constraining ADR | [ADR-0018 — Media PII And External Processing Boundary](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md) — Approved |
| Consumer Contract | [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) v1.19 |
| Producer Contract | [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) |
| Review Scope | 4 tables on the Media→AI consumer path: `ai_knowledge_sources`, `ai_knowledge_chunks`, `ai_embeddings`, `ai_model_runs` |
| Out Of Scope | `ai_assistant_sessions`, `ai_conversations`, `ai_messages`, `ai_prompt_templates`, `ai_feedback`, `ai_insights`, `ai_recommendations` |

Reason for the subset: LF-Media-Read-Contract § 7 makes AI a registered
consumer of derived content units. These four tables are the entire path from a
Read Service unit to a retrievable embedding. The remaining seven `ai_*` tables
serve conversation and authoring flows that no implemented code reaches today.

Review provenance: this is an **author self-assessment** produced in the same
stream as the Media Read runtime, not an independent Architecture Review. The
checkboxes are a review packet for the next reviewer; they do not by themselves
produce an Approved verdict.

Amendment boundary: [ADR-0006 Amendment Version 1.1](../adr/ADR-0006-AI-Foundation.md)
is **Proposed — pending Architecture Owner approval**. Everything below reviews
the Version 1.0 database shape only; v1.0.1 adds no table or column. The Learning Mastery Profile provenance rule marked
*"Proposed, chưa có hiệu lực"* in `ai_model_runs.md`, `ai_insights.md` and
`ai_recommendations.md` must not be encoded by any migration produced from this
review.

---

# A — Domain Boundary

- [x] AI owns registration, chunking, embedding and run provenance; it owns none
      of the source content.
- [x] `ai_knowledge_sources` registers a reference, never a copy of Course,
      Assessment, Media, Track or LiveClass ownership.
- [x] Chunks and embeddings are declared derived and rebuildable; neither is a
      Source Of Truth.
- [x] **Media→AI stale propagation assigned to AI.** Closed by approved
      `ai_knowledge_sources` amendment: Media exposes revision state; AI marks
      source/chunk/embedding stale and rebuilds.

# B — Tenant Isolation

- [x] Every reviewed table carries `customer_id NOT NULL`.
- [x] Every documented unique key and index is `customer_id`-leading.
- [x] `UNIQUE (id, customer_id)` and composite child FKs are documented by the
      approved 2026-08-25 amendments. F-2 closed.

# C — Read Contract Conformance

- [x] `ai_knowledge_sources` stores fingerprint/version and current textual
      content types. F-1 closed.
- [x] Chunk locator uses Media vocabulary and one unit per Media chunk. F-3
      closed; the 2026-09-05 amendment adds `region|sheet`.
- [x] AI is consumer-only: no reviewed table grants a write path into `media_*`.

# D — Lifecycle And Revision

- [x] Source/chunk/embedding status vocabularies each include a stale state.
- [x] Media revision archiving now exists in runtime
      (`ProcessMediaProcessingJob::archiveSupersededRevisions`), so a detection
      point for staleness is available.
- [x] Contract assigns stale detection/rebuild to AI. Runtime remains pending
      AI Foundation implementation; this is no longer a schema ambiguity.

# E — Retention, Deletion And PII

- [x] No reviewed table stores a provider credential or BYOK secret.
- [x] Qdrant deletion synchronization is frozen by ADR-0006 v1.0.2 and
      ADR-0018 v1.1. F-6 closed; implementation evidence remains future work.

# F — Findings

## F-1 — CLOSED 2026-08-25 — `ai_knowledge_sources` derived content identity

LF-Media-Read-Contract § 7:

> `ai_knowledge_sources` đăng ký theo derived content unit, không theo Media
> File, và lưu `source_fingerprint` cùng `processing_version` của unit đã đọc.

The documented table has neither column. Two mismatches follow:

1. **No stale detection input.** `content_hash` and `source_version` are
   AI-authored fields, not the Media values. Mapping them onto
   `source_fingerprint` / `processing_version` is an undocumented guess, and a
   guess here silently breaks the only mechanism that tells AI its chunks are
   built from a superseded revision.
2. **The `source_type` vocabulary cannot name three of the four content
   types.** Allowed values include `media_file` and `media_transcript`; the Read
   Service returns `extracted_text`, `transcript`, `caption_asset` and
   `variant`. `media_file` also contradicts the contract's explicit "không theo
   Media File".

Required before migration: amend `ai_knowledge_sources.md` to carry the unit
identity — at minimum `source_fingerprint` and `processing_version`, and a
`source_type` vocabulary aligned with the four Read Contract content types.

## F-2 — CLOSED 2026-08-25 — Tenant composite identity and foreign keys

The two most recent Foundation migrations
(`2026_08_24_000000_create_media_processing_substrate`,
`2026_08_23_120000_add_course_template_learning_mapping_intents`) both give each
table `UNIQUE (id, customer_id)` and reference parents by
`(parent_id, customer_id)`, so a cross-tenant reference is rejected by the
database rather than by application code.

The four AI docs declare single-column relationships only
(`knowledge_source_id`, `knowledge_chunk_id`, `media_file_id`, `user_id`,
`prompt_template_id`) and no `UNIQUE (id, customer_id)`. Implemented as
documented, a chunk in tenant A can reference a source in tenant B and nothing
at the schema level prevents it. Guardrails § "Mọi dữ liệu nghiệp vụ phải thuộc
một tenant" makes this an isolation defect, not a style preference.

Required before migration: an explicit decision recorded in the four table
docs — adopt the composite pattern, or document why AI Foundation departs from
it.

## F-3 — CLOSED 2026-09-05 — Chunk locator contract

LF-Media-Processing-Contract § 4 freezes one locator shape for every output:

```text
locator := { locator_type, locator_value }   // page | timespan, value always text
```

`ai_knowledge_chunks.source_locator` is free-form JSON and the doc's own sample
is `{"start_second":0,"end_second":45}` — a third shape, numeric, incompatible
with both `page` and `timespan`. A citation stored this way cannot be joined
back to the `media_extracted_texts` / `media_transcripts` row it came from, which
is the entire purpose of the locator contract.

Required before migration: constrain `source_locator` to the frozen locator
contract, or state in the doc why a chunk-level locator is a distinct vocabulary
and how it maps back.

## F-4 — CLOSED 2026-08-25 — Stale propagation ownership

The Read Contract assigns the split correctly — *"Media báo trạng thái, AI quyết
định"* — but no document says how AI observes the state change. Nothing sets
`ai_knowledge_sources.status = 'stale'`.

The runtime now has the detection point: when a new revision reaches `ready`,
the prior revision flips to `archived`. A registered source holding the old
`processing_version` is detectably stale from that moment. This needs to be
written down as a contract obligation on one side before either side implements
a poll or a signal.

## F-5 — CLOSED 2026-08-25 — Deferred `ai_model_runs` foreign keys

`assistant_session_id` and `prompt_template_id` reference two tables outside
this subset and not implemented. A subset migration must either leave them
nullable and unconstrained — departing from the repo's FK discipline — or defer
`ai_model_runs` until `ai_assistant_sessions` and `ai_prompt_templates` land.

Recommendation: implement `ai_model_runs` with the columns documented but add
the two foreign keys in the migration that creates their targets, and record
that deferral in the table doc so it is not read as an omission.

## F-6 — CLOSED 2026-09-05 — Embedding deletion synchronization

Resolved by ADR-0006 v1.0.2, ADR-0018 v1.1 and the `ai_embeddings` amendment:
MariaDB status moves to `deletion_pending` before remote work; retrieval accepts
only `ready`; exact UUID + tenant-filter deletion must be acknowledged before
`deleted`; failure retries/reconciles and blocks parent hard purge.

## F-7 — CLOSED 2026-09-05 — Vector adapter selection

Resolved by ADR-0006 v1.0.2 and LF-Tech-Stack v1.3: Qdrant self-hosted >=1.11
inside the LF-managed boundary, shared collections partitioned by indexed
`customer_id`, and mandatory tenant filter on every operation. MariaDB 11.4
remains relational only; Qdrant Cloud and external stores remain unapproved.

---

# G — Verdict

```text
AI Foundation — Media Consumer Subset

Database Architecture Review

Status

CHANGES REQUIRED

Migration authorized

NO
```

F-1–F-7 are closed in approved ADR/database/tech contracts. Migration remains
unauthorized because this packet still requires an independent architecture
rerun before Freeze under `AGENTS.md`.

# H — Owner Actions

Status 2026-09-05: actions 1–5 and F-6/F-7 are Owner-approved. Action 6—the
independent rerun—remains open; no migration is authorized.

1. Amend `ai_knowledge_sources.md`: add `source_fingerprint` and
   `processing_version`; align `source_type` with the four Read Contract content
   types; remove or justify `media_file_id` against "không theo Media File".
2. Decide the tenant composite identity/FK question for AI Foundation and record
   it in all four table docs.
3. Constrain `ai_knowledge_chunks.source_locator` to the frozen locator
   contract, or document the mapping back to it.
4. Assign the stale-propagation obligation to Media or AI in writing.
5. Decide whether `ai_model_runs` ships in this subset with deferred foreign
   keys, or waits for its reference tables.
6. Confirm this review is re-run as an independent review before Freeze; the
   present document is an author self-assessment.

---

## Owner

Architecture Team
