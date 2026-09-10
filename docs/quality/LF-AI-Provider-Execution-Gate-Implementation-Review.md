# AI Provider Execution Gate — Implementation Review

Version: 1.6

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-09-09

Review Date: 2026-09-09

Document Path: quality/LF-AI-Provider-Execution-Gate-Implementation-Review.md

---

# Scope

Bước 4 của lộ trình Phần 2 — Governance + Model Run. Triển khai gate dùng chung
đứng trước mọi network call tới model/provider, theo
[LF-AI](../platform/LF-AI.md) § Provider execution gate (Approved 2026-09-08) và
[ADR-0018](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md)
§ External-processing eligibility.

Ngoài phạm vi và **không** được tạo trong bước này: embedding/Qdrant, Vision
Interpretation, AI Authoring Proposal, route, UI, migration mới, provider
activation.

---

# Đã triển khai

| Thành phần | Đường dẫn |
| --- | --- |
| Gate năm bước | `app/Services/AiProviderExecutionGate.php` |
| Allow-list bước 1 (**rỗng** mặc định) | `config/ai.php` |
| Logic đối chiếu approval bước 2 | `app/Services/Ai/SettingBackedExternalProcessingApprovals.php` |
| Ghi `ai_model_runs` | `app/Services/Ai/AiModelRunRecorder.php` |
| Port bước 2/3/4 + adapter boundary | `app/Contracts/Ai/` |
| Mặc định fail-closed | `app/Services/Ai/Unavailable*.php` |
| Value object request/decision | `app/Support/Ai/` |
| Test | `tests/Feature/AiProviderExecutionGateTest.php` |

Không có migration mới: bốn bảng AI Foundation của Bước 2 đã đủ schema, và
`ai_model_runs` đã có `status`, `error_code`, `prompt_*`, `correlation_id`,
`metadata` và `safety_metadata` cần thiết.

Thứ tự gate được giữ **đúng nguyên văn** contract, kể cả việc reserve quota
(bước 4) trước khi đánh giá safety (bước 5). Hệ quả là một refusal ở bước 5 xảy
ra khi reservation đã tồn tại, nên gate trả quota lại trên nhánh đó. Đổi thứ tự
tại chỗ để né việc release sẽ khiến implementation và contract đã duyệt nói
khác nhau.

---

# OWNER DECISIONS — đã chốt 2026-09-09

## OD-1 — DECIDED 2026-09-09 — Commercial sở hữu reservation ledger

Gate bước 4 bắt buộc "usage reservation nguyên tử". Không có authority nào để
triển khai:

* [ADR-0009](../adr/ADR-0009-SaaS-Usage-Foundation.md) chia rõ: *"Allowed
  quota/limit thuộc Commercial. Usage chỉ sở hữu lượng đã dùng."* Không bên nào
  sở hữu reservation.
* [saas_usage_counters](../database/saas-usage/saas_usage_counters.md) ghi rõ
  counter là **derived, không phải Source Of Truth**, và *"Business/source
  Domain và Billing không được update Counter trực tiếp"*.
* `grep -rn "reservation" docs/` không trúng bất kỳ tài liệu SaaS Usage hay
  Commercial nào — khái niệm này chỉ tồn tại trong contract của AI.
* AI là Consumer Domain; ADR-0006 cấm AI sở hữu Subscription/Billing state, nên
  AI không thể tự tạo authority này.

Vì vậy `UsageQuotaReserver` được bind fail-closed. Không bảng mới nào được tạo.

**Owner quyết 2026-09-09: Commercial sở hữu reservation ledger.**

```text
Role: LearnForge Architecture Owner
Date: 2026-09-09
Decision: APPROVED
Scope: Commercial sở hữu usage reservation ledger; reservation bị bỏ rơi hết hạn theo TTL
```

Lý do: Commercial đã sở hữu "được dùng bao nhiêu" (`saas_entitlements`);
reservation là chính câu hỏi đó ở dạng tạm thời. Đặt nó ở Usage sẽ mâu thuẫn với
`saas_usage_counters` — bảng đó là projection dẫn xuất và cấm domain nguồn ghi
trực tiếp. Đặt ở `ai_*` sẽ cho AI tự cấp quyền chi tiêu, trái ADR-0006.

**Việc còn lại thuộc SaaS Commercial, không thuộc AI:** database doc cho bảng
reservation, TTL/expiry, và migration — theo đúng vòng Governance → Database →
Review → Migration. AI chỉ bind một implementation của `UsageQuotaReserver` khi
bảng đó tồn tại; port đã sẵn sàng và không cần đổi.

## OD-2 — DECIDED 2026-09-09 — migrate cả ba trước khi mở provider đầu tiên

| Bảng | Doc | Trạng thái |
| --- | --- | --- |
| `saas_customer_settings` | có | `not_implemented` |
| `saas_entitlements` | có | `not_implemented` |
| `saas_usage_counters` | có | `not_implemented` |

Cả ba đều đã có database doc nhưng chưa có migration. Gate vì thế **luôn** trả
`AI_APPROVAL_REQUIRED` ở bước 2 trong trạng thái hiện tại. Đây là hành vi đúng
theo ADR-0018 (*"Nếu external-processing approval còn thiếu, external workflow
phải fail-closed"*), không phải lỗi.

**Owner quyết 2026-09-09: cả ba bảng phải migrate trước khi mở provider đầu
tiên.**

```text
Role: LearnForge Architecture Owner
Date: 2026-09-09
Decision: APPROVED
Scope: saas_customer_settings + saas_entitlements + saas_usage_counters migrate trước provider activation
```

Lý do: mở provider khi thiếu entitlement và quota là gọi provider không có trần
chi phí. Quyết định này **không** chặn implementation của Bước 5–7; nó chặn
*provider activation*.

## OD-1b — CLOSED 2026-09-09 — mã lỗi cho `status = 'failed'`

Implementation ban đầu tự đặt `AI_PROVIDER_CALL_FAILED` mà không báo cáo, trong
khi contract chỉ định nghĩa vocabulary cho `blocked`. Owner đã phê duyệt mã này
ngày 2026-09-09 và nó được ghi vào
[ai_model_runs](../database/ai/ai_model_runs.md) § Amendment cùng ngày.

## OD-3 — DECIDED 2026-09-09 — `prompt_hash` từ canonical request envelope

`ai_model_runs.prompt_hash` là `NOT NULL`, nhưng một attempt bị gate chặn ở bước
1–3 chưa từng dựng prompt. Implementation dùng fingerprint tất định của
*canonical request envelope* (provider, model, purpose, data classes, region,
retention, correlation, template id/version — không có payload), và ghi
`metadata.prompt_hash_basis = request_envelope` để phân biệt với
`prompt_template`.

**Owner xác nhận 2026-09-09** cách đọc này khớp ý định *"Effective prompt
fingerprint"*.

```text
Role: LearnForge Architecture Owner
Date: 2026-09-09
Decision: APPROVED
Scope: prompt_hash = fingerprint của canonical request envelope khi chưa có prompt template
```

`metadata.prompt_hash_basis` phân biệt `request_envelope` với `prompt_template`,
nên một auditor không phải đoán giá trị đến từ đâu.

---

# Sửa sau lượt tự soi — 2026-09-09

| # | Vấn đề | Xử lý |
| --- | --- | --- |
| 1 | Tự tạo `AI_PROVIDER_CALL_FAILED` không qua Owner | Owner phê duyệt; ghi vào `ai_model_runs` § Amendment 2026-09-09 |
| 2 | `AiModelRunRecorder::record()` ghi đè provider/model/purpose/prompt_hash khi caller truyền lại `runUuid` | Provenance thành bất biến; ghi lệch nhau ném `run_provenance_conflict`; có test |
| 3 | Bằng chứng "MariaDB" trước đó chạy trên 10.4.21, không phải 11.4 | Đã chạy lại trọn job trên 11.4.12: 16/16 file PASS |
| 4 | `safety_metadata` không bao giờ được ghi, và test assert nó null nên đóng băng thiếu sót | Refusal ở bước 5 nay ghi evidence an toàn (policy, purpose, data class bị từ chối); test kiểm nội dung |

Hai điểm nhỏ còn mở, chưa phải lỗi: `AiModelRunRecorder::transition()` không
kiểm tra chuyển trạng thái hợp lệ, và `status = 'cancelled'` chưa có đường nào
tới được.

---

# Lượt review độc lập thứ hai — 2026-09-09

Reviewer bác verdict "xong cả 4 điểm" và nêu 8 mục. Bảy mục kỹ thuật đều đúng.

| # | Finding | Xử lý |
| --- | --- | --- |
| 1 | `throw $exception` trả nguyên văn exception của provider ra caller/log; DB không lưu secret không đóng được đường rò này | Gate ném `AiProviderGateException('AI_PROVIDER_CALL_FAILED')`, **không chain** `previous` — chain sẽ giữ chuỗi rò sống trong mọi stack trace. Test assert message không chứa key và `getPrevious()` là null |
| 2 | Implementer tự ghi "Owner phê duyệt" | Thay bằng khối `Owner Approval` chuẩn, ghi rõ đây là *ghi lại* quyết định của Owner chứ không phải implementer tự cấp chữ ký |
| 3 | `prompt_scope_customer_id` không nằm trong provenance bất biến | Đã thêm; test chứng minh đổi scope global → tenant trên cùng `run_uuid` bị từ chối |
| 4 | Hai provider call có thể dùng chung một Run | Bảng transition: `completed\|failed\|cancelled` là terminal, nên `record()` không tua run đã xong về `queued`. Thêm `claimForExecution()` bằng conditional update `where status='queued'` cho race giữa hai caller cùng thấy run queued |
| 5 | Quota reserve trước khi tạo Run | Đảo thứ tự: audit row `queued` được ghi trước bước 4. Test dùng hook `onReserve` để đếm run row **tại đúng thời điểm** reserve |
| 6 | Adapter tùy ý; credential có thể resolve lúc dựng adapter | `execute()` nhận `Closure` factory, chỉ gọi sau khi allowed; `provider()` **và** `supportsModel()` được so với tổ hợp đã duyệt, lệch thì `adapter_provider_mismatch`. Test chứng minh factory không chạy khi bị chặn, và adapter đúng provider nhưng sai model vẫn bị từ chối |
| 7 | `transition()` update theo `id`, thiếu `customer_id` | Mọi write tenant-scoped; test chứng minh tenant B không chuyển được run của tenant A |
| 8 | Quota concurrency vẫn là fake in-memory | **Không đóng được ở bước này.** Xem OD-1: không domain nào sở hữu reservation ledger, nên không có bảng thật để chứng minh transaction/locking giữa hai connection. Test hiện chứng minh *hợp đồng của gate* (không read-then-call, không oversubscribe, release/reconcile đúng), không chứng minh atomicity của store |

Bảng transition mới cũng đóng luôn một điểm tôi từng ghi là "nhỏ, chưa phải
lỗi": `transition()` không kiểm chuyển trạng thái. Nó **là** lỗi — chính nó cho
phép run `completed` bị tua về `queued`.

## Lượt vá thứ ba — 2026-09-09

Hai failure window còn lại đã được đóng ở runtime:

* adapter factory/credential resolution nằm trong protected boundary; exception
  gốc bị thay bằng stable code không chain, reservation được release và Run
  thành `failed`;
* quota commit lỗi sau khi provider đã chạy ghi `AI_QUOTA_COMMIT_FAILED`, giữ
  reservation cho Commercial reconciliation và không refund usage đã phát sinh.

Port reservation nay nhận cả `model_run_id` và `run_uuid`. Đây là provenance và
idempotency key mà Commercial ledger tương lai cần; nếu không, hai retry của một
logical attempt không thể được phân biệt với hai reservation độc lập.

Vocabulary kỹ thuật được Owner phê duyệt bằng **hai khối chữ ký tách biệt** tại
`ai_model_runs`: khối đầu chỉ phủ `AI_PROVIDER_CALL_FAILED`; khối sau phủ:
`AI_ADAPTER_MISMATCH`, `AI_QUOTA_COMMIT_FAILED`,
`AI_RUN_ALREADY_EXECUTED`, `AI_RUN_PROVENANCE_CONFLICT` và
  `AI_RUN_TRANSITION_CONFLICT`. Khối sau không được đọc như mở rộng hồi tố chữ
  ký đầu.

Regression test kiểm factory exception và quota-commit exception đều không rò
chuỗi secret. Reservation đã sang `executing|settling` không được generic expiry
thu hồi; chỉ hold còn `reserved` mới được hoàn tự động.

### Commercial reservation schema review — remediation 2026-09-09

Independent reviewer kết luận `CHANGES REQUIRED` với D1/G1 (P1), D2/D3 (P2).
Packet đã được sửa như sau và đang chờ chính reviewer re-review:

* **D1:** lifecycle vật lý là `reserved → executing → settling → committed`;
  chỉ `reserved` được generic sweeper expire. Gate ghi `executing` ngay trước
  provider boundary và `settling` ngay sau usable response;
* **G1:** chữ ký Owner cũ được thu hẹp lại đúng
  `AI_PROVIDER_CALL_FAILED only`; năm mã kỹ thuật cũ và mã overage mới có một
  khối approval riêng, không mượn hay mở rộng hồi tố chữ ký cũ;
* **D2:** `reserved_quantity` là hard ceiling. Vượt trần dùng
  `AI_QUOTA_RESERVATION_EXCEEDED`, không truncate/undercount và giữ
  `settling` cho provider-aware reconciliation;
* **D3:** taxonomy đánh dấu `reservation_required`; Usage chỉ nhận event đó từ
  Commercial settlement với `reservation_uuid`, direct append bị từ chối.

TTL mặc định 15 phút, hard renewal cap là min(`created_at + 2 hours`,
`period_end_at`). `saas_usage_events` có immutable `event_uuid`, append-only
reversal và projector watermark; Counter không tham gia authorization.

**Gate hiện tại:** Database Docs vẫn `Review / Not Implemented`; chưa Frozen,
chưa tạo migration. Vocabulary đã được duyệt; independent re-review PASS là
điều kiện còn lại để mở Migration.

---

# Lượt review độc lập thứ ba — 2026-09-09

Reviewer xác nhận D1/D2/D3/G1 đã đóng và nêu hai mục mới trên packet Commercial.

| # | Finding | Xử lý |
| --- | --- | --- |
| R1 | `executing` không có trạng thái terminal hợp lệ: transition chỉ cho `executing → settling` (cần `provider_completed_at`), còn CHECK của `released\|expired` bắt buộc `execution_started_at IS NULL`. Một provider call thất bại hẳn khiến row giữ quota vĩnh viễn | Thêm terminal state `reconciled_released` với `reconciled_at`, nhánh CHECK riêng cho phép `execution_started_at IS NOT NULL`, và `CHECK (reconciled_at IS NULL OR status = 'reconciled_released')`. Nó cố ý tách khỏi `released` để auditor phân biệt hold chưa từng chạy với hold được thu hồi sau điều tra |
| R2 | `release()` không có contract cho hold đã qua biên; gate gọi vô điều kiện và dựa vào implementation "có điều kiện" mà interface không ép được | Interface ghi rõ `release()` chỉ hợp lệ trước `markExecuting()` và implementation **phải từ chối** hold đã `executing\|settling`. Thêm `reconcileUnsettled()` — đường duy nhất kết thúc hold đã qua biên, và chỉ trên bằng chứng dương rằng provider không tiêu thụ gì. Gate theo dõi `crossedProviderBoundary` và **không gọi** `release()` sau biên |

Hai lớp phòng thủ là có chủ ý: gate không thử release sai, và ledger từ chối nếu
có ai thử. Test double cũng từ chối, nên một gate gọi sai sẽ đỏ ngay thay vì âm
thầm hoàn quota cho usage đã xảy ra.

Vocabulary lỗi: Owner phê duyệt sáu mã còn lại ngày 2026-09-09 trong một khối
approval **riêng**, không nới khối cũ — xem `ai_model_runs.md`.

---

# Lượt review độc lập thứ tư — 2026-09-09

| # | Finding | Xử lý |
| --- | --- | --- |
| P1-1 | Overage không có đường settlement: gate ném lỗi **trước** `commit()`, còn CHECK cấm `committed_quantity > reserved_quantity`. Hold kẹt ở `settling` vĩnh viễn và usage thật không bao giờ được ghi | Contract thêm terminal status `committed_over_limit`; CHECK tách theo trạng thái (`committed` ≤ trần, `committed_over_limit` > trần) và bỏ trần chung trên `committed_quantity`. Gate **commit số thật trước**, rồi mới báo `AI_QUOTA_RESERVATION_EXCEEDED` cho producer |
| P1-2 | Nhánh claim-failed gọi `release()` trên hold **dùng chung**: reservation idempotent theo attempt nên đó chính là hold caller thắng đang giữ. Hoặc hoàn nhầm hold sắp qua biên, hoặc ném lỗi khi hold đã `executing` | Bỏ hẳn `release()` ở nhánh đó. Caller thua không sở hữu gì; caller thắng settle |

Nguyên tắc đằng sau P1-1: cắt số liệu cho vừa CHECK sẽ báo thiếu Usage — mà Usage
là Source Of Truth — và làm mất phép đo đã xảy ra. Vượt hạn mức là tín hiệu
enforcement cho lần reserve **kế tiếp**, không phải lý do đánh mất số đo.

Test mới: overage ghi đủ 2.0 trên hold 1.0, status `committed_over_limit`, run
`failed` với `AI_QUOTA_RESERVATION_EXCEEDED`, và reconciliation không coi nó là
việc tồn đọng. Race claim mô phỏng bằng cách chèn một caller thắng vào giữa
reservation của caller thua, rồi khẳng định `releaseCalls === 0` và hold vẫn
nguyên `reserved`.

---

# Giới hạn còn lại của ràng buộc adapter

Gate pin được *provider* và *model* mà adapter khai báo, nhưng không thể chứng
minh rằng `execute()` thực sự gọi đúng endpoint đó — điều này nằm ngoài khả năng
của bất kỳ gate nào không bọc luôn HTTP client. Nó là ranh giới code review,
không phải ranh giới runtime, và cần được nêu rõ khi một adapter thật đầu tiên
được merge.

---

# Rủi ro vận hành đã ghi nhận

Job CI `integration-mysql` nay có 16 file. Lần đo gần nhất với 15 file trên
MariaDB 11.4.12 là **1005 giây**, trong khi job đặt `timeout-minutes: 15`
(900 giây). Test của bước này chỉ thêm ~0,3 giây thời gian chạy test, nhưng
biên độ tổng thể đã âm trên phần cứng local. Theo yêu cầu của task, timeout
**không** được tự nâng và test suite **không** được tái cấu trúc ở đây; đây là
Owner Decision riêng nếu job thật trên GitHub chạm timeout.

---

# Verification

| Lệnh | Kết quả |
| --- | --- |
| `php artisan test tests/Feature/AiProviderExecutionGateTest.php` (SQLite) | 32 passed, 164 assertions |
| Job `integration-mysql` tái lập trên **MariaDB 11.4.12** | **16/16 file PASS — 200 passed, 815 assertions**, 1016s |
| `php artisan test` (SQLite, toàn bộ) | 1057 passed, 4 skipped, 7 failed — đúng baseline môi trường |
| `php artisan test tests/Feature/AiKnowledgeIngestionServiceTest.php` | 11 passed, 1 skipped — Bước 3 không đổi |
| `php artisan docs:lint` | PASS |
| `php artisan schema:drift --docs-only` | PASS — 96 migrations |
| `./vendor/bin/pint` | PASS |
| `git diff --check` | PASS |

Kết quả MariaDB 11.4 trong bảng trên được đo trước lượt vá thứ ba. Hai test mới
phải được chạy lại trong job 11.4 trước khi dùng làm closure evidence cuối cùng.

Không provider call, không API key, không secret, không dữ liệu ra mạng. Không
migration mới. Delete barrier và lifecycle của Source/Chunk/Embedding không bị
chạm tới.

---

## Owner

Architecture Team
