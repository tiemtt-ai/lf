# LF-Regression-Audit.md

Version: 1.1

Status: Mandatory

Last Updated: 2026-07

---

# Purpose

Checklist bắt buộc phải chạy sau:

```text
Refactor

Major Feature

Authentication Changes

Tenant Changes

Navigation Changes

UI Changes

I18N Changes
```

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
Issues Found

Files Changed

Tests Added

Tests Updated

Suspicious Routes

Remaining References
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

Không được merge hoặc commit các thay đổi lớn khi chưa hoàn thành Regression Audit.

Regression Audit là bước bắt buộc để bảo vệ kiến trúc LearnForge khỏi regression ngoài ý muốn.

---

End of LF-Regression-Audit
