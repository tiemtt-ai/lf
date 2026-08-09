# LearnForge Documentation Conflict Register

Version: 1.0

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-08-09

Document Path: quality/LF-Documentation-Conflicts.md

---

# Purpose

Register chính thức để ghi nhận, theo dõi và lưu bằng chứng resolution cho các
xung đột đã được xác minh giữa Governance, ADR, Domain Policy, Database
Documentation, Quality Review và implementation của LearnForge.

Register này không phải Governance source, không tạo business/architecture
policy, không chọn bên thắng và không hợp thức hóa inconsistency. Khi cùng một
concern có yêu cầu đối nghịch, implementation phải dừng cho tới khi authority
phù hợp phê duyệt resolution.

---

# Scope

Áp dụng khi rà soát:

* Governance ↔ ADR.
* ADR ↔ Domain Policy.
* Domain Policy ↔ Database Documentation.
* Database Documentation ↔ migration/schema/implementation.
* Approved/Frozen Quality Review ↔ canonical source hiện hành.
* LF-INDEX routing ↔ file thực tế.
* Document Status ↔ Implementation Status.
* Terminology, supersession và amendment synchronization.

Register chỉ theo dõi inconsistency đã được phân loại. Nó không thay thế ADR,
Architecture Review, Regression Audit, issue tracker hoặc implementation test.

---

# What Counts as a Documentation Conflict

Một item chỉ là `CONFLICT` khi hai hoặc nhiều nguồn có thẩm quyền mô tả cùng
một concern nhưng đưa ra các yêu cầu không thể đồng thời thỏa mãn.

Ví dụ: hai source of truth khác nhau cho cùng state; ADR và schema yêu cầu các
lifecycle không tương thích; hai tài liệu Approved/Frozen định nghĩa cùng một
term theo hai nghĩa loại trừ nhau; hoặc Quality Review khóa contract khác với
ADR canonical hiện hành.

Không kết luận conflict dựa trên keyword, khác wording, thiếu implementation,
hoặc một nguồn chưa được đọc trực tiếp. Người đăng ký phải trích được yêu cầu
trung lập của từng phía và giải thích vì sao chúng không thể cùng đúng.

---

# Classification Model

| Classification | Meaning |
| --- | --- |
| `CONFLICT` | Hai nguồn chính thức quy định cùng concern theo cách không thể đồng thời đúng |
| `GAP` | Chưa có tài liệu hoặc chưa quy định đủ |
| `AMBIGUITY` | Nội dung tồn tại nhưng có nhiều cách hiểu |
| `STALE` | Nội dung có bằng chứng không còn phản ánh trạng thái mới |
| `IMPLEMENTATION_DRIFT` | Code/schema không phù hợp tài liệu, nhưng các tài liệu không xung đột nhau |
| `DUPLICATION` | Nội dung lặp, chưa có bằng chứng về yêu cầu đối nghịch |
| `ROUTING_GAP` | Tài liệu tồn tại nhưng catalog/routing chưa dẫn tới nó |
| `UNVERIFIED` | Có dấu hiệu bất thường nhưng chưa đủ bằng chứng để phân loại |

Không dùng `conflict` làm nhãn chung cho missing policy, deferred scope,
superseded history hoặc implementation chưa tồn tại.

---

# Status Lifecycle

Conflict ID có dạng tuần tự và ổn định:

```text
DOC-CONFLICT-0001
DOC-CONFLICT-0002
```

ID đã cấp không được tái sử dụng, kể cả record đã resolved hoặc invalidated.

| Status | Meaning |
| --- | --- |
| `OPEN` | Conflict đã xác minh, resolution chưa bắt đầu |
| `UNDER_REVIEW` | Owner/authority đang phân tích |
| `DECISION_REQUIRED` | Cần quyết định nghiệp vụ hoặc kiến trúc có thẩm quyền |
| `RESOLVED` | Canonical sources đã đồng bộ và có verification evidence |
| `ACCEPTED_TEMPORARILY` | Chấp nhận tạm; bắt buộc có owner, lý do và Target Review Date |
| `INVALIDATED` | Điều tra xác định không phải conflict; giữ nguyên lý do và lịch sử |

Không dùng `RESOLVED` chỉ vì implementation chọn một nhánh. Mọi canonical
source liên quan phải được cập nhật hoặc có supersession/backlink rõ ràng.

---

# Conflict Impact

`Conflict Impact` độc lập với Audit Level, Finding Severity và Final Verdict.

| Impact | Meaning |
| --- | --- |
| `BLOCKER` | Không thể triển khai/kết luận an toàn vì source of truth hoặc foundation chưa xác định |
| `HIGH` | Nguy cơ với tenant/auth, historical data, lifecycle, public contract, schema hoặc architecture boundary |
| `MEDIUM` | Ảnh hưởng behavior trong một module/flow, chưa chạm foundation |
| `LOW` | Wording, terminology hoặc routing có thể gây hiểu nhầm nhưng chưa đổi runtime behavior |

Impact thấp không cho phép bỏ qua resolution.

---

# Detection and Registration Workflow

1. Phát hiện dấu hiệu inconsistency.
2. Đọc trực tiếp mọi source được cho là đối nghịch, gồm section/heading liên quan.
3. Xác nhận các source nói về cùng concern và không thể đồng thời đúng.
4. Phân loại `CONFLICT` hoặc một classification không phải conflict.
5. Tìm duplicate record theo concern/source trước khi cấp ID mới.
6. Cấp Conflict ID kế tiếp và điền toàn bộ schema bắt buộc.
7. Thêm record vào Active Conflict Register.
8. Dừng implementation chỉ trong affected concern và thông báo authority.

Search result, automated warning hoặc implementation khác documentation là đầu
mối điều tra, không tự động là `CONFLICT`.

---

# Temporary Safety Rule

Mặc định cho mọi confirmed conflict:

```text
STOP implementation for the affected concern. Do not guess.
```

Documentation priority không tự giải quyết hai yêu cầu đối nghịch về cùng một
concern. Không triển khai theo một phía cho tới khi có approved decision.

---

# Resolution Workflow

```text
Detect
→ Verify both sources
→ Classify
→ Register
→ Stop affected implementation
→ Assign resolution authority
→ Approve decision
→ Update canonical documents
→ Update superseded/backlinks/routing
→ Verify implementation impact
→ Run documentation checks
→ Record evidence
→ Mark RESOLVED
```

Resolution phải ghi decision được duyệt, source được sửa, source
superseded/archived, implementation impact, test/lint đã chạy, commit/PR/ADR
hoặc review evidence và remaining risks. Record không bị xóa sau resolution;
nó được chuyển sang Resolved Conflict Register.

---

# Resolution Authority

Chọn authority theo concern thực tế:

| Concern | Resolution Authority |
| --- | --- |
| Architecture, foundation, cross-domain source of truth | Architecture Team |
| Domain lifecycle hoặc business responsibility | Domain Owner |
| Product/business rule | Product/Business Owner |
| Physical schema, constraint hoặc migration contract | Database Owner cùng authority của policy liên quan |
| Authentication, tenant isolation hoặc ownership | Security/Tenant Owner và Architecture Team khi chạm boundary |

Không bịa tên cá nhân, owner hoặc deadline. Nếu authority chưa xác định, Status
phải là `DECISION_REQUIRED` và concern vẫn bị STOP.

---

# Conflict Record Template

Template chỉ chứa placeholder; không phải conflict record thật.

```text
Conflict ID: DOC-CONFLICT-NNNN
Title: <neutral title>
Classification: CONFLICT
Status: OPEN | UNDER_REVIEW | DECISION_REQUIRED | RESOLVED | ACCEPTED_TEMPORARILY | INVALIDATED
Impact: BLOCKER | HIGH | MEDIUM | LOW
Detected At: YYYY-MM-DD
Detected By: <role/team/tool; do not invent a person>
Owner: <authorized role/team or Unassigned>
Affected Domain: <domain/cross-cutting>
Affected Concern: <single concern>
Sources In Conflict:
Source A: <repository/path.md#section>
Source B: <repository/path.md#section>
Additional Sources: <repository paths or None>
Contradictory Requirements:
- Source A requires: <neutral statement>
- Source B requires: <neutral statement>
Why They Cannot Both Be True: <verified explanation>
Runtime/Business Impact: <impact or None verified>
Affected Implementation: <paths/components or None verified>
Temporary Safety Rule: STOP implementation for the affected concern. Do not guess.
Required Decision: <decision needed>
Resolution Authority: <authorized role/team>
Resolution Plan: <steps; do not choose an outcome prematurely>
Target Review Date: YYYY-MM-DD | Not set
Resolved At: YYYY-MM-DD | Not resolved
Resolution: <approved decision/evidence or Not resolved>
Superseded/Updated Documents: <paths or None>
Verification Evidence: <commands/tests/review/commit or None>
Related ADR/Review/Issue/PR: <references or None>
Notes: <history and remaining risks>
```

`Sources In Conflict` phải dùng path repository chính xác và ưu tiên heading
ổn định thay cho line number. `Contradictory Requirements` phải mô tả từng phía
trung lập.

---

# Active Conflict Register

Active confirmed conflicts: None.

| ID | Title | Classification | Status | Impact | Domain | Owner | Target Review |
| --- | --- | --- | --- | --- | --- | --- | --- |
| — | No active confirmed conflicts | — | — | — | — | — | — |

---

# Resolved Conflict Register

Resolved confirmed conflicts: None.

| ID | Title | Classification | Status | Impact | Domain | Resolved At | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| — | No resolved confirmed conflicts | — | — | — | — | — | — |

---

# Baseline Scan Disposition

Baseline repository scan ngày 2026-08-09 không xác minh được hai official
sources có yêu cầu loại trừ nhau. Các nhóm sau không bị ghi sai thành conflict:

| Observation | Classification | Evidence |
| --- | --- | --- |
| Approved/Frozen specifications chưa có migration/model tương ứng cho Assessment, Track, AI và một số SaaS domains | Không phải conflict/drift; trạng thái tài liệu độc lập với implementation và việc chưa triển khai đã được ghi rõ | `LF-INDEX.md` feature routing và các `database/<domain>/README.md` ghi rõ “Chưa triển khai” |
| Một số feature chưa có Quality/Architecture Review chuyên biệt | `GAP` | `LF-INDEX.md` ghi “Chưa có review chuyên biệt” |
| Course Learning Path chưa có domain-policy section riêng dù database docs đã tồn tại | `GAP`, không phải routing conflict | `LF-INDEX.md` ghi rõ domain policy “Chưa xác định” và route tạm tới database docs |
| Product Items review lịch sử đã bị thay thế nhưng header metadata vẫn là `Document Status: Approved` | `STALE`, không phải conflict; supersession và canonical replacement đã rõ | `quality/LF-Course-Product-Items-Architecture-Review.md`, `quality/README.md`, `LF-INDEX.md` đều route sang Integrated Product v2 Review |
| Deferred feature scope trong ADR/review/schema docs | Không phải conflict nếu các nguồn không yêu cầu implementation hiện tại | Các section `Deferred`/`Explicitly Deferred` được giữ ngoài implementation authority |
| Legacy metadata debt | `GAP`, không phải semantic conflict | `config/docs-lint.php` allowlist có path và lý do chính xác |

Không có `AMBIGUITY`, `DUPLICATION`, `ROUTING_GAP` hoặc `UNVERIFIED`
item nào trong baseline scan đủ bằng chứng để đăng ký thành confirmed conflict.

---

# Relationship With Other Processes

## Documentation priority

LF-INDEX priority xác định reading order và authority. Nó không cho phép âm
thầm chọn bên thắng khi hai official sources đưa ra yêu cầu loại trừ nhau về
cùng concern; trường hợp đó phải đăng ký và STOP.

## ADR process

Resolution làm thay đổi architecture phải đi qua ADR process. Register ghi
decision evidence nhưng không thay thế hoặc tự approve ADR.

## Architecture Review

Conflict chạm architecture boundary phải qua
[Architecture Review Checklist](../governance/LF-Architecture-Review-Checklist.md).
Register không tự xác nhận Foundation Ready.

## Regression Audit

Implementation/documentation change để resolve conflict phải dùng
[LF Regression Audit](LF-Regression-Audit.md) với Final Audit Level cao nhất
áp dụng. Conflict Impact không phải Audit Level hoặc Finding Severity.

## docs:lint

`docs:lint` kiểm tra metadata, catalog/orphan, dead links, vocabulary và
superseded backlinks. Nó không phân tích semantic conflict trong văn xuôi;
lint pass không chứng minh register không có conflict.

---

# Maintenance Rules

* Không sửa/xóa lịch sử record; ghi transition và evidence.
* Không tái sử dụng ID hoặc renumber để lấp khoảng trống.
* Record `ACCEPTED_TEMPORARILY` phải có owner, lý do và Target Review Date.
* Record `RESOLVED` phải có updated/superseded sources và verification evidence.
* Record `INVALIDATED` phải giữ lý do phân loại sai ban đầu.
* Cập nhật bảng index và detail section trong cùng thay đổi.
* Chạy `php artisan docs:lint` sau mọi cập nhật register.
* Không ghi secret, production data hoặc tên cá nhân chưa được xác minh.

---

# Owner and Review Cadence

Owner: Architecture Team.

Review register:

* Khi một inconsistency mới được xác minh.
* Khi status, owner, impact hoặc evidence của record thay đổi.
* Trước implementation của affected concern.
* Tối thiểu mỗi quý đối với record đang active.

Target Review Date vẫn phải được đặt riêng cho từng conflict active; cadence
không thay thế deadline của record.
