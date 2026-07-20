# core_course_cohorts

## Purpose

`core_course_cohorts` stores operational learning groups for the Course
Domain.

A Cohort represents a class, batch, group or delivery container used to operate
learning for students.

Example:

```text
TOPIK Beginner

↓

Morning Class

Evening Class

Weekend Class
```

A Cohort is operational only.

It does not own learning content.

Learning content remains owned by:

```text
Published Course Version
```

---

## Policy Decision

Foundation v1 aligns `core_course_cohorts` with the current Course Domain Phase
4 policy.

Decision:

* `product_id` and `version_id` remain physically nullable for legacy compatibility.
* Every new Cohort requires a Product and server-resolved published Version.
* The cohort code field is named `code`.
* `code` is nullable.
* Foundation statuses are `draft`, `active`, `completed`, `archived`.
* Advanced delivery, schedule, meeting and corporate fields are deferred.

This keeps Cohort as an operational grouping layer without making it a learning
source, runtime authority or student membership table.

---

## Relationships

```text
saas_customers
1
↓
N
core_course_cohorts
```

```text
core_course_products
1
↓
N
core_course_cohorts
```

Optional. A Cohort may be organized around a Product.

```text
core_course_template_versions
1
↓
N
core_course_cohorts
```

Optional. A Cohort may be tied to a published Course Version for operational
planning and reporting.

```text
users
1
↓
N
core_course_cohorts
```

Optional. A Cohort may have one primary teacher.

```text
core_course_cohorts
1
↓
N
core_course_cohort_students
N
↓
1
core_course_enrollments
```

Student membership is not stored in `core_course_cohorts`.

---

## Business Rules

* Every Cohort must belong to `customer_id`.
* A Cohort may optionally belong to `product_id`.
* A Cohort may optionally belong to `version_id`.
* `teacher_id` is legacy/deprecated and is not a canonical teacher authority.
* `version_id`, when present, references an immutable published Course Version.
* `version_id` must not reference an editable Course Template.
* Cohort does not own learning content.
* Cohort does not grant learning access.
* Enrollment remains the runtime authority for student learning access.
* Student membership belongs to `core_course_cohort_students`.
* Cohort does not own Progress, Assessment, Certificate, Tracking or AI data.
* Cohort is operational only.
* A Cohort should be archived instead of hard deleted once operational records
  or student membership exist.

---

## Fields

### id

```text
BIGINT UNSIGNED
PRIMARY KEY
AUTO_INCREMENT
```

Primary key.

---

### customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant owner.

Foreign Key →

```text
saas_customers.id
```

---

### product_id

```text
BIGINT UNSIGNED
NULL
```

Optional Course Product associated with the Cohort.

Foreign Key →

```text
core_course_products.id
```

---

### version_id

```text
BIGINT UNSIGNED
NULL
```

Optional published Course Version associated with the Cohort.

Foreign Key →

```text
core_course_template_versions.id
```

`version_id` always references an immutable published Course Version.

`version_id` never references an editable Course Template.

---

### teacher_id

```text
BIGINT UNSIGNED
NULL
```

Optional primary teacher responsible for the Cohort.

Foreign Key →

```text
users.id
```

The referenced user should belong to the same tenant and have role:

```text
teacher
```

---

### name

```text
VARCHAR(255)
NOT NULL
```

Display name of the Cohort.

Examples:

```text
TOPIK Beginner Morning Class

TOPIK Beginner Weekend Class
```

---

### code

```text
VARCHAR(100)
NULL
```

Optional operational code for search, filtering or internal administration.

Examples:

```text
TOPIK-BEG-MORNING

TOPIK-BEG-WEEKEND
```

Foundation v1 does not require a permanent unique constraint on `code`.

---

### description

```text
TEXT
NULL
```

Optional operational description.

---

### notes

```text
TEXT
NULL
```

Optional internal tenant/admin/teacher notes for operational comments about the
Cohort.

`notes` is not public-facing by default.

`metadata` must not be used as a replacement for Cohort notes.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'draft'
```

Cohort lifecycle status.

Allowed values:

```text
draft

active

completed

archived
```

---

### capacity

```text
INT UNSIGNED
NULL
```

Optional maximum number of students for the Cohort.

`NULL` means no capacity limit is currently defined.

---

### start_date

```text
DATE NULL
```

Optional planned start date.

---

### end_date

```text
DATE NULL
```

Optional planned end date.

---

### metadata

```text
JSON NULL
```

Optional system/internal extension data.

Metadata must not become the source of learning authority, learning content,
Progress, Assessment, Certificate, Tracking or AI context.

Metadata must not be exposed as a raw user-editable notes field.

---

### created_at

```text
TIMESTAMP NULL
```

Creation timestamp.

---

### updated_at

```text
TIMESTAMP NULL
```

Update timestamp.

---

## Indexes

```sql
INDEX idx_course_cohorts_customer
(customer_id);
```

```sql
INDEX idx_course_cohorts_status
(customer_id, status);
```

```sql
INDEX idx_course_cohorts_teacher
(customer_id, teacher_id);
```

```sql
INDEX idx_course_cohorts_product
(customer_id, product_id);
```

```sql
INDEX idx_course_cohorts_version
(customer_id, version_id);
```

---

## Unique Constraints

Foundation v1 defines no permanent unique constraint for
`core_course_cohorts`.

---

## Deferred Fields

The previous detailed operational schema included fields for richer delivery
management.

These fields are deferred from Foundation v1:

```text
cohort_type
delivery_mode
max_students
current_students
starts_at
ends_at
enrollment_starts_at
enrollment_ends_at
timezone
schedule_summary
meeting_provider
meeting_url
corporate_customer_name
```

They may be reintroduced later through an approved policy and database
documentation update when Live Class, scheduling, corporate training or advanced
delivery management requires them.

---

## Sample Data

### Product Cohort

```text
id = 1

customer_id = 1

product_id = 10

version_id = 7

teacher_id = 25

name = TOPIK Beginner Morning Class

code = TOPIK-BEG-MORNING

status = active

capacity = 30

start_date = 2026-07-01

end_date = 2026-09-30

notes = Bring printed placement tests.
```

---

### Operational Cohort Without Product

```text
id = 2

customer_id = 1

product_id = NULL

version_id = NULL

teacher_id = NULL

name = Weekend Placement Group

code = NULL

status = draft

notes = NULL
```

---

## Final Statement

## Approved Lifecycle And Legacy Binding Amendment

Every new Cohort binds a Product and a published Version resolved by the
server from exactly one valid active Product Item. `version_id` is never
accepted from request input and both bindings are frozen after creation.

Allowed transitions are `draft -> active`, `draft -> archived`,
`active -> completed`, and `completed -> archived`. Completed and archived
Cohorts are read-only. Legacy rows with unresolved Product/Version remain
nullable but cannot become active, receive membership, or supply runtime
context. Backfill is permitted only when every membership Enrollment implies
the same Product and Version.

After a duplicate audit succeeds, Cohort code is unique by
`(customer_id, code)`.

`core_course_cohorts` is the operational group table for Course learning
operations.

Correct responsibility:

```text
Course Product / Published Course Version

↓

Cohort / Class / Batch

↓

Cohort Students
```

Cohort groups students operationally through
`core_course_cohort_students`.

Cohort does not replace Product, Enrollment or Published Course Version.

Cohort does not own learning content, Progress, Assessment, Certificate,
Tracking or AI context.
