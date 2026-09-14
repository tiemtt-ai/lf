# AI Vision Interpretation — Implementation Review

Version: 1.4

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-14

Document Path: quality/LF-AI-Vision-Interpretation-Implementation-Review.md

---

# Phạm vi

Bước 6 — Vision Interpretation. Lượt đầu (packet schema) tạo bảng
`ai_vision_interpretations` theo [database doc v1.1](../database/ai/ai_vision_interpretations.md)
và [ADR-0020](../adr/ADR-0020-AI-Vision-Interpretation-Boundary.md).

Lượt sau triển khai service backend — xem § Service Vision Interpretation. Lượt thứ ba nối
việc xoá Media vào đường xoá diễn giải — xem § Nối xoá Media (O-4).

**Ngoài phạm vi, chưa làm:** kích hoạt provider, diễn giải video theo khung hình, và apply
migration lên `learnforge_db`.

Bằng chứng do **implementer** tạo ra, trừ các dòng ghi rõ "reviewer". Có một lượt review
phần nối xoá Media do Owner chuyển tới (§ Review phần nối xoá — P1/P2); reviewer đã xác nhận P1
và P2 đóng **trong phạm vi lượt review đó**. Đây không phải review độc lập toàn Bước 6.

---

# Quyết định của Owner

| Quyết định | Nội dung |
| --- | --- |
| F4 | Phạm vi v1.1 chỉ `document` / `region`; video frame interpretation ngoài phạm vi tới khi ADR-0020 D7 được amend |
| F5 | Bỏ status `failed`; output không hợp lệ không tạo row |
| F6 | Tối đa một row `ready` mỗi unit slot, ép bằng `active_slot` |
| F8 | Redaction hoãn có điều kiện |
| Amend doc v1.1 | Vá F1 (FK media ghép tenant), F2 (CHECK ghép bộ), F3 (locator khớp Media, thêm `page`), F7 (xoá theo Media) |
| **Miễn trừ review Bước 6** | Owner miễn trừ điều kiện `Architecture Review passed` cho packet này, ngày 2026-09-14 |

## `AGENTS.md` § Database Rule tại thời điểm tạo migration

| Điều kiện | Trạng thái |
| --- | --- |
| Database Docs approved | **Đạt** — `ai_vision_interpretations.md` v1.1 Approved |
| ADR approved (Foundation) | **Đạt** — ADR-0006 Frozen; ADR-0020 Approved |
| Architecture Review passed | **ĐƯỢC MIỄN TRỪ** bởi Owner — không phải đã PASS |

Architecture Review Round 3 của AI Foundation đã **loại trừ** bảng này ("đi ở packet riêng"),
và `LF-Documentation-Conflicts.md` ghi migration bị chặn tới khi có re-review. Miễn trừ này
chỉ áp cho packet `ai_vision_interpretations`; không sửa quy trình toàn repository và không phủ
service Bước 6. Chỉ Owner có thẩm quyền miễn trừ; không có quyết định nào của implementer nằm
sau nó.

---

# Đã triển khai

| Thành phần | Đường dẫn |
| --- | --- |
| Migration | `database/migrations/2026_09_14_000100_create_ai_vision_interpretations.php` |
| Test vật lý | `tests/Feature/AiVisionInterpretationsSchemaTest.php` |
| Đăng ký CI | `.github/workflows/application-tests.yml` job `integration-mysql` |
| Schema contract | `docs/database/LF-SCHEMA-CONTRACT.json` — entry `ai_vision_interpretations` populate từ schema vật lý, `implemented` |
| Database doc | `docs/database/ai/ai_vision_interpretations.md` v1.1 — Implemented |
| Trạng thái nền | `docs/platform/LF-AI.md` — mục Vision Interpretation (Bước 6) |

Migration tách theo driver ở đúng một chỗ: biểu thức cột sinh `active_slot`. MariaDB dùng
`SHA2(CONCAT_WS(…))`; SQLite không có `SHA2` nên lưu cùng mã hoá không băm, với ngữ nghĩa unique
giống hệt. CHECK chỉ tồn tại trên MariaDB.

---

# Hai lỗi bắt được tại Gate Migration

Cả hai đều nằm trong **tài liệu**, và cả hai sẽ đi thẳng vào production nếu chỉ tin SQLite
hoặc tin doc đã Approved.

## G-1 — `active_slot` không phải mã hoá đơn ánh — ĐÃ VÁ

Bản đầu doc v1.1 (do implementer viết) dùng `SHA2(CONCAT_WS('|', …))`. `processing_version` và
`locator_start` là chuỗi tự do, có thể chứa `|`:

* version `v1|region|a` + locator `b`
* version `v1` + locator `a|region|b`

Hai slot khác nhau cho **cùng một chuỗi**, nên một diễn giải hợp lệ bị unique từ chối oan.

Tái lập trên **cả MariaDB 11.4.12 và 10.4.21** bằng một bảng thử với ba phương án:

| Biểu thức | Tạo được | Hai slot khác nhau | Hai `ready` cùng slot |
| --- | --- | --- | --- |
| `CONCAT_WS` (bản đầu v1.1) | có | **va chạm** | chặn |
| `JSON_ARRAY` | có | không va chạm | chặn |
| Tiền tố `CHAR_LENGTH` | có | không va chạm | chặn |

Chọn tiền tố độ dài. Không chọn `JSON_ARRAY` vì cột sinh `STORED` không được tính lại khi nâng
cấp engine: nếu định dạng `JSON_ARRAY` khác giữa các phiên bản, row cũ và row mới cùng slot sẽ
có hash khác nhau. Mã hoá tiền tố là đơn ánh vì mọi trường không có tiền tố là từ vựng đóng
(CHECK ép) hoặc số. Doc v1.1 đã sửa biểu thức và ghi lý do. Test hồi quy:
`test_distinct_slots_with_separator_characters_never_collide`.

## G-2 — `chk_avi_bbox` cho bbox dở dang lọt qua — ĐÃ VÁ

Biểu thức CHECK bbox chép từ doc v1.0:

```sql
(bbox_x IS NULL AND … AND bbox_height IS NULL)
OR (bbox_x BETWEEN 0 AND 1 AND … AND bbox_width > 0 AND …)
```

Với `bbox_width` NULL còn ba giá trị kia có mặt: vế đầu FALSE, vế sau UNKNOWN (`bbox_width > 0`),
`FALSE OR UNKNOWN` là UNKNOWN, và **CHECK cho qua khi UNKNOWN**. Cùng lớp lỗi với N-3 đã bắt ở
`ai_model_runs`.

Test vật lý `test_check_constraints_refuse_invalid_rows` với data set `partial bbox` **đỏ trên
cả 11.4.12 và 10.4.21** trước khi vá. Vá bằng `IS NOT NULL` tường minh cho cả bốn giá trị ở vế
thứ hai; test xanh trên cả hai engine sau khi vá.

**Đối chiếu các bảng anh em** — đọc nguyên văn `information_schema.CHECK_CONSTRAINTS`, không suy
luận từ tên:

| Constraint | Kết luận |
| --- | --- |
| `media_extracted_regions.chk_mer_bbox` | Đúng — đã có `IS NOT NULL` ở vế thứ hai. Đây là khuôn lẽ ra phải chép |
| `media_extracted_regions.chk_mer_crop_needs_bbox` | Không có lỗ — chỉ dùng `IS NULL`/`IS NOT NULL` |
| `media_video_frame_texts.chk_mvft_bbox` | Không có lỗ — bốn cột bbox là `NOT NULL` |

Không có lỗi tương tự trong domain Media.

## Observation O-1 — ngoài phạm vi, không sửa

`ai_knowledge_chunks` có bốn cột bbox cho phép NULL mà **không có CHECK nào**, nên bbox dở dang
hoặc ngoài khung được chấp nhận ở mức schema. Thuộc bảng của Bước 3; giao implementer ingestion.

---

# Kiểm chứng

## Môi trường

* **MariaDB server 11.4.12** (Homebrew) và **10.4.21** (binary XAMPP) — cả hai là instance cô lập:
  datadir và socket tạm trong `/tmp`, `--no-defaults`, `--skip-networking`. Trước mọi lượt chạy,
  kết nối được xác nhận bằng truy vấn `@@socket`, `database()`, `version()`, `@@skip_networking`.
* `explicit_defaults_for_timestamp`: **ON** trên 11.4.12, **OFF** trên 10.4.21 — cùng giá trị với
  CI và với môi trường của `learnforge_db`.
* Server XAMPP chính (cổng 3306, datadir chứa `learnforge_db`) đang chạy dưới `root` suốt lượt
  này và **không bị động tới**. Instance 10.4 lần cài đầu thất bại vì không ghi được thư mục tạm
  của hệ thống; đã dừng đúng tiến trình của nó (thuộc `amin`), xoá datadir và cài lại với
  `--tmpdir` riêng.
* Instance 10.4 chạy với `--no-defaults`, tức **không** áp `my.cnf` của XAMPP (collation, buffer…)
  và không có dữ liệu thật. Nó chứng minh engine, không chứng minh cấu hình hay dữ liệu của
  `learnforge_db`.

## Kết quả

| Hạng mục | 11.4.12 | 10.4.21 | Ai chạy |
| --- | --- | --- | --- |
| Toàn bộ migration từ schema trống | 100 DONE | 100 DONE | implementer |
| `AiVisionInterpretationsSchemaTest` (sau khi vá G-2) | **23 passed**, 44 assertions, 0 skip | **23 passed**, 44 assertions, 0 skip | implementer |
| Cùng test **trước** khi vá G-2 | 22 passed, **1 failed** (`partial bbox`) | 22 passed, **1 failed** (`partial bbox`) | implementer |
| CHECK thật | 9 `chk_avi_*` + `json_valid(metadata)` | giống hệt | implementer |
| FK `fk_avi_media_tenant`, `fk_avi_run_tenant` | RESTRICT / RESTRICT | giống hệt | implementer |
| Hash hình dạng cột (`COLUMN_TYPE`, nullable, default) | `1a13e474…` | **`1a13e474…` — trùng** | implementer |
| Cột `TIMESTAMP` | cả bốn `NULL`, default NULL, không `ON UPDATE` | giống hệt | implementer |
| Hai connection tranh một slot | B bị chặn (1205) khi A giữ; sau A commit B bị từ chối `1062 uk_avi_active_slot`; còn 1 `ready` | giống hệt | implementer |
| Rollback khi bảng trống, rồi migrate lại | 95 → 94 → 95 bảng | 95 → 94 → 95 bảng | implementer |
| Entry contract thu bằng `MySqlSchemaInspector` | 28 cột, 9 index, 2 FK, 10 CHECK | **trùng khớp hoàn toàn** với 11.4 | implementer |
| `schema:drift --connection` (chỉ đọc) | passed | passed | implementer |

Hash hình dạng cột trùng khớp giữa hai engine có `explicit_defaults_for_timestamp` ngược nhau
là bằng chứng schema không phụ thuộc cấu hình đó.

| Hạng mục khác | Kết quả | Ai chạy |
| --- | --- | --- |
| `AiVisionInterpretationsSchemaTest` trên SQLite | 9 passed, 14 skipped (CHECK/FK chỉ trên MariaDB) | implementer |
| Toàn suite SQLite, so tên test đỏ qua JUnit với lượt trước | **7 failed, 22 skipped, 1205 passed**, 10832 assertions; số ca 1211 → 1234 (+23 = test Vision), skipped +14, passed +9; xuất hiện mới `[]`, hết đỏ `[]` | implementer |
| `schema:drift --docs-only`, `docs:lint`, `git diff --check`, Pint (migration, test) | PASS | implementer |
| Dọn dẹp | Hai instance cô lập đã dừng (chỉ tiến trình của `amin` trên datadir tạm), thư mục tạm đã xoá; server XAMPP chính không bị động tới | implementer |

Bảy test đỏ là các test Media/runtime đã ghi nhận từ trước; không test nào thuộc AI hay Vision.

---

# Service Vision Interpretation — 2026-09-14

## Phạm vi và quản trị

Owner yêu cầu "làm service Vision Interpretation". Không có migration trong lượt này, nên
`AGENTS.md` § Database Rule không áp. Miễn trừ review ở trên chỉ phủ packet schema; service là
code ứng dụng theo các business rule của database doc v1.1 đã được Owner duyệt.

Lượt này **chạm vào domain Media ở hai chỗ**, cả hai đều theo yêu cầu retrieval audit của doc
v1.1 và cần Owner biết:

* `app/Services/MediaDerivedRetrievalAudit.php` — thêm `appendVisionInterpretation()`; phần
  resolve tenant/Media/actor được tách thành hàm dùng chung với `append()` của knowledge
  retrieval, hành vi không đổi (29/29 test knowledge retrieval xanh trên SQLite và MariaDB 11.4).
* `docs/database/media/media_access_logs.md` v1.4 → **v1.5** — thêm mục *Vision interpretation
  retrieval*, không đổi schema.

Không đổi `MediaReadService`, không ghi bảng `media_*` nào.

## Thành phần

| Thành phần | Đường dẫn |
| --- | --- |
| Service | `app/Services/AiVisionInterpretationService.php` |
| Adapter (kiểm output) | `app/Services/Ai/VisionInterpretationAdapter.php` |
| Port provider | `app/Contracts/Ai/VisionInterpretationProvider.php` |
| Ảnh đầu vào | `app/Support/Ai/VisionImageInput.php` — `#[SensitiveParameter]`, `__debugInfo()` che signed URL |
| Mặc định fail-closed | `app/Services/Ai/UnavailableVisionInterpretationProvider.php`, binding trong `AppServiceProvider` |
| Config | `config/ai.php` § `vision` — provider/model rỗng |
| Test | `tests/Feature/AiVisionInterpretationServiceTest.php`, `tests/Support/Ai/FakeVisionInterpretationProvider.php`; đăng ký job `integration-mysql` |

## Luồng `interpret()` và quy tắc tương ứng

| Bước | Quy tắc doc v1.1 / bài học |
| --- | --- |
| Provider/model rỗng → dừng trước Media Read và gate | Không đọc crop, không mint run khi chưa cấu hình |
| `MediaReadService::read(… 'document', 'region', page, includeCrop)` | Ảnh chỉ qua Media Read; `page` là selector (xem O-2) |
| Tìm region theo `locator.value`; không có crop → dừng | `LF_VISION_REGION_NOT_FOUND`, `LF_VISION_IMAGE_UNAVAILABLE`, không run |
| Anchor sao chép từ unit; kiểm độ dài trước khi trả tiền | `LF_VISION_ANCHOR_INVALID` trước gate |
| Đã có `ready` cùng slot, cùng model → trả lại, không gọi provider | Tránh tốn quota; `refresh: true` để thay |
| `authorize()` → bị chặn thì trả mã | Fail-closed, không row |
| `execute(…, $prior)`: bắt exception **và** kiểm `$executed->allowed` | Bài học P1 của embedding worker |
| Adapter kiểm output **bên trong** `execute()`: rỗng, sai UTF-8, quá dài, chứa signed URL | Gate ghi `AI_PROVIDER_CALL_FAILED`, không row (F5) |
| Ghi: stale row `ready` của slot → stale `ready` revision khác → insert; vi phạm unique thì thử lại **một** lần | F6; hết lượt thử → `LF_VISION_SLOT_CONFLICT` |

Không có mã lỗi hợp đồng `AI_*` mới. Các mã `LF_VISION_*` là mã nội bộ của service.

`forOwner()` tái kiểm từng row qua `currentRevision()` (đúng một revision; khớp
`media_file_id`, locale, fingerprint, version bằng `hash_equals`), audit từ chối với mã thật
của Media Read, và audit `allowed` **trước** khi trả — audit lỗi thì không trả gì.

`requestDeletionForMediaFile()` chỉ chuyển `ready|stale` sang `deletion_pending`, giữ mốc thời
gian gốc; `finalizeDeletion()` xoá nội dung, giữ provenance và hash.

## Bằng chứng

| Hạng mục | Kết quả | Ai chạy |
| --- | --- | --- |
| `AiVisionInterpretationServiceTest`, SQLite | **23 passed**, 100 assertions | implementer |
| Cùng test + schema test + knowledge retrieval, **MariaDB server 11.4.12** cô lập (đã xác nhận `@@socket`, `skip_networking = 1`) | **75 passed, 0 skip**, 272 assertions: service 23, schema 23, knowledge retrieval 29 | implementer |
| Dữ liệu fixture | Pipeline Media thật: upload → structured extraction (provider fake của Media) → crop gắn cho region; anchor được so với unit mà chính Media Read trả | implementer |
| Không ghi bảng Media evidence | Hash mọi bảng `media_*` (trừ `media_access_logs` do Media Read tự audit) trùng khớp trước/sau `interpret()` | implementer |
| Signed URL không bị lưu | Không xuất hiện trong `ai_vision_interpretations`, `ai_model_runs`, `media_access_logs`; nội dung diễn giải không xuất hiện trong `media_access_logs` | implementer |
| Toàn suite SQLite, so tên test đỏ qua JUnit với lượt schema | **7 failed, 22 skipped, 1228 passed**, 10932 assertions; số ca 1234 → 1257 (+23 = test service), passed +23; xuất hiện mới `[]`, hết đỏ `[]` | implementer |
| Pint (mọi file mới/sửa), `docs:lint`, `schema:drift --docs-only`, `git diff --check` | PASS | implementer |

**Đột biến — 7/7 bị bắt**, mỗi đột biến gỡ đúng một cơ chế, file được khôi phục nguyên từng byte:

| Cơ chế bị gỡ | Test đỏ |
| --- | --- |
| Kiểm từ chối ở authorize lần hai | `test_a_refusal_on_the_second_authorization_writes_nothing` |
| Stale row `ready` cũ của slot | `test_an_existing_ready_interpretation_is_reused_and_refresh_supersedes_it` |
| Stale revision cũ | `test_interpreting_the_current_revision_stales_an_older_revision` |
| Kiểm revision khi truy hồi | `test_retrieval_denies_a_row_whose_revision_moved_on` |
| Chặn output chứa signed URL | `test_unusable_output_fails_the_run_and_writes_no_row` |
| Xoá chỉ `ready\|stale` (thay bằng `<> deleted`) | `test_a_deletion_request_is_idempotent_and_keeps_the_original_time` |
| Audit fail-closed (nuốt lỗi audit) | `test_retrieval_discloses_nothing_when_audit_cannot_be_written` |

Test audit-thất-bại dùng chính service audit thật của Media (actor không resolve được trong
tenant), không mock: class đó là `final`, và mock sẽ bỏ qua đúng phần code cần kiểm.

## Observation

* **O-2 — Media Read Contract lệch triển khai.** `LF-Media-Read-Contract.md` (dòng ~143) nói region
  đọc được "với đầy đủ page, locator, bbox và crop", nhưng region unit thực tế **không** có
  `page`. Service không đọc thẳng `media_extracted_regions` (sẽ đi vòng Media Read) mà dùng
  selector `page` của Media Read — region trả về đúng là region của trang đó. Thuộc domain
  Media; không sửa.
* **O-3 — Không có tín hiệu PII cho ảnh.** Repository chưa có cột hay service nào đánh dấu PII
  trên Media. Service chỉ khai báo được `media_image`; ảnh có PII không bị phát hiện. **Điều kiện
  phải xử lý trước khi kích hoạt provider** theo ADR-0018 / ADR-0020 D5.
* **O-4 — Đường xoá chưa có người gọi — ĐÃ NỐI** ở lượt thứ ba, xem § Nối xoá Media (O-4).
* **O-5 — `LF_VISION_SLOT_CONFLICT`.** Nếu cả lượt thử lại cũng va unique, run đã `completed` và
  usage đã ghi nhận nhưng không có row. Trường hợp này được báo cho caller chứ không bị giấu.
* **O-6 — Knowledge Source và embedding cũng chưa nối xoá Media — ngoài phạm vi Bước 6, không
  sửa.** `AiKnowledgeIngestionService::requestSourceDeletion()` không có caller nào trong `app/`.
  Xoá một Media File đang là nguồn của Knowledge Source hiện không đưa source/chunk/embedding vào
  đường xoá; retrieval vẫn từ chối vì tái kiểm `currentRevision()`, nhưng nội dung chunk và
  vector còn nằm lại. Event `MediaFileDeleted` của lượt này dùng lại được cho việc đó, nhưng
  phạm vi và quyết định thuộc bước Knowledge/Embedding.

# Nối xoá Media (O-4)

## Thiết kế — Owner duyệt 2026-09-14

**Owner Approval (quyết định thiết kế, không phải review PASS):**

> Duyệt cả hai: xoá nội dung Vision khi Media bị xoá, giữ provenance/hash và usage
> thực tế; duyệt Media phát sự kiện xoá theo amendment v2.46. Trong thời gian chờ
> dọn phải chặn đọc nội dung đã mất nguồn.

Không mở rộng approval này thành miễn trừ review service, nghiệm thu Bước 6,
activation provider, apply database thật hoặc xử lý O-6. Bằng chứng implementation
dưới đây vẫn do implementer báo cáo; lượt ghi approval không chạy lại các test đó.

Media không được ghi bảng AI, và AI không được đọc thẳng `media_files`. Phần nối vì vậy chia
đôi theo domain:

| Phía | Thành phần | Việc làm |
| --- | --- | --- |
| Media | `app/Events/MediaFileDeleted.php` | Event chỉ mang `customerId`, `mediaFileId`; không biết ai nghe |
| Media | `MediaService::deleteMediaInternal()` | Dispatch **sau** khi transaction tombstone commit và **sau** `purgeMediaStorage()` (hàm này không ném lỗi), nên phía tiêu thụ không chặn được Media hoàn tất việc xoá của chính nó |
| Media | `MediaService::deletedMediaFileIds(array)` | Truy vấn thuộc Media, theo tenant hiện hành: id nào đang mang tombstone `deleted` |
| AI | `app/Listeners/PurgeVisionInterpretationsOfDeletedMedia.php` | `ShouldQueueAfterCommit` (vá P1 — xem § Review phần nối xoá), 3 lượt thử; đặt `TenantContext` theo tenant của event và khôi phục tenant trước đó trong `finally` |
| AI | `AiVisionInterpretationService::purgeForDeletedMediaFile()` | **Tái kiểm tombstone** qua Media rồi mới `requestDeletionForMediaFile()` + `finalizeDeletion(…, mediaFileId)` lặp tới hết. Event không phải bằng chứng xoá |
| AI | `AiVisionInterpretationService::reconcileDeletedMedia()` + `ai:vision-reconcile-media-deletion` | Lưới an toàn cho event bị lỡ/hết lượt thử: duyệt **theo khoá** mọi `media_file_id` còn `ready\|stale`, không lấy N đầu; rồi hoàn tất mọi row `deletion_pending`. Lịch 15 phút trong `routes/console.php`; chỉ database local |

Hai quyết định đã được Owner xác nhận:

1. **Không có cửa sổ retention riêng trước khi dọn Vision.** Media purge nội dung dẫn xuất trong cùng transaction
   (§ Retention khi xoá Media, Owner approved 2026-08-29), nên bản diễn giải cũng bị xoá nội
   dung ngay khi listener chạy: `ready|stale → deletion_pending → deleted` trong một lượt.
   Không có bản sao từ xa nào phải chờ. Queue có độ trễ, không phải xoá đồng thời
   tuyệt đối. Trong thời gian chờ listener/đối soát, bắt buộc chặn đọc nội dung
   đã mất nguồn. Giữ provenance/hash và usage thực tế đã phát sinh.
2. **Media phát event.** Đây là tác dụng phụ mới của Media: một thông báo, không ghi dữ liệu
   ngoài Media. Amendment v2.46 trong `LF-Media-Processing-Contract.md` đã được
   Owner duyệt ngày 2026-09-14.

Mã nội bộ mới: `LF_VISION_MEDIA_DELETED` — không phải mã hợp đồng `AI_*`.

## Khe hở đua giữa provider call và xoá Media

Media có thể bị xoá trong lúc provider đang trả lời. Event chạy trước khi row tồn tại sẽ không
tìm thấy gì để xoá. `interpret()` vì vậy kiểm tombstone **sau** khi insert đã commit: hoặc lần
đọc này thấy `deleted` (row bị xoá ngay, trả `LF_VISION_MEDIA_DELETED` kèm `run_uuid` — run đã
`completed`, usage đã ghi), hoặc việc xoá commit sau đó và listener thấy row. Đã tái hiện: gỡ
bước kiểm này thì row còn `ready` sau khi Media đã `deleted`.

Retrieval không phụ thuộc phần nối: `forOwner()` vẫn từ chối row của Media đã xoá qua
`currentRevision()`. Phần nối xử lý **dữ liệu còn nằm lại**, không phải quyền đọc. Yêu cầu Owner
"chặn đọc nội dung đã mất nguồn trong thời gian chờ dọn" được kiểm bằng test ở § Review phần nối
xoá — P2.

## Giới hạn đã biết

* Queue `sync` (test, hoặc môi trường không có worker): listener chạy đồng bộ ngay sau khi Media
  xoá xong; nếu listener ném lỗi, lỗi nổi lên caller dù Media đã xoá xong cả DB lẫn storage. Queue
  production là `redis`, không bị ảnh hưởng.
* Chưa có worker queue thật hoặc scheduler thật trong bằng chứng dưới đây: listener chạy qua
  `sync`, lệnh đối soát chạy qua `Artisan::call`.

## Review phần nối xoá — P1/P2 — ĐÃ VÁ

Review do Owner chuyển tới, 2026-09-14. Reviewer không sửa code.

**P1 — Listener chưa thực sự chờ commit.** Listener khai báo `ShouldQueue` +
`ShouldHandleEventsAfterCommit`. Laravel chỉ đọc `ShouldHandleEventsAfterCommit` cho listener chạy
trong tiến trình; với listener queued, `Dispatcher::propagateListenerOptions()` chỉ chép cờ
after-commit vào job từ `ShouldQueueAfterCommit` hoặc thuộc tính `$afterCommit`. Reviewer đo được
`job.afterCommit = null`, `redis.after_commit = false`. Hệ quả: nếu việc xoá Media nằm trong một
transaction ngoài, worker có thể nhận job trước khi tombstone commit, không thấy tombstone, kết thúc
thành công mà không xoá — phải chờ đối soát; transaction ngoài rollback vẫn enqueue job.

Implementer đối chiếu caller hiện tại: `MediaFileController::destroy()` / `bulkDestroy()` không mở
transaction ngoài; `CourseTemplateActivityController::deleteUnusedMediaAfterCommit()` gọi
`deleteMediaIfUnused()` bên trong `DB::afterCommit`, tức sau khi transaction của Activity đã commit.
Chưa tìm thấy caller nào đang lồng, nên đây là lỗi hợp đồng tiềm ẩn chứ chưa thấy tái hiện trên
đường hiện có; vẫn phải vá vì amendment v2.46 yêu cầu chờ commit ngoài cùng và mọi caller tương lai
lồng transaction sẽ trúng lỗi. Implementer xác nhận lại trong `vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php`
(nhánh `handlerShouldBeQueued` → `createQueuedHandlerCallable`, không đi qua
`handlerShouldBeDispatchedAfterDatabaseTransactions`).

Vá: listener implements `ShouldQueueAfterCommit` (thay cho cả hai interface cũ). Test phủ trước đó
không bắt được vì Media dispatch sau khi transaction **của chính Media** đã commit, và không test nào
có transaction ngoài.

**P2 — Test đúng điều kiện Owner duyệt.** Test "event bị lỡ" chưa gọi retrieval trong khoảng chờ.
Đã bổ sung: trước đối soát, row vẫn còn nội dung trong DB, `forOwner()` trả rỗng và audit ghi
`denied` / `detached`; ép usage về `active` thì tombstone một mình vẫn bị từ chối, audit
`denied` / `missing` (mã Media Read cho Media `deleted`).

Test mới / sửa:

| Test | Chứng minh |
| --- | --- |
| `test_the_queued_listener_job_is_marked_after_commit` | Job `CallQueuedListener` của listener có `afterCommit === true` — cờ mọi driver thật (kể cả redis) đọc trước khi enqueue |
| `test_the_listener_waits_for_the_outermost_commit` | Trong transaction ngoài, sau `deleteMedia()`: chưa job nào chạy, row còn `ready`; sau commit: chạy đúng một lần, row `deleted`. Đếm bằng `Queue::before` trong bộ nhớ, không qua DB |
| `test_a_rolled_back_media_deletion_enqueues_nothing` | Transaction ngoài rollback → không job nào chạy, row `ready`, Media không `deleted` |
| `test_reconciliation_erases_interpretations_a_missed_event_left_behind` (bổ sung) | P2 như trên |

| Hạng mục | Kết quả | Ai chạy |
| --- | --- | --- |
| Vision service + Media foundation, SQLite, trước vá | 49 passed, 262 assertions; `event:list` một listener; `docs:lint`, `schema:drift --docs-only`, `git diff --check` PASS; chưa chạy MariaDB, Redis worker, scheduler | reviewer |
| Đỏ trước vá | 3 test after-commit **đỏ** trên code cũ (1 is identical to 0 / false is true) | implementer |
| `AiVisionInterpretationServiceTest`, SQLite, sau vá | **34 passed**, 148 assertions | implementer |
| Probe đường Redis không kết nối mạng: job do chính `Dispatcher` tạo + `RedisQueue::shouldDispatchAfterCommit()` | `job.afterCommit = true`, `redis.after_commit = false`, queue redis **hoãn tới commit** = `true` | implementer |
| Service + schema + `MediaFileFoundationTest`, **MariaDB server 11.4.12** cô lập (`@@socket` tạm, `skip_networking = 1`) | **75 passed**, 320 assertions | implementer |
| Toàn suite SQLite | **7 failed, 22 skipped, 1239 passed**, 10980 assertions; passed 1236 → 1239 (+3); 7 ca đỏ vẫn là các ca Media/runtime có sẵn | implementer |
| Pint, `event:list` (một listener), `docs:lint`, `schema:drift --docs-only`, `git diff --check` | PASS | implementer |

**Đột biến — 3/3 bị bắt**, file khôi phục nguyên từng byte:

| Đột biến | Test đỏ |
| --- | --- |
| Quay lại `ShouldHandleEventsAfterCommit, ShouldQueue` | 3 test after-commit |
| Chỉ `ShouldQueue` | 3 test after-commit |
| `forOwner()` bỏ qua từ chối của Media Read | `test_reconciliation_erases_interpretations_a_missed_event_left_behind` |

Vẫn chưa kiểm: Redis worker thật và scheduler thật.

**Reviewer xác nhận, 2026-09-14:** P1 và P2 đã đóng trong phạm vi review này — listener dùng đúng
`ShouldQueueAfterCommit`, các test job flag / chờ commit / rollback xanh; khi event bị lỡ, retrieval
trả rỗng và audit ghi từ chối, kiểm cả usage detached lẫn riêng Media tombstone. Reviewer chạy lại
SQLite **52 passed**, 276 assertions (34 Vision, 18 Media foundation); **không** chạy lại MariaDB,
mutation, Redis worker hay scheduler thật.

**Đính chính của reviewer:** đường Activity gọi xoá Media qua `DB::afterCommit`; P1 là lỗi hợp đồng
tiềm ẩn khi có transaction ngoài, chưa chứng minh xảy ra trên đường Activity hiện tại. Comment trong
listener từng ghi "Course Activity edits are" (nested) đã được sửa cho khớp — chỉ comment, không đổi
logic; sau khi sửa: Pint PASS, `AiVisionInterpretationServiceTest` SQLite 34 passed (implementer).

## Bằng chứng lượt nối (trước review — số liệu đã được thay bởi bảng trên)

| Hạng mục | Kết quả | Ai chạy |
| --- | --- | --- |
| `php artisan event:list` | `MediaFileDeleted` → đúng **một** listener `PurgeVisionInterpretationsOfDeletedMedia@handle (ShouldQueue)` (auto-discovery, không đăng ký trùng) | implementer |
| `php artisan schedule:list` | `*/15 * * * * ai:vision-reconcile-media-deletion` | implementer |
| `AiVisionInterpretationServiceTest`, SQLite | **31 passed** (+8), 134 assertions | implementer |
| Service + schema test + `MediaFileFoundationTest`, **MariaDB server 11.4.12** cô lập (xác nhận `@@socket` tạm, `skip_networking = 1`, `@@port = 0`) | **72 passed**, 306 assertions: service 31, schema 23, Media foundation 18 | implementer |
| Toàn suite SQLite | **7 failed, 22 skipped, 1236 passed**, 10966 assertions; passed 1228 → 1236 (+8); 7 ca đỏ vẫn đúng các ca Media/runtime có sẵn (`MediaRevisionLifecycleTest` ×5, `AudioProcessingLocalReviewTest` ×1, `VideoTranscriptCaptionLocalReviewTest` ×1) | implementer |
| Pint, `docs:lint`, `schema:drift --docs-only`, `git diff --check` | PASS | implementer |

Test mới:

| Test | Chứng minh |
| --- | --- |
| `test_deleting_the_media_file_erases_its_interpretations_and_no_other` | `deleteMedia()` thật → cả row `ready` lẫn `stale` thành `deleted`, nội dung NULL; row của Media khác giữ `ready` |
| `test_a_media_deletion_blocked_by_an_active_usage_keeps_interpretations` | Xoá bị chặn vì usage active → không đụng diễn giải |
| `test_a_deletion_event_is_not_proof_of_deletion` | Event cho Media còn tồn tại → không xoá |
| `test_the_listener_stays_in_the_event_tenant_and_restores_the_context` | Event của tenant khác với cùng id → không xoá; tenant đúng → xoá; context trước đó được khôi phục |
| `test_a_media_deleted_during_the_provider_call_leaves_no_interpretation` | Khe hở đua ở trên |
| `test_reconciliation_erases_interpretations_a_missed_event_left_behind` | Event bị nuốt (`Event::fakeFor`) → lệnh đối soát xoá; chạy lại xoá 0; context khôi phục |
| `test_reconciliation_is_not_starved_by_media_files_that_still_exist` | Chunk 2, ba Media còn tồn tại đứng trước Media đã xoá → vẫn xoá được |
| `test_media_announces_deletion_without_knowing_about_ai` | `MediaService.php` và event không tham chiếu class/bảng AI |

**Đột biến — 8/8 bị bắt**, file khôi phục nguyên từng byte (so SHA-256):

| Cơ chế bị gỡ | Test đỏ |
| --- | --- |
| Dispatch event trong `MediaService` | `test_deleting_the_media_file_erases_its_interpretations_and_no_other` |
| Kiểm tombstone sau insert | `test_a_media_deleted_during_the_provider_call_leaves_no_interpretation` (thêm lượt riêng bỏ assert mã lỗi: row còn `ready`) |
| Tái kiểm tombstone trong `purgeForDeletedMediaFile()` | `test_a_deletion_event_is_not_proof_of_deletion` |
| Khôi phục context trong listener | `test_the_listener_stays_in_the_event_tenant_and_restores_the_context` |
| Đặt tenant của event trong listener | `test_the_listener_stays_in_the_event_tenant_and_restores_the_context` |
| Duyệt theo khoá khi đối soát | `test_reconciliation_is_not_starved_by_media_files_that_still_exist` |
| Khôi phục context trong lệnh đối soát | `test_reconciliation_erases_interpretations_a_missed_event_left_behind` |
| Vòng `finalizeDeletion()` theo Media | `test_deleting_the_media_file_erases_its_interpretations_and_no_other` |

# Step 6 closure — 2026-09-14

**Owner chốt nghiệm thu backend Bước 6**, ngày 2026-09-14, theo phạm vi đã thống nhất: schema
`ai_vision_interpretations`, service backend, nối xoá Media → xoá diễn giải kèm đối soát. Phạm vi
**không** gồm gọi AI thật, kích hoạt provider hay frontend chat.

Nghiệm thu này là quyết định của Owner, **không phải** review PASS toàn Bước 6. Để người đọc sau
không hiểu nhầm:

| Hạng mục | Cơ sở |
| --- | --- |
| Packet schema | Owner **miễn trừ** điều kiện `Architecture Review passed`; bằng chứng implementer trên MariaDB 11.4.12 và 10.4.21 |
| Service backend (gate, adapter, Media Read, slot, retrieval, audit) | Bằng chứng implementer; **không** có review độc lập |
| Nối xoá Media (O-4) | Thiết kế Owner duyệt; một lượt review có mục tiêu tìm P1/P2, reviewer xác nhận đã đóng trong phạm vi lượt đó |

Giới hạn mang theo nghiệm thu:

* Chưa kiểm với Redis worker thật và scheduler thật; bằng chứng dựa trên queue `sync`,
  `Artisan::call` và probe đường Redis không kết nối mạng.
* O-2 (Media Read Contract thiếu `page` trong region unit) và O-6 (Knowledge Source/embedding chưa
  nối xoá Media) vẫn mở, thuộc domain/bước khác.
* 7 test Media/runtime đỏ có sẵn trong toàn suite không thuộc Bước 6 và không được nghiệm thu này
  che đi.

# Trạng thái trước closure (lưu vết)

**Trạng thái trước closure: Partial.** Schema (dưới miễn trừ của Owner), service backend và phần
nối xoá Media đã triển khai.

Đã xong: gọi qua gate, kiểm output trong adapter, ảnh qua Media Read, ghi một-row-`ready`, test
không ghi `media_*`, đường xoá idempotent, retrieval tái kiểm và audit, xoá Media → xoá diễn giải
end-to-end kèm đối soát (O-4).

Còn lại trước khi đóng backend Bước 6:

* ~~Kiểm chứng chặn đọc nội dung đã mất nguồn trong thời gian chờ dọn~~ — đã có test (P2).
* ~~Reviewer xác nhận lại bản vá P1/P2~~ — đã xác nhận 2026-09-14.
* ~~Owner chốt nghiệm thu backend Bước 6~~ — đã chốt 2026-09-14, xem § Step 6 closure.

Quyết định riêng, không phải điều kiện đóng backend — **vẫn mở sau closure**:

* Kích hoạt provider vision (ADR-0018, ADR-0020 D6) — kèm điều kiện tín hiệu PII (O-3).
* Retention duration, legal hold, purge orchestration (ADR-0018) — gate mở production.
* Apply migration lên `learnforge_db`.
* Redaction — hoãn có điều kiện theo F8.
