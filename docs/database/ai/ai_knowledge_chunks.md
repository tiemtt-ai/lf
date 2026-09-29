# Table: ai_knowledge_chunks

Version: 1.6

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-29

Document Path: database/ai/ai_knowledge_chunks.md

## Media role vocabulary alignment (K3) — Approved 2026-09-28, applied dev 2026-09-29

**Đã apply lên `learnforge_db` dev local 2026-09-29** (migration review lượt 4 APPROVE, backup đã restore thử, Owner cho phép). Môi trường khác vẫn cần backup, rehearsal và cho phép riêng. Ba gate trước khi tạo migration đã qua:

1. Owner duyệt [ADR-0006 Amendment v1.0.6](../../adr/ADR-0006-AI-Foundation.md)
   ngày 2026-09-28 (bảng Foundation; K3-R1).
2. Architecture Review độc lập lượt 2: gate PASS
   ([review](../../quality/LF-AI-Knowledge-Source-Role-Alignment-Review.md)).
3. Owner duyệt Database Docs này ngày 2026-09-28.

Duyệt đúng bản đã review; đổi thiết kế thì phải review lại. Migration
`2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php` đã được viết; § Indexes và
`LF-SCHEMA-CONTRACT.json` mô tả CHECK 15 giá trị cùng thay đổi đó. Database nào chưa
chạy migration vẫn giữ CHECK 9 giá trị (physical drift sẽ báo lệch tới khi apply).
Còn phải qua migration review, backup và cho phép apply riêng.
Conflict: DOC-CONFLICT-0040 (vẫn UNDER_REVIEW tới khi có bằng chứng sau apply).

**Vấn đề.** `source_role` là snapshot chính xác của `role` trong Media Read unit
(§ Media retrieval amendment), không phải phân loại của AI. R2-21 đặt CHECK "khớp
`media_extracted_regions`" nhưng lấy vocabulary trước
[Multilingual and STEM amendment](../media/media_extracted_regions.md) (Approved
2026-09-03). Media đã nhận `image`, `chart`, `diagram`, `geometry`, `formula`,
`note`; CHECK Knowledge từ chối cả sáu, nên cả revision bị rollback. Dev local
2026-09-28: 163 region có text mang role mới (`image` 162, `formula` 1) trên 2
Media, 0 chunk region.

**Đề xuất.** Vocabulary `source_role` bằng đúng vocabulary `chk_mer_role` hiện
hành, 15 giá trị:

```sql
CHECK (source_role IS NULL OR source_role IN
       ('paragraph','heading','list','table','figure','image','chart','diagram',
        'geometry','formula','caption','note','header','footer','other'));
```

* `figure` giữ cho revision cũ, như phía Media. NULL giữ nguyên quy tắc: chỉ
  `region|formula` mang role.
* Runtime không đổi: vẫn copy nguyên role, không map `image → figure` hay bỏ region.
  Lý do là **fidelity của snapshot**: map làm chunk ghi role khác Media, mất bằng
  chứng quan sát được. `source_role` không nằm trong công thức `chunk_uuid` hay
  `content_hash`; một mapping nhất quán không đổi UUID, nhưng mapping đổi giữa hai
  lần retry sẽ bị so sánh snapshot (`assertChunkSnapshotMatches`) từ chối.
* Không đổi cột: giá trị dài nhất là `paragraph` (9 ký tự), trong `VARCHAR(20)`.
* Không backfill, không đổi chunker version: trên database giữ baseline CHECK và
  transaction, các revision bị chặn chưa từng có chunk; đối soát theo lịch đăng ký
  lại (lỗi dữ liệu của revision không có backoff). Môi trường từng tắt CHECK hoặc
  sửa tay không được suy từ giả định này: preflight từ chối, điều tra riêng.
* Không có consumer nào khác đọc `source_role` (retrieval chưa trả field này;
  ranking modifier đã hoãn), nên không nhánh đọc nào đổi. Sau khi sửa, các revision
  từng bị chặn sẽ có chunk, nên độ phủ retrieval tăng.

**Forward migration (sau khi qua gate).** Migration mới, không sửa
`2026_09_08_000100_create_ai_foundation_knowledge_tables.php` đã apply.

*Preflight trước mọi DDL* (MariaDB/MySQL; SQLite bỏ qua như Foundation và không
chứng minh enforcement):

* Đọc `information_schema.CHECK_CONSTRAINTS` theo đúng `CONSTRAINT_SCHEMA =
  DATABASE()`, `TABLE_NAME = 'ai_knowledge_chunks'`, `CONSTRAINT_NAME =
  'chk_akc_source_role'`. Phải có **đúng một** dòng.
* So biểu thức sau chuẩn hoá có kiểm soát (bỏ backtick, khoảng trắng và ngoặc thừa,
  hạ chữ thường) với **đúng** dạng `source_role is null or source_role in (<tập>)`.
  Tập phải bằng đúng 9 giá trị cũ khi `up()`, đúng 15 khi `down()`. Không chấp
  nhận biểu thức chỉ vì chứa đủ các chuỗi (ví dụ thêm `or 1=1`).
* Thiếu CHECK, nhánh NULL sai, tập lạ, hay CHECK đã là 15 mà ledger migration chưa
  ghi đều **dừng trước DDL** với thông báo nêu trạng thái thấy được và thủ tục phục
  hồi; không tự đoán.
* `@@check_constraint_checks` phải bật; migration không tắt CHECK để ALTER.
* Quyền cần: SELECT trên `information_schema` và bảng, ALTER trên
  `ai_knowledge_chunks`. Không cần quyền TRIGGER (không tạo hay sửa trigger).

*DDL:*

* `up()`: một lệnh `ALTER TABLE ai_knowledge_chunks DROP CONSTRAINT
  chk_akc_source_role, ADD CONSTRAINT chk_akc_source_role CHECK (...)`, để không có
  khoảng thời gian bảng không có CHECK. Cú pháp swap cùng tên, và việc lệnh thất bại
  không để bảng mất CHECK, phải được chứng minh trên MariaDB 11.4 ở migration review.
* Không mặc định `ALGORITHM=INSTANT/INPLACE` hay `LOCK=NONE`; thuật toán và mức
  khoá chốt bằng thực nghiệm (xem § Bảng lớn).
* `down()`: preflight tập 15 như trên; từ chối nếu còn row mang một trong sáu role
  mới ở **mọi tenant và mọi status** (kể cả `stale`, `archived`, `deleted` còn
  provenance). Không xoá hay sửa evidence để thu hẹp CHECK. Pre-count không đủ để
  chống row chen vào: writer phải được dừng (quiesce) trước `down()`, và CHECK hẹp
  tự validate lúc ALTER nên row chen vào làm ALTER thất bại, bảng giữ CHECK 15.
* Cùng thay đổi: cập nhật § Indexes và `LF-SCHEMA-CONTRACT.json`. Docs-only drift,
  fresh drift và physical drift là ba gate riêng, đều phải pass.

*Thất bại và phục hồi:*

* Lock timeout hoặc ALTER thất bại: bảng giữ nguyên CHECK cũ, ledger không ghi,
  chạy lại an toàn sau khi xử lý nguyên nhân.
* DDL đã xong nhưng ledger chưa ghi (tiến trình chết giữa chừng): lần chạy sau thấy
  CHECK 15 mà ledger thiếu và dừng, như preflight ở trên. Người vận hành xác nhận
  biểu thức rồi ghi ledger thủ công theo runbook; không retry mù.

*Bảng lớn (trước apply ở môi trường thật):* rehearsal trên dữ liệu có kích thước và
phân bố tương đương; đo thời gian validate, chờ metadata lock, thời gian chặn
writer, dung lượng đĩa nếu ALTER copy bảng, replica lag nếu có. Chốt thời hạn chờ
khoá, tiêu chí huỷ, cửa sổ bảo trì hoặc tạm dừng writer (scheduler và queue
listener), backup và cách phục hồi. Dev local dùng quy trình backup đã có.

**Chống tái diễn.** Hai lớp test:

* Contract: đọc `LF-SCHEMA-CONTRACT.json`, chọn đúng CHECK của cột `role` trong
  `media_extracted_regions` và của `source_role` trong `ai_knowledge_chunks` theo
  table + biểu thức của đúng cột (JSON không lưu tên constraint; không gom literal
  của mọi CHECK). Với K3: tập Knowledge **bằng đúng** 15 giá trị. Về sau: tập
  Knowledge ⊇ tập Media, để Media mở rộng mà quên Knowledge thì test đỏ.
* Vật lý, MariaDB 11.4 dùng một lần: đọc CHECK thật trong `information_schema`
  sau fresh migrate và sau upgrade từ baseline có dữ liệu, so với JSON.

**Kiểm chứng yêu cầu** (acceptance criteria cho migration, MariaDB 11.4 dùng một
lần):

| Yêu cầu | Kiểm chứng |
| --- | --- |
| Miền giá trị | 15 role và NULL: INSERT và UPDATE được; role ngoài tập: INSERT và UPDATE bị chặn; ghi nhận collation hiện hành, không đổi ngữ nghĩa hoa/thường |
| Snapshot và retry | Ingest fixture Media có role mới; chunk giữ nguyên role/text/locator/bbox/languages; retry giữ source, UUID, hash, số chunk, version; không sinh embedding identity mới cho dữ liệu cũ |
| Atomic theo revision | Nhiều unit, role lạ nằm sau unit hợp lệ: rollback cả revision mới; source và chunk cũ không bị stale hay ghi đè |
| Preflight | Thiếu CHECK, biểu thức lạ, tập lạ, nhánh NULL sai, CHECK đã 15 mà ledger chưa ghi: dừng trước DDL |
| Up/down | Upgrade baseline có dữ liệu; `down()` khi không có role mới: thành công; khi có từng role mới ở mọi status: từ chối; up → down → up giữ nguyên data, hash, số dòng |
| Thất bại | Row chen vào giữa pre-count và `down()`; lock timeout; ADD thất bại; tiến trình chết sau DDL: bảng không bao giờ mất CHECK |
| Governance | ADR và Database Docs được duyệt, finding review đóng; docs lint, docs-only, fresh và physical drift; migration 2026-09-08 không đổi |
| Triển khai | Backup, rehearsal, ngân sách DDL, quyền đúng; chỉ sau khi apply được phép mới kiểm Media từng bị chặn có source `active` và snapshot đầy đủ |

## Owner-ineligible archive — Approved 2026-09-26

Amendment A3 của [Knowledge Sync Contract](../../platform/LF-AI-Knowledge-Sync-Contract.md),
Owner duyệt D5. Khi owner không còn giữ Media (usage bị gỡ, Version `archived`,
activity biến mất), source và chunk chuyển `pending|active|failed|stale →
archived`; embedding của chunk đi theo quy tắc rebuild (`ready|failed → stale`,
`pending → deletion_pending`, không có `pending → stale`). `archived` không bao
giờ quay lại `active`; gắn lại usage tạo `generation` kế tiếp. Nội dung chunk
`archived` được giữ cho provenance và chỉ bị erase qua đường xoá khi Media bị
xoá. Không đổi schema: `archived` đã có trong CHECK và không có trigger
transition. Runtime: `AiKnowledgeIngestionService::archiveSource()`.

## Deterministic chunker runtime — Implemented 2026-09-08

Canonical runtime version là `media-unit-unicode-v1`, giới hạn `4000` Unicode
code points. Mỗi Media Read unit có text sinh một chunk; unit rỗng không sinh
chunk. Unit quá giới hạn được cắt tại boundary cuối cùng theo thứ tự ưu tiên:
đoạn (`\n\n`), dấu kết câu (`.?!。？！`), whitespace, rồi exact Unicode boundary.
Parts không overlap và giữ `[char_start,char_end)` liên tục.
Riêng `video_frame_text`, nhiều region có thể cùng locator `timespan`.
`part_index` tăng liên tiếp trên tất cả parts của các unit cùng locator, theo
thứ tự Media Read; không reset về 1 ở mỗi region. Mỗi chunk vẫn giữ text,
`reading_order`, bbox và offsets trong unit riêng, không nối các region.
Locator chỉ có một unit và các content type khác giữ cách đánh số/UUID cũ.
Đây là sửa runtime để tuân thủ `uk_akc_locator_part`, không đổi schema hoặc
chunker version, không backfill snapshot lịch sử.
Với unit `table` không có top-level text, runtime serialize `structure.cells`
theo `row`, rồi `column`: cột nối bằng TAB và hàng nối bằng LF. Đây là text
deterministic để chunk/embed sau này; cell order và table locator vẫn được giữ
trong provenance, không suy từ layout hình ảnh.

`chunk_uuid` là identity xác định từ source UUID, locator, part index, boundary
và chunker version. Retry cùng input giữ nguyên UUID/hash/boundary. Nếu cùng
Media revision và locator trả content/boundary khác, runtime fail-closed bằng
`non_deterministic_rebuild`; không update đè snapshot lịch sử.

## Region text-quality snapshot — Approved 2026-09-08

Candidate schema bổ sung `source_text_quality VARCHAR(20) NULL`, snapshot trực
tiếp từ Media Read unit của đúng `source_fingerprint`/`processing_version`.
Allowed values là `normal|low`; NULL dành cho non-region hoặc revision cũ không
có signal. AI không được tính lại từ chunk text và không backfill NULL thành
`normal`.

```sql
CHECK (source_text_quality IS NULL
       OR source_text_quality IN ('normal','low'));
```

Thiết kế này đã được triển khai trong AI Foundation migration và ingestion
runtime; không backfill hay sửa Media evidence lịch sử.

## Media retrieval amendment — Approved 2026-09-05

Mỗi chunk của Media source chứa **đúng một Media Read unit**. Vì vậy
`locator_start = locator_end`; context expansion dùng nhiều chunk, mỗi chunk giữ
citation riêng, không nối nhiều region thành một locator giả. Locator vocabulary
mở đúng Media Read: `page`, `timespan`, `sheet`, `region`.

Chunk snapshot ba signal rerank quan sát được: `source_role`,
`source_quality_status` và `language_evidence`. Chúng được copy từ unit của đúng
`source_fingerprint`/`processing_version`, không phải AI classification.
`language_evidence` giữ array `{script, locale, char_count}`; NULL cho content
type không có evidence đa trị. Crop URL không được snapshot vì là signed delivery
tạm thời; khi cần kiểm chứng, consumer đọc lại Media bằng locator/revision.

## Amendment — Approved 2026-08-25

Nguồn: [LF-AI-Foundation-Media-Consumer-Database-Architecture-Review](../../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md)
finding F-2 và F-3. **Approved by Owner 2026-08-25.** Thay đổi: `source_locator` JSON
tự do được thay bằng hợp đồng locator đã freeze, và thêm tenant composite
identity.

## Purpose

Derived text chunks phục vụ tenant-scoped retrieval/RAG.

## Relationships

`Knowledge Source 1 → N Knowledge Chunks`; `Chunk 1 → N Embeddings`.

## Business Rules

* Chunk derived từ authorized Knowledge Source và có thể rebuild.
* Chunk không phải Source Of Truth của source content.
* `sequence_no` unique trong một Knowledge Source revision.
* `content_hash` xác định chunk content; không dùng title làm identity.
* Allowed `status`: `pending`, `active`, `stale`, `archived`, `failed`,
  `deletion_pending`, `deleted`.
* Chunk giữ locator theo **đúng** hợp đồng locator chung tại
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
  § 4: `locator_type` là `page`, `timespan`, `sheet` hoặc `region`; giá trị luôn là text.
* Chunk ngoài Media có thể trải nhiều unit và dùng khoảng
  `locator_start`..`locator_end`. Chunk Media bắt buộc một unit nên hai giá trị
  bằng nhau.
* Media unit có text rỗng (`char_count = 0` — trạng thái hợp lệ theo Read
  Contract § D2–D4, ví dụ trang trắng trong PDF hỗn hợp) **không** sinh
  chunk. `sequence_no` liên tục trên các unit có text, nên độ phủ locator
  của một source cố ý không phủ unit rỗng. `CHECK (char_end > char_start)`
  vì thế không bao giờ bị một unit rỗng làm hỏng cả ingestion revision.
* Content/metadata tuân tenant privacy and retention.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| knowledge_source_id | BIGINT UNSIGNED NOT NULL | Parent Knowledge Source. |
| chunk_uuid | CHAR(36) NOT NULL | Stable chunk identity. |
| sequence_no | INT UNSIGNED NOT NULL | Order within source version. |
| content | LONGTEXT NULL | Extracted chunk text; erased only after lifecycle reaches `deleted`. |
| content_hash | VARCHAR(128) NOT NULL | Chunk content fingerprint. |
| token_count | INT UNSIGNED NULL | Estimated tokens. |
| locale | VARCHAR(20) NULL | Chunk locale. |
| part_index | INT UNSIGNED NOT NULL DEFAULT 1 | Deterministic part trong source unit; riêng video_frame_text tăng liên tiếp qua các unit cùng timespan. |
| char_start | INT UNSIGNED NOT NULL DEFAULT 0 | Unicode start offset. |
| char_end | INT UNSIGNED NOT NULL | Exclusive Unicode end offset. |
| locator_type | VARCHAR(20) NOT NULL | `page`, `timespan`, `sheet` hoặc `region`, theo unit nguồn. |
| locator_start | VARCHAR(50) NOT NULL | Locator của unit đầu trong chunk. |
| locator_end | VARCHAR(50) NOT NULL | Locator của unit cuối trong chunk. |
| source_role | VARCHAR(20) NULL | Region role quan sát được; NULL ngoài region/formula. |
| source_quality_status | VARCHAR(20) NULL | Table `complete|incomplete|undetermined`; NULL ngoài table. |
| language_evidence | JSON NULL | Snapshot ordered `{script,locale,char_count}` từ Media unit. |
| reading_order | INT UNSIGNED NULL | Exact Media unit reading order. |
| source_text_quality | VARCHAR(20) NULL | Exact `normal|low` snapshot; NULL when unavailable. |
| bbox_x | DECIMAL(9,6) NULL | Normalized bbox x. |
| bbox_y | DECIMAL(9,6) NULL | Normalized bbox y. |
| bbox_width | DECIMAL(9,6) NULL | Normalized bbox width. |
| bbox_height | DECIMAL(9,6) NULL | Normalized bbox height. |
| frame_width | INT UNSIGNED NULL | Frame width for video frame text. |
| frame_height | INT UNSIGNED NULL | Frame height for video frame text. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Derived chunk lifecycle. |
| deletion_requested_at | TIMESTAMP NULL | Tombstone requested. |
| deleted_at | TIMESTAMP NULL | Content erased and tombstone completed. |
| metadata | JSON NULL | Chunking strategy/version. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Rebuild/lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, chunk_uuid);
UNIQUE (id, customer_id);
UNIQUE (customer_id, knowledge_source_id, sequence_no);
UNIQUE (customer_id, knowledge_source_id, locator_type, locator_start, part_index);
INDEX  (customer_id, knowledge_source_id);
INDEX  (customer_id, status);
INDEX  (customer_id, content_hash);

FOREIGN KEY (knowledge_source_id, customer_id)
    REFERENCES ai_knowledge_sources (id, customer_id) RESTRICT;

CHECK (status IN ('pending','active','stale','archived','failed',
                  'deletion_pending','deleted'));
CHECK (locator_type IN ('page','timespan','sheet','region'));
CHECK (sequence_no >= 1);
CHECK (part_index >= 1 AND char_end > char_start);
CHECK (source_role IS NULL OR source_role IN
       ('paragraph','heading','list','table','figure','image','chart','diagram',
        'geometry','formula','caption','note','header','footer','other'));  -- K3, 2026-09-28
CHECK (source_quality_status IS NULL
       OR source_quality_status IN ('complete','incomplete','undetermined'));
CHECK (source_text_quality IS NULL OR source_text_quality IN ('normal','low'));
CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL);
CHECK (status <> 'deleted' OR deleted_at IS NOT NULL);
CHECK (status = 'deleted' OR content IS NOT NULL);
```

Locator tách thành ba cột thay vì một JSON là để **join ngược được** về
`media_extracted_texts` / `media_transcripts`. Một citation không định vị lại
được nguồn thì không phải citation, và JSON tự do thì mỗi lần chunk lại sinh một
hình dạng khác.

Readiness invariant xuyên parent: chunk của Media source phải có
`locator_start = locator_end`; `source_role` chỉ có với `region|formula`;
`source_quality_status` chỉ có với `table`; `language_evidence` phải bằng unit
đã đọc. Vi phạm fail toàn ingestion revision, không publish chunk một phần.

All chunks begin `pending` in one transaction and become `active` only after the
complete revision validates. Oversized Media units split deterministically at
the last paragraph/sentence/whitespace boundary before a revisioned token cap,
falling back to an exact Unicode-character boundary. Parts of one unit use
consecutive `part_index`, non-overlapping `[char_start,char_end)`, zero overlap
and the same locator/revision/evidence snapshots. For `video_frame_text`,
`part_index` continues across units sharing a timespan (§ Deterministic chunker
runtime); offsets and evidence snapshots stay per unit.

## Sample Data

`id=10, customer_id=1, knowledge_source_id=1, chunk_uuid=0191-chunk-0010, sequence_no=1, content=Ôn tập về bất quy tắc của ㅂ..., content_hash=sha256:def456, token_count=18, locale=vi, locator_type=region, locator_start=15#1, locator_end=15#1, source_role=paragraph, source_quality_status=NULL, language_evidence=[{script:Latn,locale:vi,char_count:41},{script:Hang,locale:ko,char_count:3}], status=active`

## Design Notes

Chunk limit and tokenizer are recorded in the chunker version.
Rebuild marks prior chunks stale/archived according to retention policy.

Delete lifecycle is `pending|active|failed|stale|archived → deletion_pending →
deleted`; `deleted` is terminal. After all child Embeddings are deleted, content
is erased and minimal provenance retained. Migration makes `content` nullable
with a CHECK requiring it for non-deleted rows. Rollback fail-closed on any row.
