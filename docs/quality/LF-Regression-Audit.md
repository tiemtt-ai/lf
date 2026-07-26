# LF-Regression-Audit.md

Version: 1.2

Status: Mandatory

Last Updated: 2026-07

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

Phân loại finding:

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

`PASS` yêu cầu không còn finding `BLOCKER` hoặc `HIGH`, mọi yêu cầu có
traceability và không còn hạng mục bắt buộc chưa kiểm chứng.

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

```bash
php artisan route:list -v

php artisan test

./vendor/bin/pint

npm run build

git diff --check
```

---

# AUDIT REPORT FORMAT

Codex phải báo:

```text
Classification
Current Behavior
Requested Behavior
Documents Reviewed
Source Of Truth
Invariants
Impact Analysis
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

---

# PASS CONDITIONS

Tất cả checklist:

```text
PASS
```

và:

```text
0 Critical Issues
```

---

# Final Statement

Không được merge hoặc commit `Existing-Feature Change` khi chưa hoàn thành
Regression Audit theo mức rủi ro.

Regression Audit là bước bắt buộc để bảo vệ kiến trúc LearnForge khỏi regression ngoài ý muốn.

---

End of LF-Regression-Audit
