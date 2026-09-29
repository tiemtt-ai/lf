# AI Knowledge Source Role Alignment (K3) — Reviewer Brief

Version: 1.3

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-09-28

Document Path: quality/LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md

---

# Vì sao có brief này

K3 (HIGH) được phát hiện khi scheduler dev local đồng bộ Media → Knowledge
2026-09-28 ([hồ sơ phát hiện](../../review-artifacts/ai-2026-09-28/Knowledge-Frame-Sync-Fix.md)):
Media đã duyệt 15 role region, nhưng `chk_akc_source_role` của Knowledge chỉ nhận 9.
Knowledge copy nguyên role (snapshot, không map), nên mọi revision có vùng `image`,
`chart`, `diagram`, `geometry`, `formula` hoặc `note` bị rollback cả source.

Sửa K3 cần đổi CHECK của bảng Foundation `ai_knowledge_chunks`. Theo AGENTS.md
§ Database Rule, **không tạo migration trước khi** Database Docs được duyệt và
Architecture Review pass. Brief này giao Architecture Review cho amendment
**trước khi có migration**. Owner đã duyệt hướng xử lý và việc soạn amendment
ngày 2026-09-28; amendment chỉ có hiệu lực sau review này và Owner duyệt.

Review trả lời: amendment có đúng và đủ để tạo forward migration không.

Verdict: `APPROVE`, `APPROVE WITH CHANGES` hoặc `REJECT`, cho từng mục § Câu hỏi và
cho toàn amendment.

---

# Tài liệu cần review

| Tài liệu | Nội dung |
| --- | --- |
| [ai_knowledge_chunks.md](../database/ai/ai_knowledge_chunks.md) § Media role vocabulary alignment (K3) — Proposed | Amendment chính: vocabulary 15 giá trị, runtime không đổi, kế hoạch forward migration, test chống tái diễn |
| [LF-Documentation-Conflicts.md](LF-Documentation-Conflicts.md) § DOC-CONFLICT-0040 | Conflict đã xác minh, UNDER_REVIEW |
| [media_extracted_regions.md](../database/media/media_extracted_regions.md) § Multilingual and STEM amendment | Vocabulary Media đã duyệt 2026-09-03 |
| `database/migrations/2026_09_03_000100_add_document_language_profiles_and_formula_evidence.php` | `chk_mer_role` vật lý 15 giá trị |
| `database/migrations/2026_09_08_000100_create_ai_foundation_knowledge_tables.php` (khoảng dòng 303) | `chk_akc_source_role` vật lý 9 giá trị |
| [Foundation review](LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md) R2-21 | Nguồn gốc CHECK "khớp `media_extracted_regions`" |
| `app/Services/AiKnowledgeIngestionService.php` (`source_role`, khoảng dòng 426; so sánh snapshot khoảng 540–564) | Runtime copy role và so sánh khi retry |

Phạm vi phụ, cùng bảng, chưa từng review. Hai thay đổi runtime ghi vào tài liệu
canonical ngày 2026-09-28 bởi một tác nhân không phải Owner:

* `ai_knowledge_chunks.md` v1.3 § Deterministic chunker runtime: `part_index` của
  `video_frame_text` tăng liên tiếp qua các unit cùng timespan.
* [LF-AI-Knowledge-Sync-Contract.md](../platform/LF-AI-Knowledge-Sync-Contract.md)
  § Lỗi và retry: `QueryException` của một revision được đếm và log
  `database_write_failed`. Owner đã duyệt mã log này 2026-09-28.

Implementer Knowledge Sync đã review hai thay đổi này (không độc lập): 2 mutation bị bắt, R1 đã sửa, R2 mở. Xem [Backbone record](LF-AI-Knowledge-Backbone-Implementation-Record.md) § Review của implementer. Reviewer đánh giá chúng ở mức thiết kế (identity, uniqueness, retry), không dựa vào kết luận của implementer.
Kiểm chứng implementation của chúng thuộc Part 2 closure round 3.

---

# Câu hỏi

1. **Nguyên nhân.** Tự đối chiếu `chk_mer_role` vật lý, tài liệu Media và
   `chk_akc_source_role`. R2-21 có đúng là dùng vocabulary trước amendment
   2026-09-03 không? Còn vocabulary snapshot nào khác của Knowledge lệch Media
   không (`source_quality_status`, `source_text_quality`, `locator_type`,
   `language_evidence`)?
2. **Phương án.** Hợp nhất 15 giá trị có đúng hơn các phương án khác không:
   map `image → figure`, bỏ CHECK và dựa vào CHECK Media, hay dùng bảng vocabulary
   dùng chung? Amendment bác map vì nó làm snapshot khác Media và hỏng so sánh
   retry/identity. Reviewer tự kiểm lập luận này.
3. **Snapshot và identity.** Mở rộng CHECK có đổi `chunk_uuid`, `content_hash`,
   so sánh retry hay chunker version của revision nào đã có chunk không? Có revision
   nào cần backfill không?
4. **Consumer.** Xác nhận không có consumer nào đọc `source_role` (retrieval,
   embedding, Vision, Authoring, ranking modifier đã hoãn). Nếu có, nêu tác động.
5. **ADR.** ADR-0006 không liệt kê `source_role`; vocabulary Media thuộc
   ADR-0019. Amendment có cần sửa ADR nào không, hay Database Docs là đủ?
6. **Kế hoạch migration.**
   * Preflight đọc `information_schema.CHECK_CONSTRAINTS` và từ chối khi biểu thức
     lạ.
   * DROP và ADD CHECK trong một ALTER.
   * `down()` từ chối khi còn row mang role mới.
   * SQLite bỏ qua.
   * Cập nhật § Indexes và `LF-SCHEMA-CONTRACT.json` cùng migration.

   Cần thêm gì cho bảng lớn ở môi trường thật (thuật toán ALTER, khoá bảng, thời
   gian)? Có phải ghi preflight quyền trigger như M4 không (migration này không tạo
   trigger)?
7. **Chống tái diễn.** Test "tập Knowledge ⊇ tập `chk_mer_role` trong
   `LF-SCHEMA-CONTRACT.json`" có đủ không, hay nên kiểm cả schema vật lý trên
   MariaDB?
8. **Kiểm chứng yêu cầu.** Danh sách trong amendment đã đủ để coi migration an toàn
   chưa? Thêm tiêu chí nếu thiếu.
9. **Phạm vi phụ.**
   * `part_index` liên tiếp theo timespan có giữ `uk_akc_locator_part`, tính
     deterministic của `chunk_uuid` và thứ tự Media Read không? Có trường hợp thứ
     tự unit đổi giữa hai lần đọc cùng revision làm UUID đổi không?
   * Nuốt `QueryException` theo revision có che lỗi hệ thống (mất kết nối, deadlock
     lặp lại) mà lẽ ra phải dừng lượt tenant không?

---

# Bằng chứng hiện có — để đối chiếu, không thay kiểm chứng

* Dev local `learnforge_db` 2026-09-28, implementer đọc (chỉ đọc):
  * `media_extracted_regions`: `image` có 362 row (162 có text), `formula` 1.
  * `ai_knowledge_chunks`: 0 chunk có `source_role`.
  * `chk_akc_source_role` vật lý 9 giá trị.
* Hồ sơ phát hiện ghi `PrivateRoleProbeTest` trên MariaDB 11.4 dựng mới: `image` bị
  từ chối bởi `chk_akc_source_role`, và Source/Chunk đều bằng 0 sau rollback.

---

# Ràng buộc độc lập

* **Không đủ tư cách:**
  * session implementer Knowledge Sync/Bước 1–7 (tác giả amendment này);
  * thread đã viết bản vá frame/`QueryException` và hồ sơ
    `review-artifacts/ai-2026-09-28/`;
  * thread đã review closure Phần 2 lượt 1–3, vì thread đó đã sửa code Knowledge.
* Reviewer **không vá**; finding giao implementer.
* Reviewer K3 vẫn đủ tư cách review Part 2 closure round 3 sau đó, nếu không sửa
  code hay tài liệu canonical.

# Ràng buộc an toàn

* Chỉ đọc. Không sửa code, migration, test hay tài liệu canonical. Báo cáo đặt tại
  `docs/quality/LF-AI-Knowledge-Source-Role-Alignment-Review.md`.
* **Không kết nối `learnforge_db`** (`127.0.0.1:3307`) và XAMPP `:3306`.
* Probe DDL, nếu cần, chạy trên MariaDB 11.4 dùng một lần: `/tmp`,
  `--no-defaults --skip-networking`, database tên bắt đầu `lf_`, tắt và xoá khi
  xong. Migration thử chỉ đặt trong bản sao riêng, không symlink `vendor`.
* Không gọi provider thật, không thêm secret, không gửi dữ liệu ra mạng.

# Snapshot

Ghi SHA-256 lúc bắt đầu và cuối lượt của:

* `docs/database/ai/ai_knowledge_chunks.md`
* `docs/quality/LF-Documentation-Conflicts.md`
* `docs/platform/LF-AI-Knowledge-Sync-Contract.md`
* `app/Services/AiKnowledgeIngestionService.php`
* `app/Services/AiKnowledgeSyncService.php`

# Định dạng báo cáo

* Header chuẩn, reviewer, snapshot, ngày.
* Verdict từng câu hỏi 1–9, kèm bằng chứng của chính reviewer.
* Findings `BLOCKER | HIGH | MEDIUM | LOW`: vị trí, tình huống, bằng chứng, đề xuất
  (không vá).
* Điều kiện để tạo migration, nếu `APPROVE WITH CHANGES`.
* Bảng lệnh đã chạy và mục chưa kiểm.

---

# Lượt 2 — sau review lượt 1 (2026-09-28)

[Review lượt 1](LF-AI-Knowledge-Source-Role-Alignment-Review.md): **APPROVE WITH
CHANGES**, gate tạo migration chưa mở. Implementer đã xử lý:

| Finding | Xử lý | Nơi kiểm |
| --- | --- | --- |
| K3-R1 HIGH — thiếu ADR | [ADR-0006 Amendment v1.0.6](../adr/ADR-0006-AI-Foundation.md) **Proposed**, chờ Owner duyệt: chỉ đổi tập giá trị `source_role` theo ADR-0019 v1.7; không đổi ownership, identity, dữ liệu, số bảng | ADR-0006 § Amendment Record 1.0.6 |
| K3-R2 HIGH — bắt mọi `QueryException` | Phân loại SQLSTATE: lớp `22`/`23` và mã 1366 đi tiếp; lỗi khác dừng tenant bằng exception chỉ mang SQLSTATE/mã driver; `register()` chỉ bắt unique violation | `AiKnowledgeSyncService::isRevisionLocal()`, `systemicFailure()`; `AiKnowledgeIngestionService::register()`; contract § Lỗi và retry; Backbone record § Phân loại lỗi database |
| K3-R3 MEDIUM — kế hoạch DDL | Preflight (scope, đúng một CHECK, so biểu thức chuẩn hoá, `check_constraint_checks`, quyền), không mặc định algorithm/lock, `down()` mọi tenant/status + quiesce, phục hồi khi thất bại, bảng lớn | `ai_knowledge_chunks.md` § K3 |
| K3-R4 MEDIUM — kiểm chứng | Test contract bằng đúng 15 + superset về sau; test vật lý fresh + upgrade; bảng acceptance criteria | như trên |
| K3-R5 LOW — câu chữ | Fidelity snapshot thay cho "hỏng identity"; `paragraph` 9 ký tự | như trên |
| K3-R6 LOW — log một lần | Ghi rõ best-effort (không atomic, mất cache thì log lại) | contract; comment code |

Phạm vi lượt 2: kiểm lại sáu finding. Riêng K3-R2, chạy lại probe systemic của lượt
1. Không cần review lại câu 1, 3, 4, trừ khi thay đổi chạm tới.

Kiểm chứng của implementer, không thay kiểm chứng của reviewer:

* `php artisan test`: 1326 passed, 23 skipped (trước khi thêm dataset 1366 `22007`).
* 5 mutation K3-R2 đều bị bắt.
* Probe MariaDB 11.4.12 thật: CHECK `23000/4025`, trùng khoá `23000/1062`, quá dài
  `22001/1406`, chuỗi không hợp lệ `22007/1366`.

Gate tạo migration vẫn đóng cho tới khi Owner duyệt ADR v1.0.6 và Database Docs,
sau khi lượt 2 pass.

---

# Lượt 3 — migration review (2026-09-28)

Owner đã duyệt ADR-0006 v1.0.6 và Database Docs K3 ngày 2026-09-28, sau khi lượt 2
cho gate Architecture Review PASS. Implementer đã viết migration theo đúng kế hoạch
đã duyệt. **Chưa apply lên database nào ngoài instance MariaDB dùng một lần.**

## Thay đổi cần review

| File | Nội dung |
| --- | --- |
| `database/migrations/2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php` | Preflight (đúng một CHECK theo schema/table/tên, `check_constraint_checks` bật trên MariaDB, biểu thức chuẩn hoá có kiểm soát khớp đúng nhánh NULL + IN-list, tập role đúng 9 khi `up()` và 15 khi `down()`, không trùng); đổi CHECK bằng một `ALTER` DROP + ADD; `down()` từ chối khi còn row mang role mới ở mọi tenant và mọi status. Thông báo từ chối là message thường, không tạo mã lỗi mới. SQLite bỏ qua |
| `docs/database/ai/ai_knowledge_chunks.md` § Indexes, `docs/database/LF-SCHEMA-CONTRACT.json` | CHECK 15 giá trị, cùng thứ tự với migration |
| `tests/Unit/KnowledgeSourceRoleVocabularyTest.php` | Contract: tập Knowledge đúng 15 giá trị, và luôn chứa mọi role của Media |
| `tests/Integration/AiKnowledgeSourceRoleMigrationMariaDbTest.php` | 21 test vật lý theo bảng acceptance criteria; đã thêm vào danh sách `integration-mysql` của CI |

## Đối chiếu acceptance criteria — kiểm chứng của implementer

| Yêu cầu | Test |
| --- | --- |
| Miền giá trị | `test_every_approved_role_and_null_is_written_and_anything_else_refused`: 15 role và NULL INSERT/UPDATE được; `bogus`/`figures` bị chặn `23000/4025`; chữ hoa của role mới hành xử như chữ hoa của role cũ (collation không đổi ngữ nghĩa) |
| Snapshot và retry | `test_a_new_role_revision_is_snapshotted_verbatim_and_a_retry_changes_nothing` |
| Atomic theo revision | `test_an_unknown_role_rolls_back_the_whole_new_revision_and_keeps_the_old_one` |
| Preflight | `test_up_refuses_before_any_ddl_when_the_check_is_already_wide` (DDL xong mà ledger chưa ghi); `test_up_refuses_an_unexpected_constraint_state` 6 dataset; `test_up_refuses_while_check_enforcement_is_off` |
| Up/down | `test_down_refuses_while_any_tenant_holds_a_new_role_in_any_status` 6 status; `test_up_down_up_keeps_every_row_and_the_narrow_check_is_enforced_in_between` |
| Thất bại | `test_a_row_written_after_the_count_fails_the_narrowing_and_the_wide_check_stays` (writer chen trước ALTER qua connection thứ hai: ALTER thất bại `4025`, bảng giữ CHECK 15); `test_a_lock_timeout_leaves_the_check_untouched` (MDL giữ ở connection khác: `1205`, CHECK không đổi) |
| Governance | `test_the_migrated_schema_carries_exactly_the_contract_check`; migration 2026-09-08 không đổi |
| Triển khai | Chưa làm: backup, rehearsal bảng lớn, apply có cho phép — ngoài phạm vi lượt này |

Kết quả trên MariaDB 11.4.12 dùng một lần (`--no-defaults --skip-networking`, đã tắt
và xoá):

* Integration K3: 21 passed (158 assertions).
* `schema:drift --connection=mysql` trên chính database đó **sau** khi test chạy xong:
  PASS. Lần đầu drift báo lệch vì bước khôi phục trong test dựng lại CHECK sai thứ tự;
  lỗi ở test, đã sửa, và test nay tự kiểm khôi phục đúng nguyên văn contract.
* `php artisan test`: 1329 passed, 23 skipped. `docs:lint`, docs-only drift PASS.

Mutation trên bản sao riêng (0 symlink), khôi phục khớp SHA `742b5dc3…`:

| Mutation | Bị bắt |
| --- | --- |
| Bỏ preflight `up()` | 8 test preflight |
| Bỏ bước đếm row `down()` | 6 dataset `down refuses…` (thêm 6 test đỏ dây chuyền vì ALTER tự commit transaction test) |
| Regex lỏng (không neo) | `extra OR branch`, `NULL branch missing`, `NOT NULL instead of NULL` |
| Tách DROP và ADD thành hai lệnh | test writer chen trước ALTER (bảng mất CHECK) |
| Bỏ kiểm `check_constraint_checks` | test enforcement tắt |
| Chấp nhận role trùng | dataset `role duplicated` |

Ghi chú hiệu năng: `migrate:fresh` 101 migration trên instance này mất khoảng 5 phút
(test đầu mỗi lượt ~300 giây); các test K3 còn lại dưới 2 giây.

## Việc của reviewer

1. Tự chạy `AiKnowledgeSourceRoleMigrationMariaDbTest` và `KnowledgeSourceRoleVocabularyTest`
   trên MariaDB 11.4 dùng một lần; đối chiếu tên test qua JUnit.
2. Đọc migration theo từng mục acceptance criteria; thử thêm trạng thái lạ nếu thấy
   preflight còn lỗ (chuẩn hoá biểu thức là điểm dễ sai nhất).
3. Chạy lại ít nhất các mutation nguy hiểm nhất (tách hai lệnh, regex lỏng, bỏ đếm row).
4. `schema:drift --connection=mysql` trên schema dựng mới.
5. Verdict: migration có đủ điều kiện apply lên `learnforge_db` dev local (sau backup,
   khi Owner cho phép) hay chưa. Ghi thêm mục "Lượt 3" vào báo cáo K3 hiện có.

---

# Lượt 4 — sau migration review lượt 3 (2026-09-28)

[Lượt 3](LF-AI-Knowledge-Source-Role-Alignment-Review.md): **REJECT, chưa đủ điều kiện
apply**. K3-R8 HIGH: normalizer `str_replace('`', '', …)` xoá cả backtick trong string
literal, nên `'para`graph'` được đọc thành `'paragraph'` và `up()` đổi một CHECK lạ.
Architecture Review gate và Owner approval không đổi.

**Vá.** `nullableInList()` không còn chuẩn hoá bằng regex. Biểu thức được tách token
trước:

* Identifier trong backtick (hỗ trợ ``` `` ``` escape) chỉ được gỡ quoting của chính nó.
* String literal giữ nguyên nội dung; literal có `''` hoặc `\` escape bị đánh dấu và
  không form nào nhận.
* Introducer chỉ nhận `_utf8mb4`, và chỉ trong dạng MySQL; ký tự khác thành token lạ.

Chỉ hai chuỗi token được chấp nhận: dạng MariaDB đã lưu, và dạng MySQL (mỗi vế trong
ngoặc). Literal phải là `[a-z_]` chữ thường. Không có gì bị "sửa" cho khớp.

Implementer còn thấy normalizer cũ hạ chữ thường toàn bộ biểu thức, nên `'PARAGRAPH'`
cũng thành `'paragraph'`: cùng lớp lỗi với K3-R8, nay cũng bị từ chối.

**Regression.**

* `tests/Unit/KnowledgeSourceRoleCheckParserTest.php` (không cần database), 24 ca:
  4 dạng hợp lệ (MariaDB lưu, spacing/hoa-thường keyword, identifier không quote,
  MySQL có `_utf8mb4`); 20 dạng bị từ chối, gồm backtick trong literal, `''`, `\`,
  literal chữ hoa, literal có khoảng trắng, introducer trong dạng MariaDB, introducer
  lạ trong dạng MySQL, escape trong identifier, cột khác, `OR 1 = 1`, `AND 1 = 0`,
  thiếu nhánh NULL, `IS NOT NULL`, `NOT IN`, trùng role, danh sách rỗng, dấu phẩy
  thừa, quote chưa đóng, dạng MySQL thiếu ngoặc.
* `AiKnowledgeSourceRoleMigrationMariaDbTest`:
  * `test_up_refuses_an_unexpected_constraint_state` thêm `backtick inside a literal`
    và `upper-case literal`;
  * test mới `test_down_refuses_an_unexpected_constraint_state` (backtick trong
    literal, `OR 1=1`) cho chiều `down()`.

**Kiểm chứng của implementer** (MariaDB 11.4.12 dùng một lần, đã tắt và xoá):

* 51 passed (260 assertions): 25 integration, 2 contract, 24 parser. JUnit xác nhận
  các ca K3-R8 đã chạy trên MariaDB.
* `schema:drift --connection=mysql` PASS sau khi test chạy xong.

Mutation parser trên bản sao riêng, khôi phục khớp SHA:

| Mutation | Bị bắt |
| --- | --- |
| Đưa lại normalizer cũ | 4 ca: backtick trong literal, literal chữ hoa, introducer trong dạng MariaDB, introducer lạ trong dạng MySQL |
| Nhận introducer bất kỳ, ở mọi dạng | 2 ca introducer |
| Nhận literal có escape | ca `\` (ca `''` vẫn bị chặn vì `'` không thuộc `[a-z_]`) |

**Việc của reviewer lượt 4:** chạy lại probe K3-R8 của lượt 3 và thử thêm biến thể
literal hoặc quoting; chạy suite K3, các mutation nguy hiểm và drift; kết luận điều
kiện apply. Ghi thêm mục "Lượt 4" vào báo cáo.
