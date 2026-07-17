# LF-Core-Course.md

Version: 3.5

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

Product Item phải trỏ tới `version_id`.

## Rule 4 — Enrollment Freeze

Enrollment lưu `version_id` tại thời điểm mua/ghi danh.

Product đổi Version không làm thay đổi Enrollment hiện có.

## Rule 5 — Versioned Progress

Learning Progress tham chiếu:

```text
version_lesson_id

version_activity_id
```

Progress không tham chiếu working Template Lesson/Activity.

Student Version Lesson access is evaluated from the active Enrollment and the
immutable Version Lesson. `previous_lesson_completed` means the manually
selected prerequisite Version Lesson and requires completed Lesson Progress in
the exact same tenant, Enrollment, Product, Version and student context.
`date_based` compares the frozen UTC timestamp; unknown/inconsistent rules fail
closed.

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
    ├── Template Section
    │   └── Template Lesson
    │       └── Template Activity
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

Mỗi Section bắt buộc khai báo `allows_lessons`. Chỉ Section có giá trị `true`
được hiển thị thao tác và chấp nhận Lesson; hierarchy không mặc định đồng nghĩa
với khả năng chứa Lesson.

## Course Authoring Tree UI

Course Template là object duy nhất hiển thị Status trong editing structure.
Section, Lesson và Activity vẫn giữ business fields cần thiết nhưng không hiển
thị Status trong authoring tree.

Presentation hierarchy:

```text
Course Template
└── Section (optional grouping container)
    └── Lesson (primary authoring unit)
        └── Activity rows
```

* Activity nằm trực tiếp dưới Lesson, không dùng nested Activity card.
* Mỗi Activity row hiển thị icon, title text, View, Edit và Delete theo thứ tự.
* Activity title là text thuần, không clickable. View là action riêng và không
  trỏ tới Edit.
* Activity có attached Media hợp lệ mở tenant-scoped signed URL trong tab mới;
  external-link Activity mở URL HTTP(S) hợp lệ trong tab mới.
* Các Activity khác mở readonly Activity detail trong cùng authoring context.
  Readonly detail không có form control hoặc mutation, nhưng cung cấp Back và
  Edit riêng cho user đã được authorize.
* Media/external Activity không có target trực tiếp hợp lệ dùng readonly detail
  cho action View.
* Lesson không có lifecycle/status riêng. Activity status không hiển thị trong tree.
* `lesson_type` phân loại semantic Lesson bằng các code `regular`, `review`,
  `midterm_exam`, `final_exam`, `other_exam`. Classification không tự thay đổi
  scheduling, grading, completion, unlock, Assessment, Activity hoặc Cohort.
* Empty Activity state chỉ hiển thị `Chưa có hoạt động.`.
* Section `allows_lessons = false` không render Lesson heading, count, empty
  state hoặc action gắn Lesson; child Sections vẫn hiển thị bình thường.

Student không học trực tiếp working content.

---

# Published Course Snapshot

```text
Course Template Version
├── Version Lesson
│   └── Version Activity
└── Version Section
    ├── Version Section
    │   └── Version Lesson
    │       └── Version Activity
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

The approved field-level snapshot architecture is defined by:

* [ADR-0012 — Course Template Published Version Snapshot](../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md).
* [Course Template Version database documentation](../database/course/core_course_template_versions.md).

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

## Course Template Lifecycle And Product Eligibility

This section is the canonical source of truth for the relationship between a
working Course Template, immutable Template Versions and Course Product
binding.

Course Template data uses exactly four statuses:

```text
draft
active
inactive
archived
```

Create and normal edit expose only `draft`, `active` and `inactive`.
`archived` is entered only through the dedicated Admin archive action. Only an
`inactive` Template may be archived. Archived Templates are read-only.

Only an `active` Template may publish a new Version. Publish validates the
locked working Template inside its transaction and does not change the working
Template status. Template lifecycle changes never update, invalidate or
silently migrate an existing Version, Product Item, Enrollment or Progress.

## Course Template Publish — Information Readiness

This section is the canonical policy for validating working Course Template
information at the publish boundary. Readiness presentation and the direct
publish action must use the same server-side validator. Publish opens a
transaction, locks and reloads the working Template, then runs that shared
validation before creating any Version snapshot.

Only a working Template with `status = active` may publish. Its Course Category
must exist, belong to the same `customer_id`, have a non-blank valid name and
have `status = active`. `title` and the free-text `publisher_name` are required,
must not be blank after trimming and must not exceed 255 characters.

`short_description`, `description`, SEO fields, `difficulty_level`,
`estimated_minutes_per_lesson` and `estimated_lesson_count` remain nullable.
When present:

* `short_description` and SEO fields follow their canonical documented length
  limits;
* `description` is plain text and every presentation must render it escaped;
  raw HTML execution is not permitted;
* `difficulty_level` must be `beginner`, `intermediate` or `advanced`;
* `estimated_minutes_per_lesson` and `estimated_lesson_count` must be integers
  greater than or equal to `1`.

`estimated_lesson_count` is descriptive metadata, not a structural limit. A
difference from the actual working Lesson count produces a warning only. It
does not block publish and must not recalculate or mutate the authored value.

Introduction image, video and document are independent optional items and may
coexist. An uploaded introduction item must reference a same-tenant Media File
with `status = ready`, the canonical file type, MIME type and extension, and
exactly one active Media Usage for the working `course_template`, exact
Template ID and matching intro slot. Embedded introduction video accepts only
a canonical HTTPS YouTube or Vimeo source normalized and verified through
`TrustedVideoUrlService`; upload and embed states remain mutually exclusive.

Information that passes readiness is frozen into the immutable Course Template
Version according to
[ADR-0012 — Course Template Published Version Snapshot](../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md).
Any publish failure rolls back the new Version and all new Version Media
Usages. It must not leave a partial snapshot.

An inactive Template, inactive Category or invalid legacy authoring state may
remain visible and editable under existing authorization, but cannot create a
new Version until corrected. Blocking a new publish never modifies or
invalidates an existing Version, Product Item, Enrollment or Progress and must
not silently migrate historical consumers.

A new Course Product may select a Template only when all conditions hold:

* the Template belongs to the current `customer_id`;
* the Template belongs to the selected Course Category;
* the Template status is `active`;
* the Template has at least one Version with status `published`.

After selecting a Template, the Product may select any specific `published`
Version belonging to that Template and tenant. `deprecated` and `archived`
Versions are not eligible for a new binding. `is_current` does not by itself
limit the eligible published Versions.

An existing Product keeps its persisted Template and Version binding when the
working Template later becomes `inactive` or `archived`. Edit must continue to
show that historical binding and may update unrelated Product fields without
revalidating the unchanged binding against new-Product eligibility. If the user
changes either Template or Version, the complete new-Product eligibility rules
apply to the requested target binding.

Product Item and Enrollment continue to reference the immutable Version.
Template lifecycle therefore must not unlink Product Items, replace Versions,
silent-migrate Enrollments, or reset Progress.

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

# Duplicate Published Version To Draft

A Course Template has one editable draft and many immutable published
Versions. A `customer_admin` may select an immutable Version and replace the
working content of that Version's own Template.

```text
Selected immutable Version

↓ duplicate

Existing Template working draft
```

Duplicate:

* keeps the existing Template identity;
* replaces working Sections, Lessons and Activities transactionally;
* restores direct, nested Section and Sectioned Lesson structure, ordering and
  prerequisites;
* sets the working Template status to `draft`;
* increments the existing `working_revision`;
* does not create or modify a published Version;
* does not create a Template, Product or second draft;
* does not change Product, Enrollment, Progress or Completion.

The action belongs to Course Edit → History and Version Detail. Canonical
behavior is defined by
[ADR-0013 — Course Template Version Duplicate to Draft](../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md).

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

Canonical Template/Version selection and historical-binding policy is defined
in [Course Template Lifecycle And Product Eligibility](#course-template-lifecycle-and-product-eligibility).

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

Enrollment không chỉ là quan hệ Student ↔ Course.

Enrollment đại diện cho:

```text
Student

+

Product

+

Published Course Version

+

Cohort optional

+

Learning Lifecycle
```

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

version_id
```

`version_id`

Foreign Key →

```text
core_course_template_versions.id
```

`version_id` luôn tham chiếu immutable published Course Version.

`version_id` không bao giờ tham chiếu editable Course Template.

Business rules:

* Enrollment phải thuộc `customer_id`.
* Enrollment phải thuộc một student user.
* Enrollment nên lưu `product_id` khi quyền truy cập được cấp thông qua Course
  Product.
* Enrollment phải lưu `version_id` của published Course Version được
  giao cho student.
* Enrollment không được trỏ trực tiếp tới editable Course Template làm learning
  source.
* Khi Product đổi sang active Version mới hơn, existing Enrollments giữ nguyên
  `version_id` ban đầu.
* New Enrollments nhận active Product Item Version tại thời điểm access được
  cấp.
* Cohort là optional. Không phải Enrollment nào cũng cần Cohort.
* Enrollment không nên hard delete khi đã có learning progress, assessment
  attempts, certificate hoặc tracking data downstream.
* Enrollment là access authority cho learning, progress, certificate
  eligibility và AI learning context.

Enrollment sources:

```text
admin

teacher

self_registration

purchase

promotion

import

api
```

Enrollment lifecycle statuses:

```text
pending

active

suspended

completed

expired

cancelled
```

Enrollment hết hạn không làm mất Version hoặc Progress history.

## Enrollment Relationship

```text
Student

↓

Enrollment

↓

Product

↓

Published Course Version

↓

Progress

↓

Assessment

↓

Certificate

↓

Tracking

↓

AI
```

Enrollment là runtime authority của learning.

All learning runtime modules should resolve student access through Enrollment.

Progress, Assessment, Certificate, Tracking và AI Context đều gắn với enrolled
published Version.

## Enrollment Runtime Authority

Enrollment determines:

* whether a student can access learning;
* which Product granted access;
* which published Version is assigned;
* optional Cohort membership;
* learning lifecycle status.

Enrollment là single source of truth cho learning access.

## Enrollment Creation Policy

Enrollment may be created from:

* Customer Admin;
* Teacher, if permitted by tenant policy;
* Product Purchase;
* Self Registration;
* Promotion / Campaign;
* Bulk Import;
* External API.

Regardless of the creation source, every Enrollment follows the same Version
resolution process.

### Version Resolution

Official flow:

```text
Student

↓

Enrollment Request

↓

Resolve Product

↓

Resolve Current Active Product Item

↓

Resolve Published Course Version

↓

Store version_id

↓

Create Enrollment
```

Enrollment never chooses a Published Version directly.

The assigned Version is always resolved from the Product at the moment the
Enrollment is created.

### Version Freeze Rule

```text
Product A

↓

Current Active Version

↓

Version 7

Student A purchases today

↓

Enrollment.version_id = 7

Later

Product A switches to

Version 8

Student A

↓

continues Version 7

Student B

↓

receives Version 8
```

Changing a Product never changes historical Enrollments.

This guarantees:

* learning consistency;
* progress consistency;
* assessment consistency;
* certificate consistency;
* tracking consistency;
* AI context consistency.

### Special Enrollment Rules

1. One Enrollment never changes its assigned `version_id` after creation.
2. Changing Product configuration never updates historical Enrollments.
3. Enrollment may optionally belong to one Cohort.
4. Progress, Assessment, Certificate, Tracking and AI always use the
   Enrollment's `version_id`.
5. Enrollment should never reference editable Course Templates.
6. Enrollment should never be recreated simply because a Product receives a
   newer Version.

Product and Version relationship:

```text
Course Template

↓

Published Course Version

↓

Course Product

↓

Enrollment

↓

Progress / Assessment / Certificate / Tracking / AI
```

Example:

```text
Product A currently sells Version 7.

Student A enrolled today:
- enrollment.version_id = 7

Later Product A is updated to Version 8.

Student A remains on Version 7.
Student B enrolled later receives Version 8.
```

This protects historical learning consistency.

Implementation guidance:

* Do not implement Enrollment as simple `student_id + course_id` only.
* Do not use editable `template_id` as the source of student learning.
* Use `product_id` and `version_id` to preserve snapshot-based learning.
* Validate tenant isolation using `customer_id`.
* Avoid hard delete if downstream learning records exist.

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
version_id

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

version_id

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

`version_id` được lưu để analytics/quality reporting theo Version.

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

version_id

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
