# LF-Regression-Audit.md

Version: 1.3

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: quality/LF-Regression-Audit.md

---

# Purpose

Checklist bắt buộc cho mọi thay đổi được phân loại
`Existing-Feature Change` theo
[LF Development Standards](../LF-Development-Standards.md), không phụ thuộc
người dùng có yêu cầu audit hay regression test hay không.

Checklist cũng bắt buộc cho:

```text
Refactor

Major Feature

Authentication Changes

Tenant Changes

Navigation Changes

UI Changes

I18N Changes
```

Phạm vi kiểm tra phải tỷ lệ với rủi ro. Mọi hạng mục không áp dụng hoặc chưa thể
chạy phải có lý do và được ghi trong báo cáo; không được âm thầm bỏ qua.

---

# CANONICAL AUDIT LEVEL

## Independent concepts

`Audit Level` phản ánh độ sâu kiểm tra bắt buộc cho một thay đổi:

```text
LOW
MEDIUM
HIGH
```

`Finding Severity` phản ánh mức nghiêm trọng của một vấn đề được phát hiện:

```text
BLOCKER
HIGH
MEDIUM
LOW
```

Hai khái niệm độc lập. Audit `LOW` vẫn có thể phát hiện finding `HIGH` hoặc
`BLOCKER`; audit `HIGH` không có nghĩa thay đổi có finding nghiêm trọng. Không
dùng Audit Level làm Final Verdict và không dùng Finding Severity để thay thế
phạm vi audit.

Báo cáo phải dùng ba nhãn riêng:

```text
Audit Level: LOW | MEDIUM | HIGH
Findings By Severity: BLOCKER | HIGH | MEDIUM | LOW
Final Verdict: PASS | PASS WITH DOCUMENTED RISKS | FAIL | BLOCKED
```

## Classification process

Agent phải đánh giá Audit Level hai lần:

1. Trước implementation, dựa trên requested scope và impact analysis.
2. Sau implementation, dựa trên final diff và tác động thực tế.

Quy tắc canonical:

```text
Final Audit Level = highest applicable risk level
```

Chọn mức cao nhất có bất kỳ tiêu chí nào áp dụng. Không phân loại chỉ theo số
dòng code, số file thay đổi hoặc cảm nhận chủ quan. Audit Level là phạm vi tối
thiểu và không được hạ sau implementation chỉ vì tests đã pass.

## LOW

Chỉ dùng khi thay đổi hẹp, dễ đảo ngược, không đổi business behavior hoặc
contract quan trọng. Ví dụ: typo/label/hint không đổi nghĩa, presentation nhỏ
không đổi data/action/validation/navigation, refactor nội bộ rất hẹp có test
bảo vệ, documentation không đổi business policy/ADR/schema/source of truth,
hoặc test-only không đổi production behavior.

Không được dùng `LOW` nếu thay đổi chạm validation, authorization, tenant
scope, query/filter làm đổi tập dữ liệu, lifecycle/status transition,
persistence/database, route/middleware/public contract, shared component nhiều
consumer, transaction/idempotency/lock/concurrency, historical or immutable
data, hoặc security-sensitive behavior.

## MEDIUM

Dùng cho thay đổi behavior trong một module hoặc flow khi source of truth và
architecture boundary không đổi. Ví dụ: field không đổi schema, module
validation, query/filter/search/pagination/ordering, module UI/navigation,
CRUD behavior, action trong lifecycle hiện có, role/access cục bộ không đổi
authorization foundation, shared component có consumer xác định, bug fix nhiều
nhánh trong cùng flow, hoặc internal integration không đổi public contract.

Phải nâng `HIGH` khi tác động vượt một module, chạm architecture boundary hoặc
có nguy cơ ảnh hưởng dữ liệu lịch sử.

## HIGH

`HIGH` bắt buộc khi thay đổi chạm ít nhất một trong các yếu tố:

* Authentication/authorization architecture, role hoặc permission model.
* Tenant resolution, tenant isolation hoặc ownership.
* Shared route/middleware contract.
* Domain responsibility, source of truth hoặc foundational lifecycle/state machine.
* Published snapshot, version lock, immutable hoặc historical data.
* Database schema, migration, constraint, index hoặc data backfill.
* Cross-domain write hoặc nhiều module/consumer.
* Public API, external integration hoặc backward compatibility.
* Transaction boundary, concurrency, lock, idempotency hoặc double-submit.
* Payment, billing, entitlement hoặc security-sensitive flow.
* File/media ownership hoặc destructive cleanup.
* Shared infrastructure/reusable abstraction có blast radius lớn.
* Architecture Guardrail, ADR hoặc approved/frozen foundation.
* Nguy cơ mất, ghi sai, silent-update data hoặc rollback không đơn giản.
* Tác động phải qua Architecture Review Checklist.

Diff nhỏ không phải lý do hạ một thay đổi `HIGH`.

## Mandatory escalation

| Trigger | Minimum result |
| --- | --- |
| Dependency/consumer ngoài phạm vi ban đầu | Ít nhất `MEDIUM`; `HIGH` nếu vượt module hoặc chạm boundary |
| Final diff chạm file/domain ngoài impact analysis | Ít nhất `MEDIUM`, rồi phân loại lại toàn bộ diff |
| Test cho thấy behavior cũ đổi ngoài yêu cầu | Ít nhất `MEDIUM`; `HIGH` nếu ảnh hưởng contract/data/boundary |
| Sửa schema, route/middleware dùng chung, authorization hoặc tenant scope | `HIGH` |
| Migration, backfill, cleanup hoặc historical-data impact | `HIGH` |
| Thay đổi public contract hoặc backward compatibility | `HIGH` |
| Baseline test fail chưa được phân loại | Ít nhất `MEDIUM` và verdict `BLOCKED` cho tới khi phân loại; nâng `HIGH` nếu failure chạm high-risk scope |
| Thêm transaction, lock, idempotency hoặc constraint để bảo vệ invariant | `HIGH` |
| Policy, ADR, schema và implementation lệch nhau | `HIGH` và áp dụng stop condition phù hợp |
| Finding `HIGH`/`BLOCKER` cho thấy audit hiện tại chưa đủ | Nâng ít nhất một level; verdict vẫn tuân theo Finding Severity |

Khi mức ban đầu không còn đủ: dừng kết luận, nâng Audit Level, hoàn thành phần
checklist/verification bổ sung và cập nhật báo cáo. Không được hạ Audit Level
sau implementation.

## Required verification matrix

| Hạng mục | LOW | MEDIUM | HIGH |
| --- | --- | --- | --- |
| Current/requested behavior | Bắt buộc | Bắt buộc | Bắt buộc |
| Source of truth/invariants | Xác nhận phạm vi liên quan | Phân tích đầy đủ trong module | Phân tích đầy đủ cross-domain |
| Dependency/consumer review | Direct consumers | Direct + known indirect consumers | Full impact graph phù hợp phạm vi |
| Baseline tests | Khi có test liên quan | Bắt buộc khi khả thi | Bắt buộc; failure phải được phân loại |
| Characterization tests | Khi behavior cũ chưa rõ | Bắt buộc cho risky uncovered behavior | Bắt buộc cho high-risk uncovered behavior |
| Requirement traceability | Ngắn gọn | Đầy đủ | Đầy đủ theo requirement/invariant |
| Targeted tests | Bắt buộc | Bắt buộc | Bắt buộc |
| Module/shared tests | Khi áp dụng | Bắt buộc | Bắt buộc |
| Full suite | Không mặc định | Theo blast radius/rủi ro | Bắt buộc khi môi trường cho phép |
| Authorization/tenant tests | Khi áp dụng | Bắt buộc nếu liên quan | Bắt buộc |
| Negative/tampering paths | Khi áp dụng | Bắt buộc nếu input/action đổi | Bắt buộc |
| Transaction/idempotency/concurrency | Khi áp dụng | Khi write flow có invariant | Bắt buộc nếu liên quan |
| Migration/schema verification | Không áp dụng nếu đúng LOW | Nếu liên quan phải nâng HIGH | Bắt buộc nếu đổi data/schema |
| Browser/manual QA | Khi đổi presentation | Bắt buộc cho UI interaction đáng kể | Bắt buộc cho high-risk UI flow khi khả thi |
| Build/lint/formatter | Theo file thay đổi | Theo stack bị ảnh hưởng | Toàn bộ stack bị ảnh hưởng |
| Architecture Review | Không | Nếu phát hiện boundary thì nâng HIGH | Bắt buộc nếu chạm architecture boundary |
| Final diff review | Bắt buộc | Bắt buộc | Bắt buộc |

## Minimum commands by level

Các placeholder `<...>` phải được thay bằng test thực tế của phạm vi thay đổi.

### LOW

```bash
php artisan test <targeted-test-files>
git diff --check
```

Chạy thêm formatter, lint hoặc build phù hợp loại file. Nếu không có targeted
test phù hợp, ghi rõ lý do và dùng verification khác có thể kiểm chứng; không
được báo `PASS` chỉ dựa trên đọc code.

### MEDIUM

```bash
php artisan test <targeted-test-files>
php artisan test <related-module-or-shared-tests>
git diff --check
```

Khi áp dụng, chạy `./vendor/bin/pint --test`, `npm run build`,
`php artisan route:list -v` và browser/manual QA cho UI interaction đáng kể.

### HIGH

```bash
php artisan test <targeted-test-files>
php artisan test <related-module-or-shared-tests>
php artisan test
./vendor/bin/pint --test
npm run build
git diff --check
```

Theo tác động, bổ sung route/middleware inspection, migration/constraint
verification, tenant/authorization/historical compatibility tests,
transaction rollback, concurrency/idempotency, external-contract tests và
browser QA cho high-risk flow. Không dùng destructive migration hoặc production
data để test.

Nếu command bắt buộc không thể chạy, ghi command, nguyên nhân, verification
thay thế, `Unverified Items` và `Remaining Risks`. Chọn verdict theo mức độ
thiếu kiểm chứng; không dùng `PASS` khi còn verification trọng yếu chưa thực hiện.

---

# CHANGE SAFETY AUDIT

## Impact Analysis

```text
[ ] Current behavior và requested behavior đã được xác nhận

[ ] Source of truth và invariants đã được xác định

[ ] Direct dependencies và indirect consumers đã được rà soát

[ ] Tenant, role, authorization, lifecycle và historical-data impact đã được rà soát

[ ] Public contract, backward compatibility và migration impact đã được rà soát
```

## Baseline And Characterization

```text
[ ] Related baseline tests đã được chạy trước thay đổi khi khả thi

[ ] Baseline failure đã được phân loại trước implementation

[ ] Risky existing behavior thiếu coverage đã có characterization test
```

## Requirement Traceability

Mỗi yêu cầu và invariant phải ánh xạ đến implementation evidence và ít nhất một
test hoặc một lý do có thể kiểm chứng khi test không khả thi.

```text
[ ] Requirement -> implementation -> verification mapping đầy đủ

[ ] Negative path và request tampering đã được kiểm tra khi áp dụng

[ ] Authorization và tenant isolation đã được kiểm tra khi áp dụng
```

## Test Coverage Matrix

```text
[ ] Happy path

[ ] Validation và old input/error rendering

[ ] Authorization, role và tenant isolation

[ ] Lifecycle/state transition và historical snapshot

[ ] Transaction rollback, constraint, idempotency/double-submit khi áp dụng

[ ] Targeted tests

[ ] Module/shared tests

[ ] Full suite khi khả thi hoặc theo mức rủi ro

[ ] Lint/formatter/build/migration verification khi áp dụng
```

## Final Diff Review

```text
[ ] Diff chỉ chứa thay đổi trong phạm vi

[ ] Không có file/abstraction/migration/refactor không cần thiết

[ ] Không có debug code, secret, tenant bypass hoặc authorization bypass

[ ] Không sửa test để hợp thức hóa behavior sai

[ ] git diff --check đạt
```

## Finding Severity And Verdict

Finding Severity chỉ phân loại vấn đề đã phát hiện:

```text
BLOCKER
HIGH
MEDIUM
LOW
```

Final verdict chỉ được dùng một trong:

```text
PASS
PASS WITH DOCUMENTED RISKS
FAIL
BLOCKED
```

* `PASS`: hoàn thành mọi hạng mục của Final Audit Level; không còn finding
  `BLOCKER`/`HIGH`; traceability đầy đủ; không còn unverified item trọng yếu;
  mọi command bắt buộc pass.
* `PASS WITH DOCUMENTED RISKS`: core implementation/verification pass, không
  còn finding `BLOCKER`/`HIGH`, và chỉ còn rủi ro chấp nhận được đã ghi lý do,
  impact, next action. Không dùng để bỏ qua test bắt buộc.
* `FAIL`: verification thất bại do thay đổi, acceptance criteria/invariant
  không đạt, hoặc còn finding `HIGH` có thể tiếp tục sửa.
* `BLOCKED`: không thể tiếp tục/kết luận vì conflict, source of truth chưa rõ,
  baseline failure chưa phân loại, thiếu quyết định, finding `BLOCKER`, hoặc
  không thể thực hiện verification trọng yếu.

---

# AUTHENTICATION AUDIT

## Login

```text
[ ] /login tồn tại

[ ] Không có /admin-login

[ ] Không có /teacher-login

[ ] Không có /student-login
```

---

## Redirect

```text
[ ] customer_admin -> /admin

[ ] teacher -> /teacher

[ ] student -> /
```

---

## Verification

```text
[ ] verified middleware hoạt động

[ ] auth middleware hoạt động
```

---

# ROLE AUDIT

## Official Roles

```text
[ ] customer_admin

[ ] teacher

[ ] student
```

---

## Access Rules

```text
[ ] student không vào /admin

[ ] student không vào /teacher

[ ] teacher không vào /admin

[ ] guest không vào protected routes
```

---

# STUDENT EXPERIENCE AUDIT

```text
[ ] role:student vẫn tồn tại

[ ] Student Experience tồn tại

[ ] Student Portal không tồn tại

[ ] /student không là entry point chính
```

---

## Student Routes

```text
[ ] /my-courses protected

[ ] /learning-history protected

[ ] /ai-tutor protected

[ ] /profile protected
```

---

## Student Redirect

```text
[ ] student login -> /
```

---

# TENANT AUDIT

```text
[ ] ResolveTenant hoạt động

[ ] TenantContext hoạt động

[ ] tenant.user hoạt động
```

---

## Isolation

```text
[ ] tenant A không thấy tenant B

[ ] tenant A admin không quản lý tenant B

[ ] tenant A teacher không thấy tenant B
```

---

# NAVIGATION AUDIT

## Public

```text
[ ] Home

[ ] Courses

[ ] Assessments

[ ] Services

[ ] Teachers

[ ] About

[ ] Contact
```

---

## Student

```text
[ ] My Courses

[ ] Learning History

[ ] AI Tutor

[ ] Profile
```

---

## Admin

```text
[ ] /admin
```

---

## Teacher

```text
[ ] /teacher
```

---

# COURSE AUDIT

```text
[ ] Course Template là working draft

[ ] Course Template Version là published immutable snapshot

[ ] Course Product Item chỉ tham chiếu Course Template Version

[ ] Không có Runtime Course legacy

[ ] Enrollment thuộc Course Product

[ ] Enrollment khóa template_version_id

[ ] Progress tham chiếu Version Lesson / Version Activity

[ ] Product đổi Version không silent-update Enrollment hiện có

[ ] Version lifecycle là draft_snapshot -> published -> deprecated -> archived

[ ] Deprecated/archived Version không thay đổi Enrollment hiện có

[ ] Section optional; flat và sectioned Blueprint đều publish được

[ ] Lesson có Section luôn cùng Template và customer_id với Section

[ ] Không tạo hidden/default Section hoặc tự động tạo Section 1

[ ] Re-enrollment tạo Enrollment mới cho learning cycle mới

[ ] Progress, Completion và Product-based Certificate tham chiếu enrollment_id

[ ] Một Enrollment chỉ có một active Cohort membership

[ ] Cohort transfer update membership hiện tại, không tạo history

[ ] Notes/Bookmarks create-update chỉ khi Enrollment active

[ ] Review dùng user_id, không dùng student_id

[ ] Mỗi Product có tối đa một active Certificate mapping trong Foundation

[ ] Certificate minimum_score_percentage dùng thang phần trăm

[ ] Certificate verification luôn có tenant context và customer_id NOT NULL

[ ] Public Course Detail

[ ] Register

[ ] Purchase

[ ] Favorite

[ ] Enrollment

[ ] Learning Access
```

---

## Rule

```text
[ ] Favorite != Enrollment
```

---

# I18N AUDIT

## Locale

```text
[ ] vi default

[ ] en available

[ ] fallback en
```

---

## Translation

```text
[ ] LF_ convention used

[ ] No hardcoded multilingual UI text
```

---

## Language Switcher

```text
[ ] Public Website

[ ] Student Experience

[ ] Teacher Back Office

[ ] Admin Back Office
```

---

# SECURITY AUDIT

```text
[ ] No tenant bypass

[ ] No role bypass

[ ] No locale bypass

[ ] No direct unauthorized route access
```

---

# REQUIRED SEARCHES

Run:

```bash
grep -R "/student" .
grep -R "Student Portal" .
grep -R "student portal" .
grep -R "admin-login" .
grep -R "teacher-login" .
grep -R "student-login" .
```

---

# REQUIRED COMMANDS

Chọn command theo Final Audit Level và stack bị ảnh hưởng tại
[Minimum commands by level](#minimum-commands-by-level). Không bắt mọi thay đổi
`LOW` chạy full suite hoặc frontend build khi không liên quan.

---

# AUDIT REPORT FORMAT

Codex phải báo:

```text
Classification
Audit Level
Audit Level Rationale
Escalation Triggers Reviewed
Initial Audit Level
Final Audit Level
Current Behavior
Requested Behavior
Documents Reviewed
Source Of Truth
Invariants
Impact Analysis
Dependencies And Consumers
Files Changed
New Files
Implementation Summary
Tests Added Or Updated
Commands And Results
Requirement-To-Test Traceability
Unverified Items
Remaining Risks
Findings By Severity
Final Verdict
```

Nếu level thay đổi, thêm:

```text
Audit Level Escalation:
- From:
- To:
- Trigger:
- Additional verification performed:
```

Nếu không nâng mức: `Audit Level Escalation: None`.

---

# PASS CONDITIONS

Chỉ dùng `PASS` theo định nghĩa canonical tại
[Finding Severity And Verdict](#finding-severity-and-verdict). Thuật ngữ
`Critical` không phải Finding Severity canonical; finding ngăn kết luận là
`BLOCKER` hoặc `HIGH` theo các điều kiện verdict ở trên.

---

# Final Statement

Không được merge hoặc commit `Existing-Feature Change` khi chưa hoàn thành
Regression Audit theo mức rủi ro.

Regression Audit là bước bắt buộc để bảo vệ kiến trúc LearnForge khỏi regression ngoài ý muốn.

---

End of LF-Regression-Audit
