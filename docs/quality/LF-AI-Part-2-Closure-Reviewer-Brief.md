# AI Part 2 (Knowledge) — Final Closure Reviewer Brief

Version: 1.6

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-29

Document Path: quality/LF-AI-Part-2-Closure-Reviewer-Brief.md

---

# Vì sao có brief này

Lộ trình Owner "Phần 2: AI Knowledge" kết thúc bằng **Final independent closure
review**: một lượt review độc lập xem **toàn bộ** Phần 2 (các bước 0–7) có đạt điều
kiện đóng hay chưa. Các review trước chỉ xem từng mảnh. Nhiều bước (4–7) đi qua
bằng **miễn trừ của Owner**, nên đây là lần đầu có người độc lập nhìn phần
implementation của chúng cùng các tương tác giữa các bước.

Mục tiêu Phần 2 (Owner): dữ liệu Media trở thành tri thức có provenance, sẵn sàng
cho đề xuất có người duyệt và cho AI thật sau này; **không** cần AI thật chạy.

Verdict yêu cầu: `PASS`, `PASS WITH DOCUMENTED RISKS`, `CHANGES REQUIRED` hoặc
`BLOCKED`, cho **từng điều kiện đóng** (§ Điều kiện) và cho **toàn Phần 2**.

---

# Tình trạng từng bước — nguồn để đối chiếu, không phải bằng chứng thay reviewer

| Bước | Nội dung | Trạng thái review hiện có | Hồ sơ |
| --- | --- | --- | --- |
| 0a | AI Foundation (4 bảng Media-consumer) | Round 3 independent **PASS** (thiết kế); review độc lập bốn migration AI: từng file APPLY-READY WITH DOCUMENTED RISKS | [0a](LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md), [migrations](LF-AI-Migrations-Pre-Apply-Review.md) |
| 0b | Learning Gate 2 | Independent **PASS WITH DOCUMENTED RISK** | [Gate 2](LF-Learning-Gate-2-Independent-Review.md) |
| 1 | Đồng bộ tài liệu | Một phần được review xương sống xem (F3 cũ, F4/F5) | [backbone record](LF-AI-Knowledge-Backbone-Implementation-Record.md) |
| 2 | Migration Foundation | Xem 0a + review migration | như trên |
| 3 | Ingestion, đồng bộ Media → Knowledge, delete barrier | Independent **PASS WITH DOCUMENTED RISKS** (3 lượt); Owner chốt đóng Source/Chunk 2026-09-26 | [backbone review](LF-AI-Knowledge-Backbone-Independent-Review.md) |
| 4 | Provider execution gate, model run, quota Commercial | **Owner waiver** 2026-09-12; không có independent PASS | [provider gate](LF-AI-Provider-Execution-Gate-Implementation-Review.md) |
| 5 | Embedding, Qdrant, retrieval | Architecture review độc lập từng FAIL (lịch sử); **Owner waiver** 2026-09-14; AR-P3-2/3/7 là điều kiện trước activation | [architecture](LF-AI-Embedding-Qdrant-Architecture-Review.md), [implementation](LF-AI-Embedding-Qdrant-Implementation-Review.md) |
| 6 | Vision Interpretation | **Owner waiver** + Owner nghiệm thu backend 2026-09-14; không có independent PASS cho service | [Vision](LF-AI-Vision-Interpretation-Implementation-Review.md) |
| 7 | AI Authoring Proposal (backend + HTTP) | **Owner nghiệm thu dưới miễn trừ** 2026-09-17; self-review không độc lập; UI chưa có | [Step 7](LF-AI-Authoring-Proposal-Implementation-Review.md) |

Các waiver trên là quyết định hợp lệ của Owner, **không phải PASS**. Reviewer không
được coi chúng là bằng chứng kỹ thuật.

---

# Điều kiện đóng Phần 2 (từ lộ trình Owner)

Reviewer đánh giá từng điều bằng kiểm chứng của chính mình:

1. 0a và 0b đều PASS.
2. Docs, schema, migration và implementation không drift.
3. Ingestion/rebuild/delete idempotent.
4. Không trộn revision hoặc tenant.
5. Delete barrier được chứng minh bằng test.
6. Provider và quota fail-closed.
7. Retrieval luôn post-validate authorization.
8. Vision interpretation giữ provenance độc lập (không ghi ngược Media evidence).
9. AI output chỉ là proposal trước khi giáo viên duyệt (không ghi canonical Mapping trực tiếp; accept ≠ publish).
10. Mutation tests bảo vệ tenant, provenance, revision, stale lifecycle, delete barrier, quota và promotion boundary.

---

# Trọng tâm: những gì chưa từng được review độc lập

## Bước 4 — governance và quota

* Gate có fail-closed thật khi thiếu allow-list, tenant approval, entitlement, quota,
  safety/data-class? Quota check có chạy **trước** provider call? Reservation
  atomic, không double-spend dưới hai connection?
* `ai_model_runs` ghi đủ success/failure/quota-blocked/safety-blocked; không có
  credential, source text hay dữ liệu nhạy cảm trong run, audit, log, exception.
* Credential chỉ resolve trong adapter sau khi mọi gate pass.

## Bước 5 — embedding, Qdrant, retrieval

* Lifecycle `pending → ready | failed → stale → deletion_pending → deleted`, không
  có `processing`; retry/crash recovery; relational là nguồn đúng, Qdrant chỉ là index.
* Retrieval post-validate: embedding ready, source/chunk active, đúng revision, đúng
  tenant, quyền Media còn hiệu lực. Modifier xếp hạng **đã được Owner hoãn** tới
  consumer đầu tiên — chỉ kiểm việc hoãn được ghi đúng và retrieval chưa có consumer.
* AR-P3-2/3/7: xác nhận vẫn được ghi là điều kiện trước activation, và đánh giá có
  chặn đóng Phần 2 không (Phần 2 không bao gồm activation).

## Bước 6 — Vision

* Model gate, quota pre-check, `run_uuid`, provenance Media revision + page/bbox;
  không ghi interpretation vào bảng Media evidence.
* Xoá Media → purge Vision (listener after-commit + đối soát); retrieval chặn nội
  dung mất nguồn trong lúc chờ dọn.

## Bước 7 — Authoring proposal (backend + HTTP)

* AI không ghi canonical Mapping; mọi thay đổi Course/Learning qua owner ports; Mapping
  canonical chỉ materialize khi Course Version và Framework Version đều published.
* Giáo viên sửa được trước khi duyệt; accept không publish; quyền teacher/admin trên
  41 route; tenant, idempotency, freshness (stale/context/prompt), successor, rebase,
  erasure khi Media bị xoá.
* Không dữ liệu payload trong log (P1-R1 đã vá), cursor mã hoá gắn actor/tenant.

## Xuyên suốt

* **Xoá Media** kéo đủ ba nhánh AI (Knowledge/embedding, Vision, Authoring), sau
  commit, có đối soát khi mất event, không vượt barrier.
* **Tenant**: mọi đường đọc/ghi AI lọc `customer_id`; không đường nào nhận tenant
  từ input.
* **Mutation** (điều kiện 10): các mutation hiện có nằm rải ở từng hồ sơ; reviewer
  lập danh sách mutation tối thiểu cho từng invariant, chạy trên bản sao, ghi cái
  nào bị bắt, cái nào sống sót.

---

# NGOÀI phạm vi

* UI duyệt đề xuất và mọi frontend (thuộc Phần 3).
* Provider activation, model thật, trợ giảng frontend, modifier xếp hạng (đã hoãn).
* Apply database môi trường thật (có review migration riêng).
* Các module không thuộc Phần 2 trừ chỗ tương tác trực tiếp (Media deletion, Course
  publish, Learning promotion).

Reviewer có thể tách thành hai lane nếu Owner giao hai người: **Lane A** Bước 4–5
(governance, quota, embedding, retrieval); **Lane B** Bước 6–7 và xoá Media xuyên
suốt. Mỗi lane vẫn trả lời các điều kiện đóng liên quan; báo cáo cuối gộp một verdict.

---

# Ràng buộc độc lập

* **Không đủ tư cách:** tác nhân đã viết hoặc vá code của bất kỳ bước nào trong
  phạm vi, gồm session implementer Knowledge Sync/Bước 6/Bước 7 và implementer Bước 4–5.
* **Đủ tư cách nếu chưa sửa code/tài liệu canonical:** reviewer các lượt trước
  (xương sống, migration, Gate 2). Không dựa vào verdict hay số liệu của họ; tái lập.
* Reviewer **không vá**; finding giao implementer.

# Ràng buộc an toàn

* Chỉ đọc; không sửa code, migration, test, tài liệu canonical. Báo cáo ở `docs/quality/`.
* **Không kết nối `learnforge_db`** (database dev của Owner, hiện trên MariaDB 11.4
  `127.0.0.1:3307`): test dùng `RefreshDatabase` sẽ xoá sạch dữ liệu. Dùng instance
  MariaDB 11.4 dùng một lần trong `/tmp`, `--skip-networking`, database tên bắt đầu
  `lf_` (guard của một số test); tắt và xoá khi xong. Không chạm XAMPP `:3306`.
* Không gọi provider thật, không thêm secret, không gửi dữ liệu ra mạng.
* Mutation chỉ trên bản sao riêng, đối chiếu SHA-256; không symlink `vendor`.
* Không chạy song song các suite dùng chung fixture/storage/database.
* Không ký cho điều chưa tự kiểm chứng.

# Snapshot

Commit Owner giao (`<COMMIT>`); nếu review working tree thì ghi SHA-256 các file
chính lúc bắt đầu và cuối lượt.

---

# Kiểm chứng tối thiểu

```bash
php artisan test
```

Toàn danh sách `integration-mysql` trong `.github/workflows/application-tests.yml`
trên schema MariaDB 11.4 dựng mới; đối chiếu **tên** test đỏ qua JUnit, không suy từ
tổng số. Skip không phải PASS.

```bash
php artisan docs:lint
```

```bash
php artisan schema:drift --docs-only
```

Thêm `schema:drift --connection=mysql` trên schema dựng mới, và probe riêng cho các
câu hỏi ở § Trọng tâm khi test hiện có không trả lời được.

---

# Định dạng báo cáo

* `docs/quality/LF-AI-Part-2-Closure-Review.md`; header chuẩn, reviewer, snapshot, ngày.
* Bảng 10 điều kiện đóng: verdict từng điều + bằng chứng của chính reviewer.
* Findings `BLOCKER | HIGH | MEDIUM | LOW`: file:dòng, tình huống cụ thể, bằng chứng
  tái lập, đề xuất (không vá).
* Mục riêng cho Bước 4, 5, 6, 7 (lần đầu review độc lập implementation).
* Bảng mutation: invariant → mutation → bị bắt/sống sót.
* Bảng lệnh và kết quả của reviewer; mục chưa kiểm.
* Verdict toàn Phần 2 và điều còn thiếu để đóng (nếu có).

---

# Remediation sau closure review — 2026-09-27

[Báo cáo](LF-AI-Part-2-Closure-Review.md): **CHANGES REQUIRED** (MEDIUM 2, LOW 2).
Implementer đã vá cả bốn; reviewer cần chạy lại vùng ảnh hưởng.

| Finding | Vá | Hồ sơ |
| --- | --- | --- |
| C1 MEDIUM race ghi đè model run | Khoá dòng khi đọc-kiểm-ghi trong `AiModelRunRecorder` | [Bước 4](LF-AI-Provider-Execution-Gate-Implementation-Review.md) § Vá sau Part 2 closure review |
| C2 MEDIUM thiếu safety policy | Fail-closed khi policy thiếu hoặc sai hình dạng | như trên |
| C3 LOW mô tả lệch | ADR-0006 (câu authoring cũ đánh dấu lịch sử), comment `config/ai.php` về reservation, LF-AI (migration đã có trên dev local) | — |
| C4 LOW regression fingerprint | `test_a_unit_differing_in_one_identity_component_is_rejected_atomically` (fingerprint / version / locale riêng từng thành phần); mutation M02 của reviewer nay bị bắt | `AiKnowledgeIngestionServiceTest` |


# Remediation sau round 2 — 2026-09-28

[Báo cáo §10](LF-AI-Part-2-Closure-Review.md): **CHANGES REQUIRED**; C1, C3, C4
CLOSED, C2 PARTIALLY CLOSED. Suite mặc định có một test đỏ do môi trường.

| Việc | Vá | Hồ sơ |
| --- | --- | --- |
| C2 — 5 hình dạng override/container sai vẫn cho gọi adapter | Kiểm từng lớp policy trước khi gộp; override khai báo sai (kể cả `null`), key purpose lạ, forbidden không phải list hoặc ngoài vocabulary đều `AI_SAFETY_BLOCKED` | [Bước 4](LF-AI-Provider-Execution-Gate-Implementation-Review.md) § Round 2 — C2 còn mở |
| `VideoTranscriptCaptionLocalReviewTest::test_a_corrupt_video_fails_extraction_without_output_or_workspace_residue` đỏ khi thiếu Faster Whisper | Provider kiểm runtime STT trước khi FFmpeg tách audio. Test nay dùng runtime stub `/usr/bin/false` (nếu STT bị gọi nhầm thì ra mã lỗi khác và test đỏ), chỉ đòi FFmpeg thật, thiếu FFmpeg thì skip có lý do. Đã chạy với đường dẫn runtime không tồn tại: ca này PASS, năm ca real-video skip | test file |

# Round 3 BLOCKED và K3 — quyết định Owner 2026-09-28

[Báo cáo §11](LF-AI-Part-2-Closure-Review.md): round 3 **BLOCKED**. Thread reviewer
lượt 1–3 đã sửa code Knowledge (bản vá frame `part_index` và `QueryException`, hồ sơ
[Knowledge-Frame-Sync-Fix](../../review-artifacts/ai-2026-09-28/Knowledge-Frame-Sync-Fix.md)),
nên không còn đủ tư cách độc lập. Hồ sơ đó cũng phát hiện **K3 (HIGH)**:
`chk_akc_source_role` hẹp hơn role Media đã duyệt (DOC-CONFLICT-0040).

Owner duyệt ngày 2026-09-28:

1. **Sửa K3 trước round 3.**
   * Amendment Proposed trong
     [ai_knowledge_chunks.md](../database/ai/ai_knowledge_chunks.md).
   * Architecture Review theo
     [brief K3](LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md).
   * Owner duyệt, rồi tạo forward migration, kiểm trên MariaDB 11.4 và apply dev sau
     backup.
2. **Mã log `database_write_failed` được duyệt** (Knowledge Sync Contract § Lỗi và
   retry). Đây là mã log vận hành, không phải mã lỗi exception.
3. **Round 3 giao reviewer mới.** Người này chưa từng viết hay sửa code nào của
   Phần 2, và không phải thread lượt 1–3. Round 3 chạy trên snapshot chốt **sau khi
   K3 xong**, gồm:
   * C2 và test video hỏng (§ Remediation sau round 2);
   * bản vá frame `part_index` và `QueryException`;
   * migration K3;
   * xác nhận lại điều kiện 2 và 6 và verdict toàn Phần 2.

   Snapshot SHA-256 sẽ được ghi lại khi bàn giao; bảng SHA của lời nhắn round 3 trước
   không còn dùng.

# Round 3 — giao reviewer K3, 2026-09-29

Owner đồng ý gộp round 3 vào lượt của reviewer K3
([báo cáo K3](LF-AI-Knowledge-Source-Role-Alignment-Review.md), lượt 1–4). Reviewer
này chưa từng sửa code hay tài liệu canonical của Phần 2, nên vẫn đủ tư cách độc lập.
Thread reviewer lượt 1–3 của closure thì không.

## Trạng thái trước round 3

| Hạng mục | Trạng thái | Hồ sơ |
| --- | --- | --- |
| C1, C3, C4 | CLOSED ở round 2 | báo cáo closure §10 |
| C2 — safety policy sai hình dạng | Đã vá sau round 2, **chưa ai xác nhận độc lập** | [Bước 4](LF-AI-Provider-Execution-Gate-Implementation-Review.md) § Round 2 — C2 còn mở |
| Test video hỏng đỏ khi thiếu Faster Whisper | Đã vá (runtime stub), chưa xác nhận | § Remediation sau round 2 |
| Frame `part_index` | APPROVE ở K3 lượt 1 | báo cáo K3 câu 9 |
| Phân loại `QueryException`, `register()` chỉ bắt unique | K3-R2 CLOSED ở K3 lượt 2 | báo cáo K3 lượt 2 |
| K3-R7 — exception gốc trong trace | Đã vá, **chưa xác nhận** | [Backbone record](LF-AI-Knowledge-Backbone-Implementation-Record.md) § K3-R7 |
| Log `database_write_failed` mỗi cửa sổ (R2) | APPROVE WITH CHANGES → best-effort ghi rõ (K3-R6 CLOSED) | báo cáo K3 lượt 2 |
| K3 migration | APPROVE lượt 4; **đã apply dev 2026-09-29** | DOC-CONFLICT-0040 RESOLVED |

Bằng chứng apply dev (của implementer, reviewer không kết nối dev nên không tự kiểm):

* Backup `learnforge_db-before-k3-20260929-083246.sql` (SHA-256 `400065f7…`) đã restore thử.
* Target preflight của chính migration pass trên dev.
* `migrate`: 388 ms. Sau đó CHECK 15 role, physical drift `learnforge_db` sạch, 3186
  chunk có sẵn không đổi.
* `ai:knowledge-sync`: ingested=3, failed=0. Media 55 có 3 source `active` (Course
  Version Activity 29/35/36), mỗi source 164 chunk; đối chiếu 164/164 với region nguồn
  khớp.

## Phạm vi round 3

1. **C2:** chạy lại probe safety-variants (17 cấu hình và 5 biến thể còn lọt ở round 2)
   cùng các hình dạng reviewer tự nghĩ; oracle blocked/refund/no-factory/no-usage-event.
2. **Test video hỏng:** chạy suite mặc định trong môi trường **không** có Faster
   Whisper; đánh giá stub `/usr/bin/false` có kiểm đúng điều test tuyên bố.
3. **K3-R7:** kiểm exception thoát ra khi `zend.exception_ignore_args=0` không giữ
   `QueryException` hay SQL/bindings trong trace; đánh giá test mới của implementer.
4. **Toàn Phần 2:**
   * `php artisan test`;
   * toàn danh sách `integration-mysql` (nay 37 đường dẫn, gồm
     `AiKnowledgeSourceRoleMigrationMariaDbTest`) trên MariaDB 11.4 dựng mới, đối
     chiếu tên test qua JUnit;
   * `docs:lint`, `schema:drift --docs-only`, `schema:drift --connection=mysql`.
5. **Verdict** cho điều kiện đóng 2, 4, 6, 10 và toàn Phần 2. Ghi vào báo cáo closure
   (thêm §12), giữ nguyên §1–11.

Không cần review lại những gì đã PASS/CLOSED ở báo cáo K3, trừ khi snapshot đổi. Không
chạy lại 14 mutation lượt 1; chỉ thêm mutation cho C2 và K3-R7 nếu reviewer thấy cần.

# Round 4 — sau §12, 2026-09-29

[§12](LF-AI-Part-2-Closure-Review.md): **CHANGES REQUIRED**, chỉ vì C2 ở lớp gốc
`ai.safety` (key gõ sai `purpose`, key lạ, key số bị bỏ qua). K3-R7 CLOSED, test video
hỏng PASS, suite và drift sạch.

Vá và bằng chứng: [Bước 4](LF-AI-Provider-Execution-Gate-Implementation-Review.md) §
Round 3 — lớp gốc `ai.safety`. Lớp gốc chỉ nhận `default` và `purposes`. Có regression
dùng ledger thật cho cặp gõ sai/viết đúng; mutation bỏ kiểm lớp gốc bị bắt.

Phạm vi round 4 (hẹp):

1. Chạy lại `safety-variants.php` (52 lượt §12.3) cùng cặp typo/correct và các biến
   thể lớp gốc; oracle blocked/refund/no-factory/no-usage-event.
2. Chạy `tests/Feature/AiProviderExecutionGateTest.php` trên SQLite và MariaDB 11.4
   dùng một lần; `php artisan test`; `docs:lint`; `schema:drift --docs-only`. Chỉ file
   gate đổi, nên toàn danh sách integration không bắt buộc, trừ khi reviewer thấy cần.
3. Cập nhật điều kiện 2 và 6, và verdict toàn Phần 2 trong §13, giữ nguyên §1–12.

# Kết quả — 2026-09-29

[§13](LF-AI-Part-2-Closure-Review.md): **C2 CLOSED; điều kiện 2 và 6 PASS; toàn Phần 2
PASS WITH DOCUMENTED RISKS.** Phần 2 đóng. Rủi ro còn lại ghi ở
[LF-AI](../platform/LF-AI.md) § Phần 2 AI Knowledge — đóng 2026-09-29.
