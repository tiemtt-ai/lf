# AI Migrations — Independent Pre-Apply Review

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-26

Document Path: quality/LF-AI-Migrations-Pre-Apply-Review.md

Reviewer: Codex — independent review session; không phải tác giả/người vá của bốn migration

Snapshot: `01cce9e37064829778676156cb34ef06fe786e47`

Final Verdict: **BLOCKED** — toàn bộ lần apply lên `learnforge_db`

---

## 1. Kết luận và phạm vi chữ ký

**Bốn migration đạt kiểm chứng DDL trên schema dựng mới của cả MariaDB 10.4.21 và 11.4.12. Chưa đủ điều kiện apply lên database thật.** Chưa nhận được output `migrate:status`, dump do Owner giao hoặc quyền/cấu hình tài khoản apply. Reviewer không truy cập database thật để bù những đầu vào thiếu này.

| Migration | Verdict riêng, giới hạn ở schema dựng mới | Điều kiện/rủi ro còn lại |
| --- | --- | --- |
| M1 — Foundation, `2026_09_08_000100_create_ai_foundation_knowledge_tables.php` | **APPLY-READY WITH DOCUMENTED RISKS** | Ba P1 và ba P2 cũ đã đóng trong source hiện hành. Phải xác minh ledger/schema thật: hồ sơ cũ từng ghi M1 đã apply trước bản vá. |
| M2 — Embedding generation, `2026_09_13_000100_add_ai_embedding_generation.php` | **APPLY-READY WITH DOCUMENTED RISKS** | Cần Foundation đúng phiên bản; ALTER có thể chờ metadata lock. Rollback chỉ được phép khi không có generation > 1. |
| M3 — Vision, `2026_09_14_000100_create_ai_vision_interpretations.php` | **APPLY-READY WITH DOCUMENTED RISKS** | Các CHECK/FK/generated identity đã kiểm trên hai engine; rollback từ chối mọi row, kể cả tombstone. |
| M4 — Authoring, `2026_09_15_000100_create_ai_authoring_proposal_packet.php` | **APPLY-READY WITH DOCUMENTED RISKS** | Bắt buộc preflight quyền/config trigger, dừng writer, backup đã thử restore; có trạng thái DDL dở dang khi lỗi. |
| **Toàn bộ lần apply lên `learnforge_db`** | **BLOCKED** | Thiếu rehearsal dữ liệu thật; baseline thật chưa xác nhận; engine đích theo Owner là 10.4.21, dưới floor bắt buộc của dự án. |

Verdict từng file không cấp phép thực thi lên database thật, không miễn trừ floor engine và không ký cho runtime/provider/UI. Không phát hiện lỗi DDL mới buộc vá source trong các điều kiện đã kiểm. Những finding phía dưới gồm **gate apply và rủi ro vận hành**; không được diễn giải thành PASS vô điều kiện.

**Khuyến nghị: nâng lên MariaDB 11.4 được dự án hỗ trợ trước khi apply**, rồi rehearsal trên bản sao dump với đúng version/config sẽ dùng. Việc 10.4.21 thi hành các constraint trong probe không thay đổi yêu cầu `>= 10.5`. Reviewer không thay Owner quyết định nâng engine và không tự thực hiện nâng cấp.

## 2. Snapshot, độc lập và an toàn

Brief đã đọc trước: `docs/quality/LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md`. Đối chiếu README, LF-INDEX/routing, Guardrails, Schema Drift Standard, các table docs AI/Course liên quan, runtime requirements và hồ sơ remediation Foundation. Số liệu của hồ sơ implementer không được dùng thay kết quả tự chạy.

| File | SHA-256 đầu/cuối — original và bản sao bằng nhau |
| --- | --- |
| M1 | `f87b6557b4a62f3f4619d9765067b50c72f63b1008498dc19135d9754881ec65` |
| M2 | `d244f76c98edce52bc6cf485722860f4318adb6f8325a53024f3d5d32410fe2f` |
| M3 | `91b4f59ac6cd71ea9ef5b7beb540e5373a2e90573717c47aa5bd48325c18fa8f` |
| M4 | `c23c8d7660d36820902f0fa05e89b2d794ef265de960f722d2704be24de05c58` |
| `docs/database/LF-SCHEMA-CONTRACT.json` | `b08e943f1cb5acf6f5c1365578b98338b3cb3a6268e459ecf5862415213ac97b` |

Workspace evidence: `/private/tmp/lfmig-review.9lyjofsi` (viết tắt **R**). Bản sao tại `R/snapshot`; `vendor` được copy vật lý, không phải symlink, và kiểm không có symlink bên trong. Reflection xác nhận class được nạp từ `R/snapshot/app/...`. `.env` thật không được copy. Probe riêng nằm ở `R/probes`; không sửa bốn migration để fault injection. So SHA-256 đầu/cuối và kiểm original không bị sửa. File duy nhất reviewer thêm trong repo là báo cáo này.

| Thuộc tính | Engine 10.4 | Engine 11.4 |
| --- | --- | --- |
| Binary | `/Applications/XAMPP/xamppfiles/sbin/mysqld` | `/usr/local/opt/mariadb@11.4/bin/mariadbd` |
| Version thực đo | `10.4.21-MariaDB` | `11.4.12-MariaDB` |
| Datadir | `R/d104/` | `R/d114/` |
| Socket | `R/104.sock` | `R/114.sock` |
| `@@skip_networking` | `1` | `1` |
| `@@explicit_defaults_for_timestamp` | `0` | `1` |
| Binary log khi chạy suite/DDL/fault | OFF | OFF |
| Binary log ở probe quyền riêng sau đó | ON, server-id 104 | ON, server-id 114 |

Khởi tạo/chạy với `--no-defaults`, datadir/socket/pid/tmpdir riêng, `--skip-networking`; mọi connection dùng Unix socket tường minh. Database đều bắt đầu `lf_ai_authoring_`. Không kết nối `learnforge_db`, không dùng socket XAMPP thật hoặc TCP 3306. Output `schema:drift` vẫn in host/port mặc định từ config (`127.0.0.1:3306`): đó là metadata config không được DSN dùng khi `DB_SOCKET` có giá trị; `SELECT VERSION(), @@datadir, @@socket, @@skip_networking` xác nhận server thật sự được kết nối là instance tạm.

Suite dùng fake provider/vector store; ca Qdrant thật bỏ qua có chủ ý. Không provider thật, không secret thật, không đưa dữ liệu ra mạng. Các suite/probe dùng chung fixture chạy tuần tự. Probe quyền binary log dùng database riêng, không dùng fixture ứng dụng.

**Cleanup đã hoàn tất:** hai instance đã shutdown; PID/socket không còn; `d104/`, `d114/` và hai dump synthetic đã xoá. `R/cleanup.json` ghi kiểm tra sau xoá. Không có dump Owner nào được nhận hoặc xoá. Logs/probe/source copy còn lại để đối chiếu.

## 3. Findings và gate còn mở

### B1 — BLOCKER: chưa có rehearsal và baseline thật còn mâu thuẫn trong hồ sơ

- Vị trí: `docs/quality/LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md` § Rehearsal; `docs/quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md:106`.
- Brief hiện gọi đây là lần apply đầu tiên. Hồ sơ Foundation cũ lại ghi đã apply M1 ở batch 27, trước bản vá, với schema thiếu generation/FK/CHECK. Đây là **mâu thuẫn bằng chứng lịch sử**, chưa phải kết luận database hiện nay vẫn sai.
- Nếu ledger đã ghi M1, `migrate` sẽ bỏ qua source M1 mới; chạy ba migration sau không tự sửa Foundation cũ. Nếu có bảng dở dang nhưng ledger chưa ghi, CREATE có thể lỗi 1050.
- Chưa có status/dump Owner cung cấp để tái lập. Không được ký APPLY-READY toàn bộ. Cần status, dump và rehearsal; nếu đúng là schema lịch sử cũ còn tồn tại thì cần phương án forward remediation được review riêng, không xoá ledger hoặc ép replay CREATE TABLE lên dữ liệu đang giữ.

### H1 — HIGH: engine đích dưới floor bắt buộc

- Vị trí: `docs/tech/LF-Tech-Runtime-Requirements.md:82` và `:85`; `docs/quality/LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md:33`.
- Tình huống: chạy apply trên 10.4.21 chỉ vì probe DDL xanh. Tài liệu yêu cầu deployment preflight fail **trước** migration khi ngoài floor.
- Bằng chứng riêng: 10.4.21 thực sự chạy được packet/CHECK/trigger trong instance tạm, nhưng điều đó không phải miễn trừ policy. 11.4.12 cũng đạt cùng test và drift.
- Đề xuất: nâng engine được hỗ trợ và rehearsal đúng version/config đích. Không khuyến nghị apply trên engine hiện tại; không coi kết quả này là review toàn bộ quy trình upgrade hay crash recovery.

### H2 — HIGH: thiếu quyền trigger làm Authoring hỏng muộn, retry không tự hồi phục

- Vị trí: `database/migrations/2026_09_15_000100_create_ai_authoring_proposal_packet.php:24` (tạo/ALTER trước trigger) và `:392` (CREATE TRIGGER). Không có preflight quyền trigger trong `up()` trước DDL.
- Probe riêng trên **cả hai engine**: restore baseline synthetic, hoàn tất M1/M2/M3 và các migration ngoài AI; dùng account test có quyền DDL/DML nhưng bỏ `TRIGGER`, chạy `migrate` cho M4.
- Kết quả: lỗi **1142** tại `trg_apr_update`; **sáu bảng Authoring và bốn cột Course đã tồn tại**, không có trigger Authoring, M4 chưa được ghi vào ledger. Retry bằng root vẫn lỗi **1050**, `ai_authoring_generation_requests` đã tồn tại. Logs `R/fault-privilege104.log`, `R/fault-privilege114.log`.
- Binary log: probe account có `TRIGGER` nhưng không `SUPER`; bật binlog, trust=0 → **1419**; trust=1 → tạo trigger được trên cả hai engine (`R/binlog104.log`, `R/binlog114.log`). Không suy quyền app thật từ quyền root của fixture.
- Cần preflight trong quy trình apply: effective grants, config binlog/trust, quyền DDL/DML cần thiết và quyền của DEFINER; chứng minh trên clone bằng đúng mô hình account. DBA quyết định cách cấp quyền; reviewer không đề nghị cấp SUPER vĩnh viễn cho app. Giữ account DEFINER hợp lệ sau deploy. Đây là gate vận hành bắt buộc trước apply, không phải yêu cầu âm thầm vá migration trong review.

### R1 — MEDIUM: khoảng trống CHECK origin có thể nhận write rồi làm apply thất bại

- Vị trí: `database/migrations/2026_09_15_000100_create_ai_authoring_proposal_packet.php:328`; helper `checks()` tại `:83` tạo các câu ALTER riêng.
- Probe riêng trên **cả hai engine**: hook sau khi DROP CHECK trả về, một connection thứ hai commit `origin='invalid_review'` vào một manual intent synthetic; chưa có CHECK mới để chặn. Sau đó ADD origin CHECK lỗi **4025**. Sáu bảng đã tạo, M4 chưa được ghi ledger; retry lỗi **1050**. Logs `R/fault-window104.log`, `R/fault-window114.log`.
- Đây là chứng minh cửa sổ DDL bằng write đối nghịch; không khẳng định luồng manual hiện tại tự sinh origin đó. Hai manual row hợp lệ không đổi trong probe apply bình thường.
- Mitigation: dừng web write, workers, scheduler và mọi writer ngoài app trong toàn bộ cửa sổ apply/verify. Một transaction Laravel hoặc chỉ bật maintenance page không chặn hết writer. Nếu yêu cầu apply online, cần thiết kế/review khác để loại cửa sổ này.

### L1 — LOW: mô tả rollback trong brief có hai chỗ dễ dùng sai

- Vị trí: brief `:30` và § Kiểm chứng (`migrate:rollback --step=4`); `database/migrations/2026_09_13_000100_add_ai_embedding_generation.php:31`.
- M2 **cho rollback khi có row generation=1**, chỉ chặn generation>1. Probe riêng down/up giữ nguyên toàn bộ field còn lại của row generation=1 trên cả hai engine. Canonical `ai_embeddings.md` mô tả đúng; câu “cả bốn từ chối khi có dữ liệu” trong brief quá rộng.
- Trên fresh reconstruction 100 migration cùng batch, bốn bước cuối là Authoring, Vision, Embedding, **quota ngày 12/9**; Foundation ngày 8/9 vẫn còn. Reviewer đã thấy trực tiếp trong `R/rollback104.log`, `R/rollback114.log`, không tính lượt đó là rollback đủ bốn AI.
- Đã kiểm lại đúng: dựng baseline **96 migration ngoài AI**, sau đó `migrate` chạy đúng bốn AI trong batch mới; `rollback --step=4` lúc này đúng bốn AI, rồi migrate lại thành công trên hai engine. Không dùng `--step=4` như runbook hồi phục database thật khi chưa đọc ledger.

## 4. M1 — Foundation: trả lời đủ năm câu

### M1.1 — Sáu finding cũ

| Finding cũ | Kết luận | Bằng chứng tự kiểm |
| --- | --- | --- |
| P1-1 Media FK tenant-aware | **CLOSED trong source** | Harvest composite FK `(media_file_id,customer_id) → media_files(id,customer_id)` RESTRICT. Test file không tồn tại, tenant khác và hard-delete parent đều đạt trên hai engine. |
| P1-2 usage/content pair | **CLOSED** | `chk_aks_usage_pair` khớp bảy cặp hợp lệ, gồm formula/document. Test năm cặp sai bị từ chối, bảy cặp đúng được nhận trên hai engine; CHECK_CLAUSE harvest thật. |
| P1-3 tombstone khoá registration | **CLOSED** | Unique chứa generation; same generation vẫn collide, generation 2 tạo được và row deleted cũ giữ nguyên. Probe riêng generation=0 bị `chk_aks_generation` từ chối. |
| P2-1 unit rỗng | **CLOSED về policy/schema** | `ai_knowledge_chunks.md:101` quy định không sinh chunk cho unit rỗng, sequence liên tục trên unit có text; `char_end > char_start` phù hợp. Không dùng review migration này để ký lại toàn bộ ingestion runtime. |
| P2-2 ADR 11/12 bảng | **CLOSED về tài liệu** | ADR-0006 editorial correction v1.0.4 và danh sách hiện hành ghi 12 bảng/6 nhóm, có Vision. Đây là inventory Foundation, không phải số bảng được bốn migration này tạo. |
| P2-3 conflict register | **CLOSED về tài liệu** | `LF-Documentation-Conflicts.md:254`–`:255` có 0035/0036 RESOLVED; record ở phần resolved, 0036 có Sources In Conflict. |

`AiFoundationKnowledgePacketMariaDbTest`: **20 tests / 29 assertions** mỗi engine, không skip. Bổ sung probe độc lập ngoài suite cho all-NULL identity, source generation=0, width và full-schema rollback comparison. Không mutation source migration trong lượt này; không tuyên bố mutation coverage.

### M1.2 — NULL identity

Generated sentinels được engine thật tạo dạng STORED. Test locale NULL trùng revision bị chặn; probe riêng source non-Media với tất cả thành phần identity nullable đều NULL cũng bị **1062** ở lần đăng ký thứ hai. Có `RTRIM(source_fingerprint)` trong expression trên cả hai engine. Generation tiếp theo là registration khác, không sửa tombstone cũ.

### M1.3 — FK hoãn/thứ tự

M1 tạo `ai_model_runs` trước Sources/Chunks/Embeddings; embedding FK tham chiếu run đã tồn tại. **Hai FK tới Assistant Session và Prompt Template vẫn chưa được thêm**, đúng `ai_model_runs.md:205`: bảng đích ngoài subset hiện tại, FK phải được thêm bởi migration bảng đích tương lai. Chỉ có cột/index và CHECK prompt scope hiện nay. Không ký rằng hai FK đã tồn tại hay đã enforce. M4 thêm FK predecessor sau khi proposals/revisions đều đã tạo; up/down đúng thứ tự theo kiểm chứng vật lý.

### M1.4 — Width/runtime

`processing_version VARCHAR(100)`, UUID `CHAR(36)` khớp contract/harvest. Probe gọi thật `MediaProcessingOrchestrator::versionFor()` với chuỗi version/ffmpeg dài, không gọi provider; kiểm năm guard: OCR local document, OCR sau language profile, STT multilingual, VAD, video STT. Output lần lượt có độ dài **92, 73, 84, 71, 74**; lưu vào Sources rồi đọc lại không đổi byte, source UUID dài 36. Không khẳng định mọi cấu hình tuỳ ý của job ngoài các nhánh này đều được nén.

### M1.5 — Rollback

Giữ duy nhất một model run (bảng cuối trong danh sách preflight), gọi `down()`; bị từ chối khi ba bảng con rỗng vẫn chưa bị drop. Probe lấy toàn bộ SHOW CREATE của packet/Course và thân trigger trước/sau, SHA bằng nhau và query listener không thấy CREATE/ALTER/DROP/TRUNCATE. Empty packet rollback/up lại thành công trong chuỗi bốn AI đã tách batch đúng.

## 5. M2 — Embedding generation

**M2.1:** MySQL branch dùng **một ALTER TABLE** để add generation, đổi unique và thêm CHECK. Không có drop-index statement riêng để session khác ghi vào khoảng không có unique giữa hai câu. Physical tests chứng minh duplicate generation 1 bị từ chối, generation 2 cùng identity được nhận, generation 0 bị từ chối. Không thử cắt điện/crash giữa DDL; không suy atomicity toàn migration từ tính chất một câu ALTER.

**M2.2:** Row generation=2 làm down throw `LF_EMBEDDING_GENERATION_ROLLBACK_REFUSED` trước DDL, hash schema trước/sau bằng nhau. Probe riêng generation=1 thực hiện down/up được, giữ nguyên id/provenance/status/vector identity/timestamp; up gán generation=1 trở lại. Query trace ghi đúng hai ALTER cho down/up (`R/generation1*.log`).

## 6. M3 — Vision

**M3.1:** Harvest khớp table doc v1.1: document/region/region, page>=1; bbox all-NULL hoặc đủ bốn giá trị chuẩn hoá/kích thước dương; tenant-aware FK tới Media File và Model Run; retained/erased content và mốc xoá; active_slot chỉ có giá trị ở ready. Có RTRIM cho CHAR fingerprint và length prefix cho trường tự do trong hash, tránh va chạm separator. `AiVisionInterpretationsSchemaTest`: **23 cases / 44 assertions** mỗi engine, gồm partial bbox, tenant mismatch, active-slot uniqueness, delimiter cases và tombstone. Timestamp nullable explicit hoạt động ở cả default OFF/ON. FK chỉ chứng minh run tồn tại/cùng tenant; trạng thái run completed và quyền đọc Media vẫn là trách nhiệm runtime theo doc, không phải đảm bảo thêm của FK.

**M3.2:** Có một interpretation → down từ chối trước DDL; full schema hash không đổi. Empty down/up đạt trên hai engine.

## 7. M4 — Authoring

**M4.1:** Đủ sáu bảng, 17 trigger AI; composite FK tới Learning Framework Version/Node chứa tenant và framework/version membership; Course revision/target/context review FK chứa đúng proposal/revision parent. FK predecessor thêm sau bảng revision. Provenance FK tới `media_files(id,customer_id)` tại M4:227 **được** `ai_authoring_proposal_sources.md:80` và `:114` cho phép; không có FK tới bảng cấu trúc extraction/job của Media. `intent_id` trên application là historical receipt không FK tới mutable Course intent, đúng doc.

**M4.2:** 20 physical tests / 128 assertions trên mỗi engine kiểm immutable history (so binary/null-safe), delete bị chặn, erasure hợp lệ chỉ dưới deletion_pending parent, bảo toàn hashes/actor/identity, source seal, terminal states, cross-tenant/cross-parent và rollback construction. Standalone concurrency probe trên hai connection thật chứng minh ba trường hợp: duplicate UUID chờ lock rồi chỉ một request; CAS claim thua cập nhật 0 row; insert source chờ parent rồi kiểm lại seal đã commit. Không dùng sleep để suy có lock wait. Quyền trigger: xem H2; quyền app/DEFINER/config thật **chưa kiểm**.

**M4.3:** Hai Course intent synthetic (lesson + activity, origin manual) được tạo **trước** apply; sau apply mọi field cũ giữ nguyên, bốn AI pointer đều NULL. Vì vậy row manual hợp lệ thoả CHECK mới trong fixture. Không kết luận mọi row thật đều hợp lệ khi chưa có dump. Cửa sổ DROP/ADD CHECK có thật và tái lập được (R1 MEDIUM phía trên); phải dừng writer.

**M4.4:** `supported()` trả false trên SQLite, up/down bỏ qua packet. SQLite không thể chứng minh M4 hoặc các composite FK liên quan. Chữ ký trong báo cáo chỉ dựa vào MariaDB vật lý; không có test schema nào của năm file bị skip vì driver. Skip duy nhất là ca Qdrant thật ngoài mục tiêu DDL.

## 8. X — Kết quả xuyên suốt

### X1 — Hai engine tách biệt

| Kiểm chứng riêng | MariaDB 10.4.21 | MariaDB 11.4.12 |
| --- | --- | --- |
| Năm file bắt buộc | 125 passed, 1 skipped; 508 assertions | 125 passed, 1 skipped; 508 assertions |
| Thời gian suite, gồm fresh schema | 57,88 s | 299,58 s |
| Danh tính test/outcome qua JUnit | Trùng 11.4; 0 failed/error | Trùng 10.4; 0 failed/error |
| `schema:drift --connection=mysql` | passed, 100 migrations, 48 INFO, 0 non-INFO | passed, 100 migrations, 48 INFO, 0 non-INFO |
| Bốn AI apply trên baseline 96 non-AI | 9,257 s | 43,268 s |
| Empty four-AI rollback | 0,625 s | 3,644 s |
| Four-AI reapply | 9,815 s | 43,692 s |
| Hai manual intent giữ nguyên | Đạt | Đạt |
| Rollback populated, full schema unchanged | 4/4 điều kiện packet đạt | 4/4 điều kiện packet đạt |
| M2 populated generation=1 down/up | Giữ nguyên row | Giữ nguyên row |
| Concurrency UUID/claim/seal | 3/3 đạt | 3/3 đạt |
| Thiếu TRIGGER; retry | 1142; rồi 1050 | 1142; rồi 1050 |
| Write trong CHECK gap; retry | 4025; rồi 1050 | 4025; rồi 1050 |
| Binlog ON, TRIGGER có, SUPER không | trust=0: 1419; trust=1: đạt | trust=0: 1419; trust=1: đạt |

Không thấy khác biệt hành vi trong phạm vi probe. SHOW CREATE có khác cách in collation ở cột và giá trị auto-increment do fixture; loại các chi tiết đó thì DDL thu được khớp. Raw CHECK_CLAUSE, FK column mappings và trigger bodies harvest khớp giữa hai engine. Không coi version 11.4.12 đã thử là bằng chứng chính xác cho CI 11.4.3 hoặc cấu hình XAMPP thật.

### X2 — Partial DDL và hồi phục

M1/M3/M4 có nhiều câu CREATE/ALTER/trigger; MariaDB có thể giữ các câu đã hoàn tất nếu câu sau lỗi. M2 là một ALTER nhưng vẫn không tạo transaction bao trùm cả lượt migrate. Fault M4 chứng minh Laravel không ghi migration thành công khi up lỗi, trong khi các object trước đó đã commit. Retry không idempotent. Đây là rủi ro trên **cả** 10.4 và 11.4.

Preflight cần có trước DDL: đúng engine; ledger/physical shape/đủ parent keys; không object cũ/dở dang bất ngờ; quyền/config trigger; manual data thoả constraints; đủ disk/temp space; không transaction/writer đang giữ lock. Source migration hiện không tự thực hiện toàn bộ preflight này. Runbook apply phải có gate đó; không xem sự hiện diện của `down()` là cơ chế hồi phục lỗi giữa chừng.

### X3 — Khoá và thời gian

Số giây ở bảng trên là local synthetic fixture nhỏ, **không phải** upper bound hay estimate cho `learnforge_db`. Chưa biết cardinality, dung lượng, long transactions, disk, SQL mode/binlog hoặc engine thực của mọi bảng đích. ALTER/FK có thể chờ metadata locks ở Course và bảng được tham chiếu; có thể cần rebuild/scan theo engine/operation. Migration không hứa `LOCK=NONE`/online ALTER. Chưa đo thời gian giữ từng lock hoặc mức downtime thật; cần rehearsal dump và mô hình writer/transaction phù hợp, lên maintenance window có dự phòng.

### X4 — Drift và harvest

Chạy selected connection mode, không SQLite, trên cả hai schema dựng thật. Lấy **108 dòng CHECK_CLAUSE** (bao gồm CHECK do JSON sinh) và **97 dòng thành phần FK** trên các bảng AI + Course intents; đây không phải 97 foreign-key constraint. Có 49 trigger trong toàn schema, trong đó 17 Authoring. SQL lọc cả `CONSTRAINT_SCHEMA`/`TABLE_SCHEMA`/`TRIGGER_SCHEMA` theo database tạm, không lẫn schema khác. Các ledger đủ 100 file, không pending/missing source. Sau baseline corrected chain, drift vẫn passed; harvest sau reapply khớp harvest sau up đầu tiên.

### X5 — Rollback đúng phạm vi

Packet rỗng: đã down đúng bốn AI và up lại. Packet có dữ liệu: gọi riêng `down()` từng migration với điều kiện guard tương ứng, kiểm full schema trước/sau và trace không có DDL. Đây là preflight **từng migration**, không phải transaction hay preflight chung cho bốn file. Không dùng rollback step=4 trên database có dữ liệu để kỳ vọng bốn migration cùng từ chối trước mọi thay đổi: các migration rỗng ở cuối có thể đã bị down trước khi migration trước đó từ chối.

Bằng chứng SHA-256 full schema trước/sau từng refusal (hai hash bằng nhau; trace DDL = 0):

| Engine | Packet/điều kiện | SHA-256 trước = sau |
| --- | --- | --- |
| 104 | 2026_09_08 | `e5f4b2bc9758ffc6afca17ff0627ea33b498810a9c53180e135612e173673a25` |
| 104 | 2026_09_13 | `6a7fe3b2c630e5cb4b7d200efd13323b79f1d020984e2bb4459e0b7e9ff4c370` |
| 104 | 2026_09_14 | `7ea3e5ea1fec62911d0844a2d60f73a3ac5cefb8cda7ac4b03998749df25b995` |
| 104 | 2026_09_15 | `320f01050e6097a702c5373cbc1bf72a20e062d87361e8c5e1c033f6f523d31d` |
| 114 | 2026_09_08 | `e7ed2f8732df8fc52c5e8438fe16c9e82169245b531175fe8328552eb3679662` |
| 114 | 2026_09_13 | `598c195ecb0be730d40eafc17d2cb43b8ff0498bb39c3a4a82da47523d061b11` |
| 114 | 2026_09_14 | `98b73e7dd2245e27f220cd60da78a56adb44efe89aab7dd23745fda31fee7f8a` |
| 114 | 2026_09_15 | `283dd9548046d48d911b4eaf6d668497935a038595e26411cedcd088c3cb1a68` |

## 9. Lệnh và bằng chứng của reviewer

Các lệnh dưới đây đã chạy trên bản sao riêng. `R/run.sh <104|114>` export `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_URL=''`, `DB_SOCKET=R/<engine>.sock`, `DB_DATABASE=lf_ai_authoring_mig<engine>`, account root tạm; cache/session array, queue sync, fake Media provider và network proxy không hoạt động. `REVIEW_DB` chỉ được harness dùng cho database clone cùng prefix.

Lệnh suite chính, chạy lần lượt với cả 104 và 114:

```sh
R/run.sh ENGINE test \
  tests/Integration/AiFoundationKnowledgePacketMariaDbTest.php \
  tests/Integration/AiAuthoringProposalPacketMariaDbTest.php \
  tests/Feature/AiVisionInterpretationsSchemaTest.php \
  tests/Integration/CourseTemplateLearningMappingPromotionMariaDbTest.php \
  tests/Feature/AiEmbeddingServiceTest.php --log-junit=R/testsENGINE.xml
```

| Lệnh/harness | Kết quả tự chạy / evidence |
| --- | --- |
| `git rev-parse HEAD`; SHA-256 original/copy/HEAD | Snapshot và năm hash ở §2; cuối review không đổi. |
| Hai binary `--no-defaults --version`; install-db; server `--skip-networking` | Version/datadir/socket/timestamp defaults ở `identity104.log`, `identity114.log`; không đọc server thật. |
| Suite năm file phía trên | `tests104.log/xml`, `tests114.log/xml`; 125 passed + 1 Qdrant skip mỗi engine; `junit-comparison.json` so tên/outcome. |
| `run.sh ENGINE schema:drift --connection=mysql --format=json` | `drift104.log`, `drift114.log`: passed, 48 INFO, 0 non-INFO. |
| `run.sh ENGINE php R/probes/review.php harvest` | `harvest*.log`: SHOW CREATE, CHECK_CLAUSE, FK mappings, triggers, identity và autoload path. |
| `... review.php widths` | `widths*.log`: năm guard/version round-trip, UUID36, all-NULL duplicate 1062, generation0 bị CHECK. |
| `... review.php populated` | `populated*.log`: cả bốn guard throw, full schema SHA bằng nhau, không DDL. |
| `python3 R/verify.py` | `verify-results.json`: drift/probe/manual/concurrency; lượt rollback4 đầu chứa quota nên không tính là đủ bốn AI. |
| `mariadb-dump --no-defaults --skip-ssl --socket=R/ENGINE.sock -uroot --routines --triggers lf_ai_authoring_migENGINE` | Chỉ dump synthetic DB riêng để làm baseline clone; không phải dump của Owner. Lần dùng client 11.4 dump 10.4 thiếu `--skip-ssl` lỗi 2026; thêm flag cho **Unix socket riêng** rồi đạt. Không lỗi migration. |
| `python3 R/chain.py` | Restore clone; down Foundation rỗng, dựng quota baseline bằng root, migrate đúng bốn AI; `chain-results.json`, `four-up/down/reup*.log`, `four-drift*.log`. |
| `... review.php manual-check` | `manualcheck*.log`, `four-manual*.log`: 2 row cũ nguyên vẹn, 4 AI pointers NULL. |
| `... review.php generation1` | `generation1*.log`: populated M2 down/up giữ row; trace một ALTER mỗi chiều. |
| `run.sh ENGINE php tests/Support/Ai/authoring-packet-concurrency.php` | `concurrency*.log`: UUID, claim, seal đều có observed lock wait rồi đúng kết quả. |
| `python3 R/faults.py` | `fault-privilege*.log`, `fault-window*.log`: lỗi chủ ý đúng 1142/4025; retry 1050, còn object chưa ghi ledger. Harness thành công không có nghĩa migration lỗi đã PASS. |
| Restart instance tạm với `--log-bin=R/dENGINE/binlog --server-id=ENGINE --log-bin-trust-function-creators=0`; `php R/probes/binlog.php ENGINE` | `binlog*.log`: không SUPER, có TRIGGER; 1419 ở trust0, tạo được ở trust1; trả global trust về giá trị đầu. |
| Final harvest/re-hash/cleanup | `final-harvest*.log`, `integrity-final.json`, `cleanup.json`, `evidence-sha256.json`. |

Bản dump synthetic được restore chỉ trên instance riêng; bản sao source/probe/log giữ ở R để đối chiếu. Không có phép thử nào trong bảng này là rehearsal từ dữ liệu thật.

## 10. Chưa kiểm và điều kiện đóng gate

1. **Chưa nhận/restore dump thật**, chưa nhận status/ledger thật; chưa biết chính xác migration ngoài AI nào còn pending. Mâu thuẫn lịch sử Foundation chưa được phân xử.
2. Chưa biết effective grants/DEFINER của account apply/runtime, binlog/trust, SQL mode, timestamp defaults và cấu hình storage của target. Probe quyền là mô hình account tạm.
3. Chưa kiểm row counts/check violations/dữ liệu lịch sử thật trước/sau apply; chưa có đo lock/downtime ở kích thước thật hoặc rehearsal restore backup thật.
4. Chưa thử power loss, disk full, kill engine trong DDL, phục hồi crash, hoặc toàn bộ quy trình upgrade 10.4→11.4. Không ký atomic migration từ các probe exception có kiểm soát.
5. Không chạy toàn suite mặc định/33-file integration trong lượt này; đã chạy đủ năm file brief yêu cầu và các probe riêng. Runtime AI, provider activation, UI và permissions nghiệp vụ nằm ngoài chữ ký.

Đóng gate cần Owner giao status + dump; đối chiếu schema Foundation; chọn engine được hỗ trợ; rehearsal bằng đúng config/account và pending list, đo thời gian/row preservation/drift; chứng minh backup restore và kế hoạch dừng writer. Sau đó mới cập nhật verdict toàn bộ. Không cần reviewer sửa source để che bất kỳ mục chưa kiểm nào.

## 11. Kế hoạch apply đề xuất — chưa thực hiện trên database thật

1. **Chốt baseline.** Owner cung cấp status/dump cùng checksum và thời điểm xuất; đối soát M1 batch27 trong hồ sơ cũ. Nếu có schema cũ/dở dang hoặc migration ngoài AI pending, dừng để lập phạm vi/forward remediation riêng. Không sửa ledger cho khớp ý định.
2. **Chốt engine trước.** Upgrade/rehearsal trên phiên bản 11.4 được chọn và config dự kiến; việc restore dump 10.4 vào clone 11.4 không tự chứng minh đầy đủ quy trình upgrade tại chỗ. Deployment preflight từ chối version ngoài floor.
3. **Backup có khả năng phục hồi.** Owner/DBA tạo backup nhất quán gồm schema, data, migrations ledger, triggers/routines/events, thông tin grants/DEFINER và cấu hình liên quan; lưu ngoài repo, checksum và quyền truy cập hạn chế. Với backup logical, `--single-transaction` chỉ đủ cho bảng transactional và không bảo vệ trước DDL đồng thời; chọn cách backup phù hợp engine thực. Thử restore vào instance riêng và kiểm row counts/drift trước khi coi backup dùng được.
4. **Rehearsal đúng lần apply.** Trên clone của dump, dùng đúng pending list/account/config và chính snapshot/hash này; chạy `migrate` như dự kiến, đo thời gian, so row counts của các bảng có sẵn, dữ liệu manual, constraints/triggers và drift. Nếu dự kiến nâng engine, rehearsal cả clone 10.4 và engine mới. Physical tests destructive chỉ chạy trên bản sao rehearsal có thể bỏ đi; không chạy RefreshDatabase lên backup duy nhất hoặc database thật.
5. **Preflight và dừng ghi.** Xác nhận quyền CREATE/ALTER/INDEX/REFERENCES/TRIGGER và DML/SELECT cần thiết, điều kiện binlog, DEFINER tồn tại; đủ disk/tmp; parent composite keys có thật; manual rows hợp lệ; không table/column/trigger collision. Drain queue, scheduler, worker và transaction dài; chặn mọi writer tới Course/AI/parent liên quan. Thực thi một migration runner duy nhất.
6. **Thứ tự dự kiến nếu đúng bốn AI pending:** Foundation → Embedding generation → Vision → Authoring. Đây là thứ tự filename khi các non-AI đã ở baseline. Không bỏ qua quota hoặc migration khác còn pending bằng cách đoán; phải theo status đã review.
7. **Phát hiện lỗi/sau apply.** Thu stdout/stderr/exit code, ledger và schema metadata sau mỗi chặng; khi tất cả thành công, kiểm đủ bốn ledger entries, 17 trigger Authoring, ba FK AI trên Course, CHECK origin/provenance, row counts/manual data và `schema:drift` trước khi mở writer. Exit 0 một mình chưa đủ nếu physical schema cũ đã được ledger che.
8. **Nếu lỗi giữa chừng:** giữ writer dừng; không chạy lại migrate hoặc rollback mù. Chụp schema/ledger/log trạng thái lỗi, xác định object đã commit. Ưu tiên restore **toàn bộ baseline backup đã rehearsal** vào môi trường sạch rồi xác minh/cutover theo runbook, thay vì import đè dump lên schema chứa object dư. Nếu cần cứu thủ công, DBA/Owner phải duyệt kế hoạch forward repair hoặc cleanup chính xác theo actual schema và dữ liệu; test kế hoạch trên clone trước. Không drop trigger để sửa history, không xoá audit/tombstone, không đánh dấu M4 đã chạy khi trigger thiếu. `down()` có thể từ chối dữ liệu và không được thiết kế làm cleanup mọi partial-up state.
9. **Chỉ mở lại writer** sau row preservation, drift, ledger, quyền/DEFINER và smoke checks đã đạt. Nếu có write sau điểm backup, restore mất các write đó; phải có kế hoạch bảo toàn/replay được Owner duyệt trước, không tự bỏ dữ liệu để khôi phục nhanh.

**Verdict cuối: BLOCKED cho lần apply thật; bốn file có verdict riêng có điều kiện ở §1.** Không có thao tác nào được thực hiện trên `learnforge_db` trong review này.

---

## Lượt 2 — Re-review M4

Ngày: 2026-09-26–2026-09-27 (Asia/Ho_Chi_Minh). Reviewer vẫn độc lập, không viết/vá M4 hoặc helper/test của implementer. Brief v1.2 và § Packet migration apply-safety hardening đã đọc; mọi kết quả dưới đây do reviewer tự chạy. Nội dung lượt 1 phía trên được giữ nguyên từng byte (SHA-256 trước append: `668c9d990a35fe09cb65e990235a93e1b947b740bef3b9c45ca8342aba9ba625`); header/Snapshot phía trên thuộc lượt 1, Final Verdict không đổi. Nhận định H2/R1 và chữ ký M4 cũ được cập nhật **chỉ** bằng phần này.

### Snapshot và verdict

HEAD: `03d308b67d612be5aa85d99a6abea7f2e06e75d0` **cộng working tree chưa commit** tại thời điểm chụp. HEAD một mình không chứa toàn bộ bản gia cố. Workspace review riêng: `/private/tmp/lfm4r2.xnmj_0qg` (R2). Có physical copy `vendor`, không symlink; `.env` thật không được copy. Kiểm cuối 1.018 file của original và copy đều khớp snapshot (`integrity-final.json`). SHA-256 các file cần ký:

| File | SHA-256 |
| --- | --- |
| M4 `database/migrations/2026_09_15_000100_create_ai_authoring_proposal_packet.php` | `bf48be43d2c218d5fe205f240529b47361da108964fbcc28a9cbaf8a143c1994` |
| `app/Support/Database/TriggerCreationPreflight.php` | `b974ea650fc291703e8cf8cd981753139ef65fb6cbc18d1b59c582b177afd910` |
| `tests/Integration/AiAuthoringPacketApplySafetyMariaDbTest.php` | `98470b7dd904ac0b82631e7fd316fe3ab9540d498167b9a07a19187c6324d99a` |
| `tests/Unit/TriggerCreationPreflightTest.php` | `9fb712214106464ae6f205d2d903e32315b382f38f18d35696d64f5db605ba92` |
| M1 — không đổi so lượt 1 | `f87b6557b4a62f3f4619d9765067b50c72f63b1008498dc19135d9754881ec65` |
| M2 — không đổi | `d244f76c98edce52bc6cf485722860f4318adb6f8325a53024f3d5d32410fe2f` |
| M3 — không đổi | `91b4f59ac6cd71ea9ef5b7beb540e5373a2e90573717c47aa5bd48325c18fa8f` |
| `docs/database/LF-SCHEMA-CONTRACT.json` — không đổi | `b08e943f1cb5acf6f5c1365578b98338b3cb3a6268e459ecf5862415213ac97b` |

- **M4 mới: APPLY-READY WITH DOCUMENTED RISKS**, giới hạn trên schema/fixture và mô hình account đã kiểm ở hai engine.
- **Toàn bộ apply: BLOCKED**, không đổi. B1, H1, account/config thật và rehearsal dump vẫn chưa được xác nhận; lượt này không mở rộng chữ ký sang các mục đó.
- Không đánh giá lại thiết kế/code M1–M3; chỉ xác nhận SHA và chạy các file regression bắt buộc liên quan.

### Trạng thái findings cũ

| Finding | Trạng thái lượt 2 | Bằng chứng và giới hạn |
| --- | --- | --- |
| H2 — thiếu trigger privilege phát hiện muộn | **CLOSED trong phạm vi gia cố M4 đã yêu cầu** | Thiếu TRIGGER/trust0 thiếu SUPER dừng trước DDL, schema không đổi; cấp đủ quyền/trust1 thì up thành công. Partial guard bắt đủ 6 dạng bảng và 4 dạng cột. Không ký rằng account thật đã đủ quyền, hoặc preflight là bảo đảm mọi DDL thành công. |
| R1 — cửa sổ CHECK origin | **CLOSED** | Up/down đều swap trong một ALTER; probe cạnh tranh có observed metadata lock; write sai bị chặn trước/sau; ALTER lỗi 4025 giữ CHECK cũ và full schema hash. |
| L1 — mô tả rollback | **CLOSED về nội dung rollback** | Brief v1.2 đã phân biệt M2 generation>1 với ba packet còn lại, yêu cầu so schema và cảnh báo step=4 có thể gồm quota. Khi dùng step=4 vẫn phải biết chính xác bốn migration cuối từ ledger; chỉ nói “cùng batch” chưa đủ nếu batch còn file khác. Không re-review M1–M3 trong lượt này. |

Không phát hiện finding mới buộc sửa M4/helper theo contract preflight giới hạn đã công bố. Các rủi ro còn lại dưới đây là giới hạn phải đưa vào runbook, không phải xác nhận apply thật.

### H2: probe độc lập, trước mọi DDL

M4 `:28`–`:29` gọi partial-state guard rồi preflight trước `$this->requests()` (`:31`). Helper `TriggerCreationPreflight.php:26`–`:40` chỉ SELECT server/account và information_schema. Reviewer dùng query trace và **SHOW CREATE của toàn bộ base tables + trigger definitions** trong database tạm, không chỉ đếm sáu bảng.

Trên mỗi engine:

1. Down M4 khi packet rỗng; account probe có các quyền DDL/DML cần thiết nhưng không TRIGGER. `up()` ném `LF_MIGRATION_PREFLIGHT_TRIGGER_PRIVILEGE`; không bảng/cột/trigger mới, full schema SHA trước/sau bằng nhau; trace không có DDL/DML/GRANT/REVOKE. Grant TRIGGER trực tiếp rồi chạy lại **cùng baseline** thành công, không cần cleanup partial DDL.
2. Tạo lần lượt từng stub trong 6 tên bảng, rồi lần lượt từng cột AI trong 4 tên cột Course (10 trường hợp độc lập, cleanup mỗi fixture trước case tiếp). Cả 10 lần đều ném `LF_AUTHORING_PACKET_PARTIAL_STATE`, chỉ rõ object tồn tại; schema không đổi, không còn lỗi 1050 như probe lượt 1. Guard **từ chối**, không tự sửa hoặc xoá phần dở dang.
3. Restart cùng instance tạm với binary log ON, `trust=0`; account có TRIGGER trực tiếp nhưng không SUPER. `up()` ném `LF_MIGRATION_PREFLIGHT_BINLOG_TRIGGER` trước DDL, full schema không đổi. Đổi trust=1 **chỉ trên instance tạm** rồi up bằng account đó thành công, đủ packet/triggers. Trả cấu hình tạm về giá trị ban đầu sau probe.

Mỗi engine có 12 refusal cases riêng (1 thiếu TRIGGER + 10 partial state + 1 binlog). Hash trước/sau và toàn bộ query trace ở `R2/guardsENGINE.log`, `R2/binlogENGINE.log`; không suy kết quả binlog chỉ từ unit test boolean.

### R1: concurrency và failure của câu ALTER thật

Vị trí mới: M4 `:356`–`:358` (up), `:513`–`:514` (down). Probe ghi lại chính SQL do migration phát ra, gọi down/up thật và xác nhận mỗi hướng có đúng một statement chứa cả DROP và ADD origin. Sau đó dùng lại **nguyên câu ALTER đã capture** trên Course intents với hai manual fixture và các cột/FK AI đang tồn tại, tạo đúng trạng thái trước swap; không sửa source migration.

- Connection cha giữ transaction/row lock trên intents, nên giữ metadata lock. Connection thứ hai gửi ALTER và được quan sát ở trạng thái `Waiting for table metadata lock` qua PROCESSLIST.
- Khi ALTER đang chờ, CHECK manual-only vẫn có và write origin sai từ transaction cha bị 4025. Connection thứ ba gửi write origin sai, cũng quan sát được metadata lock wait; không đoán thứ tự bằng sleep.
- Cha commit: ALTER thành công, writer đang chờ bị 4025 dưới CHECK mới. Hai row vẫn origin manual. Không có bước DROP độc lập cho writer chen giữa.
- Failure case: giữ CHECK manual-only, cho một row manual có AI pointer không NULL (trạng thái đối nghịch chỉ ở fixture), rồi chạy đúng combined ALTER. ADD provenance CHECK thất bại 4025; **toàn bộ SHOW CREATE trước/sau bằng nhau**, CHECK manual-only vẫn có và tiếp tục từ chối origin sai. Cleanup fixture rồi chạy cùng ALTER thành công.

| Engine | Full schema SHA-256 trước = sau ALTER thất bại |
| --- | --- |
| 10.4.21 | `39674617106f70d91099fe414a9f6b0378c0813734e76a398250eb7cd95d6414` |
| 11.4.12 | `3f69b2b9f948f784f472a1385df4384eec866016a7684a78ac1e30b7156c4f0f` |

Đây là proof cho serialization và lỗi constraint có kiểm soát, không phải thử mất điện/crash giữa DDL. Toàn M4 vẫn gồm nhiều câu DDL tự commit; vẫn phải dừng writer và có backup đã rehearsal. Down cũng không trở thành transaction bao trùm việc bỏ FK/cột/bảng.

### Giới hạn preflight và DEFINER

**False negative có chủ ý — chấp nhận cho runbook với account apply được chuẩn bị trước.** Test mới xác nhận direct schema grant được nhận, grant chỉ qua role bị từ chối. Probe riêng còn cấp TRIGGER chỉ trên một bảng: helper từ chối nhưng account đó CREATE TRIGGER trên bảng được cấp quyền thành công. Vì M4 tạo các bảng mới, yêu cầu direct schema/global grant là policy bảo thủ, có thể vận hành được. Nên dùng account migration riêng với quyền trực tiếp trên schema cần apply; không vì helper từ chối role mà tự cấp SUPER cho account app. Nếu tổ chức chỉ cho role/table grants, cần DBA chuẩn bị phương án được review hoặc sửa contract/helper qua lượt review khác, không bypass im lặng.

**Có trường hợp preflight qua nhưng CREATE TRIGGER vẫn hỏng.** Trên cả hai engine, account có direct TRIGGER, binlog trust1, server `read_only=1`: helper qua nhưng CREATE TRIGGER lỗi **1290**. Đây không phải sai kết quả truy vấn privilege; nó chứng minh “pass metadata” không đồng nghĩa “server cho phép DDL”. Helper đã mô tả giới hạn này ở `:18`–`:20`. Runbook cần kiểm khả năng ghi/server state và mọi quyền DDL khác trước apply. Việc quyền/config bị đổi sau preflight, object collision xuất hiện sau partial check, hết đĩa hoặc mất connection vẫn có thể làm up hỏng; không có lock nào cố định mọi điều kiện đó cho cả migration.

**DEFINER cần tồn tại và giữ quyền mà trigger sử dụng sau deploy.** Probe riêng tạo trigger dưới account khác rồi thử INSERT từ root: revoke SELECT của DEFINER làm trigger lỗi **1142**; xoá DEFINER làm lỗi **1449**. Do đó không được tạo trigger bằng account tạm rồi xoá account đó sau apply; cũng không thu hồi mù các quyền cần cho thân trigger. Quyền runtime của account app không tự thay quyền DEFINER. Probe dùng bảng test riêng và được cleanup, không thay trigger canonical.

Các fixture/probe account được tạo bởi reviewer/test trên instance bỏ đi. **Bản thân preflight không tạo object hay account nào.** Không có thử tạo trigger để “preflight” trên database thật.

### M4.1–M4.4 và X2 sau gia cố

- **M4.1:** sáu bảng, composite FK Learning/Course và provenance Media không đổi; harvest đối chiếu trực tiếp lượt 1 trên từng engine. Không thay boundary hoặc column shape.
- **M4.2:** packet suite 20 tests/128 assertions vẫn đạt; kiểm immutable history, erasure, parent/tenant và các luồng hợp lệ. Quyền thiếu được phát hiện trước DDL trong phạm vi contract; giới hạn/DEFINER như trên. Không ký runtime authoring hay provider.
- **M4.3:** combined ALTER loại khoảng trống origin giữa statements; concurrency/failure proof ở trên; hai manual fixture còn nguyên. Không suy row thật hợp lệ khi chưa có dump.
- **M4.4:** SQLite vẫn skip packet; mọi chữ ký vật lý ở lượt này dựa trên MariaDB. Unit test chỉ chứng minh decision matrix, không thay proof engine.
- **X2:** missing privilege và partial state hiện fail sạch; những lỗi khác vẫn có thể để lại DDL dở dang. Khi guard báo partial state phải dừng, xem schema/ledger và restore hoặc reviewed repair; không đánh dấu migration đã chạy hoặc chạy lại mù. §11 lượt 1 vẫn áp dụng, bổ sung kiểm `read_only`, privilege/config ổn định và vòng đời DEFINER.

### Bảng lệnh/kết quả do reviewer chạy

Hai engine dùng `--no-defaults`, socket/datadir/pid riêng dưới R2, `--skip-networking`; xác minh VERSION/datadir/socket trước test. Suite chính dùng binlog OFF; probe riêng restart binlog ON (server-id 104/114). Timestamp defaults OFF ở 10.4, ON ở 11.4. Không kết nối `learnforge_db`, socket XAMPP thật hoặc TCP 3306; không provider thật. Wrapper đặt DB_URL rỗng và socket tường minh, fake provider, cache/session array.

Lệnh suite, chạy tuần tự trên hai engine:

```sh
R2/run.sh ENGINE test \
  tests/Integration/AiFoundationKnowledgePacketMariaDbTest.php \
  tests/Integration/AiAuthoringProposalPacketMariaDbTest.php \
  tests/Feature/AiVisionInterpretationsSchemaTest.php \
  tests/Integration/CourseTemplateLearningMappingPromotionMariaDbTest.php \
  tests/Feature/AiEmbeddingServiceTest.php \
  tests/Integration/AiAuthoringPacketApplySafetyMariaDbTest.php \
  tests/Unit/TriggerCreationPreflightTest.php --log-junit=R2/testsENGINE.xml
```

| Lệnh / bằng chứng | MariaDB 10.4.21 | MariaDB 11.4.12 |
| --- | --- | --- |
| Suite bảy file ở trên; `testsENGINE.log/xml` | exit 0; 135 passed, 1 skipped, 549 assertions; 230.53 s | exit 0; 135 passed, 1 skipped, 549 assertions; 1273.90 s |
| `R2/run.sh ENGINE schema:drift --connection=mysql --format=json` (đầu và cuối) | PASS; 48 INFO, không warning/error | PASS; 48 INFO, không warning/error |
| `R2/run.sh ENGINE php R2/probes/harvest.php harvest` | 108 CHECK, 97 FK component rows, 49 triggers toàn schema (17 Authoring); khớp lượt 1 | Cùng số lượng; CHECK/FK/trigger definitions khớp lượt 1 của 11.4 |
| `R2/run.sh ENGINE php R2/probes/guards.php basic` | exit 0; missing TRIGGER + 10 partial cases: schema SHA giữ nguyên, không DDL/DML; grant rồi retry thành công | exit 0; cùng kết quả |
| `R2/run.sh ENGINE php R2/probes/origin.php` | exit 0; observed ALTER/writer MDL wait; writes sai 4025; ALTER lỗi giữ CHECK/schema; giữ 2 manual rows | exit 0; cùng kết quả |
| `R2/run.sh ENGINE php R2/probes/guards.php binlog` sau restart binlog ON | exit 0; trust0/no SUPER từ chối trước DDL; trust1 full up thành công; read_only preflight qua nhưng CREATE lỗi 1290 | exit 0; cùng kết quả |
| Table-only grant probe trong `guards.php basic`; role trong suite | Table grant: helper từ chối dù CREATE TRIGGER được; role-only bị helper từ chối | Cùng kết quả |
| `R2/run.sh ENGINE php R2/probes/definer.php` | exit 0; revoke SELECT → 1142; drop DEFINER → 1449 | exit 0; cùng kết quả |
| `python3 R2/mutations.py ENGINE`; từng JUnit | 3/3 mutant đỏ đúng assertion, 0 errors; restore đúng SHA; test đúng xanh lại | 3/3 mutant đỏ đúng assertion, 0 errors; restore đúng SHA; test đúng xanh lại |
| `python3 R2/proof-summary.py` | 12 guard cases không đổi schema; so harvest và JUnit đạt | Cùng kết quả; cả 136 tên/outcome JUnit bằng 10.4 |
| `mariadb-admin --no-defaults --socket=R2/ENGINE.sock -uroot shutdown`; cleanup datadir | PASS; socket/pid/datadir đã xoá | PASS; socket/pid/datadir đã xoá |


Năm file cũ tổng 125 passed + 1 skipped, 508 assertions; file apply-safety 4 passed/35 assertions; unit preflight 6 passed/6 assertions. Skip duy nhất là Qdrant thật. JUnit đối chiếu tên/outcome giữa engine, không tính skip là PASS schema.

### Mutation: test mới bắt đúng lỗi

Chỉ sửa M4 trong `R2/snapshot`, luôn khôi phục trong `finally`, đối chiếu SHA sau từng case; helper/test original không bị sửa. Chạy từng test bằng PHPUnit với bootstrap reviewer đánh dấu schema đã dựng để dùng lại database tạm sạch về packet, tránh migrate:fresh toàn dự án cho mỗi mutant. **Suite bảy file chính phía trên không dùng bootstrap này.** Sau mỗi case khôi phục file, down/up packet bằng bản đúng để chuẩn hoá fixture. Không chạy mutation khi suite engine khác đang dùng cùng snapshot.

| Mutation | SHA-256 mutant | 10.4.21 | 11.4.12 |
| --- | --- | --- | --- |
| Bỏ lời gọi preflight → thiếu TRIGGER tới CREATE và lỗi 1142 | `97cc2cb3194d86255a0ebae1f5e003d65bda9b528f4d4d402fcd8f64cddef42c` | 1 failure, 0 errors | 1 failure, 0 errors |
| Bỏ partial-state guard → CREATE TABLE lỗi 1050 | `594c1e7e2d61ad61b0038ca84bc5a509ba383424d1a36d079248c631e36aa0bc` | 1 failure, 0 errors | 1 failure, 0 errors |
| Tách DROP/ADD origin → assertion cùng statement thất bại | `b6a82266515edb955ea16c753b456c974c32fbde1f580d402d61c7b511d6aa80` | 1 failure, 0 errors | 1 failure, 0 errors |


Mỗi mutant có đúng **1 assertion failure, 0 errors** ở test tương ứng theo JUnit, không phải lỗi bootstrap/connection. Sau khôi phục, test origin swap chạy lại xanh (1 test/13 assertions) trên từng engine; final harvest/drift vẫn đúng. SHA restored luôn `bf48be43d2c218d5fe205f240529b47361da108964fbcc28a9cbaf8a143c1994`.

### Chưa kiểm, cleanup và kết luận

- B1, H1, status/dump/rehearsal, grants/config/DEFINER của target thật **ngoài phạm vi lượt 2 và vẫn chưa xác nhận**. Không thay verdict các mục đó bằng kết quả account giả lập.
- Không thử server crash/mất điện/đĩa đầy, mọi tổ hợp grant pattern/SQL mode/version hoặc mọi race thay config/quyền. Concurrency proof tập trung đúng câu swap origin; không ký apply online cho toàn M4.
- Không mở rộng review thiết kế M1–M3; không chạy toàn suite mặc định/33-file integration ngoài bảy file yêu cầu. Standalone authoring concurrency của lượt 1 không chạy lại vì trigger bodies không đổi; lượt này có concurrency probe riêng cho ALTER.
- Cả hai server riêng đã shutdown thành công; socket, pid-file và toàn bộ datadir `R2/d104`, `R2/d114` đã xoá, xác nhận trong `cleanup104.json`, `cleanup114.json`. Không còn instance review chạy; giữ lại bản sao source và bằng chứng để tái lập.
- Logs, JUnit, query traces, mutation hashes và probe source ở R2, có `evidence-sha256.json` để đối chiếu. Kiểm integrity cuối xác nhận code/test/migration/canonical docs original không bị reviewer sửa; chỉ append phần này vào báo cáo.

**Verdict lượt 2: M4 APPLY-READY WITH DOCUMENTED RISKS; toàn bộ lần apply vẫn BLOCKED.** H2/R1 được đóng trong phạm vi gia cố đã kiểm trên hai engine, L1 đóng về hướng dẫn rollback; các điều kiện apply thật vẫn bắt buộc.
