# LF-Core-Course.md

Version: 3.6

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

## Rule 5 Amendment — Multiple Lesson Prerequisites

[ADR-0015 — Course Lesson Multiple Prerequisites](../adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md),
replaces the single-prerequisite rule with:

* `all_previous_lessons_completed`;
* `selected_lessons_completed` with match policy `all` or `any`.

Direct and Sectioned Lessons use separate content lanes. Direct Lessons consider
earlier direct Lessons only. Sectioned Lessons use depth-first Section-tree
order, then Lesson `sort_order`, then Lesson `id`. Selected prerequisites must
precede the dependent Lesson in the same lane.

Publish freezes the effective set as immutable Version Lesson prerequisite
rows. Runtime evaluates completed Lesson Progress only in the exact tenant,
student, Enrollment, Product and Version context. Missing or inconsistent
prerequisite graphs fail closed.

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

Teacher assignments are optional mutable operating configuration of the
working Template. A Template with no active assigned Teacher receives a
non-blocking readiness warning and may still publish; the assignment can be
completed later. Assignments are not Course content and are not copied into immutable Template Versions;
later assignment changes do not mutate an existing Version, Product Item,
Enrollment or Progress. Assignment integrity at the publish boundary must not
be described as enforced until the shared publish graph loads and validates
that relationship.

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

## Course Template Publish — Content Readiness

Every published Template must contain at least one Lesson, and every Lesson
must contain at least one Activity. Sections remain optional and empty Sections
are permitted. The shared readiness validator and direct publish action must
reject corrupt or cross-tenant Section, Lesson and Activity rows instead of
silently omitting them from the Version graph.

Embedded-video Activities accept only canonical HTTPS YouTube or Vimeo URLs
normalized by `TrustedVideoUrlService`. Live Class Activities use the `manual`
completion rule; percentage and pass thresholds are integers from `1` through
`100`. Activity unlock rules are limited to `none`,
and `previous_activity_completed`, matching the immutable Version Activity
runtime contract. `VersionActivityAccessService` evaluates the frozen
prerequisite against completed Activity Progress in the exact tenant, student,
Enrollment, Product, Version and Version Lesson context. Activity prerequisites
need not precede the dependent Activity by `sort_order`.

### Activity Learning Availability

Every working Activity declares when it is intended to be available relative
to the real Cohort Session that schedules its Lesson:

* `anytime`; or
* one or more of `before_session`, `during_session`, `after_session`.

`anytime` is mutually exclusive with the three Session-relative choices.
Existing Activities default to `anytime`. Activity `sort_order` controls only
display order and does not define availability.

This declaration belongs to the Course definition and is frozen into the
Version Activity at publish. It does not reference or require a Live Class
Activity in the Template. Only a curriculum Cohort Session bound to the
relevant Version Lesson is a runtime time anchor. An operational Session
outside published content is not a Lesson/Activity availability anchor and
cannot affect Course Progress or Completion.

Learning availability is independent from `is_required`, completion rules,
unlock rules and Progress. The Course Template feature records and snapshots
the declaration only; actual time-window calculation, Session rescheduling,
automatic activation and learner reminders belong to the future Cohort/Session
runtime contract.

A Quiz Activity may be authored with a provisional positive integer
`assessment_quiz_id`, but any Template containing a Quiz Activity is blocked
from publish. Publishing remains blocked until Assessment Phase 2 defines the
tenant-owned, published and immutable Quiz binding.

Publish and every Section, Lesson or Activity mutation serialize through the
same working Template lock. Validation runs after that lock and before any
Version graph is created.

Uploaded Activity media must exist in `media_files`, belong to the same tenant,
have `status = ready`, match the Activity file type and have exactly one active
`course_activity` usage for the exact Activity and slot. Publish validates the
type, MIME and extension combination through the canonical MediaService policy;
it must not maintain a separate MIME vocabulary. Media readiness remains the
source of truth, so Activity publish validation does not perform a physical
storage HEAD/existence request.

The same Media Domain boundary applies to uploaded Template introduction media:
publish checks `status = ready`, tenant, owner/slot cardinality, file type, MIME
and extension, but performs no physical storage HEAD request while holding the
Template transaction lock.

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

## Product Registration And Promotion Windows

Thời gian đăng ký và thời gian khuyến mại đều là cấu hình không bắt buộc.
Mỗi khoảng thời gian phải tuân theo quy tắc cặp đầy đủ: hoặc cả thời điểm bắt
đầu và kết thúc cùng để trống, hoặc cả hai cùng có giá trị. Khi có giá trị,
thời điểm bắt đầu phải nhỏ hơn thời điểm kết thúc.

Nếu Product không giới hạn thời gian đăng ký, thời gian khuyến mại được phép
thiết lập độc lập. Nếu Product có thời gian đăng ký, toàn bộ thời gian khuyến
mại phải nằm trong khoảng đăng ký:

```text
registration_starts_at <= sale_starts_at

sale_ends_at <= registration_ends_at
```

Quy tắc này áp dụng thống nhất khi tạo và chỉnh sửa Product. Việc thay đổi
thời gian đăng ký phải bị từ chối nếu làm cho khoảng khuyến mại hiện tại nằm
ngoài khoảng đăng ký mới. Hệ thống không được tự động xóa hoặc điều chỉnh các
mốc thời gian người dùng đã nhập để hợp thức hóa yêu cầu.

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

## Enrollment Creation And Immutable Binding

Every Enrollment is one learning cycle. Every current and future creation
source must use the same backend creation policy:

```text
Entry point

↓

Enrollment Creation Action

↓

Enrollment Eligibility Policy

↓

Product Course Version Resolver

↓

Locked Product and Version binding

↓

Enrollment insert
```

The request may provide Student, Product, enrollment time, source and approved
optional configuration, but never `version_id`. Inside the creation transaction,
the backend reloads and validates the tenant-owned active Student and Product,
registration window, exactly one active Product Item, matching Product Item
Template and Version Template, and the tenant-owned `published` immutable
Version. Preview data is never commit authority.

`product_id` and `version_id` become immutable when the Enrollment row is
inserted. They cannot be changed while the Enrollment is `pending`, `active`,
`suspended`, `completed`, `expired` or `cancelled`. Product Version changes do
not migrate historical Enrollments. Changing Product or Version requires the
current cycle to end through its approved lifecycle and a new eligible
Enrollment to be created.

Duplicate and re-enrollment policy:

| Existing cycle status | New cycle for the same tenant, Student and Product |
| --- | --- |
| `pending` | Rejected |
| `active` | Rejected |
| `suspended` | Rejected |
| `completed` | Allowed as a new Enrollment |
| `expired` | Allowed as a new Enrollment |
| `cancelled` | Allowed as a new Enrollment |

No permanent unique constraint may be added on Student and Product because it
would block valid re-enrollment. Creation correctness instead uses deterministic
authority-row locks and duplicate revalidation. Lock order is submission or
idempotency authority when applicable, Student IDs ascending, Product IDs
ascending, Product Items by Product and Item ID, Versions by ID, then Enrollment
history by ID. Bulk owns one transaction for its complete atomic submission;
the shared creation core must not open or commit a pair-level transaction when
called by Bulk.

Application request/update whitelists reject attempts to change the binding.
The production MySQL database additionally rejects an actual update that changes
`product_id` or `version_id`; this persistence guard does not replace complete
binding validation on insert. Runtime learning always uses
`enrollment.version_id` and never falls back to the Product's current Version.

## Admin Bulk Enrollment

Customer Admin uses one unified Bulk Enrollment flow supporting one or many
Students and one or many Products. The Cartesian product `N Students × M
Products` creates independent Enrollment candidates and is limited to 100
pairs per atomic submission. There are no direction modes and no group
Enrollment.

Every pair is tenant-revalidated and resolves exactly one active Product Item
to its published immutable Version inside the submission transaction. Existing
`pending`, `active` or `suspended` cycles block the complete submission. A
terminal historical cycle requires explicit pair-level re-enrollment
confirmation and remains unchanged when the new cycle is created. Any invalid
pair rolls back the complete submission.

Admin assignment follows the same Product registration-window and automatic
Enrollment time-projection policy as every other creation source. No source
may bypass that policy unless a separate bypass is explicitly approved.

Step 2 review and confirmation may define an internal note for the submission.
Enrollment, access and review timestamps remain server-owned canonical values
calculated from Product duration configuration and the official enrollment
time; clients cannot override them.

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

Admin Enrollment lifecycle is limited to `pending -> active|cancelled`,
`active -> suspended|cancelled`, and `suspended -> active|cancelled`.
`completed`, `expired`, and `cancelled` are terminal. Completion and expiry are
system/runtime-owned transitions. Enrollment transitions never mutate Cohort
Membership; a current Membership continues to consume Cohort capacity for every
Enrollment status until an explicit Membership Remove or Transfer action.
Runtime access requires an active Enrollment, and Cohort Add/Transfer accepts
only active Enrollments.

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

### Product Registration Authority Amendment — 2026-08-04

`core_course_products.registration_starts_at` and
`core_course_products.registration_ends_at` are the only registration-window
authority for every Enrollment creation source. This includes Product context,
Cohort context, admin single and bulk creation, self-registration, purchase,
import, API and future sources.

A Cohort never owns or overrides a registration window. Cohort
`start_date`/`end_date` describe only the class operating period and must not be
used to infer, replace or validate Product registration eligibility. Businesses
that need different recruitment windows create distinct Products; those
Products may reuse the same Course Template/Version and operate different
Cohorts.

Starting from Cohort context only preselects the Cohort's Product for the shared
Enrollment creation flow. It does not introduce a Cohort-specific eligibility
policy or bypass. Assigning an already-created active Enrollment to a Cohort is
membership management, not a new Enrollment decision.

Regardless of the creation source, every Enrollment follows the same Version
resolution process.

### Registration And Time Projection Policy

Every creation source must use one shared backend policy for Product
registration eligibility and Enrollment time projection. This applies to
Customer Admin, authorized Teacher, Product Purchase, Self Registration,
Promotion/Campaign, Bulk Import, External API and any later source. No source
may automatically bypass the Product registration window without a separately
approved bypass policy.

`enrolled_at` is the official instant used for eligibility. A Product with both
`registration_starts_at` and `registration_ends_at` equal to `NULL` has no
registration-time limit. Otherwise both boundaries must exist, start must be
before end, and creation is permitted only when:

```text
registration_starts_at <= enrolled_at <= registration_ends_at
```

An incomplete or invalid Product registration window fails closed as a Product
configuration error. Comparisons use normalized datetime values under the LF
UTC and tenant/user timezone convention, never formatted date strings.

Product discovery and selection for Enrollment creation must follow the same
policy in single-create, edit and bulk workflows. Eligibility is always
evaluated against the `enrolled_at` currently selected by the operator, never
against the server's current time merely because the screen is being viewed.

Creation selectors present eligible Products in the primary list and place
ineligible Products in a collapsed secondary group. Ineligible Products are
disabled and expose a precise reason: registration has not opened, registration
has ended, the registration window is incomplete or invalid, or another
eligibility rule failed. Products without a configured registration window are
eligible with respect to time. The positive status label is **Eligible for
enrollment**, not a generic Product-validity claim.

Select-all affects eligible Products only, and selection counts report the
number selected out of the eligible result set. Search and pagination may bound
the ineligible group so expired Products do not make the primary workflow
unwieldy. An edit workflow with one immutable Product applies the same policy
to that Product and must display its exact failure reason rather than offering
a different eligibility interpretation.

Changing `enrolled_at` triggers a complete re-evaluation of Product eligibility,
group counts, select-all state and all access/review previews. A previously
selected Product that becomes ineligible remains visibly selected in a marked
correction area; the client must not silently remove it. Continue/save remains
blocked until the operator changes `enrolled_at` or removes that Product. The
backend remains authoritative and repeats the same validation during preflight
and commit/save to cover stale clients and concurrent Product changes.

Duration projection branches by the Product's authoritative `offering_type`.
For `self_paced_course`, `access_duration_days` must be a positive integer.
Creation freezes both duration inputs and the resulting access window on
Enrollment:

```text
Enrollment.access_duration_days = Product.access_duration_days

Enrollment.review_duration_days = Product.review_duration_days
```

These Enrollment fields are immutable historical snapshots. Product duration
changes must never update them. A new self-paced Enrollment must have a
positive `access_duration_days`; `review_duration_days` may be `NULL`, `0`, or
a positive integer according to the Product contract.

The access window is calculated as:

```text
access_starts_at = enrolled_at

access_ends_at = access_starts_at + access_duration_days
```

The main access interval is half-open:

```text
access_starts_at <= now < access_ends_at
```

If `review_duration_days > 0`, the review window immediately follows without
overlap or gap:

```text
review_starts_at = access_ends_at

review_ends_at = review_starts_at + review_duration_days
```

The review interval is also half-open:

```text
review_starts_at <= now < review_ends_at
```

If review duration is `NULL` or `0` according to the Product contract,
`review_starts_at` and `review_ends_at` remain `NULL`. Lesson, Activity, Live
Class or expected-session counts must never be used as duration inputs.
Clients may preview these values but cannot submit authoritative duration or
computed timestamps; the backend resolves Product duration and calculates the
final Enrollment values.

For `live_online_course`, Product and Enrollment store both duration fields as
`NULL`; `access_starts_at`, `access_ends_at`, `review_starts_at`, and
`review_ends_at` are also `NULL`. Enrollment creation does not reject that
state and expiry automation must not interpret it as expired. The system must
not infer live duration from Lessons, Live Class Activities, Sessions,
Schedules, or Cohort dates. Runtime access still requires an active Enrollment
and all applicable Cohort, Session, and LiveClass authorization checks.

All Enrollment creation sources use this same offering-aware backend policy.
This includes admin single and bulk workflows and any self-registration,
purchase, import, or API source.

The stored Enrollment duration snapshots and timestamps are historical frozen
results. Later changes to Product duration, Product Version or Product
registration window do not change existing Enrollments and must not trigger a
silent batch update.

Editing status, Cohort, notes or other unrelated data preserves all Enrollment
time fields and duration snapshots.

An approved workflow may change `enrolled_at` only when the Enrollment has
valid duration snapshots. The shared backend policy must revalidate the new
instant against the Product registration window and all other current
eligibility rules, then recompute the complete timestamp chain from the
Enrollment snapshots, never from current Product duration values. Changing
`enrolled_at` does not permit changing Product, Version, duration snapshots or
extending access independently.

Legacy Enrollments whose duration snapshots are `NULL` keep their stored
timestamps and cannot change `enrolled_at`. The interface must explain that the
historical duration cannot be reconstructed safely. Duration snapshots must
not be backfilled from current Product values. Derivation from historical
timestamps is permitted only under a separately reviewed data-repair policy
that proves those timestamps were generated automatically and were never
manually edited or extended.

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
* Mọi Cohort mới khóa một Product và published Version được server resolve từ
  đúng một active Product Item hợp lệ. Request không được chọn Version. Product
  đổi Version không cập nhật Cohort hoặc Enrollment đã có.
* Cohort lifecycle là `draft -> active -> completed -> archived`, cùng nhánh
  `draft -> archived`. Cohort `draft` và `active` được quản lý membership như
  setup operation theo authorization và validation hiện hành; chỉ Cohort
  `active` được thực hiện runtime operations.
* Chỉ Enrollment `active` được thêm hoặc chuyển vào Cohort `draft` hoặc
  `active`. Membership trong Cohort `draft` không kích hoạt Cohort, không đổi
  Enrollment status và không tự cấp learning access.
* Activation `draft -> active` là action riêng và phải revalidate toàn bộ
  membership, tenant/Product/Version binding, capacity cùng các readiness
  requirements áp dụng. Nếu có lỗi, Cohort giữ nguyên `draft` và server trả về
  đầy đủ các điều kiện chưa đạt.
* Cohort legacy thiếu Product/Version không được activate, nhận membership hoặc
  làm runtime context cho tới khi binding được resolve chắc chắn.
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
