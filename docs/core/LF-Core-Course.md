# LF-Core-Course.md

Version: 3.1

Status: Official Foundation

Last Updated: 2026-07

---

# LF-Core Course Architecture

Kiến trúc Course Domain chính thức:

```text
Category

↓

Course Template

↓

Course Template Version

↓

Course Product

↓

Enrollment

↓

Learning Progress
```

Course Template là working draft.

Course Template Version là published snapshot immutable.

Course Product bán một Template Version cụ thể.

Enrollment khóa Template Version tại thời điểm mua/ghi danh.

---

# Core Principles

## Rule 1 — Working Draft

`core_course_templates` và nhóm Template Section/Lesson/Activity là nội dung
working để giáo viên tiếp tục chỉnh sửa.

## Rule 2 — Immutable Publish

Publish Template tạo một Course Template Version cùng Version
Section/Lesson/Activity snapshots.

Published Version không được sửa learning content.

## Rule 3 — Product Version Binding

Course Product không trỏ tới working Template.

Product Item phải trỏ tới `template_version_id`.

## Rule 4 — Enrollment Freeze

Enrollment lưu `template_version_id` tại thời điểm mua/ghi danh.

Product đổi Version không làm thay đổi Enrollment hiện có.

## Rule 5 — Versioned Progress

Learning Progress tham chiếu:

```text
version_lesson_id

version_activity_id
```

Progress không tham chiếu working Template Lesson/Activity.

## Rule 6 — No Runtime Course

Không tạo:

```text
core_courses

core_course_sections

core_course_lessons

core_course_activities
```

Template Version snapshots không phải Course Runtime. Chúng là published,
immutable revisions của Course Definition.

---

# Working Course Definition

```text
Course Template
├── Template Lesson
│   └── Template Activity
└── Template Section
    └── Template Lesson
        └── Template Activity
```

Tables:

```text
core_course_templates

core_course_template_sections

core_course_template_lessons

core_course_template_activities
```

Working content có thể được giáo viên chỉnh sửa.

Student không học trực tiếp working content.

---

# Published Course Snapshot

```text
Course Template Version
├── Version Lesson
│   └── Version Activity
└── Version Section
    └── Version Lesson
        └── Version Activity
```

Tables:

```text
core_course_template_versions

core_course_template_version_sections

core_course_template_version_lessons

core_course_template_version_activities
```

Published snapshot phục vụ:

* Course Product
* Enrollment
* Learning Progress
* Completion
* Certificate
* Tracking
* AI Context
* Historical audit

---

# Category

Category phân loại working Course Template.

```text
Course Category

1

↓

N

Course Templates
```

Database:

```text
core_course_categories
```

---

# Template Version Lifecycle

```text
Edit Working Template

↓

Create draft_snapshot

↓

Snapshot Sections / Lessons / Activities

↓

Validate Snapshot

↓

Publish Version

↓

Immutable
```

Lifecycle:

```text
draft_snapshot

↓

published

↓

deprecated

↓

archived
```

* `published`: có thể dùng cho Product sale mới.
* `deprecated`: không bán mới; existing Enrollment vẫn học.
* `archived`: chỉ lưu trữ/audit; không dùng cho Product mới.

Nếu cần sửa nội dung:

```text
Edit Working Template

↓

Publish New Version
```

Không sửa Version đã publish.

---

# Course Product

Course Product là Commerce layer.

Product chịu trách nhiệm:

* pricing
* sale campaign
* visibility
* registration window
* access duration
* certificate option
* refund policy
* marketing metadata

Product Item liên kết:

```text
Course Product

↓

Course Product Item

↓

Course Template Version
```

Product không expose working Template content.

Product không sao chép Version content.

---

# Product Version Update Policy

Product có thể được cấu hình cho Version mới chỉ bằng policy có kiểm soát.

Các lựa chọn:

```text
New sales use new Template Version

Existing Enrollments keep locked Version
```

hoặc:

```text
Create a new Course Product
```

Không được silent-update content của Enrollment hiện có.

Product versioning so với tạo Product mới cần owner xác nhận theo từng business model.

---

# Enrollment

Enrollment cấp quyền học Course Product và khóa learning Version.

```text
Student

↓

Enrollment

↓

Course Product

↓

Course Template Version
```

Required context:

```text
customer_id

student_id

product_id

template_version_id
```

Enrollment hết hạn không làm mất Version hoặc Progress history.

---

# Learning Progress

```text
Enrollment

↓

Product Progress

↓

Version Lesson Progress

↓

Version Activity Progress
```

Progress tables:

```text
core_course_progress

core_course_lesson_progress

core_course_activity_progress
```

Canonical references:

```text
template_version_id

version_section_id

version_lesson_id

version_activity_id
```

Working `template_lesson_id` và `template_activity_id` không được dùng làm
learning progress source.

---

# Notes And Bookmarks

Notes và Bookmarks của enrolled learning phải lưu:

```text
product_id

enrollment_id

template_version_id

version_lesson_id

version_activity_id
```

Video position, document page và anchor thuộc frozen Version Activity.

---

# Reviews

Review vẫn thuộc:

```text
Enrollment

↓

Course Product
```

`template_version_id` được lưu để analytics/quality reporting theo Version.

Review không thuộc working Course Template.

Review identity dùng `user_id`, không dùng `student_id`. Student là role; thiết
kế này cho phép mở rộng Teacher, QA hoặc Internal Review trong phase sau.

---

# Completion And Certificate

Completion thuộc Product/Enrollment/Template Version context.

Certificate rules:

```text
Certificate Template

↓

Certificate Template Product Mapping

↓

Course Product + Template Version
```

Issued Certificate snapshot:

* product identity
* template version identity
* version number
* Course Template title
* completion rule
* score/result
* Certificate Template/rendering data

Thay đổi Template, Version mapping hoặc Product không làm thay đổi certificate đã cấp.

Foundation certificate rules:

* Một Product có tối đa một active Certificate Template Product Mapping.
* Phase sau có thể mở rộng nhiều mapping nếu có use case được phê duyệt.
* `minimum_score_percentage` luôn là phần trăm chuẩn hóa, không phải absolute score.
* Product-based Certificate luôn tham chiếu `enrollment_id`.
* Certificate verification luôn chạy trong tenant context.
* Verification log, kể cả failed lookup, phải có `customer_id NOT NULL`.

Certificate Domain architecture and historical evidence rules are canonical at:

* [LF-Core-Certificate](LF-Core-Certificate.md).
* [ADR-0011 — Certificate Foundation](../adr/ADR-0011-Certificate-Foundation.md).

---

# Media And Assessment

Working Template Activity có thể tham chiếu Media, Assessment hoặc Live Class.

Khi publish Version Activity:

* Snapshot content reference cần thiết; hoặc
* Trỏ tới immutable/versioned asset của domain tương ứng.

Mutable external content không được làm thay đổi silent learning experience của
Enrollment đã khóa Version.

---

# Tracking And AI

Tracking/AI context tối thiểu:

```text
customer_id

user_id

product_id

enrollment_id

template_version_id

version_lesson_id

version_activity_id
```

AI có thể dùng source Template IDs cho lineage/reporting, nhưng learning context
phải dùng Version IDs.

---

# Intentional Denormalization

Published snapshots, counters, aggregates, last-position fields và metadata được
chấp nhận khi có:

```text
Purpose

Source of Truth

Publish / Update / Recalculation Rule

Allowed Consumers
```

Version snapshot là historical source of truth của enrolled learning.

Marketing/display cache không được dùng cho Completion, Certificate, Billing hoặc AI.

---

# Design Rules

1. Mọi business data phải thuộc `customer_id`.
2. Working Template và published Template Version phải tách trách nhiệm.
3. Published Version immutable.
4. Product Item tham chiếu Template Version.
5. Enrollment khóa Template Version.
6. Progress tham chiếu Version Lesson/Activity.
7. Product update không silent-migrate existing Enrollment.
8. Certificate mapping có Product + Template Version context.
9. Source Template IDs chỉ dùng lineage/reporting.
10. Không tạo lại Runtime Course tables.
11. Deprecated/archived Version không làm thay đổi existing Enrollment.

---

# Foundation P1 Decisions

* Một Enrollment là một learning cycle; học lại tạo Enrollment mới.
* Không unique vĩnh viễn Student/User–Product; Progress, Completion và
  Product-based Certificate luôn phân biệt cycle bằng `enrollment_id`.
* Section là tùy chọn; Lesson thuộc trực tiếp Template hoặc Section cùng
  Template và `customer_id`. Publish giữ nguyên cả cấu trúc flat và sectioned,
  không tạo hidden/default Section.
* Một Enrollment chỉ có một active Cohort; chuyển lớp cập nhật membership hiện
  tại, không lưu membership history và không dùng `is_current`.
* Notes và Bookmarks chỉ được tạo hoặc cập nhật khi Enrollment `active`; không
  hỗ trợ preview, guest hoặc anonymous records.
* Review dùng `user_id`, không dùng `student_id`.
* Foundation có một active Certificate mapping trên mỗi Product.
* Certificate threshold dùng `minimum_score_percentage`.
* Certificate verification luôn tenant-scoped và có owner.

---

# Final Statement

Course Template hỗ trợ authoring.

Course Template Version bảo vệ published content.

Course Product thương mại hóa một Version.

Enrollment khóa Version.

Learning Progress ghi nhận hành trình học trên Version immutable đó.

---

End of LF-Core-Course
