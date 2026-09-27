# AI Migrations Pre-Apply — Reviewer Brief

Version: 1.4

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-26

Document Path: quality/LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md

---

# Vì sao có brief này

Owner chọn phương án (a) ngày 2026-09-26: **một review độc lập cho cả bốn
migration AI trước lần apply đầu tiên lên `learnforge_db`.** Lệnh `migrate`
chạy cả bốn cùng lúc, và chưa migration nào có code review độc lập PASS cho bản
DDL hiện hành:

| Migration | Nội dung | Tình trạng review |
| --- | --- | --- |
| `2026_09_08_000100_create_ai_foundation_knowledge_tables.php` | `ai_knowledge_sources`, `ai_knowledge_chunks`, `ai_embeddings`, `ai_model_runs` | Round 3 PASS là PASS **thiết kế**; người ký Round 3 viết DDL; code review độc lập trả **FAIL / Migration NO** (3 P1, 3 P2); tác giả vá; **chưa re-review** ([hồ sơ 0a](LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md) § Step 1) |
| `2026_09_13_000100_add_ai_embedding_generation.php` | `generation` + unique key + CHECK trên `ai_embeddings` | Owner miễn trừ Architecture Review (Bước 5) |
| `2026_09_14_000100_create_ai_vision_interpretations.php` | `ai_vision_interpretations` | Owner miễn trừ (Bước 6) |
| `2026_09_15_000100_create_ai_authoring_proposal_packet.php` | Sáu bảng `ai_authoring_*`, trigger lịch sử/erase, **ALTER bảng Course đang có dữ liệu** `core_course_template_learning_mapping_intents` | Owner miễn trừ (Bước 7); miễn trừ ghi rõ không cho phép apply `learnforge_db` |

Các miễn trừ cho phép **tạo** migration. Chúng không phải review PASS và không
bao phủ việc apply. Apply là thao tác một chiều: `down()` của Foundation, Vision
và Authoring từ chối khi packet có dữ liệu; `down()` của `generation` chỉ từ chối
khi có `generation > 1` (sửa theo review L1).

## Trạng thái thật `learnforge_db` — Owner cho phép kiểm, 2026-09-27

Owner quyết định `learnforge_db` là database dev local và cho phép implementer làm
việc trực tiếp sau khi backup (phương án a). Đây là **Owner miễn trừ gate rehearsal
cho môi trường dev local**, không phải review PASS; verdict BLOCKED của review vẫn áp
dụng cho mọi môi trường thật về sau.

* Backup trước mọi thao tác: `mysqldump --single-transaction --routines --triggers
  --events` ra `/Users/amin/LF-db-backups/learnforge_db-20260927-071325-before-ai-migrations.sql`
  (ngoài repo), SHA-256 `3a4a3c30b741667b78fec0d0e043b0338229b1a67c104af46fcaee08d9f61129`.
* Server: MariaDB 10.4.21 XAMPP, `log_bin=0`.
* Ledger: Foundation `2026_09_08_000100` ở batch 27; `generation`, Vision, Authoring
  (cùng migration quota 2026-09-12) ở batch 28. **Không có migration pending.** Mọi bảng
  AI có 0 row; Course intents có 3 row `manual`.
* `php artisan schema:drift --connection=mysql` (chỉ đọc) trên `learnforge_db`:
  **passed, 0 finding ngoài INFO**. Kiểm tay: `generation`, `chk_aks_generation`,
  `chk_aks_usage_pair`, `fk_aks_media_tenant`, unique identity gồm `generation`, biểu
  thức `identity_fingerprint` bản có `rtrim`; `chk_cct_lmi_origin` và
  `chk_cct_lmi_ai_provenance`; 17 trigger Authoring, DEFINER `root@localhost`.
* Kết luận B1: bốn bảng Foundation đã được dựng lại đúng bản vá sau cảnh báo batch 27
  cũ; không cần rollback hay rebuild M1. M4 trên database này được apply từ source
  **trước** khi gia cố (SHA `c23c8d76…`); schema object giống hệt bản gia cố, chỉ khác
  preflight và cách gộp câu `ALTER`, nên không cần chạy lại.
* Không có thay đổi nào được ghi vào `learnforge_db` trong lượt kiểm này.

**Chuyển engine local sang MariaDB 11.4 — Owner quyết định, 2026-09-27 (H1 cho dev local).**

* Thử restore trước: backup 07:13 vào instance 11.4.12 dùng một lần — restore sạch
  (36 s, không lỗi, kể cả generated column Foundation), 101/101 bảng và 26.104 row khớp
  **từng bảng**, trigger/CHECK/FK 49/297/344 khớp, 0 pending, `schema:drift` passed.
* Cutover: instance Homebrew `mariadb@11.4` chạy bằng `brew services`, cổng 3307, chỉ
  `127.0.0.1`, datadir `/usr/local/var/mysql`, cấu hình `/usr/local/etc/my.cnf.d/learnforge.cnf`;
  user app riêng `learnforge` (ALL trên `learnforge_db`, mật khẩu chỉ trong `.env`). Dump mới
  từ XAMPP ngay trước chuyển (không có writer), restore, đối chiếu lại: 101/101 bảng, 26.104 row
  khớp từng bảng, 49/297/344 khớp. `.env` đổi sang 3307/`learnforge`; app đọc đúng dữ liệu,
  0 pending, `schema:drift --connection=mysql` passed trên 11.4.12.
* Trigger giữ DEFINER `root@localhost`, tài khoản tồn tại lâu dài trên instance 11.4.
* Bản 10.4 trên XAMPP còn nguyên nhưng **đã cũ** từ lúc cutover; không ghi vào đó. `.env` cũ
  lưu ngoài repo tại `/Users/amin/LF-db-backups/` để quay lại nếu cần.
* Chỉ áp dụng cho dev local; môi trường thật khác vẫn theo runbook và verdict của review.

**Sau review — M4 đã đổi (2026-09-26):** Owner ủng hộ gia cố M4 theo R1 và H2;
SHA-256 M4 mới `bf48be43d2c218d5fe205f240529b47361da108964fbcc28a9cbaf8a143c1994` khác bản reviewer đã ký. Chi tiết và bằng chứng
implementer: [hồ sơ Bước 7](LF-AI-Authoring-Proposal-Implementation-Review.md)
§ Packet migration apply-safety hardening. Reviewer cần chạy lại phần M4 (M4.1–M4.4 và
X2) trên snapshot mới; ba migration còn lại không đổi.

**Đính chính sau review (B1):** câu "lần apply đầu tiên" ở trên sai. Hồ sơ 0a
§ Step 1 CI Gate — Cảnh báo trạng thái database thật ghi `learnforge_db` đã có bốn
bảng Foundation, apply 2026-09-08 batch 27, **trước** bản vá P1 (0 row lúc ghi).
Người soạn brief đã bỏ sót ghi chú này. Trạng thái thật phải xác nhận bằng
`migrate:status` và dump do Owner giao.

**Rủi ro đã biết cần reviewer đánh giá:** `learnforge_db` chạy trên XAMPP
MariaDB **10.4.21**, thấp hơn mức tối thiểu `>= 10.5` ghi trong
[LF-Tech-Runtime-Requirements](../tech/LF-Tech-Runtime-Requirements.md) và
[LF-Tech-Stack](../tech/LF-Tech-Stack.md). CI dùng `mariadb:11.4.3`. Hai engine đã
từng xử lý khác nhau cùng một câu DDL trong dự án này.

Verdict yêu cầu **cho từng migration và cho toàn bộ lần apply**: `APPLY-READY`,
`APPLY-READY WITH DOCUMENTED RISKS`, `CHANGES REQUIRED` hoặc `BLOCKED`. Không ký
cho điều chưa tự kiểm chứng.

---

# Ràng buộc độc lập

* **Không đủ tư cách:** tác giả hoặc người vá của bất kỳ migration nào trong bốn
  migration trên, gồm:
  * tác nhân đã ký Round 3 và viết DDL Foundation;
  * implementer Bước 5 (`generation`), Bước 6 (Vision) và Bước 7 (packet Authoring);
  * session implementer của Knowledge Sync (đã viết migration Vision và phần lớn
    code Bước 6/7).
* **Đủ tư cách nếu chưa sửa gì:** reviewer đã trả FAIL cho code review Foundation
  (hồ sơ 0a yêu cầu chính người đó re-review); reviewer của báo cáo đánh giá lại
  2026-09-26. Reviewer khác cũng được.
* Không dựa vào kết luận hay bảng số của các hồ sơ trước; tái lập.
* Reviewer **không vá**; finding giao lại cho implementer.

# Ràng buộc an toàn

* Chỉ đọc. Không sửa migration, code, test hay tài liệu canonical. Báo cáo đặt ở
  `docs/quality/` hoặc working directory.
* **Không kết nối `learnforge_db` và không chạm server XAMPP đang chạy ở cổng
  3306** — kể cả lệnh chỉ đọc như `migrate:status` hay `mysqldump`. Mọi thứ cần
  từ database thật do Owner tự lấy và giao (§ Rehearsal).
* Được dùng **binary** XAMPP 10.4.21 để dựng instance **riêng**: datadir, socket
  và pid-file trong `/tmp`, `--skip-networking`. Tiền lệ: hồ sơ Vision đã kiểm cả
  11.4.12 và 10.4.21 theo cách này.
* Không symlink `vendor` vào bản sao/worktree; xác nhận class được nạp từ đúng
  snapshot.
* Mutation (nếu có) chỉ trên bản sao riêng, ghi và đối chiếu SHA-256.
* Tắt instance và xoá datadir sau khi xong.

# Snapshot

Bốn migration đã nằm trong HEAD `01cce9e`; working tree hiện không sửa file
migration nào. Ghi SHA-256 lúc bắt đầu:

```bash
shasum -a 256 database/migrations/2026_09_08_000100_create_ai_foundation_knowledge_tables.php database/migrations/2026_09_13_000100_add_ai_embedding_generation.php database/migrations/2026_09_14_000100_create_ai_vision_interpretations.php database/migrations/2026_09_15_000100_create_ai_authoring_proposal_packet.php docs/database/LF-SCHEMA-CONTRACT.json
```

---

# TRONG phạm vi

* Bốn file migration, `up()` và `down()`.
* Database docs tương ứng trong `docs/database/ai/` và
  [`core_course_template_learning_mapping_intents`](../database/course/core_course_template_learning_mapping_intents.md).
* `docs/database/LF-SCHEMA-CONTRACT.json` và `schema:drift`.
* Test vật lý: `AiFoundationKnowledgePacketMariaDbTest`,
  `AiAuthoringProposalPacketMariaDbTest`, `AiVisionInterpretationsSchemaTest`,
  `CourseTemplateLearningMappingPromotionMariaDbTest`, phần migration trong
  `AiEmbeddingServiceTest`; probe `tests/Support/Ai/authoring-packet-concurrency.php`.
* Kế hoạch apply lên database **đã có dữ liệu**: thứ tự, thời gian khoá, lỗi giữa
  chừng, khôi phục.

# NGOÀI phạm vi

* Service/runtime AI (có brief riêng, ví dụ
  [xương sống Knowledge](LF-AI-Knowledge-Backbone-Reviewer-Brief.md)).
* Provider activation, UI, deployment production.
* Migration không thuộc AI, trừ khi Owner báo chúng cũng đang chờ apply cùng lượt.

---

# Câu hỏi reviewer phải trả lời

## M1 — Foundation

1. Tái kiểm **từng** P1/P2 của code review FAIL (hồ sơ 0a § Step 1 Remediation):
   FK tenant-aware cho `media_file_id`; CHECK cặp usage/content (`chk_aks_usage_pair`)
   khớp Media Read Contract § 3 gồm `formula → document`; `generation` trong unique
   key và `CHECK (generation >= 1)`; ba P2. Bản vá có đúng và có test chặn không?
2. NULL semantics của identity: generated sentinel có chặn trùng thật khi cột
   NULL, trên **cả hai** engine?
3. FK hoãn của `ai_model_runs` được thêm đúng thứ tự, không vòng?
4. Độ rộng cột so với giá trị runtime thật (ví dụ `processing_version VARCHAR(100)`
   sau quy tắc nén; UUID `CHAR(36)`).
5. `down()` preflight toàn packet **trước** mọi DDL; từ chối khi có dữ liệu.

## M2 — `generation`

1. Unique key cũ được thay đúng, không có cửa sổ mất unique?
2. `down()` từ chối khi `generation > 1` trước mọi DDL.

## M3 — Vision

1. Provenance (Media revision, page/bbox), CHECK và FK tenant khớp
   `ai_vision_interpretations.md`.
2. `down()` từ chối khi có row.

## M4 — Authoring packet

1. Sáu bảng, FK composite sang Learning (`core_learning_framework_versions`,
   `core_learning_nodes`) và Course khớp docs; không có FK tới bảng Media mà AI
   không được phụ thuộc.
2. Trigger lịch sử bất biến và erase: đúng hành vi, không chặn nhầm luồng hợp lệ.
   **Trigger cần quyền gì trên server đích** (`TRIGGER`; với binary log bật có thể
   cần `SUPER` hoặc `log_bin_trust_function_creators`)? Tài khoản ứng dụng có đủ không?
3. **ALTER `core_course_template_learning_mapping_intents` trên bảng đang có dữ
   liệu:** cột mới, `DROP CONSTRAINT chk_cct_lmi_origin` rồi thêm lại CHECK. Mọi row
   hiện có (origin `manual`) có thoả CHECK mới? Có khoảng thời gian bảng không có
   CHECK origin không, và có quan trọng không?
4. `up()` bỏ qua trên SQLite (`supported()`): có che lỗi test nào không?

## X — Xuyên suốt

1. **Engine đích 10.4.21 dưới floor 10.5:** bốn migration có chạy đúng trên
   10.4.21 không (CHECK, generated column, `DROP CONSTRAINT`, JSON, trigger)? Chênh
   lệch nào so với 11.4? Có nên apply trên engine dưới floor hay phải nâng engine
   trước — nêu khuyến nghị, quyết định là của Owner.
2. **Lỗi giữa chừng:** DDL MariaDB tự commit từng câu. Nếu `up()` hỏng ở giữa trên
   database thật thì trạng thái còn lại là gì, `migrate` có chạy lại được không, và
   cần khôi phục thủ công thế nào? Có migration nào cần preflight trước DDL mà chưa có?
3. **Khoá và thời gian:** ALTER trên bảng Course có dữ liệu và FK trỏ tới bảng lớn
   (`media_files`, bảng Learning) — ước lượng khoá/thời gian ở kích thước thật.
4. `schema:drift` trên schema dựng từ migration khớp `LF-SCHEMA-CONTRACT.json` trên
   cả hai engine; CHECK_CLAUSE được harvest, không đoán.
5. Rollback: trên database rỗng `down()` sạch và `up()` lại được; khi có dữ liệu thì
   từ chối **trước** DDL.

---

# Rehearsal trên bản sao dữ liệu thật

Điều kiện để ký `APPLY-READY` cho toàn bộ lần apply. **Owner tự làm và giao**:

1. Output `php artisan migrate:status` trên `learnforge_db` (để biết chính xác các
   migration đang chờ, kể cả migration không thuộc AI).
2. Một bản dump `learnforge_db` (schema + dữ liệu, hoặc schema + dữ liệu đã ẩn danh
   nếu có PII) đặt ngoài repo.

Reviewer restore dump vào instance **10.4.21 riêng** (và 11.4 nếu Owner dự định
nâng engine), chạy `php artisan migrate` đúng như sẽ chạy thật, đo thời gian, rồi
kiểm: số row các bảng có sẵn không đổi, `schema:drift --connection=mysql` sạch,
test vật lý chạy trên schema đó, và `down()` từ chối khi có dữ liệu. Xoá dump và
instance sau khi xong.

Không có dump thì ghi **chưa kiểm** và không ký `APPLY-READY` cho toàn bộ lần apply;
vẫn ký được từng migration trên schema dựng mới.

---

# Kiểm chứng

## Hai instance riêng

```bash
DD=$(mktemp -d /tmp/lfmig.XXXXXX)
/usr/local/opt/mariadb@11.4/bin/mariadb-install-db --datadir="$DD/d114" --auth-root-authentication-method=normal
/usr/local/opt/mariadb@11.4/bin/mariadbd --datadir="$DD/d114" --socket="$DD/114.sock" --pid-file="$DD/114.pid" --skip-networking &
```

Instance 10.4.21 dùng binary XAMPP với datadir/socket/pid-file riêng trong `$DD`
và `--skip-networking`; cách khởi tạo datadir của 10.4 (`mysql_install_db`) khác
11.4, xem hồ sơ Vision. **Không dùng socket hay datadir của XAMPP.** Trước mọi
lệnh, trên từng instance:

```sql
SELECT VERSION(), @@datadir, @@socket, @@explicit_defaults_for_timestamp;
```

Socket phải ngắn (đường dẫn trên ~103 ký tự sẽ lỗi).

## Test và drift

Chạy với `APP_ENV=testing DB_CONNECTION=mysql DB_URL= DB_SOCKET=<socket> DB_DATABASE=<db tạm> DB_USERNAME=root DB_PASSWORD=` trên **từng** engine:

```bash
php artisan test tests/Integration/AiFoundationKnowledgePacketMariaDbTest.php tests/Integration/AiAuthoringProposalPacketMariaDbTest.php tests/Feature/AiVisionInterpretationsSchemaTest.php tests/Integration/CourseTemplateLearningMappingPromotionMariaDbTest.php tests/Feature/AiEmbeddingServiceTest.php
```

```bash
php artisan schema:drift --connection=mysql
```

Probe constraint thật thay vì tin migration:

```sql
SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE TABLE_NAME LIKE 'ai\_%' OR TABLE_NAME = 'core_course_template_learning_mapping_intents';
```

```sql
SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_NAME LIKE 'trg\_%';
```

Rollback: `php artisan migrate:rollback --step=4` chỉ đúng bốn migration AI khi
chúng là batch cuối (ví dụ dựng 96 migration ngoài AI trước, rồi `migrate` bốn AI);
trên schema dựng cùng một batch, bốn bước cuối gồm cả migration quota (L1). Không
dùng `--step=4` làm runbook hồi phục database thật. Trên database rỗng rồi `migrate`
lại. Sau đó kiểm từ chối rollback **theo điều kiện riêng của từng migration**, gọi
`down()` từng file: Foundation, Vision, Authoring từ chối khi packet có row bất kỳ;
`generation` chỉ từ chối khi có row `generation > 1` — một row `generation = 1` phải
rollback/re-apply được và giữ nguyên các cột còn lại. Xác nhận từ chối xảy ra trước
DDL bằng so schema trước/sau, không chỉ dựa vào exception.

Một lượt skip không phải PASS: `AiVisionInterpretationsSchemaTest` và các test
MariaDB tự bỏ qua trên SQLite.

---

# Bằng chứng hiện có (phải tái lập)

| Nguồn | Số liệu ghi nhận |
| --- | --- |
| Hồ sơ 0a | `integration-mysql` 156 passed/583 assertions trên 11.4.12 sau remediation Round 1 code review (trước khi có ba migration sau) |
| Hồ sơ Bước 7 | Packet 20 test/128 assertions trên 10.4.21; 11.4.12 phạm vi ảnh hưởng 109/418; drift 100 migration, 0 non-INFO |
| Hồ sơ Vision | Schema test trên 11.4.12 và 10.4.21 |
| Session Knowledge Sync và review độc lập xương sống, 2026-09-26 | Toàn danh sách `integration-mysql` (33 file) 545 passed, 1 skipped, 0 failed trên 11.4.12 schema dựng mới, do cả implementer lẫn reviewer xương sống tự chạy — **chỉ 11.4**, không có 10.4, không có dữ liệu thật; bốn migration không đổi trong suốt các lượt đó |

Chưa từng có: chạy bốn migration **liên tiếp** trên 10.4.21; apply trên bản sao
dữ liệu thật; đo thời gian khoá; kiểm quyền trigger của tài khoản ứng dụng.

---

# Định dạng báo cáo

* Đặt tại `docs/quality/LF-AI-Migrations-Pre-Apply-Review.md` hoặc working directory.
* Header chuẩn; reviewer, snapshot (SHA-256 bốn migration + schema contract), ngày,
  phiên bản chính xác của từng engine đã dùng.
* Verdict **từng migration** và **toàn bộ lần apply**, kèm điều kiện.
* Findings `BLOCKER | HIGH | MEDIUM | LOW`: file:dòng, tình huống cụ thể, bằng chứng
  tái lập, đề xuất (không vá).
* Trả lời M1–M4 và X; tách rõ kết quả trên 10.4.21 và 11.4.
* Bảng lệnh đã chạy và kết quả của chính reviewer; ghi rõ mục chưa kiểm (đặc biệt
  rehearsal nếu không có dump).
* Kế hoạch apply đề xuất cho Owner: thứ tự, backup trước apply, cách phát hiện và
  khôi phục khi lỗi giữa chừng.
