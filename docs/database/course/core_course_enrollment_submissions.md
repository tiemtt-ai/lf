# Table: core_course_enrollment_submissions

Version: 1.0

Status: Approved and Frozen

Approval Date: 2026-07-21

Document Path: database/course/core_course_enrollment_submissions.md

## Purpose

Stores the durable preflight and idempotency authority for one Customer Admin
Bulk Enrollment submission. It is not an Enrollment group, learning cycle,
batch source or replacement for `core_course_enrollments`.

One submission normalizes unique sorted Student IDs and Product IDs. Their
Cartesian product produces independent Student–Product Enrollment candidates:

```text
student_count × product_count = pair_count <= 100
```

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `admin_id` | BIGINT UNSIGNED | Customer Admin that prepared the submission. |
| `token_hash` | CHAR(64) | Unique SHA-256 hash; raw token is never stored. |
| `payload_hash` | CHAR(64) | SHA-256 of stable canonical JSON. |
| `student_ids` | JSON | Unique ascending Student IDs. |
| `product_ids` | JSON | Unique ascending Product IDs. |
| `reenrollment_confirmations` | JSON | Sorted `{student_id, product_id, previous_enrollment_id}` confirmations. |
| `configuration` | JSON | Normalized access/review windows and internal note. |
| `pair_count` | UNSIGNED SMALLINT | Exact Cartesian pair count; backend invariant `1..100`. |
| `status` | VARCHAR(50) | `prepared`, `completed` or `invalidated`; no database ENUM. |
| `expires_at` | TIMESTAMP | Prepared-token expiry, 30 minutes after issue. |
| `committed_at` | TIMESTAMP NULL | Atomic commit time. |
| `invalidated_at` | TIMESTAMP NULL | Explicit invalidation time. |
| `result` | JSON NULL | Durable result returned by idempotent replay. |
| `created_at`, `updated_at` | TIMESTAMP NULL | Audit timestamps. |

## Constraints and indexes

- Unique `token_hash`.
- Index `(customer_id, admin_id, status, expires_at)`.
- Index `(customer_id, created_at)`.
- `customer_id` references `saas_customers.id` with restricted deletion.
- `admin_id` references `users.id` with restricted deletion. Durable submission
  evidence is not cascade-deleted when an Admin or tenant is removed.
- Backend preflight and commit both enforce `pair_count <= 100`; selections are
  never truncated.
- No permanent Student–Product uniqueness is introduced.

## Canonical payload

Canonical JSON uses fixed keys, normalized nullable values and ascending IDs.
Re-enrollment confirmations sort by Student, Product, then previous Enrollment.
The payload includes:

```text
student_ids
product_ids
reenrollment_confirmations
configuration
```

Changing any value produces a different `payload_hash` and invalidates use of
the prior prepared token for the changed payload.

## Transaction and replay policy

Commit locks the submission first, then locks authority rows in deterministic
order:

```text
Submission
→ Students ordered by id
→ Products ordered by id
→ Product Items / published Versions ordered by Product and Item id
→ existing Enrollments ordered by Student, Product and Enrollment id
```

All candidates are revalidated before inserts. Any invalid candidate rolls back
the entire transaction. Enrollment inserts, Product enrollment counters and the
submission `completed` result are committed together. A rollback leaves the
submission uncompleted. Replaying a completed token for the same tenant, Admin
and canonical payload returns `result` without creating Enrollment.

## Domain boundaries

- Enrollment source remains canonical `admin`; no `bulk` source is added.
- Product and immutable published Version resolution remains server-owned.
- Submission records do not mutate Enrollment lifecycle, Cohort, Progress,
  Completion or historical learning evidence.
