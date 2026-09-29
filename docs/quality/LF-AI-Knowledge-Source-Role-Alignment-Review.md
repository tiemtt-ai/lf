# AI Knowledge Source Role Alignment (K3) — Independent Architecture Review

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-28

Document Path: quality/LF-AI-Knowledge-Source-Role-Alignment-Review.md

Reviewer: Codex, session Architecture Review K3, 2026-09-28

## Kết luận

**Toàn amendment K3: APPROVE WITH CHANGES. Chưa đạt gate tạo migration.**

Mở CHECK từ 9 lên đúng 15 role là hướng sửa đúng: giữ Media làm authority,
không sửa evidence, identity, ownership hoặc `customer_id`. Tuy nhiên packet
chưa đủ authorization cho thay đổi Foundation và chưa chốt các điều kiện DDL,
rollback, physical regression bên dưới. Approval có điều kiện này không phải
`Architecture Review passed` để lập migration ngay.

**Phạm vi phụ câu 9: REJECT đối với thiết kế bắt mọi QueryException; APPROVE
đối với frame part_index.** Cơ chế hạn chế log tuần tự phù hợp ý định đã duyệt,
nhưng không thay cho phân loại lỗi hệ thống. Đây không phải verdict closure
Phần 2 round 3.

| Câu | Verdict |
| --- | --- |
| 1 — Nguyên nhân/vocabulary khác | APPROVE |
| 2 — Phương án và lập luận identity | APPROVE WITH CHANGES |
| 3 — Snapshot/identity/backfill | APPROVE |
| 4 — Consumer | APPROVE |
| 5 — Database Docs đủ, không cần ADR? | REJECT |
| 6 — Kế hoạch migration | APPROVE WITH CHANGES |
| 7 — Chống tái diễn | APPROVE WITH CHANGES |
| 8 — Danh sách kiểm chứng | APPROVE WITH CHANGES |
| 9 — Phạm vi phụ tổng thể | REJECT |

## Độc lập, phạm vi và nguồn bằng chứng

Reviewer đã đọc § Ràng buộc độc lập trước khi nhận việc. Theo toàn bộ lịch sử
ngữ cảnh được cung cấp của session này, reviewer chưa viết/sửa code hoặc tài liệu
canonical AI Phần 2, không phải implementer Sync/frame/QueryException, không
phải reviewer closure đã vá code. Không suy diễn danh tính tác giả từ những
file đang modified trong working tree. Không khẳng định khả năng kiểm chứng
lịch sử ngoài ngữ cảnh session được cung cấp.

Chỉ tạo báo cáo này. Không sửa code, migrations, tests, canonical docs hay
conflict register. Không dùng kết luận self-review của implementer làm bằng
chứng. Đã đọc trực tiếp source, migration, hợp đồng, ADR và chạy probe tự soạn
trong stdin, không ghi probe thành test của repository.

Không đọc `.env`, không boot Laravel application, không chạy Artisan, không
kết nối database/provider/network. PHP probe dùng autoload hiện có, reflection,
Mockery và ArrayStore trong bộ nhớ. Không dựng MariaDB, không tạo migration thử,
không có instance hay bản sao cần dọn.

Classification: Existing-Feature Change, architecture review trước migration.
Initial/Final Audit Level: HIGH (Foundation constraint, immutable snapshot,
transaction/retry). Audit Level Escalation: None. Đây là review thiết kế;
implementation audit và deployment verification vẫn chưa thực hiện.

Routing đã áp dụng: README → LF-INDEX → Guardrails → ADR-0006/ADR-0019 và ADR
change policy → AI/Media contracts → table docs/migrations/schema contract →
Architecture Review Checklist/Schema Drift/Regression Audit. Đã tham chiếu
LF-OS, Data Modeling, Development Standards và Implementation Rules. Những
phần UI/auth/navigation không bị thay đổi và không thuộc verification này.

## Snapshot SHA-256

Sáu file đều khớp handoff ở lần đọc đầu, kiểm lại trước ghi báo cáo và lần kiểm
cuối sau ghi báo cáo. `Đầu = cuối` cho từng dòng dưới đây; không phát hiện drift.
Các kết luận dùng working tree bàn giao, không dùng riêng HEAD vì đã có nhiều
thay đổi chưa commit trước review.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `docs/database/ai/ai_knowledge_chunks.md` | `24ce844ae3aaab3ae2f3646a7f2e46ef2d96c1a5796c19d0d2cb7125647c5faa` |
| `docs/quality/LF-Documentation-Conflicts.md` | `48f363d6b4070b4ae49346a4582ca40797bc2bec6b277fd1cde2eb16a0d0685c` |
| `docs/platform/LF-AI-Knowledge-Sync-Contract.md` | `3ec666d331eb6c704e7eff1f5a4cd0c18a68fef4286aa457197ceb7479038dc9` |
| `app/Services/AiKnowledgeIngestionService.php` | `edcbd92ef0cae20f66ffdcb1bb9a584fb3f08b6bfa5ab2134bee342e88febdd0` |
| `app/Services/AiKnowledgeSyncService.php` | `c0f4ec4b964f9b44e3b112ca2d33d4c1cf8dd44110581a95dd21973ac9f109a1` |
| `docs/quality/LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md` | `c99d48d344512f197d3a621f0ac94c9bf54bcd072ef4fc8433707e666d3d5962` |

## 1. Nguyên nhân — APPROVE

Tự đối chiếu ba nguồn:

* `media_extracted_regions.md:43` và ADR-0019 § Amendment v1.7 Approved
  2026-09-03: 14 role cho revision mới, thêm `figure` giữ revision cũ = 15.
* `2026_09_03_000100_add_document_language_profiles_and_formula_evidence.php:55`:
  DDL `chk_mer_role` có đúng 15 giá trị đó.
* `2026_09_08_000100_create_ai_foundation_knowledge_tables.php:303`:
  DDL `chk_akc_source_role` chỉ có 9 giá trị.

Python đọc độc lập `LF-SCHEMA-CONTRACT.json` trả Media=15, Knowledge=9,
`Media - Knowledge = chart, diagram, formula, geometry, image, note`;
tập Proposed bằng đúng tập Media. R2-21 tại Foundation review dòng 975 và
bản CLOSED dòng 335 liệt kê đúng vocabulary 9 giá trị cũ. Vì vậy nhận định
“khớp Media” trong R2-21 đã dùng tập trước amendment; đây là đối chiếu nội dung,
không phải suy đoán thời điểm reviewer cũ đọc tài liệu.

Ingestion copy role tại dòng 426–428; đăng ký và ghi chunk nằm trong transaction
`register()` dòng 149–282. Một CHECK failure không thể commit source/chunk mới
một phần qua đường này. Source cũ đã tồn tại trước transaction, nếu có, được
rollback về trạng thái trước đó, không có nghĩa tổng số source trong DB luôn 0.

| Snapshot khác | Đối chiếu độc lập | Kết quả |
| --- | --- | --- |
| `source_quality_status` | Media table doc CHECK và JSON contract: `complete/incomplete/undetermined`; Foundation DDL dòng 305–306 và copy dòng 429–431 | Không lệch tập |
| `source_text_quality` | Media Read Contract § Region text quality; service dòng 567–570 phát `normal/low`; DDL dòng 307–308 nhận cả hai và NULL | Không lệch tập |
| `locator_type` | Media Read Contract và DDL Media: page/timespan/region/sheet; Foundation DDL dòng 300 nhận đủ bốn | Không lệch tập cho content được ingest; locator null của delivery asset không thuộc chunk corpus |
| `language_evidence` | MediaReadService dòng 537–554 giữ thứ tự ordinal; Ingestion dòng 414,432 copy array, rỗng → NULL; JSON không có enum hẹp | Không có CHECK vocabulary chặn evidence; region giữ `{script,locale,char_count}`, locale nullable. Transcript có shape riêng từ spoken-language evidence, không được ép thành region hoặc tự thêm script |

Không phát hiện vocabulary Knowledge hẹp hơn Media ở bốn mục trên. Đây không
phải chứng nhận mọi field Media khác đều không có drift.

DOC-CONFLICT-0040 mô tả đúng vấn đề và trạng thái UNDER_REVIEW/STOP implementation
phù hợp. Các số liệu `163 region`, `0 chunk` của dev là **evidence được bàn giao**,
chưa được reviewer xác nhận lại. Không truy cập DB bị cấm. “CHECK vật lý” ở kết
luận này được xác định từ DDL nguồn/contract; chưa inspect instance thật.

## 2. Phương án — APPROVE WITH CHANGES

Giữ CHECK nullable và mở đúng 15 role là thay đổi nhỏ, phù hợp LF-OS Simplicity
và Media authority. Bỏ CHECK Knowledge sẽ làm mất guard tại nơi lưu snapshot:
CHECK Media không trực tiếp bảo vệ row Knowledge, cũng không thay thế invariant
cho các writer sau này. Bảng vocabulary chung tạo thêm ownership, lifecycle và
FK/seed governance không cần thiết cho sửa drift này.

Không map `image → figure`: đánh mất evidence Media và lệch hợp đồng copy
nguyên bản. Tuy nhiên câu “làm hỏng retry/identity” phải viết chính xác hơn:

* `source_role` được so sánh trong `assertChunkSnapshotMatches()` dòng 540/564;
  đổi mapping giữa các lần retry của snapshot đã tồn tại sẽ fail-closed.
* `source_role` **không** nằm trong công thức `chunk_uuid` dòng 492–494 hay
  `content_hash` dòng 420. Một mapping nhất quán ở mọi lần không tự làm UUID
  khác hoặc tự làm retry thất bại; nó vẫn sai vì mất fidelity.
* Probe thực tế qua `desiredChunks()`/`stableUuid()` với cùng text/locator và
  15 role: copy đủ 15, chỉ một UUID và một content hash.

Sửa prose này và lỗi độ dài: `paragraph` có **9**, không phải tối đa 8 ký tự.
`VARCHAR(20)` vẫn đủ. Không cần đổi thiết kế đề xuất.

## 3. Snapshot, identity và backfill — APPROVE

Đổi CHECK chỉ thay miền giá trị được phép ghi. Không có UPDATE data, không đổi
identity input, content hash, snapshot comparison, chunker version, source
fingerprint hay processing version. Existing `figure`, NULL và 9 role cũ vẫn
hợp lệ. Revision mới từng bị CHECK chặn có thể đăng ký ở lần đối soát kế tiếp;
không cần backfill hay tái xử lý Media.

Kết luận không backfill áp dụng cho database tuân baseline CHECK/transaction.
Không được suy ra “mọi môi trường đều chưa có chunk role mới” chỉ từ dev sample:
môi trường từng tắt CHECK hoặc sửa tay cần bị preflight từ chối/điều tra. Không
rebuild hoặc đổi generation hàng loạt để sửa K3. Giữ nguyên source/chunk lịch
sử, embedding và provenance.

## 4. Consumer — APPROVE

Search toàn `app/tests/config/routes/resources` cho `source_role` chỉ thấy
writer và hai phía snapshot comparison trong Ingestion; test fixture có
`paragraph`. Đã kiểm thêm projection để tránh chỉ dựa vào grep:

* Retrieval dòng 103–111 lấy content/hash/locator/reading_order/text_quality,
  không trả source_role.
* Embedding dòng 503–507 lấy content/hash và source identity; không dùng role
  để tính embedding identity. Đường reread chunk cũng không dùng role.
* Vision dòng 159/186 đọc `unit.structure.role` trực tiếp từ Media Read,
  không đọc `ai_knowledge_chunks.source_role`.
* Authoring không có tham chiếu field này. LF-AI dòng 345–351 ghi rõ Owner hoãn
  ranking modifiers/context expansion ngày 2026-09-26.

Không thấy downstream consumer hiện hành phân nhánh theo giá trị field này.
Không diễn giải thành “hành vi tổng thể không đổi”: sau sửa, các revision bị
chặn sẽ có thêm chunk/candidate hợp lệ, nên độ phủ retrieval có thể tăng.

## 5. ADR — REJECT phương án chỉ Database Docs

ADR-0006 không enum `source_role`, nhưng § Foundation Freeze dòng 649–658 nói:
“Changes to Domain Boundary, ownership, Source Of Truth or the 12-table
Foundation require: Approved ADR Amendment; or New ADR.” § Future Extensions
dòng 700–701 nhắc lại cho thay đổi Foundation tables. Brief cũng xác định đây
là CHECK của bảng Foundation; AGENTS.md yêu cầu ADR approved nếu đổi Foundation.

Không có ngoại lệ được dẫn nguồn cho “chỉ sửa CHECK để khớp upstream”. ADR-0019
v1.7 đã duyệt vocabulary Media, chưa ghi approval sửa constraint Knowledge.
ADR-0006 v1.0.1 duyệt ranking/evidence nhưng cũng nói approval ấy không cho phép
migration. Vì vậy Database Docs không tự thay thế gate ADR.

Cần amendment ngắn liên kết ADR-0006 với ADR-0019 v1.7, ghi rõ mở CHECK snapshot,
giữ role nguyên bản, không đổi ownership/identity/backfill, rồi Owner approve.
Không cần ADR số mới nếu chỉ bổ sung phạm vi này. Reviewer không sửa ADR hay
tự cấp ngoại lệ. Đây là finding K3-R1.

## 6. Kế hoạch migration — APPROVE WITH CHANGES

Đồng ý forward migration; không sửa lịch sử Foundation. Một ALTER chứa DROP
và ADD tránh khoảng trống CHECK giữa hai statement, nhưng không chứng minh
zero downtime hay tự chứng minh algorithm được hỗ trợ trên MariaDB 11.4.
Cần bổ sung vào kế hoạch trước khi lập migration:

1. Preflight phải scope chính xác database/table/constraint, yêu cầu constraint
   tồn tại đúng một lần và đúng predicate nullable + tập 9 role. Cho phép khác
   whitespace/backtick/parentheses vô nghĩa bằng chuẩn hóa có kiểm soát; không
   chấp nhận chỉ vì có đủ chín chuỗi trong biểu thức (ví dụ `OR 1=1`). Missing,
   rộng/hẹp lạ, đã là 15 nhưng ledger chưa ghi phải dừng với thông báo rõ và
   recovery procedure, không tự đoán. `down()` kiểm trạng thái đầu vào 15 tương tự.
2. Kiểm CHECK enforcement đang bật; không tắt CHECK để ALTER. Liệt kê quyền
   thực tế cần cho metadata/SELECT/ALTER trên target. **Không cần preflight
   quyền TRIGGER như M4** vì không tạo/sửa trigger; fresh reconstruction cả
   repository vẫn có thể cần TRIGGER từ migration khác.
3. Với bảng lớn: rehearsal trên dữ liệu có kích thước/phân bố tương đương,
   chốt ALGORITHM/LOCK khả dụng bằng kết quả thực nghiệm, đo thời gian validation,
   metadata-lock wait, writer blocking, disk headroom nếu copy/rebuild và replica
   lag nếu có. Không mặc định INSTANT/INPLACE hoặc LOCK=NONE. Đặt thời hạn chờ,
   tiêu chí abort, maintenance window/pause writers khi cần, backup và phục hồi.
4. `down()` kiểm role mới ở **mọi tenant, mọi status**, kể cả stale/archived/deleted
   còn provenance, không chỉ active. Không xóa/sửa evidence để rollback. Tránh
   race count→DDL bằng quiesce writers; CHECK thu hẹp phải validate dữ liệu tại
   thời điểm ALTER và fail nếu có row mới chen vào. Không coi pre-count là đủ.
5. Down/up roundtrip phải bảo toàn dữ liệu và constraint cũ khi bị từ chối.
   Thất bại DDL/lock timeout không được để bảng mất CHECK hoặc ledger báo thành
   công. DDL hoàn tất nhưng ledger chưa ghi cần recovery rõ, không mù quáng retry.
6. SQLite skip được giữ để nhất quán, nhưng ghi rõ SQLite không chứng minh
   enforcement. Cập nhật Indexes/JSON contract cùng migration sau approval;
   docs-only, fresh và physical drift là các gate riêng.

Không thực hiện DDL trong lượt này; tính chấp nhận cú pháp swap cùng tên,
algorithm/lock và failure atomicity phải được chứng minh ở migration review
trên MariaDB 11.4 dùng một lần. Không khẳng định các điều này đã pass.

## 7. Chống tái diễn — APPROVE WITH CHANGES

Test Knowledge ⊇ Media trong contract là hữu ích nhưng chưa đủ: contract và
migration có thể cùng giữ baseline cũ hoặc contract đổi mà DDL không đổi.
Đối với K3 hiện tại còn phải assert **bằng đúng 15** và reject role lạ, không
chỉ ⊇. Giữ superset test như guard tương lai cho mở rộng Media.

JSON hiện lưu CHECK dưới key `expression`, không lưu tên `chk_mer_role`.
Test phải chọn table + expression của field đúng, không giả định có tên vật lý.
Không dùng regex gom mọi literal của mọi CHECK thành một vocabulary.

Cần integration trên MariaDB 11.4: fresh migrations, upgrade baseline có dữ
liệu, inspect CHECK thực trong information_schema, thử INSERT/UPDATE các role
và drift với JSON. Physical test bảo vệ cả nullable branch và CHECK enforcement;
SQLite không thay thế được. Đây là K3-R4.

## 8. Danh sách kiểm chứng — APPROVE WITH CHANGES

Giữ các tiêu chí sẵn có, bổ sung requirement-to-test trước implementation:

| Requirement | Verification cần có |
| --- | --- |
| Miền giá trị chuẩn | Cả 15 role và NULL INSERT/UPDATE thành công; role ngoài tập INSERT/UPDATE bị chặn; ghi nhận collation hiện hành, không đổi semantics case/collation ngầm |
| Snapshot/retry | Ingest fixture Media role mới, copy nguyên role/text/locator/bbox/languages; retry giữ source/chunk UUID/hash/count/version; không tạo embedding identity mới cho dữ liệu cũ |
| Revision atomicity | Nhiều unit, role lạ ở sau unit hợp lệ: rollback toàn revision mới; source/chunk cũ không bị stale/overwrite do transaction lỗi |
| Preflight | Missing CHECK, predicate lạ, tập role lạ, nullable branch sai, CHECK đã rộng nhưng ledger chưa ghi: từ chối trước mutation |
| Up và down | Upgrade populated baseline; down không có role mới thành công; down có từng role mới ở mọi status từ chối; up→down→up; data/hash/count giữ nguyên |
| Concurrency/failure | Writer chen giữa pre-count/down; lock timeout; ADD thất bại; interruption/ledger recovery; không để mất CHECK |
| Governance/schema | ADR/Database Docs approved, review findings được đóng; docs lint, docs-only, fresh/physical drift; historical migration không đổi |
| Deployment | Backup/rehearsal, DDL budget, quyền đúng; sau apply được phép mới kiểm những Media bị chặn, source active và snapshot đầy đủ |

Đây là acceptance criteria cho migration tương lai, không phải test pass của
lượt review này. Dev apply/production activation không được cấp quyền bởi báo
cáo này. Không chạy các lệnh kết nối dev để hoàn thiện evidence.

## 9. Phạm vi phụ — REJECT tổng thể

### Frame part_index — APPROVE

`desiredChunks()` dòng 398–415 cấp counter theo `locator.type|locator.value`
chỉ cho `video_frame_text`; char offsets và bbox vẫn theo từng unit. Nó thỏa
`uk_akc_locator_part` mà không nối nội dung các region.

Probe gọi chính method hiện hành với một unit 4001 ký tự Hàn, một unit 6 ký tự
cùng timespan, một unit 4 ký tự ở timespan khác:

```text
part_index: [1,2,3,1]
offsets: [[0,4000],[4000,4001],[0,6],[0,4]]
repeated_input_identical: true
uuids_identical: true
permutation_changes_uuid_sequence: true
single unit part_index: [1,2]
```

Thứ tự là precondition thực sự: đảo unit có thể đổi UUID gắn với nội dung hoặc
đổi nội dung gắn với cùng UUID nếu boundary bằng nhau. Runtime sẽ phát hiện
snapshot mismatch khi retry. Nhưng MediaReadService dòng 448–453 order theo
`locator_value`, `reading_order`, cuối cùng `id`; đây là total order trên cùng
tập immutable rows. Migration frame có unique revision locator/reading_order.
Không thấy hai lần đọc **cùng revision bất biến** đổi thứ tự theo query này.
Không yêu cầu chunker sắp xếp lại evidence Media.

Revision nhiều unit có text cùng timespan trước bản vá không thể commit qua
unique key cũ; một unit cùng locator giữ part/UUID cũ. Vì vậy không tăng
chunker version/backfill là hợp lý trong baseline. Môi trường từng bypass
unique/viết dữ liệu riêng phải audit, không tự áp giả định này.

### QueryException — REJECT

`AiKnowledgeSyncService.php:355–368` bắt mọi QueryException mà không xem SQLSTATE,
driver code hay loại operation. Reviewer gọi **syncForMedia() thật**, thay các
dependency bằng mock và DB/Log bằng facade mock, không mở connection. Hai owner,
cùng revision giả, lần lượt bơm lỗi từ `ingestForSync()`:

| Lỗi tổng hợp | Kết quả tự chạy |
| --- | --- |
| HY000 / 1142, thiếu quyền ghi | Không exception thoát, attempts=2, failed=2 |
| 40001 / 1213, deadlock đã hết retry | Không exception thoát, attempts=2, failed=2 |
| HY000 / 2006, mất kết nối | Không exception thoát, attempts=2, failed=2 |

Đây là probe control flow, không phải tái tạo lỗi engine. `register()` dùng
transaction attempts=3; Laravel ManagesTransactions dòng 104–114 rollback rồi
retry concurrency error, cuối cùng rethrow. Broad catch ngoài cùng làm mất
phân biệt “revision bị CHECK từ chối” và “hạ tầng không ghi được”.

Mất kết nối kéo dài có thể vẫn làm tenant dừng vì query ngoài catch, gồm
`alreadyActive()` hoặc cuối `reconcileTenant()`, sẽ fail. **Không kết luận mọi
outage bị nuốt hoàn toàn.** Counterexample đủ rõ là quyền ghi chunk mất trong
khi SELECT/cache còn hoạt động, hoặc deadlock lặp lại chỉ ở transaction ghi:
mọi revision failed, vòng lặp vẫn chạy; command `AiKnowledgeSync.php:75` trả
SUCCESS khi không có exception tenant, dù tổng failed > 0. `syncForMedia()` còn
trả trực tiếp sau ingest loop nên không có query cuối để phát hiện outage.

Đề xuất thiết kế: chỉ tiếp tục đối với lỗi dữ liệu/constraint cục bộ đã phân
loại; lỗi connection/schema/permission và concurrency đã hết retry đi đường
lỗi tenant hoặc cơ chế dừng có giới hạn được duyệt. Ghi nhận bằng mã/SQLSTATE
đã làm sạch, không SQL/bindings/message. Rà cả catch trong `register():207–221`:
nó biến lỗi insert source thành `registration_conflict` nếu không tìm row
cạnh tranh, nên chỉ sửa catch Sync chưa bảo đảm lỗi hệ thống được giữ nguyên.
Không tạo mã log mới ngoài phạm vi Owner đã duyệt nếu chưa có quyết định.

### Log một lần mỗi cửa sổ — APPROVE WITH CHANGES

Đã probe method `backoff()` thực với Container tối thiểu + ArrayStore, cấu hình
[60,1440], clock giả: số log tích lũy ở t=0, lặp t=0, t=60m+1s, t=120m+2s,
t=180m+2s, rồi xóa key/attempts là `[1,1,2,2,3,4]`; attempts sau reset=1.
Code thành công dòng 347–348 xóa đúng hai key. Key log có suffix riêng nên
không đặt ingest backoff; failed vẫn tăng từng attempt. Payload log dòng
448–455 chỉ chứa tenant/owner/usage/content type/error code, không text/SQL.

Giới hạn: `Cache::has → log → put` không atomic, có thể log hai lần khi event
worker và scheduler cùng xử lý key. `withoutOverlapping` của scheduler không
tự serialize mọi event job. Nếu “một lần” là cam kết cứng, dùng atomic claim
và kiểm concurrency; nếu là best-effort vận hành, ghi rõ. Cache loss có thể
log lại nhưng không làm sai snapshot. Giảm log không được che việc lỗi hệ thống
lặp lại; cần giải quyết K3-R2 trước khi chấp nhận toàn thiết kế lỗi.

## Findings

| ID / mức | Vị trí, tình huống và bằng chứng | Đề xuất / gate |
| --- | --- | --- |
| K3-R1 — HIGH | Amendment K3 § Forward migration; ADR-0006:649–658,700–701 và AGENTS Database Rule. Packet đổi CHECK Foundation nhưng chưa dẫn approval ADR cho việc đó | Bổ sung và duyệt ADR amendment liên kết Media v1.7; chặn tạo migration cho tới khi đủ gate |
| K3-R2 — HIGH | Sync:355–368, command:75; probe ba loại QueryException đều đi tiếp. Catch Ingestion:207 cũng có thể đổi lỗi hệ thống thành domain conflict | Phân loại revision-local và systemic; systemic đi đường lỗi tenant, bounded retry; không dùng log throttle thay error handling. Chặn phê duyệt phạm vi phụ lỗi/closure, không buộc gộp runtime patch vào migration CHECK |
| K3-R3 — MEDIUM | K3 § Forward migration chưa có DDL budget, algorithm/lock evidence, down race, input-state/recovery guard đầy đủ | Bổ sung kế hoạch tại câu 6 trước gate tạo migration; kiểm engine ở migration review |
| K3-R4 — MEDIUM | K3 § Chống tái diễn/kiểm chứng chủ yếu dựa contract và happy path | Bổ sung physical fresh+upgrade, rollback/failure tests, exact set/NULL/UPDATE và snapshot/retry tại câu 7–8 |
| K3-R5 — LOW | K3 dòng 41–42: mapping “hỏng identity”, role “≤8 ký tự” | Viết đúng snapshot fidelity/retry comparison; UUID không chứa role; paragraph dài 9 |
| K3-R6 — LOW | Sync:364–366; Sync Contract:229 “một lần mỗi cửa sổ” | Chốt best-effort hay atomic guarantee; kiểm cạnh tranh nếu yêu cầu cứng |

Không có finding yêu cầu đổi tenant/auth boundary. Không thay Media source of
truth hay xóa/sửa lịch sử để hợp thức hóa Knowledge. Không sửa các findings
trong lượt độc lập này.

## Điều kiện để tạo migration

1. Implementer cập nhật proposal theo K3-R3/R4/R5, bổ sung ADR amendment K3-R1;
   reviewer kiểm lại packet, Owner approve ADR và Database Docs, ghi rõ trạng
   thái freeze/approval. Khi đó mới chuyển architecture gate sang passed.
2. Conflict 0040 phải giữ unresolved/implementation pending cho tới khi có
   bằng chứng sửa; không gọi nó resolved chỉ vì proposal được duyệt. Bổ sung
   ADR gate vào resolution plan qua tác giả có thẩm quyền.
3. Chỉ sau các gate trên mới tạo forward migration; Indexes và schema contract
   cập nhật cùng thay đổi. Không chỉnh migration 2026-09-08.
4. Migration phải qua MariaDB 11.4 disposable verification theo câu 6–8 trước
   apply. Database Docs approval không đồng nghĩa deployment approval.
5. K3-R2/R6 được xử lý trong lane runtime/phạm vi phụ và review độc lập tiếp;
   không cần đổi chunker/version hoặc trộn runtime fix vào DDL K3. Không dùng
   approval CHECK để đóng câu 9 hoặc Part 2 closure.

## Lệnh đã chạy và giới hạn kiểm chứng

| Lệnh / nhóm lệnh thực chạy | Kết quả |
| --- | --- |
| `shasum -a 256` sáu file trong bảng snapshot, đầu/trước ghi/cuối | Khớp handoff; không drift |
| `git status --short` | Ghi nhận working tree đã dirty trước review; không reset/stash |
| `cat`, `sed -n`, `rg -n` trên docs/ADR/migrations/services/tests/vendor liên quan | Đối chiếu nguồn tại các vị trí ghi trong câu 1–9; không dùng self-review làm verdict |
| `rg -n 'source_role' app tests config routes resources` và kiểm projection consumer | Chỉ ingestion writer/retry + test fixture; không thấy downstream role consumer |
| `python3` qua stdin, parse JSON checks theo table/field và Proposed SQL | Media 15, Knowledge 9, thiếu đúng sáu; Proposed=Media; quality sets bằng nhau |
| `command -v php` | `/usr/local/opt/php@8.3/bin/php` |
| `php` qua stdin + autoload/reflection, desiredChunks/stableUuid | Frame [1,2,3,1], repeat identity ổn định, permutation thay identity sequence; copy đủ 15 role, UUID/hash không phụ thuộc role |
| `php` qua stdin + Container/ArrayStore/Carbon, actual backoff() | Window/reset kết quả [1,1,2,2,3,4]; không DB/cache bên ngoài |
| `php` qua stdin + Mockery, actual syncForMedia() | Ba systemic QueryException giả đều failed=2, attempts=2, không throw |
| Hai lần đầu thiết lập probe systemic | Một PHP parse error, một thiếu binding facade `db`; sửa harness trong stdin rồi lần cuối exit 0. Không phải lỗi sản phẩm, chưa có kết nối DB |
| Hai đường dẫn đọc thử | `AiKnowledgeSyncCommand.php` và `vendor/illuminate/...` không tồn tại; đã tìm và đọc `AiKnowledgeSync.php`, `vendor/laravel/framework/src/...` |
| `git diff --check` | Exit 0 tại lần kiểm trước báo cáo; kiểm riêng report ở cuối |

Probe tái lập: dùng `vendor/autoload.php` mà không require bootstrap/app;
reflection instance không constructor cho pure chunker; frame fixtures/text
và expected output tại câu 9. Probe log dùng ArrayStore và frozen clock như
trên. Probe systemic dùng hai owner 101/102, TenantContext giả 987654,
MediaRead mock một transcript revision, DB fluent mock trả exists=false và
title collection, Log mock; inject PDOException.errorInfo qua QueryException
trong mock ingestForSync, gọi syncForMedia(1). Toàn bộ identifiers là synthetic.

**Chưa kiểm:** live/fresh MariaDB, cú pháp/algorithm/lock/atomicity DDL, migration
up/down (chưa được phép tạo), physical schema drift, PHPUnit integration/full
suite/mutation suite, concurrency log thực, provider/vector store, dev số liệu
và performance production. Không có khẳng định PASS cho những mục này. Các
test sẵn có chỉ được đọc; không lấy việc chúng tồn tại làm bằng chứng đã chạy.
Không chạy formatter/build vì không thay code/UI và phải giữ snapshot bàn giao.

Files changed/new bởi reviewer: duy nhất báo cáo này. Không migration, không
runtime patch, không chỉnh canonical docs. Architecture verdict giữ nguyên
**APPROVE WITH CHANGES**, authorization tạo migration **chưa mở**.

---

## Lượt 2 — sau remediation 2026-09-28

Review Date: 2026-09-28

Reviewer: Codex, cùng reviewer độc lập của lượt 1. Không sửa code, test,
migration hoặc canonical docs trong cả hai lượt; vẫn đủ tư cách độc lập.

### Verdict và gate hiện tại

**Amendment CHECK K3: APPROVE. Gate Architecture Review cho việc tạo forward
migration: PASS.** ADR-0006 v1.0.6 Proposed đủ nội dung để làm ADR gate sau khi
Owner phê duyệt. **Chưa được tạo migration ngay:** hai điều kiện authorization
còn lại là **Owner duyệt ADR v1.0.6 và Owner duyệt Database Docs K3**. Không cần
thêm một lượt architecture review chỉ để xác nhận chữ ký nếu Owner duyệt đúng
packet/scope đã review; thay đổi nội dung thiết kế phải được đánh giá lại.

**Phạm vi phụ runtime: APPROVE WITH CHANGES.** Bản vá phân loại lỗi đã sửa hành
vi HIGH của K3-R2: systemic dừng, revision-local tiếp tục, message và previous
chain sạch. Tuy nhiên kiểm tra sâu hơn phát hiện **K3-R7 MEDIUM**: exception
thoát ra còn giữ QueryException gốc trong trace arguments khi PHP bật thu
thập arguments. Không dùng PASS của CHECK để đóng toàn bộ yêu cầu “exception
không chứa dữ liệu gốc”, hay đóng Part 2 closure. K3-R7 thuộc lane runtime,
**không chặn viết migration CHECK sau hai approval Owner**; cách tách gate này
giữ đúng điều kiện số 5 của lượt 1.

Lượt 1 ở trên giữ nguyên như lịch sử tại snapshot cũ. Verdict ở mục Lượt 2 này
thay thế verdict cũ cho packet bàn giao lượt 2.

### Snapshot lượt 2

Tám hash đầu lượt đều khớp handoff. Kiểm lại cuối lượt sau khi thêm báo cáo:
đầu = cuối, không drift. Không diễn giải thay đổi hợp lệ giữa hai lượt thành
drift trong một lượt.

| File | SHA-256 đầu = cuối lượt 2 |
| --- | --- |
| `docs/adr/ADR-0006-AI-Foundation.md` | `d5d156421248aef7dc440670fd3f19cbe9cfe327d8d0324b124086068e39bdc6` |
| `docs/database/ai/ai_knowledge_chunks.md` | `2dabaa27846428aa321d6f6a25961880665e2bdc1462ae45fe90f6cd5b648b1d` |
| `docs/quality/LF-Documentation-Conflicts.md` | `0eef31a98860e4810caf201ee0171e5c1fe30124653407ffba904cddfa611a09` |
| `docs/platform/LF-AI-Knowledge-Sync-Contract.md` | `e6060db3df3247d4016b14f5e022ecc422f9022baf7b4e3306d3e332f963e341` |
| `app/Services/AiKnowledgeIngestionService.php` | `a7b0b5d2c0e3da9d6eeb9f8ac338be23b61d960ed944a10029f28326843aa506` |
| `app/Services/AiKnowledgeSyncService.php` | `a09f2161d84237774e4bba44f2338d9d6752baf44d48e8dc7205bb73d735e71f` |
| `tests/Feature/AiKnowledgeSyncServiceTest.php` | `a93d4884848349de2f72cc8e5ebf8c8a5b57feb027a6e593ca5c0c82fa6208a4` |
| `docs/quality/LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md` | `4f4d6f55746812e261069557622a44b05091cd93046b49b527e992360cb0884c` |

Bảo toàn lượt 1: 29.594 byte đầu của báo cáo có SHA-256
`0a920e64216aa91b9b15c8f72a27ff6e0e1ed87f3c2fd3490e86104cbbef8158`,
giống file trước khi append lượt 2.

### Đối chiếu K3-R1..R6

| Finding | Trạng thái lượt 2 | Kiểm chứng của reviewer |
| --- | --- | --- |
| K3-R1 HIGH | CLOSED về nội dung thiết kế; Owner approval PENDING | ADR-0006:78–133 nêu rõ một CHECK, đủ 15 role, nullable, verbatim snapshot, không đổi identity/hash/data/version/table inventory; liên kết ADR-0019 v1.7, conflict và table proposal. Có ô approval để trống, giới hạn quyền viết migration với quyền apply |
| K3-R2 HIGH | CLOSED cho phân loại systemic/revision-local và previous-chain; yêu cầu không giữ dữ liệu gốc còn K3-R7 | Probe lại service thật, gồm 1142/1213/2006/1205, 22xxx/23xxx/1366; probe riêng register thật. Kết quả chi tiết bên dưới |
| K3-R3 MEDIUM | CLOSED ở mức kế hoạch trước migration | Preflight exact scope/count/predicate/nullable/set, enforcement/quyền; down mọi tenant/status + quiesce; DDL một statement, failure/recovery/ledger, rehearsal bảng lớn đã có |
| K3-R4 MEDIUM | CLOSED ở mức acceptance criteria | Contract chọn đúng table/field expression, exact 15 + guard superset; physical fresh + populated upgrade; INSERT/UPDATE, atomic revision, retry, preflight, up/down, race/timeout/failure và deployment criteria |
| K3-R5 LOW | CLOSED | Proposal viết lại đúng snapshot fidelity, UUID/hash không chứa role; paragraph dài 9; phạm vi không backfill gắn baseline enforcement; phân biệt consumer branch với tăng coverage |
| K3-R6 LOW | CLOSED | Sync Contract § Lỗi và retry và comment Sync:372–374 ghi best-effort, race hai worker và cache loss; không hứa atomic once-only |

**K3-R1.** Nội dung Proposed đủ để Owner duyệt thay đổi Foundation đã yêu cầu
ở lượt 1; chưa tự có hiệu lực vì review này. Câu “Approval authorizes a forward
migration to be written” tại ADR đọc cùng ba gate rõ ràng ở table proposal,
AGENTS Database Rule và resolution plan mới của DOC-CONFLICT-0040; không phải
miễn Database Docs/Architecture Review. Không có thay đổi domain ownership,
tenant/customer_id, lifecycle hay source of truth. Không yêu cầu ADR số mới.

**K3-R3/R4.** Kế hoạch đã đủ để cho implementer viết migration sau approval.
Các phát biểu “bảng giữ nguyên CHECK khi ALTER thất bại” là acceptance criteria
phải chứng minh, không phải kết quả engine đã được reviewer xác nhận. Không
mặc định thuật toán/lock; giới hạn thời gian, writer quiesce, disk/replica và
backup/recovery được yêu cầu trước apply. Thao tác ghi ledger thủ công sau DDL
hoàn tất chỉ thuộc recovery runbook có authorization và kiểm chứng schema;
không dùng nó bỏ qua migration verification. Không cần TRIGGER preflight cho
riêng CHECK swap. Implementation phải giữ literal semantics khi chuẩn hóa
biểu thức, không bỏ qua predicate lạ bằng parser quá dễ dãi; acceptance preflight
âm đã có để kiểm việc này trong migration review.

DOC-CONFLICT-0040 vẫn UNDER_REVIEW và resolution plan đã ghi ADR → review lượt
2 → Owner approval → migration → MariaDB verification → migration review →
apply có backup → đối soát. Giữ unresolved tới evidence sau apply là đúng.

### K3-R2 — probe độc lập lại đường Sync

Dùng `php` stdin, autoload hiện có; Container/ArrayStore/DB/Log mocks, hai owner
101/102, TenantContext giả 987654 và transcript revision tổng hợp. Gọi
`AiKnowledgeSyncService::syncForMedia(1)` **thật**, không gọi classifier thay
cho control flow. Mock ingest phát PDO errorInfo được bọc QueryException chứa
ba marker riêng cho SQL, binding và driver message. Với local failure, owner
đầu lỗi và owner sau thành công; với systemic, mọi attempt sẽ lỗi nếu bị gọi.

| SQLSTATE / driver | Kết quả tự chạy |
| --- | --- |
| `42000 / 1142` | RuntimeException thoát, attempts=1; không gọi owner thứ hai |
| `HY000 / 1142` (compatibility với probe lượt 1) | RuntimeException thoát, attempts=1 |
| `40001 / 1213` | RuntimeException thoát, attempts=1 |
| `HY000 / 2006` | RuntimeException thoát, attempts=1 |
| `HY000 / 1205` | RuntimeException thoát, attempts=1 |
| Thiếu SQLSTATE/driver | RuntimeException thoát, attempts=1; báo `unknown`, `0` |
| `23000 / 4025` | attempts=2, failed=1, ingested=1 |
| `23000 / 1062` | attempts=2, failed=1, ingested=1 |
| `23000 / 1048` | attempts=2, failed=1, ingested=1 |
| `22001 / 1406` | attempts=2, failed=1, ingested=1 |
| `22007 / 1366` | attempts=2, failed=1, ingested=1 |
| `HY000 / 1366` | attempts=2, failed=1, ingested=1 |

Tất cả systemic cases trả class RuntimeException, không phải QueryException;
message chỉ có SQLSTATE/mã driver, `(string)$exception` không có marker SQL,
binding hoặc driver message; `getPrevious() === null`. Local logs không có
marker và tiếp tục đúng owner kế tiếp. Đây là lỗi **đã thoát khỏi ingestion**;
probe không giả vờ chứng minh retry/rollback engine cho deadlock hay lock wait.

Đọc code Sync:357–376 và :450–469 xác nhận classification fail-closed cho các
lớp khác; không còn gom mọi lỗi vào failed rồi tiếp tục. Đường command bắt
Throwable, tăng tenant_errors và trả non-zero vẫn nguyên; không cần thay command
để nhận RuntimeException này. Tests mới của implementer đã có dataset và command
case tương ứng, nhưng reviewer **không lấy chúng làm test đã chạy của mình**.

### K3-R2 — probe riêng register()

Gọi `register()` hiện hành bằng reflection với một Media unit tổng hợp,
DB transaction mock thực thi callback, fluent query trả collection rỗng và
insert source ném lỗi. Không mở connection. Tự chạy:

* `42000/1142`, `40001/1213`, `HY000/2006`, `HY000/1205`, `23000/4025`,
  `22001/1406`, `22007/1366`, `HY000/1366`: **cùng object QueryException** đi
  lên, không lookup source cạnh tranh, không đổi thành registration_conflict.
* `UniqueConstraintViolationException` `23000/1062`: có đúng một lookup source
  cạnh tranh; fixture không có winner nên phát registration_conflict đúng nhánh.

Search file Ingestion xác nhận chỉ có import/catch
`UniqueConstraintViolationException` tại dòng 7/206, không còn catch
QueryException rộng. Probe này kiểm branching/propagation, không chứng minh
race insert thật hay semantics transaction thật. Phần đó vẫn thuộc closure
implementation verification.

### K3-R7 — MEDIUM: exception giữ dữ liệu gốc trong trace arguments

**Vị trí:** `AiKnowledgeSyncService.php:463–469`, lời gọi tại :363.

`systemicFailure(QueryException $exception)` tạo RuntimeException mới ngay
trong method đang nhận exception gốc. PHP CLI dùng trong review có
`zend.exception_ignore_args` rỗng/off. Trong cả sáu systemic cases ở probe service
thật, `getTrace()` của exception mới chứa một argument **chính object
QueryException gốc**. Object đó giữ `getSql()`, `getBindings()` và errorInfo
driver message. `json_encode($exception->getTrace())` đã chứa marker driver
message tổng hợp. Không cần previous chain để giữ reference này.

Probe đối chứng gọi method thật dưới hai cấu hình trong hai bước của cùng process
cho kết quả:

| Cấu hình | Raw QueryException reachable từ trace | `(string)` sạch | Previous NULL |
| --- | --- | --- | --- |
| `zend.exception_ignore_args=0` | Có | Có | Có |
| `zend.exception_ignore_args=1` | Không | Có | Có |

Đây là lý do chưa thể xác nhận tuyệt đối yêu cầu “exception thoát ra không chứa
SQL/bindings/message”. Chỉ message/string rendering/previous chain đã pass.
Không phát hiện deployment requirement hiện hành buộc ignore_args=1 trong
`docs/tech` hoặc `config` qua search.

**Phạm vi tác động đã kiểm:** Laravel
`DatabaseUuidFailedJobProvider.php:62` và `DatabaseFailedJobProvider.php:59`
dùng string conversion khi lưu exception; probe string sạch. Command chỉ in
class. Vì thế **không báo cáo một vụ rò SQL trong failed_jobs hiện hành**.
Finding nằm ở object/structured trace escape khi reporter/debugger serialize
arguments, và ở cam kết dữ liệu của exception. Không liên quan miền giá trị
CHECK hoặc DDL nên không ngăn gate architecture của migration.

**Đề xuất giao implementer:** không truyền QueryException gốc vào frame tạo
exception mới; chỉ truyền hai scalar đã làm sạch, hoặc redact tham số nhạy cảm
bằng cơ chế PHP được kiểm chứng. Có thể enforce ignore_args=1 cho toàn bộ
runtime như biện pháp bổ sung, nhưng không tự coi cấu hình local/prod đều đã
đúng. Thêm kiểm tra cả message, previous và structured trace với
ignore_args=0; không chỉ `(string)$exception`. Reviewer không vá.

### Verdict câu hỏi cập nhật

| Câu | Verdict lượt 2 | Cơ sở |
| --- | --- | --- |
| 1 | APPROVE, giữ lượt 1 | Không thay nguồn vocabulary/check baseline; không chạy lại physical inspection |
| 2 | APPROVE | K3-R5 đã sửa lập luận snapshot/identity |
| 3 | APPROVE, giữ lượt 1 | Không đổi identity/chunker/backfill trong proposal |
| 4 | APPROVE, giữ lượt 1 | Không thay consumer; proposal đã nói rõ tăng coverage |
| 5 | APPROVE cho ADR Proposed | Nội dung đủ, hiệu lực còn chờ Owner ký; không phải chấp thuận bỏ ADR |
| 6 | APPROVE cho kế hoạch | K3-R3 đã đủ; thực nghiệm DDL thuộc migration review |
| 7 | APPROVE cho kế hoạch test | K3-R4 đã có contract và physical gates |
| 8 | APPROVE cho acceptance criteria | Chưa tuyên bố implementation hoặc deploy pass |
| 9 | APPROVE WITH CHANGES | Frame giữ APPROVE; systemic/local flow và best-effort log đã đúng; K3-R7 MEDIUM còn mở cho exception object privacy |

Không còn finding mở chặn **thiết kế CHECK K3**. K3-R7 phải theo lane runtime
trước khi kết luận yêu cầu privacy của exception đã hoàn tất. Approval migration
không cấp waiver cho finding này.

### Lệnh, evidence và việc chưa kiểm ở lượt 2

| Lệnh/nhóm lệnh tự chạy | Kết quả |
| --- | --- |
| `shasum -a 256` tám file handoff, đầu và cuối | Khớp toàn bộ; không drift |
| `sed`, `rg`, `nl -ba` trên ADR, proposal, conflict, contract, hai service, test và queue failed-job providers | Nguồn trực tiếp cho đối chiếu trên; không dựa verdict implementer |
| `git status --short` | Working tree bàn giao có thay đổi trước review; không sửa/reset chúng |
| `php -r` đọc `zend.exception_ignore_args` | CLI mặc định off (giá trị rỗng) |
| `php` stdin probe Sync thật + dependency mocks | 12 cases: 6 systemic stop + 6 local continue; message/string/chain checks pass; trace retention phát hiện K3-R7 |
| `php` stdin probe register thật + transaction/query mocks | 8 non-unique propagate đúng object + 1 unique branch lookup rồi domain conflict |
| `php` stdin probe systemicFailure thật, ignore_args 0/1 | Xác nhận trace reference phụ thuộc cấu hình; không thay php.ini, chỉ setting trong process đã kết thúc |
| `php -l` hai service và test Sync | Cả ba không có syntax error |
| `rg` tìm exception_ignore_args/SensitiveParameter trong docs/tech, config, Sync | Không có match; exit 1 của search này không phải probe thất bại |
| `git diff --check`; kiểm riêng report cuối lượt | Không whitespace error |
| SHA-256/byte-prefix báo cáo trước và sau append | Lượt 1 giữ nguyên byte-for-byte |

Không chạy MariaDB vì các câu hỏi control flow/privacy đã kiểm được trực tiếp
không cần engine. Các SQLSTATE engine thực ghi trong brief là evidence của
implementer, **không được relabel thành reviewer xác nhận MariaDB 11.4**. Không
chạy full suite, mutation, integration, queue thực hay concurrency/database
transaction thật. Không tạo migration thử, instance DB hoặc bản sao vendor.
Không kết nối learnforge_db/XAMPP, không đọc secret, không gọi provider/network.

Audit Level giữ HIGH cho phạm vi architecture/retry; đây không phải full
implementation regression audit. Thay đổi duy nhất của reviewer trong lượt 2
là append mục này. **Architecture Review gate CHECK: PASS; Owner ADR và
Database Docs approval: PENDING; migration/apply: chưa thực hiện.**

---

## Lượt 3 — migration review, 2026-09-28

Reviewer: Codex, cùng reviewer độc lập của lượt 1–2.

Classification: Existing-Feature Change — Foundation CHECK migration.

Initial/Final Audit Level: HIGH. Audit Level Escalation: None.

### Kết luận lượt 3

**Migration verdict: REJECT / NOT APPLY-READY. Regression verdict: FAIL.**

Migration tại SHA `742b5dc3…` **chưa đủ điều kiện apply lên learnforge_db dev
local**, kể cả sau backup và authorization thông thường cho apply. Có một
finding mới **K3-R8 HIGH**: chuẩn hóa biểu thức làm thay đổi nội dung string
literal và chấp nhận CHECK lạ thay vì dừng trước DDL. Đã tái hiện trên MariaDB
11.4.12 bằng cả probe độc lập và test bổ sung trên schema đầy đủ.

Thiết kế mở rộng 15 role vẫn đúng. Owner approval ADR-0006 v1.0.6 và Database
Docs K3 đã có trong snapshot lượt 3; **Architecture Review gate vẫn PASS**.
Finding này thuộc implementation của preflight, không yêu cầu hủy approval
thiết kế. Implementer cần sửa normalizer và bổ sung regression rồi giao review
migration lại. Không dùng baseline test xanh hoặc drift PASS để bỏ qua finding.

Kết quả chính do reviewer tự chạy:

| Kiểm chứng | Kết quả |
| --- | --- |
| Integration K3 nguyên bản | 21 tests, 158 assertions, 0 failures/errors/skips |
| Unit vocabulary nguyên bản | 2 tests, 54 assertions, 0 failures/errors/skips |
| Probe bổ sung miền giá trị/down/snapshot | 2 tests, 166 assertions, PASS |
| CHECK literal lạ trên migration nguyên bản | 1 test, 1 failure: migration không từ chối trước DDL |
| Ba mutation được yêu cầu | Cả ba bị bắt bởi assertion cụ thể, không có lỗi harness |
| Chạy lại các test bắt mutation sau restore | 8 tests, 50 assertions, PASS |
| `schema:drift --connection=mysql` | PASS trên schema mới: 101 migrations, ledger không pending/missing, chỉ 48 INFO deferred tables |

Không apply vào dev, XAMPP hoặc production. K3-R7 runtime của lượt 2 không
được review lại trong lượt này và không được ngầm đóng.

### Snapshot, tính độc lập và môi trường

Đầu lượt đã đối chiếu đủ bảy hash với handoff; cuối lượt sau append kiểm lại
đều khớp, không drift. Reviewer chỉ thêm mục Lượt 3 vào báo cáo này; mọi probe,
mutation, bootstrap và fixture bổ sung đều ở bản sao riêng trong `/tmp`.
Không sửa source, test, migration hoặc canonical docs của workspace.

| File | SHA-256 đầu = cuối lượt 3 |
| --- | --- |
| `database/migrations/2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php` | `742b5dc3f428fa5451ab4f96d016c24579c3834b3d687a62fce6f6b7ba610f46` |
| `tests/Integration/AiKnowledgeSourceRoleMigrationMariaDbTest.php` | `2c2cce00184d2e539872eeb111d82337e6305836737c401c11b0e8406ba266ff` |
| `tests/Unit/KnowledgeSourceRoleVocabularyTest.php` | `f622f805ed1808439f90d420bee6356e0396de7a18599554dbe2b3bd9d89c5a6` |
| `docs/database/ai/ai_knowledge_chunks.md` | `8efe12a96d77f1778a656008b9bbf4de8ba0c3de05d1ab25f618b18497bbf4d8` |
| `docs/database/LF-SCHEMA-CONTRACT.json` | `d307583095087592e16db5f4c38c50dd9b4a9d7b8e35bc120e44040bc89049ae` |
| `docs/adr/ADR-0006-AI-Foundation.md` | `2651560d0109ff99dcbdba827da52dc1d8732e07cafcd133d283a4bfe83db43b` |
| `docs/quality/LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md` | `2dda538a246d8a27269e05787ae54c1e8e84691ec08aa684c0e25eaa8e2e8dd4` |

Bảo toàn lượt 1–2: 45.539 byte đầu của báo cáo có SHA-256
`9282bdf411d7b1a6f9f5f8b3517fe78a6f6df8ba53a1456d7aace2200faebf4d`,
giống file trước append lượt 3.

Môi trường tự dựng:

* MariaDB **11.4.12**, PHP **8.3.35**, PHPUnit **11.5.55**.
* Root tạm `/tmp/lf-k3-review-bXsIsu`; datadir và Unix socket nằm dưới root này.
  Server chạy `--no-defaults --skip-networking`; đã query xác nhận
  `@@skip_networking=1`. Database chỉ có tên task `lf_k3_review`, `lf_k3_probe`
  ngoài các system schemas của MariaDB.
* Bản sao dùng `rsync -aL`, loại `.env*`, `.git`, cached config, database SQLite,
  storage thật; kiểm toàn bản sao có **0 symlink**, vendor là bản copy vật lý.
  Cấu hình mới chỉ dành cho test, socket riêng, host localhost/port 0,
  DB_URL rỗng, cache/session array, provider Media fake. Không đọc secret hoặc
  dùng dữ liệu dev; fixture là dữ liệu tổng hợp.
* `innodb_flush_log_at_trx_commit=2` chỉ trên server dùng một lần để giảm I/O.
  Do đó đây không phải chứng nhận power-loss durability/crash recovery; race
  validation và MDL timeout đã thử thực, kill/crash server giữa DDL chưa thử.
* Sandbox ban đầu chặn Unix socket; đã chạy server/client với quyền công cụ
  được cấp cho đúng instance dùng một lần. Không chuyển qua TCP hoặc socket
  XAMPP để khắc phục.

### 1. Chạy test và đối chiếu JUnit

Lệnh baseline trong bản sao:

```sh
DB_CONNECTION=mysql DB_DATABASE=lf_k3_review DB_HOST=localhost DB_PORT=0 \
DB_USERNAME=root DB_PASSWORD='' DB_URL='' \
DB_SOCKET=/tmp/lf-k3-review-bXsIsu/db.sock \
php vendor/bin/phpunit \
  tests/Integration/AiKnowledgeSourceRoleMigrationMariaDbTest.php \
  tests/Unit/KnowledgeSourceRoleVocabularyTest.php \
  --log-junit /tmp/lf-k3-review-bXsIsu/evidence/baseline.xml
```

Baseline tự chạy `migrate:fresh` qua RefreshDatabase, không bypass migration.
Tổng thời gian **301,213 giây**, memory 56,50 MB; testcase đầu gồm migrate:fresh
mất 293,488 giây. Reviewer parse JUnit, đối chiếu class/file, tên method và
dataset, không chỉ dựa vào dòng “OK”.

Class Integration: `Tests\Integration\AiKnowledgeSourceRoleMigrationMariaDbTest`:

| Tên method trong JUnit | Dataset / số testcase | Assertions |
| --- | --- | --- |
| `test_the_migrated_schema_carries_exactly_the_contract_check` | 1 | 6 |
| `test_every_approved_role_and_null_is_written_and_anything_else_refused` | 1 | 29 |
| `test_a_new_role_revision_is_snapshotted_verbatim_and_a_retry_changes_nothing` | 1 | 6 |
| `test_an_unknown_role_rolls_back_the_whole_new_revision_and_keeps_the_old_one` | 1 | 6 |
| `test_up_refuses_before_any_ddl_when_the_check_is_already_wide` | 1 | 6 |
| `test_down_refuses_while_any_tenant_holds_a_new_role_in_any_status` | `active`, `stale`, `archived`, `failed`, `deletion_pending`, `deleted` — 6 | 36 |
| `test_up_refuses_an_unexpected_constraint_state` | `constraint missing`, `extra OR branch`, `NULL branch missing`, `NOT NULL instead of NULL`, `one role short`, `role duplicated` — 6 | 36 |
| `test_up_refuses_while_check_enforcement_is_off` | 1 | 8 |
| `test_up_down_up_keeps_every_row_and_the_narrow_check_is_enforced_in_between` | 1 | 11 |
| `test_a_row_written_after_the_count_fails_the_narrowing_and_the_wide_check_stays` | 1 | 8 |
| `test_a_lock_timeout_leaves_the_check_untouched` | 1 | 6 |

Class Unit: `Tests\Unit\KnowledgeSourceRoleVocabularyTest`:

| Tên method trong JUnit | Testcase | Assertions |
| --- | --- | --- |
| `test_knowledge_accepts_exactly_the_approved_fifteen_roles` | 1 | 19 |
| `test_knowledge_accepts_every_role_media_may_write` | 1 | 35 |

Tổng đúng **23 / 212**, tất cả 0 fail/error/skip. Đã kiểm CI
`.github/workflows/application-tests.yml:183` có Integration K3 trong job
`integration-mysql`; không suy từ cấu hình CI rằng remote CI đã chạy.

### 2. Đối chiếu acceptance criteria với migration và kiểm chứng

| Mục acceptance | Đọc implementation / kết quả tự kiểm |
| --- | --- |
| 15 role, NULL, INSERT/UPDATE, role lạ | WIDE đúng 15 và giữ nullable; baseline physical PASS. Test gốc UPDATE sáu role mới + NULL/figure, nên reviewer bổ sung UPDATE cả 15 + NULL: PASS |
| Collation không đổi | Migration không ALTER column/collation; baseline so chữ hoa role mới/cũ PASS trên utf8mb4_unicode_ci. Không suy rộng sang mọi custom collation |
| Snapshot và retry | Baseline PASS role/content/UUID/hash/locator/reading_order. Reviewer thêm bbox không rỗng, languages có locale NULL, offsets, metadata chunker version và retry: PASS |
| Atomic revision | Test unit lạ sau unit hợp lệ làm rollback revision mới, source/chunk cũ giữ nguyên: PASS |
| Preflight đúng constraint/tập/predicate/enforcement | assertCheckIs scope schema/table/name và count=1, check enforcement bật, exact sorted set và duplicate guard. Sáu trạng thái lạ gốc PASS, nhưng **FAIL literal backtick** — K3-R8 |
| Forward migration / lịch sử | Migration mới, narrow→wide, không UPDATE evidence. Historical Foundation file không phải đối tượng mutation; không bị reviewer sửa |
| Down tất cả tenant/status | Query count không tenant/status filter. Test gốc chỉ role geometry ở 6 status; reviewer bổ sung đủ sáu role mới × 7 status gồm pending: 42/42 bị từ chối trước DDL, row giữ nguyên |
| Up→down→up giữ dữ liệu | Test populated baseline với paragraph/figure/NULL PASS; narrow chặn image, wide nhận image; CHECK khôi phục đúng contract |
| Writer chen giữa count và ALTER | Connection thứ hai insert note sau count; một ALTER thất bại 4025, wide CHECK vẫn tồn tại: PASS |
| MDL timeout | Connection thứ hai giữ MDL, lock_wait_timeout=1; 1205, wide CHECK giữ nguyên: PASS |
| DDL xong, ledger thiếu | Test gọi up khi CHECK đã wide chứng minh refuse trước DDL. Chưa kill process ở đúng khoảng DDL/ledger; không gọi đó là crash test |
| Schema contract/drift | JSON/Table Indexes đã 15; physical drift trên 101 fresh migrations PASS, ledger không pending/missing |
| Quyền / bảng lớn / deployment | Chạy bằng root của instance dùng một lần; chưa kiểm restricted deployment account, size thật, replica, maintenance window, backup/restore hoặc dev apply. Các gate vận hành vẫn bắt buộc |

Hai test reviewer bổ sung chỉ nằm trong bản sao, tên JUnit:

* `test_reviewer_all_roles_update_and_every_new_role_status_blocks_down`:
  143 assertions, PASS (16 UPDATE + ma trận 42 trường hợp down và giữ evidence).
* `test_reviewer_nonempty_bbox_languages_offsets_and_version_survive_retry`:
  23 assertions, PASS. Languages gồm Latn/vi và Hang/NULL; bbox
  x=0.1, y=0.2, width=0.3, height=0.4.

Các lượt bổ sung/mutation dùng bootstrap riêng đặt
`RefreshDatabaseState::$migrated=true` **sau khi baseline migrate:fresh đã pass**,
để tái dùng schema thật, không tốn thêm 5 phút mỗi process. Không đổi file test
gốc để bỏ assertion. Không dùng bootstrap này cho baseline. Sau mutation đã
restore đúng hash migration, dọn fixture tổng hợp của mutation và chạy lại tám
case liên quan: PASS, 50 assertions.

### 3. K3-R8 — HIGH: normalizer sửa literal, preflight chấp nhận CHECK lạ

**Vị trí:** migration `nullableInList()`, dòng **138**:

```php
$normalized = strtolower(str_replace('`', '', $clause));
```

`str_replace` xóa backtick trong cả identifier lẫn string literal. Ví dụ CHECK
sau có 9 role nhưng **không phải** baseline đã duyệt:

```sql
CHECK (source_role IS NULL OR source_role IN
 ('para`graph','heading','list','table','figure','caption','header','footer','other'))
```

MariaDB trả CHECK_CLAUSE giữ nguyên backtick bên trong `'para`graph'`. Sau
normalization, migration nhìn thấy `'paragraph'`, sorted set khớp NARROW,
`assertCheckIs()` đi qua và `up()` thực hiện DDL đổi sang WIDE.

Reviewer dựng table tối thiểu trong **lf_k3_probe**, dùng chính migration
nguyên bản và query CHECK_CLAUSE thật; kiểm hành vi bằng INSERT trước/sau:

| Trạng thái | `paragraph` | literal có backtick | `PARAGRAPH` | `up()` |
| --- | --- | --- | --- | --- |
| CHECK baseline 9 role | Nhận | Từ chối | Nhận | APPLIED, đúng |
| CHECK lạ thay paragraph bằng literal trên | Từ chối | Nhận | Từ chối | **APPLIED, sai: phải refuse trước DDL** |
| CHECK thêm `AND 1=0` | Từ chối | Từ chối | Từ chối | REFUSED, đúng |

Sau up sai, `paragraph` được nhận: đây là thay đổi ngữ nghĩa thật, không phải
khác whitespace hoặc quoting vô hại. Probe `_binary` introducer cũng đã thử;
MariaDB lưu expression/semantics giống baseline trên fixture này nên **không
dùng trường hợp đó làm finding thứ hai**.

Đã tái hiện lại trên **schema đầy đủ lf_k3_review** bằng testcase bổ sung
`test_reviewer_literal_backtick_is_not_identifier_quoting`. Test cài CHECK lạ,
yêu cầu migration refuse, nhưng migration up hoàn tất. JUnit có **1 failure,
0 errors/skips**, thông báo chứa `up() must refuse this state.`. Test khôi phục
CHECK 15 đúng contract trong finally trước các bước drift/mutation.

**Tác động:** môi trường có CHECK lạ nhưng bảng rỗng hoặc các row chỉ dùng tám
role còn lại sẽ bị migration tự thay schema, trái gate không đoán/không chữa
drift. Với row mang literal lạ, ADD có thể fail validation, nhưng đó không phải
sự từ chối trước DDL và không chữa lỗ preflight. Hai phía up/down dùng cùng
parser nên remediation phải bảo vệ cả hai hướng. Không khẳng định dev hiện có
CHECK lạ — reviewer không kết nối dev.

**Đề xuất, không vá:** tách SQL token/literal trước normalization; chỉ bỏ quoting
được nhận dạng đúng quanh identifier `source_role`, bảo toàn literal contents.
Từ chối literal/escape/introducer không nằm trong grammar đã duyệt thay vì sửa
nó thành role hợp lệ. Test thêm backtick trong literal cho up và down, xác nhận
không phát ALTER và expression giữ nguyên; giữ các case trailing OR, NULL branch,
duplicates cùng test baseline hợp lệ. Kiểm literal semantics cũng khi đổi case,
whitespace hoặc charset introducer, không dựa vào regex sau một bước làm mất
thông tin. Sau remediation chạy lại K3 suite, adversarial cases, mutations và
drift trên MariaDB 11.4 dùng một lần.

### 4. Mutation verification

Chỉ sửa migration **trong bản sao**. Ba variant được lint trước khi chạy; mỗi
variant bắt đầu từ đúng bản gốc, không chồng mutation. Chọn test đã PASS trong
baseline. JUnit không có harness error hoặc skip.

| Mutation thực hiện | Test/dataset bắt | Bằng chứng |
| --- | --- | --- |
| Tách một ALTER thành DROP riêng và ADD riêng | `test_a_row_written_after_the_count_fails_the_narrowing_and_the_wide_check_stays` | 1 failure / 5 assertions: size CHECK thực tế **0**, expected 1. Writer note làm ADD narrow fail sau khi DROP đã commit |
| Bỏ `^`/`$` ở hai regex whole-expression; giữ literal regex | `test_up_refuses_an_unexpected_constraint_state`, dataset **extra OR branch** | 1 failure trong 6 cases / 35 assertions; up đã chạy thay vì refuse. Năm dataset khác vẫn pass; không nhận kết quả mutation rộng hơn của implementer là của reviewer |
| Xóa cả count và nhánh refusal của down | `test_down_refuses_while_any_tenant_holds_a_new_role_in_any_status`, dataset **active** | 1 failure / 3 assertions: phát QueryException `23000/4025` từ ALTER thay vì RuntimeException `Rollback refused: 1…`; đã đi vào DDL |

Sau cùng restore migration trong copy về
`742b5dc3f428fa5451ab4f96d016c24579c3834b3d687a62fce6f6b7ba610f46`.
Chạy lại race + sáu preflight datasets + down/active: **8 tests / 50 assertions,
0 failures/errors/skips**. Do đó mutation kill có đối chứng khôi phục, không chỉ
là thất bại do fixture hỏng từ mutation trước.

### 5. Physical schema drift

Chạy sau baseline và các probe bổ sung đã restore CHECK, **trước mutations**:

```sh
php artisan schema:drift --connection=mysql --format=json
```

Process dùng cùng DB environment/socket cô lập, target trong JSON là
`selected-read-only / mysql / localhost / port 0 / lf_k3_review`.
Kết quả `status=passed`, **101 migration files**, `pending=[]`,
`missing_source=[]`, 48 findings đều **INFO table.deferred**. Không có
BLOCKER/HIGH/MEDIUM/LOW. Đây là fresh physical schema của reviewer, không phải
schema dev. Drift kiểm schema đã migrate từ baseline chuẩn; nó không chứng minh
preflight xử lý được CHECK lạ, vì vậy không phủ định K3-R8.

### 6. Lệnh, bằng chứng và giới hạn

| Nhóm lệnh đã chạy | Kết quả/giới hạn |
| --- | --- |
| `shasum -a 256` bảy file đầu/cuối; byte-prefix hash báo cáo | Handoff giữ nguyên; lượt 1–2 không bị sửa |
| `cat`, `sed`, `rg`, `nl` migration/tests/ADR/table docs/config/RefreshDatabase/CI | Đọc implementation trực tiếp và đối chiếu acceptance, không dùng kết luận implementer thay kiểm chứng |
| `mariadbd --no-defaults --version` | 11.4.12-MariaDB |
| `mktemp`, `rsync -aL`; scan symlink | Bản sao riêng trong /tmp, 0 symlink |
| `mariadb-install-db --no-defaults`; `mariadbd --no-defaults ... --skip-networking` | Instance riêng; truy cập bằng socket explicit, không TCP |
| Baseline PHPUnit + `--log-junit` | 23/212 PASS; first fresh migration ~293,488s |
| PHP Capsule/Facade probe CHECK trên lf_k3_probe | Baseline hợp lệ apply; literal backtick lạ cũng apply — lỗi; extra AND bị từ chối |
| PHPUnit probes bổ sung | 2/166 PASS, 1 adversarial failure tái hiện K3-R8 trên full schema |
| Ba mutation qua bootstrap tái dùng schema | Cả ba bị bắt; migration copy restore đúng hash |
| `schema:drift --connection=mysql --format=json` | PASS, chỉ INFO |
| PHPUnit targeted sau restore | 8/50 PASS |
| `mariadb-admin --no-defaults --protocol=SOCKET --socket=... shutdown` | Exit 0, server process exit 0; log “Shutdown complete”; socket/pid file biến mất |
| Xóa root dùng một lần, kiểm tồn tại | Đã xóa toàn bộ bản sao, datadir, socket, probe, mutation và raw logs/JUnit trong /tmp sau khi tổng hợp evidence ở đây |
| Kiểm whitespace báo cáo | Không whitespace error |

Các lệnh setup lần đầu bị sandbox chặn socket hoặc dừng copy dư thừa không
được tính là lỗi sản phẩm. Không có auto-review rejection còn treo. Server test
cuối cùng được initialize xong trước khi nhận connection, query version và
skip_networking đã xác nhận môi trường đúng.

Hash raw evidence đã đọc trước khi dọn instance (raw files không còn sau cleanup):

| Evidence | SHA-256 |
| --- | --- |
| baseline JUnit | `f9667ba240ce0a2a84504148155507c6229195e2e44cbe02b53576c720ac9739` |
| additional JUnit | `9b75d0d8357830787aa1bc348c94313a18802a2f4a17959fd767415849f680c8` |
| adversarial JUnit | `3d054b8f268e1a78565244efc206276ce31c23f9e452e5d3f9cf382e81fad6d4` |
| split mutation JUnit | `0987102f62994447b7cd7e50e567f9b69d07af4b8d25b2d91c04f4b709afca81` |
| loose-regex mutation JUnit | `382bdc63349c84d74049ff66d19c41d545a482d33624fad3449fff32e4c2e2ea` |
| no-count mutation JUnit | `1bb6082e84863bfe83baf901a077f3d5adb883e44e13fa9a97a4284d71705057` |
| restored JUnit | `a504bc0129ffbab218c4e80dc25316f157d116888ce81d3f7f714bc78bfa4325` |
| physical drift JSON | `f5a9d71dc19a4f46b3cb044b936d34c69d5c878dcb7b52e598e7c18d1def9325` |

Chưa kiểm: full application suite/mutations ngoài K3, provider/vector store,
production-size ALTER algorithm/downtime/disk/replica, quyền account deploy,
power-loss/process-crash DDL/ledger, live dev preflight và backup/restore. Những
mục này không được báo PASS. Không cần dựng thêm instance để chứng minh finding
đã tái hiện hai lần. Không gọi provider thật, không kết nối learnforge_db
127.0.0.1:3307 hoặc XAMPP :3306.

### Điều kiện để đổi verdict apply

1. Implementer sửa **K3-R8 HIGH**, bổ sung regression chống normalization đổi
   literal; reviewer độc lập xác nhận preflight refuse trước DDL ở cả up/down.
2. K3 integration/unit, adversarial probes, ba mutation và physical drift tiếp
   tục pass/bắt mutation đúng trên MariaDB 11.4. Không sửa migration Foundation
   lịch sử hoặc evidence để làm test pass.
3. Sau migration review đạt apply-ready: thực hiện target preflight theo approved
   runbook, backup có đường phục hồi, chốt writer/lock/time budget phù hợp dev và
   **Owner cho phép apply riêng**. Bảng lớn cần rehearsal riêng, không lấy timing
   của fixture nhỏ thay performance evidence.

**Hiện tại: không apply.** Files changed bởi reviewer: chỉ append Lượt 3 vào
báo cáo này. Không để lại mutation hay test bổ sung trong workspace. Owner
approval thiết kế không phải waiver cho implementation preflight sai.

---

## Lượt 4 — Migration review lại sau K3-R8 (2026-09-28)

Reviewer: Codex. Phạm vi là migration K3 sau remediation tokenizer, không mở
lại approval thiết kế. Reviewer không viết hoặc sửa code/canonical docs AI Phần 2;
chỉ append báo cáo này. Mutation và probe tự viết chỉ nằm trong bản sao riêng.
Các kết luận dưới đây dựa trên lần chạy của reviewer, không lấy kết quả tự review
của implementer làm bằng chứng thay thế.

### Verdict

**APPROVE — APPLY-READY WITH DOCUMENTED RISKS cho learnforge_db dev local,
sau backup, target preflight và khi Owner cho phép apply riêng.**

**K3-R8 HIGH: CLOSED.** Tokenizer giữ nội dung literal; CHECK có literal backtick
hoặc literal chữ hoa không còn bị sửa thành vocabulary chuẩn. Reviewer xác nhận
refuse trước DDL ở cả `up()` và `down()`. Không phát hiện finding mới chặn migration
trong phạm vi lượt này. Ba mutation nguy hiểm đều bị bắt; baseline, kiểm tra sau
phục hồi và physical drift pass.

Architecture Review gate lượt 2 và Owner approval ADR/Database Docs vẫn có hiệu
lực. Verdict REJECT lượt 3 được thay thế **cho bản migration đúng snapshot bên
dưới**; nội dung và bằng chứng lượt 1–3 giữ nguyên. Approval này không đóng hay
waive K3-R7 thuộc lane runtime/phạm vi phụ, không xác nhận production readiness,
và không phải lệnh apply. Reviewer không kết nối hoặc thay đổi dev database.

### 1. Snapshot đầu/cuối và môi trường

Cả tám file được hash lúc bắt đầu và cuối lượt; mọi hash đều khớp handoff,
không có drift. Cột hash dưới đây là giá trị của cả hai lần:

| File | SHA-256 đầu = cuối |
| --- | --- |
| `database/migrations/2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php` | `ace102dbe617b0ace302eca11365df3e68eda470936511807804d5b4024a4cc6` |
| `tests/Integration/AiKnowledgeSourceRoleMigrationMariaDbTest.php` | `dd77264590424320e8d7b40cd6b3006922f6f1be9b91c66398c32b2b47b9d096` |
| `tests/Unit/KnowledgeSourceRoleVocabularyTest.php` | `f622f805ed1808439f90d420bee6356e0396de7a18599554dbe2b3bd9d89c5a6` |
| `tests/Unit/KnowledgeSourceRoleCheckParserTest.php` | `42ff88c1c6388584998b1672fb63270dedd4e9d8c1efefdcf81f2232c15aac0d` |
| `docs/database/ai/ai_knowledge_chunks.md` | `8efe12a96d77f1778a656008b9bbf4de8ba0c3de05d1ab25f618b18497bbf4d8` |
| `docs/database/LF-SCHEMA-CONTRACT.json` | `d307583095087592e16db5f4c38c50dd9b4a9d7b8e35bc120e44040bc89049ae` |
| `docs/adr/ADR-0006-AI-Foundation.md` | `2651560d0109ff99dcbdba827da52dc1d8732e07cafcd133d283a4bfe83db43b` |
| `docs/quality/LF-AI-Knowledge-Source-Role-Alignment-Reviewer-Brief.md` | `9e577027ec054af41f46955bb7735df58bb79bd5aacbb517b999adb4e7b7e8df` |

Báo cáo trước append: **66.731 bytes**, SHA-256
`92f358dd5a35b5228d8a5cae6e84086149eef9cda13a7af9cb0b0630c7173904`.
Prefix đó được bảo toàn byte-for-byte.

Môi trường reviewer: MariaDB **11.4.12**, PHP **8.3.35**, PHPUnit **11.5.55**.
Root dùng một lần `/tmp/lf-k3-r4-GAAQqW`; bản sao `app` chứa vendor thật,
scan có **0 symlink**. Không copy `.env` thật; cấu hình test tổng hợp chỉ trỏ
socket riêng, database `lf_k3_r4` và `lf_k3_r4_probe`, provider fake.
Server được initialize/start bằng `--no-defaults`, có `--skip-networking`;
query xác nhận `@@skip_networking=1`. Mọi connection đều qua socket explicit.
Không kết nối `learnforge_db` tại 127.0.0.1:3307 hoặc XAMPP :3306, không gọi
provider thật, không thêm secret.

### 2. Review tokenizer và probe độc lập — APPROVE

`nullableInList()` nay tách identifier, keyword, punctuation và literal trước
khi match hai dạng biểu thức lưu trong metadata. Chỉ identifier được bỏ quoting
của identifier; keyword được hạ chữ thường. Nội dung literal không bị hạ chữ
thường hoặc xóa backtick. Literal có escape được đánh dấu riêng và không được
grammar nhận; introducer chỉ được nhận theo dạng MySQL đã định nghĩa. Match phải
tiêu thụ toàn bộ token, từ chối role trùng. Sau parse, `assertConstraint()` còn
so sánh **chính xác** tập role với NARROW hoặc WIDE tương ứng chiều migration.

Reviewer đọc lại đường thực thi: preflight kiểm đúng constraint/table/schema,
enforcement MariaDB, vocabulary; `up()`/`down()` dùng một ALTER chứa cả DROP/ADD;
`down()` đếm role mới không lọc tenant hoặc status trước khi ALTER. Remediation
không nới các gate này và không sửa Foundation migration lịch sử.

Probe vật lý tự viết gọi migration thật trên bảng tối thiểu trong
`lf_k3_r4_probe`, lấy CHECK_CLAUSE thực từ MariaDB. Hook `beforeExecuting` đếm
ALTER trong lúc gọi migration; so sánh CHECK trước/sau. **36 lượt = 18 trường
hợp × hai chiều**, mọi kết quả đúng kỳ vọng, không có setup error:

| Nhóm CHECK đầu vào, thử ở cả up/down | Kết quả thực |
| --- | --- |
| Literal chứa backtick `para` + backtick + `graph`; `PARAGRAPH` | REFUSED; 0 ALTER; CHECK giữ nguyên — tái kiểm trực tiếp K3-R8 và lỗi hạ chữ thường |
| Literal doubled quote, backslash, escaped quote | REFUSED; 0 ALTER; CHECK giữ nguyên |
| Literal thêm space cuối, newline cuối, U+200B, chữ a Cyrillic U+0430, `parágraph` | REFUSED; 0 ALTER; CHECK giữ nguyên |
| Literal có `COLLATE utf8mb4_bin`; biểu thức `concat('para','graph')` | REFUSED; 0 ALTER; CHECK giữ nguyên |
| Baseline; `_binary'paragraph'`; `_latin1'paragraph'`; `N'paragraph'`; hex `0x706172616772617068`; string quote kép | MariaDB lưu CHECK_CLAUSE **byte-equal baseline**; APPLIED với đúng 1 ALTER |

Tổng cộng **24 lượt refuse**, **12 lượt apply baseline-equivalent**. Nhóm cuối
không phải tokenizer tự sửa literal: server đã lưu biểu thức đúng baseline,
được probe đối chiếu trước khi gọi migration. Vì vậy không đánh đồng cú pháp
SQL đầu vào khác nhau với CHECK metadata lạ bị chấp nhận. Dạng MySQL có
introducer còn được unit test ở lớp parser; chưa thử trên MySQL server thật.

Probe parser bổ sung **780 trường hợp**: chèn từng byte 0–255 tại ba vị trí
trong `paragraph` (768), thêm 5 literal Unicode và 7 biến thể quoting,
identifier, comment, malformed expression, introducer hoặc keyword Unicode.
Không trường hợp literal/biểu thức khác nào bị biến thành tập baseline:
`unexpected_baseline_acceptance=[]`.

Giới hạn quan trọng của kết quả parser: đây là kiểm tra gate hoàn chỉnh bằng
so sánh tập role, không khẳng định mọi literal bất thường đều trả `null` ngay
tại parser. Ví dụ neo `$` của regex literal có thể đứng trước newline cuối;
newline vẫn được giữ trong giá trị, nên exact vocabulary comparison từ chối.
Probe MariaDB có newline xác nhận không có ALTER. Không có bypass ở đây và
không cần sửa code để đạt điều kiện review. Tập probe hữu hạn không phải chứng
minh hình thức cho mọi SQL syntax hoặc SQL mode.

### 3. PHPUnit và đối chiếu JUnit — APPROVE

Reviewer tự chạy ba file yêu cầu trên bản sao, với connection mysql trỏ socket
riêng. Lần baseline dùng `migrate:fresh` thực qua test bootstrap, không bỏ qua
migration. Lệnh chính:

```sh
php vendor/bin/phpunit \
  tests/Integration/AiKnowledgeSourceRoleMigrationMariaDbTest.php \
  tests/Unit/KnowledgeSourceRoleCheckParserTest.php \
  tests/Unit/KnowledgeSourceRoleVocabularyTest.php \
  --log-junit /tmp/lf-k3-r4-GAAQqW/evidence/baseline.xml
```

Environment explicit: `DB_CONNECTION=mysql`, `DB_DATABASE=lf_k3_r4`,
`DB_HOST=localhost`, `DB_PORT=0`, `DB_USERNAME=root`, password/DB_URL rỗng,
`DB_SOCKET=/tmp/lf-k3-r4-GAAQqW/db.sock`.

**51 tests, 260 assertions, 0 failure/error/skip**, tổng thời gian CLI
**05:25.450**. Reviewer parse JUnit để đối chiếu tên test và dataset, không
chỉ dựa vào dòng tổng kết CLI:

| Suite | Tests / assertions | Tên và coverage xác nhận trong JUnit |
| --- | --- | --- |
| `AiKnowledgeSourceRoleMigrationMariaDbTest` | 25 / 182 | Exact contract CHECK; mọi approved role + NULL và role lạ; snapshot/retry; rollback toàn revision lạ; already-wide; down evidence đủ sáu status; tám dataset unexpected CHECK của up; hai dataset unexpected CHECK của down; enforcement off; up/down/up giữ row; writer chen sau count; lock timeout |
| `KnowledgeSourceRoleCheckParserTest` | 24 / 24 | `test_the_stored_forms_of_the_baseline_are_recognised`: 4 dataset; `test_any_other_constraint_is_refused`: 20 dataset |
| `KnowledgeSourceRoleVocabularyTest` | 2 / 54 | `test_knowledge_accepts_exactly_the_approved_fifteen_roles`; `test_knowledge_accepts_every_role_media_may_write` |

Các dataset regression đã chạy thực:

* `test_up_refuses_an_unexpected_constraint_state`: constraint missing, extra OR
  branch, NULL branch missing, NOT NULL instead of NULL, one role short, role
  duplicated, **backtick inside a literal**, **upper-case literal**.
* `test_down_refuses_an_unexpected_constraint_state`: **backtick inside a literal**,
  extra OR branch.
* `test_down_refuses_while_any_tenant_holds_a_new_role_in_any_status`: active,
  stale, archived, failed, deletion_pending, deleted.
* Parser valid: MariaDB as stored; MariaDB spacing/keyword case; unquoted
  identifier; MySQL as stored. Parser invalid: backtick/doubled quote/backslash
  escape, uppercase/space literal, hai introducer sai dạng, escaped identifier,
  cột khác, extra OR/AND, thiếu NULL, IS NOT NULL, NOT IN, duplicate role, empty
  list, trailing comma, hai unterminated quote và MySQL thiếu ngoặc.

Sau mutation, migration bản sao được restore đúng hash `ace102dbe…`. Dọn fixture
mutation chỉ trong database dùng một lần và chạy lại **toàn bộ 51 tests / 260
assertions: PASS, 0 failure/error/skip**, 10,105 giây. Lần kiểm phục hồi dùng
bootstrap tái sử dụng schema đã dựng mới thành công, không claim chạy lại
`migrate:fresh` lần thứ hai.

### 4. Mutation và physical drift — APPROVE

Mutation chỉ áp dụng vào migration trong bản sao. Mỗi biến thể qua PHP lint;
bootstrap mutation tái dùng schema sau baseline để tránh lặp fresh migration
khoảng năm phút. Các test lỗi dưới đây đều là assertion failure, **0 harness
error**, exit 1 đúng kỳ vọng:

| Mutation | Kiểm chứng của reviewer |
| --- | --- |
| Khôi phục normalizer cũ | Parser: 24 tests / 24 assertions, 4 failures: backtick literal, uppercase literal, introducer MariaDB, introducer khác MySQL. Integration targeted: 10 tests / 57 assertions, 3 failures: up backtick, up uppercase, down backtick. K3-R8 thực sự bị suite phát hiện |
| Tách DROP và ADD thành hai statement | `test_a_row_written_after_the_count_fails_the_narrowing_and_the_wide_check_stays`: 1 test / 5 assertions, 1 failure; CHECK thực tế có size 0 thay vì 1 sau ADD thất bại. Chứng minh assertion phát hiện cửa sổ mất CHECK |
| Bỏ count/refusal của down | Dataset active: 1 test / 3 assertions, 1 failure; nhận SQLSTATE 23000 / 4025 khi ALTER thay vì thông báo `Rollback refused: 1 ai_knowledge_chunks row(s)`. Test phân biệt refuse sớm với chạy DDL rồi thất bại |

`php artisan schema:drift --connection=mysql --format=json` chạy trên schema
fresh của baseline, trước mutation, trả exit 0 / **status=passed**:
**101 migration files**, `pending=[]`, `missing_source=[]`, **48 INFO
table.deferred**, không BLOCKER/HIGH/MEDIUM/LOW. Đây là physical drift với
database `lf_k3_r4`, không phải docs-only drift hoặc dev schema. Drift pass
không thay thế probe CHECK lạ; hai loại bằng chứng đều đã chạy ở lượt này.

### 5. Bằng chứng, cleanup và điều kiện apply

Hash raw evidence reviewer đã đọc/đối chiếu trước khi xóa thư mục dùng một lần:

| Evidence | SHA-256 |
| --- | --- |
| Baseline JUnit | `3b4084083a0db5fa4238051bfc752f1c811b054330eb93662df813048756b19c` |
| Physical probe JSONL | `b0fdc7d476cd18ce59724f4feb95a1336b4e8e4b733ed683e6be5f4f284b877b` |
| Parser probe | `417ab94f0536bda88e6f7e47b075a5e6b45e678ab98ca2bd475a3bdfdd58962f` |
| Old-normalizer parser JUnit | `4af8a6dd1866d7ef95f4474e2a0f2ea2fb74fb199f30eec3f46f550c8e933775` |
| Old-normalizer physical JUnit | `c72c824a0bb9c262e0ca62b9dc8762d9054ea94d3a6150ab35ba9c6916e486ee` |
| Split mutation JUnit | `ca35491ba0eb1c1bf9a6b13b01055e4359f8d0fb8da8e012bb66887a2ee56ae0` |
| No-count mutation JUnit | `d6cab4cc42c79043fe47831af5868befae820a5590fad6f0d178f9cc7c79713e` |
| Restored JUnit | `379a8272cfc542a3623098587c9c1c80564aec2ca81b25436d0bef627bd6c805` |
| Physical drift JSON | `b8ac70bd1c0ba0ddbe230e434ff2525ff0ddf033728124d9edc9b1d600119c08` |

Cleanup đã hoàn tất: `mariadb-admin --no-defaults --protocol=SOCKET
--socket=... shutdown` exit 0; server process exit 0; log có `Shutdown complete`;
socket và pid file biến mất. Đã xóa toàn bộ `/tmp/lf-k3-r4-GAAQqW`, gồm bản sao,
vendor, datadir, probes, mutations và raw JUnit/logs; kiểm root không còn tồn tại.
Workspace chỉ append báo cáo này, không để lại code/test/migration sửa đổi.

Điều kiện vận hành vẫn phải thực hiện trước khi apply dev:

1. Backup có đường phục hồi; target preflight theo approved runbook xác nhận
   constraint/enforcement/ledger/quyền phù hợp, không bỏ gate nếu gặp CHECK lạ.
2. Chốt writer handling, lock/time budget và cách xử lý thất bại/ledger theo
   Database Docs K3. Với bảng lớn cần rehearsal riêng.
3. **Owner cho phép apply riêng.** Reviewer chưa thực hiện backup, target
   preflight hoặc apply lên `learnforge_db`.

Chưa kiểm full application suite, account deploy hạn chế quyền, production-size
ALTER/disk/replica, MySQL server thật hoặc crash/power-loss recovery. Instance
review dùng `innodb_flush_log_at_trx_commit=2`; test writer race và lock timeout
không phải bằng chứng durability khi mất điện. Những giới hạn này không chặn
review migration dev trong phạm vi đã duyệt, nhưng không được diễn giải thành
PASS cho các môi trường hoặc failure mode chưa thử.
