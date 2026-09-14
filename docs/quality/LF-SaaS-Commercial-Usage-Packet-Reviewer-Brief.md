# SaaS Commercial + Usage Packet — Reviewer Brief

Version: 1.4

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-14

Document Path: quality/LF-SaaS-Commercial-Usage-Packet-Reviewer-Brief.md

---

# Vì sao có brief này

Brief này là công cụ review tùy chọn cho packet SaaS của Bước 4. Theo quyết
định Owner ngày 2026-09-12 bên dưới, thiếu review hoặc PASS độc lập **không còn
là điều kiện chặn** đóng tài liệu, migration hay đóng Bước 4. Bốn hồ sơ hiện
Frozen/Implemented; bằng chứng mới nằm trong artifact Bước 4. Review vẫn tùy
chọn và không phải điều kiện kích hoạt AI thật.

Không phải yêu cầu sửa. Reviewer **không** vá lỗi mình tìm ra; mọi finding được
giao lại cho implementer.

---

# Owner confirmation — 2026-09-12

**Quyết định mới, thay thế điều kiện review trong trình tự trước:** Owner yêu
cầu "loại bỏ điều kiện phải review độc lập mới đóng, review hay không là quyền
của tôi". Phạm vi: Bước 4 và packet bốn bảng SaaS trong brief này. Review có
thực hiện hay không, bởi ai và thời điểm nào do Owner quyết định; không được
tự đặt lại yêu cầu PASS độc lập để chặn công việc.

Đây là ngoại lệ do Owner chỉ định cho yêu cầu review bắt buộc của packet,
không sửa quy trình toàn repository. Ghi nhận là **Owner waiver**, không phải
review PASS.

### Điều kiện `AGENTS.md` nào được miễn trừ

Nói chính xác điều được miễn, để người đọc sau không nhầm "đã miễn trừ" thành
"đã đạt". `AGENTS.md` § Database Rule: *"Không tạo migration trước khi: Database
Docs approved / ADR approved nếu thay đổi là Foundation / Architecture Review
passed."*

| Điều kiện | Trạng thái 2026-09-12 |
| --- | --- |
| Database Docs approved | **Đạt** — bốn hồ sơ Frozen |
| ADR approved (Foundation) | **Đạt** — ADR-0009 Frozen |
| Architecture Review passed | **ĐƯỢC MIỄN TRỪ** — không có artifact review độc lập nào tồn tại |

`database/migrations/2026_09_12_000100_create_saas_usage_quota_packet.php` được
tạo dưới miễn trừ này, không phải sau khi điều kiện thứ ba được đáp ứng.

Miễn trừ do **Owner** đưa ra và chỉ Owner mới có thẩm quyền đó. Implementer
không miễn trừ được `AGENTS.md`, và không có quyết định nào của implementer nằm
sau miễn trừ này. Ai muốn khôi phục điều kiện thứ ba thì
[brief Q1–Q11](#câu-hỏi-bắt-buộc-trả-lời) vẫn dùng được nguyên trạng. Approval cũ không bị mở rộng; chưa triển khai/kiểm thử thì không
được ghi thành đã hoàn thành. Phê duyệt thiết kế/ADR, bảo vệ tenant, tính đúng
đắn dữ liệu và các kiểm thử kỹ thuật vẫn phải được đáp ứng.

| Thứ tự | Công việc / bằng chứng hoàn tất | Trạng thái |
| --- | --- | --- |
| 1 | Owner commit packet và ghi SHA để truy vết | Theo quyền commit của Owner; không phải gate review |
| 2 | Review Q1–Q11 nếu Owner yêu cầu | Tùy chọn theo waiver; **chưa thực hiện** |
| 3 | Ghi nhận phê duyệt Owner đúng bản thiết kế, chuyển bốn hồ sơ Approved/Frozen | Đã ghi theo yêu cầu thực hiện 2026-09-12 |
| 4 | Tạo migration theo contract; kiểm constraints và fail-closed rollback trên database test MariaDB 11.4 | Đã thực hiện; chưa apply database ứng dụng |
| 5 | Hoàn thiện store: reserve, settlement, retry, reconciliation; kiểm concurrency hai connection | PASS trên MariaDB 11.4.12, không dùng AI thật |
| 6 | Tổng hợp bằng chứng, đóng Bước 4 backend | Implemented; xem artifact closure evidence, không suy ra full suite ứng dụng xanh |

Nếu có thay đổi logic/schema, phải ghi nhận và phê duyệt đúng phạm vi;
Owner quyết định có review lại hay không. Waiver không phê duyệt trước các
amendment chưa xác định.
Việc apply migration lên database đang sử dụng phải được phân biệt với chạy
migration trên database test; bảng trên không phải bằng chứng đã apply.

**AI thật không phải điều kiện đóng Bước 4.** Không cần frontend chat, API key,
provider activation hoặc người dùng thật. Dùng provider giả lập/bằng chứng
kiểm thử có kiểm soát để kiểm store và reconciliation; test concurrency phải
dùng store thật trên database test, không chỉ fake ledger. Việc kích hoạt
provider sau này vẫn theo ADR-0018, nằm ngoài phạm vi này.

---

# Tính độc lập nếu Owner chọn review độc lập

Các yêu cầu dưới đây chỉ áp dụng khi Owner chọn loại review **độc lập**;
không phải điều kiện để được tiếp tục triển khai hoặc đóng Bước 4.

* Reviewer **không được** là người đã viết packet hoặc đã thực hiện remediation.
  Cụ thể: tác nhân đã viết `AiProviderExecutionGate`,
  `DatabaseUsageQuotaReserver`, bốn hồ sơ bảng, và
  `LF-AI-Provider-Execution-Gate-Implementation-Review.md` **không đủ tư cách**.
* Không dựa vào verdict hoặc báo cáo trước đó. Mọi số liệu trong tài liệu hiện
  có đều do implementer tự chạy (xem § Bằng chứng hiện có) và phải được kiểm
  chứng lại, không được thừa nhận.
* Trong lượt review: **không sửa** ADR, contract, database docs, schema,
  migration hay code. Chỉ tạo/cập nhật review artifact trong `docs/quality/`.
* Không ghi review artifact vào `docs/platform/`, `docs/database/`,
  `docs/governance/` hoặc `docs/core/`.
* Không gọi provider bên ngoài, không tạo embeddings, không ghi Qdrant.

---

# Neo review

**Commit SHA:** `________________` *(Owner điền trước khi giao)*

Bắt buộc neo vào SHA, **không** vào working tree. Tại thời điểm soạn brief, cây
làm việc có 29 file thay đổi trong đó **18 file untracked**. `git diff HEAD`
không nhìn thấy file untracked — đây chính là lý do một reviewer trước đó đã đọc
nhầm trạng thái cũ và kết luận trên code không tồn tại.

Lệnh reviewer nên dùng để thấy đúng toàn bộ phạm vi:

```bash
git show --stat <SHA>
```

---

# Phạm vi

## TRONG phạm vi

Bốn hồ sơ bảng và phần triển khai store tương ứng:

| Hồ sơ | Trạng thái hiện tại |
| --- | --- |
| `docs/database/saas-commercial/saas_entitlements.md` | v1.1, Frozen, Implemented |
| `docs/database/saas-commercial/saas_usage_reservations.md` | v1.0, Frozen, Implemented |
| `docs/database/saas-usage/saas_usage_events.md` | v1.1, Frozen, Implemented |
| `docs/database/saas-usage/saas_usage_counters.md` | v1.1, Frozen, Implemented |

Code:

* `app/Services/Ai/DatabaseUsageQuotaReserver.php` — reservation store
* `app/Services/Ai/DatabaseCommercialEntitlements.php` — entitlement resolver,
  chia sẻ `effectiveQuery()` với store
* `app/Contracts/Ai/{UsageQuotaReserver,CommercialEntitlements}.php`
* `app/Support/Ai/QuotaReservationHandle.php`
* `tests/Feature/DatabaseUsageQuotaReserverTest.php`
* Phần gate tiêu thụ reservation: `app/Services/AiProviderExecutionGate.php`
  (bước 4 và nhánh settlement), `tests/Feature/AiProviderExecutionGateTest.php`

ADR nền: `docs/adr/ADR-0009-SaaS-Usage-Foundation.md`,
`docs/adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md`.

## NGOÀI phạm vi

Cây làm việc đang chứa cả Bước 5, **không** thuộc lượt review này:

* `app/Services/AiEmbeddingService.php`, `app/Services/AiKnowledgeRetrievalService.php`
* `app/Services/Ai/{QdrantVectorStore,EmbeddingProviderAdapter,UnavailableEmbeddingProvider,UnavailableVectorStore}.php`
* `app/Contracts/Ai/{EmbeddingProvider,VectorStore}.php`
* `app/Support/Ai/{VectorPoint,EmbeddingWorkItem}.php`
* `app/Console/Commands/AiKnowledgePrepare.php`
* `tests/Feature/{AiEmbeddingServiceTest,AiKnowledgeRetrievalServiceTest}.php`
* `docs/quality/LF-AI-Embedding-Qdrant-Implementation-Review.md`

Bước 5 sẽ có lượt review riêng. Nếu reviewer thấy lỗi ở đó, ghi thành finding
phụ lục chứ đừng để nó ảnh hưởng verdict của packet.

---

# Quyết định đã chốt — kiểm việc triển khai, đừng lật lại

Reviewer kiểm xem code/doc có **thực hiện đúng** những quyết định này không, chứ
không tranh luận lại chúng:

* **OD-1 (2026-09-09)** — Commercial sở hữu reservation ledger. Không domain nào
  khác được ghi `saas_usage_reservations`.
* **Thứ tự gate 1→5 giữ nguyên văn contract**, kể cả việc reserve quota (bước 4)
  trước khi đánh giá safety (bước 5). Hệ quả là refusal ở bước 5 xảy ra khi hold
  đã tồn tại, nên gate trả quota trên nhánh đó. Đổi thứ tự để né việc release là
  **sai**, vì sẽ khiến implementation và contract nói khác nhau.
* **Mã lỗi đóng**: blocked `AI_APPROVAL_REQUIRED`, `AI_QUOTA_EXCEEDED`,
  `AI_SAFETY_BLOCKED`; failed `AI_PROVIDER_CALL_FAILED`, `AI_QUOTA_COMMIT_FAILED`,
  `AI_ADAPTER_MISMATCH`. Không mã mới. Nếu contract chưa đủ cho một tình huống →
  báo OWNER DECISION, không tự chế.
* **`usage_type` không nằm trong capacity predicate** — các metric dùng chung
  feature/unit phải dùng chung hạn mức. Đây là quyết định có chủ ý.

---

# Câu hỏi bắt buộc trả lời

Mỗi câu cần verdict **PASS / FAIL / OWNER DECISION** kèm bằng chứng cụ thể (số
dòng, output lệnh, hoặc kịch bản tái lập). "Đọc thấy hợp lý" không tính.

**Q1 — Tenant isolation.** Mọi FK liên bảng có phải composite `(child_id,
customer_id) → parent(id, customer_id)` không, và mọi bảng cha có `UNIQUE (id,
customer_id)` không? Có đường nào để một row tenant A tham chiếu row tenant B?

**Q2 — Máy trạng thái 8 status.** `reserved`, `executing`, `settling`,
`committed`, `committed_over_limit`, `released`, `expired`, `reconciled_released`.
Hồ sơ có liệt kê **từng** transition hợp lệ theo tên không? Đặc biệt kiểm có
đường từ `executing` tới một trạng thái terminal không — thiếu đường này từng là
finding P1-2.

**Q3 — Công thức capacity.** Những status nào bị tính vào hạn mức? Đối chiếu
hồ sơ với `DatabaseUsageQuotaReserver::available()` và các hằng `HELD`,
`CONSUMED`, `SETTLED`, `CLOSED_UNUSED`. Cảnh báo: **việc gộp nhóm status thay vì
liệt kê từng cái đã phá thiết kế này hai lần** (P1-1 công thức capacity, P2-4
quy tắc retry "non-terminal"). Kiểm xem còn chỗ nào gộp nhóm không.

**Q4 — Idempotency và double-billing.** Một attempt có thể sinh hai hold không?
Hai commit không? Kiểm UNIQUE key trên định danh attempt, và xác nhận
`period_key` **không** nằm trong đó — nếu có, một attempt vắt qua ranh giới kỳ sẽ
tạo hold thứ hai.

**Q5 — Bẫy timestamp ngầm.** `explicit_defaults_for_timestamp` là **0** trên
XAMPP MariaDB 10.4.21 (môi trường triển khai) và **1** trên MariaDB 11.4 (CI).
Cùng một migration cho ra schema khác nhau, và `schema:drift --fresh` không phát
hiện được. Schema của bốn bảng có độc lập với cấu hình này không? Kiểm **cả bốn**
— lần vá trước sót `saas_entitlements` (finding P0-1).

**Q6 — Lease và biên kỳ.** `lease_expires_at` có bị chặn trên bởi
`max_lease_expires_at` và bởi cuối kỳ không, **cả lúc khởi tạo lẫn lúc gia hạn**?
Đây là một trong ba bản vá mới nhất.

**Q7 — Múi giờ.** `DatabaseUsageQuotaReserver` ghi thời điểm bằng `now()->utc()`,
trong khi biên kỳ lấy theo `quota_timezone` của entitlement. Phép so sánh
`->min($maxLease)` dùng thời điểm tuyệt đối nên **có vẻ** đúng — hãy tự xác nhận
với một tenant **không** ở UTC, vì đây là loại lỗi chỉ lộ ra ở đúng điều kiện đó.
Cũng kiểm xem có nơi nào trong cùng bảng ghi thời gian không qua class này không:
trộn múi giờ trong một bảng sẽ làm mọi phép so sánh sai âm thầm.

**Q8 — Ranh giới provider.** Sau khi hold đã `executing`/`settling`, có đường nào
`release()` được gọi không? Hồ sơ có nói rõ chỉ provider-aware reconciliation mới
được kết thúc một hold đã vượt ranh giới không?

**Q9 — Reconciliation.** Store đã có receipt reader injectable: unknown giữ
hold, zero giải phóng qua reconciliation, positive settle đầy đủ. Kiểm binding
tenant/attempt/metric, thời điểm receipt, digest provenance và transaction.
Không yêu cầu provider thật để kiểm chứng; thiếu reader không được suy ra zero.

**Q10 — Trạng thái triển khai.** Bốn hồ sơ ghi Implemented vì migration và test
store thật đã tồn tại. Phân biệt bằng chứng trên database test với apply lên
database ứng dụng; không tự suy ra đã triển khai production hoặc bật provider.

**Q11 — Usage Events là Source Of Truth.** Settlement ghi Usage Event và cập
nhật reservation trong **cùng** transaction chứ? Có kịch bản nào Usage và ledger
bất đồng không?

---

# Lệnh kiểm chứng

## Bắt buộc: MariaDB 11.4, không phải SQLite

**SQLite bỏ qua sạch mọi CHECK constraint** (migration guard bằng
`!== 'sqlite'`), nên bộ test mặc định **không chứng minh được gì** về schema.
XAMPP là 10.4.21, dưới sàn `>= 10.5` của repo. Chỉ MariaDB 11.4 Homebrew mới đại
diện được môi trường CI (`mariadb:11.4.3`).

```bash
DD=/tmp/lf-review-$(date +%s)
mkdir -p "$DD"
/usr/local/bin/mariadb-install-db --datadir="$DD/data" --auth-root-authentication-method=normal
/usr/local/bin/mariadbd --datadir="$DD/data" --socket="$DD/mysql.sock" --skip-networking &
```

Dựng database rồi chạy:

```bash
DB_CONNECTION=mysql DB_SOCKET="$DD/mysql.sock" DB_HOST=localhost DB_USERNAME=root DB_PASSWORD='' php artisan schema:drift --fresh
```

Lấy chuỗi CHECK **thật** thay vì đoán — MariaDB bỏ ngoặc thừa khi lưu
`CHECK_CLAUSE`:

```sql
SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE();
```

Hai bẫy cú pháp đã gặp: MariaDB **không** có `ALTER TABLE ... DROP CHECK` (phải
dùng `DROP CONSTRAINT`); và `AUTO_INCREMENT` **không** được tham chiếu trong
generated column (`ERROR 1901`, MDEV-30016).

## Test

```bash
php artisan test --filter "DatabaseUsageQuotaReserverTest|AiProviderExecutionGateTest"
```

```bash
php artisan test
```

```bash
php artisan docs:lint
```

## Bẫy quy trình

Nếu số liệu test không khớp giữa hai lượt chạy, kiểm tiến trình mồ côi trước khi
kết luận — `pkill -f "artisan test"` **không** khớp tiến trình con `phpunit`:

```bash
pgrep -fl phpunit
```

---

# Bằng chứng hiện có, và ai đã chạy

Toàn bộ số liệu dưới đây do **implementer** tạo ra. Không con số nào đã được
kiểm chứng độc lập. Đây là thứ cần kiểm lại, không phải thứ để thừa nhận.

| Hạng mục | Kết quả | Ai chạy |
| --- | --- | --- |
| `DatabaseUsageQuotaReserverTest` + `AiProviderExecutionGateTest` | 41 passed, 203 assertions | implementer, tái lập 2026-09-11 |
| Toàn bộ suite (SQLite) | 7 failed, 4 skipped, 1104 passed | implementer, 2026-09-11 |
| 7 lỗi đó chạy lại trên `a9da018` (worktree riêng) | ~~đỏ y hệt → không phải hồi quy~~ **VÔ HIỆU về phương pháp** (đính chính 2026-09-14): worktree symlink `vendor`, Composer tính `$baseDir` từ đường dẫn thật nên code `App\`/`Tests\` được nạp từ repo chính — không phải baseline `a9da018`. Kết luận "7 lỗi có sẵn" nay dựa trên baseline reviewer độc lập dựng lại với `vendor` cục bộ (introduced `[]`, resolved `[]`), xem LF-AI-Embedding-Qdrant-Architecture-Review § 6 | implementer |
| `docs:lint` | passed | implementer |

Bảy lỗi có sẵn nằm ở `MediaRevisionLifecycleTest` (5),
`VideoTranscriptCaptionLocalReviewTest` (1), `AudioProcessingLocalReviewTest` (1)
— đều phụ thuộc ffmpeg/whisper thật, không chạm packet này.

Đã từng có một lượt chạy báo **16 failure**. Không tái lập được; lượt kiểm ngay
sau đó trên cùng cây cho đúng 7. Nếu reviewer gặp lại con số khác 7, ghi lại danh
sách `⨯` đầy đủ — đó là dữ kiện, không phải nhiễu.

---

# Những gì KHÔNG được tính là PASS

* Bộ test SQLite xanh. Nó không chạm CHECK constraint.
* `schema:drift --fresh` xanh, nếu chưa kiểm bẫy
  `explicit_defaults_for_timestamp` (Q5) — lệnh này không phát hiện được nó.
* Hồ sơ mô tả đúng hành vi, nếu chưa kiểm code có làm đúng như mô tả.
* Code đúng, nếu hồ sơ chưa nói cùng một điều — migration bị chặn bởi **hồ sơ**,
  nên hồ sơ mới là thứ phải đạt.

---

# Định dạng kết quả

Tạo `docs/quality/LF-SaaS-Commercial-Usage-Packet-Architecture-Review.md` gồm:

1. Neo: SHA đã review, ngày, môi trường kiểm chứng (phiên bản MariaDB thật, lấy
   từ `SELECT VERSION()` chứ không phải phiên bản client).
2. Verdict tổng: **PASS / FAIL**.
3. Bảng 11 câu hỏi với verdict và bằng chứng từng câu.
4. Danh sách finding theo mức P0/P1/P2, mỗi finding kèm: hiện tượng, cách tái
   lập, tác động, và **giao cho ai vá** — reviewer không tự vá.
5. Mục OWNER DECISION riêng cho những chỗ contract chưa đủ.

Nếu Owner yêu cầu review, ghi đúng verdict và bằng chứng, không tự biến waiver
thành PASS. Owner quyết định việc phê duyệt/Frozen; không bắt buộc có verdict
độc lập. Finding kỹ thuật vẫn phải được xử lý hoặc ghi nhận rủi ro đúng thẩm
quyền. Không suy ra Bước 4 Done chỉ từ phê duyệt thiết kế hay waiver review.
