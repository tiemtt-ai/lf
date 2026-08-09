# Enrollment Lifecycle Architecture Review

Version: 1.1

Document Status: Frozen

Implementation Status: Unknown

Last Updated: 2026-08-09
Date: 2026-08-01

Document Path: quality/LF-Enrollment-Lifecycle-Architecture-Review.md

## Scope reviewed

- Enrollment canonical statuses and timestamps.
- Admin transition matrix supplied for this review.
- Enrollment Edit/Show UI and mutation routes.
- Course runtime access checks.
- Cohort membership eligibility and capacity behavior.
- Existing Enrollment status/timestamp consistency.

## Approved transition authority

Admin-owned transitions proposed by the approved request:

```text
pending -> active
pending -> cancelled
active -> suspended
active -> cancelled
suspended -> active
suspended -> cancelled
```

System/runtime-owned and out of scope:

```text
active -> completed
pending|active|suspended -> expired
```

`completed`, `expired`, and `cancelled` are terminal. No outgoing transition is
approved. A new learning cycle requires a new Enrollment.

## Schema audit

Canonical columns currently present:

- `completed_at`
- `expired_at`
- `cancelled_at`

Columns not present and not defined by the authoritative database document:

- `activated_at`
- `suspended_at`

No migration may be created for the missing columns without an approved
database amendment. Activation and suspension therefore cannot invent or
clear timestamp semantics.

## Runtime audit

- `VersionActivityAccessService` fails closed unless Enrollment status is
  exactly `active`.
- Cohort add/transfer eligibility accepts only active Enrollments.
- Enrollment creation by Customer Admin is server-owned `active`; Create must
  not expose a status selector.
- Existing general Edit currently accepts an arbitrary target status. This is
  identified for removal after the blocker below is resolved.

## Data consistency audit

The local database was checked for:

- terminal status with missing matching timestamp;
- populated terminal timestamp with a different status.

All six inconsistency counts were zero. No remediation or backfill was run.

## Approved Cohort capacity policy

Enrollment transitions never mutate Cohort Membership. A current Membership
continues to count toward capacity regardless of Enrollment status. Only an
explicit Membership Remove or Transfer action releases or moves capacity.

Runtime learning access still requires `Enrollment.status = active`. Add and
Transfer continue to accept only active Enrollments. No cross-domain lifecycle
matrix, Membership history, `is_current`, `activated_at`, or `suspended_at` is
introduced.

## Bulk lifecycle actions

The Admin Enrollment list exposes only explicit bulk lifecycle mutations.
Generic bulk common-information updates are not exposed; notes and other
editable metadata remain individual Enrollment edits. Bulk lifecycle accepts
only a canonical action:

```text
suspend     active -> suspended
reactivate suspended -> active
cancel      pending|active|suspended -> cancelled
```

The server resolves the target status; clients never submit an arbitrary
target. Availability in the UI is advisory and requires every selected row to
support the action. The server revalidates the complete selection under lock.

Each request normalizes unique positive Enrollment IDs and is limited to 100
submitted IDs. The transaction loads exactly the tenant-owned rows, ordered by
Enrollment ID, with `FOR UPDATE`. A missing, cross-tenant, stale or ineligible
row rejects the whole request. No partial success or silent skipping is
allowed. Reactivation then locks required Product rows in ascending Product ID
order before validating Product existence, immutable published Version and
the Enrollment access window. This preserves the existing lock hierarchy:

```text
Enrollment IDs ascending -> Product IDs ascending
```

Cancel writes one server timestamp to `cancelled_at` for every row in that
transaction. Suspend and reactivate preserve the existing `cancelled_at`
value. Competing requests serialize through row locks; the loser revalidates
the new status and fails atomically rather than applying a second transition.

Bulk lifecycle never changes Student, Product, immutable `version_id`, source,
access/review configuration, Cohort Membership/capacity, Progress, Completion,
Assessment or Certificate state. Runtime learning access continues to require
`status = active`.

The current Enrollment lifecycle implementation has no canonical domain audit
event/log writer. The repository-wide SaaS audit table is not used by the
existing single Enrollment lifecycle flow. Bulk lifecycle therefore does not
invent a competing audit mechanism or schema; this remains an explicit audit
infrastructure gap for a future approved architecture change.

## Policy 2 addendum — Enrollment duration snapshots

### Decision

Approved additive nullable columns on `core_course_enrollments`:

```text
access_duration_days INTEGER UNSIGNED NULL
review_duration_days INTEGER UNSIGNED NULL
```

They are immutable historical snapshots owned by Enrollment, copied from the
tenant-owned Product during creation in the same transaction. They do not
change Product or Version ownership and introduce no cross-domain write.

New Enrollment invariants are enforced by the shared backend creation policy:

```text
access_duration_days > 0
review_duration_days IS NULL OR review_duration_days >= 0
```

The database columns remain nullable for backward compatibility. Existing rows
are not backfilled because current Product values are not reliable historical
evidence. A legacy row with missing snapshots preserves its current timestamps
and cannot change `enrolled_at`.

### Existing-feature architecture gate

- Source of truth remains Enrollment for runtime access and Product for new
  Enrollment configuration.
- Tenant isolation remains explicit through `customer_id` on all Product and
  Enrollment reads/writes.
- Product, Version, source and lifecycle invariants are unchanged.
- Public routes do not change; create/edit request contracts add only the
  approved `enrolled_at` behavior.
- Historical timestamps and legacy rows are preserved without inference or
  backfill.
- Migration is additive and rollback removes only the two new nullable columns;
  no historical migration may be edited.

### Review result

Architecture Review: **Passed**.

ADR: not required because this is an additive existing-feature schema change
inside the established Course/Enrollment ownership boundary, not a Foundation
or cross-domain architecture decision.

Owner approval: approved in the implementation request on 2026-08-01.

## Implementation conformance audit — Course Version lock

Audit date: 2026-08-03

Scope: code review only; no implementation or schema change

Verdict: **FAIL**

This section records implementation conformance against the existing Enrollment
Version policy. It does not amend or supersede the approved lifecycle decisions
above.

### Executive conclusion

Admin single-create and Admin Bulk Enrollment currently resolve `version_id` on
the server from exactly one active Product Item whose Version is `published`.
Both exposed Admin update and lifecycle paths preserve `product_id` and
`version_id`. Existing lesson/activity runtime uses `enrollment.version_id`, so
changing a Product Item from Version 7 to Version 8 does not migrate an existing
Enrollment.

The binding is nevertheless not fully immutable across all required layers:

- `product_id` and `version_id` remain ordinary mutable database columns. The
  schema has independent foreign keys but no trigger or equivalent persistence
  enforcement preventing later updates.
- There is no Enrollment model/observer or sole shared Enrollment creation
  action. Current production code uses Query Builder directly.
- Single-create and Bulk duplicate Product/Version resolution and implement
  different duplicate/re-enrollment policies. Bulk rejects an existing
  non-terminal cycle and explicitly confirms terminal re-enrollment;
  single-create performs no existing-cycle check.
- Neither resolver verifies that `core_course_product_items.template_id` equals
  the linked `core_course_template_versions.template_id`.
- Only Admin single-create and Admin Bulk creation exist. Teacher,
  self-registration, purchase, promotion, import and API creation sources are
  documented values but have no implemented writer to verify.
- Create shows Version information and edit/show render Product and Version as
  read-only text, but the explicit “Đã khóa khi ghi danh” badge and historical
  helper text are absent.

The accurate current characterization is therefore:

```text
Version is snapshotted and protected by the exposed Admin HTTP writers,
but is not persistence-enforced or uniformly proven for every creation source.
```

### Entry-point inventory

| Source | Implementation | Audit result |
| --- | --- | --- |
| Admin single create | `CourseEnrollmentController::store()` | Exists; server-resolved Version, but duplicate/re-enrollment behavior differs from Bulk. |
| Admin Bulk | `BulkEnrollmentService::commit()` | Exists; atomic, locked and idempotent. |
| Teacher | None found | Not implemented. |
| Self-registration | None found | Not implemented. |
| Purchase | None found | Not implemented. |
| Promotion | None found | Not implemented. |
| Import | None found | Not implemented. |
| API | None found | Not implemented. |
| Factory, seeder, command, job, listener | No production Enrollment writer found | Test fixtures use direct inserts only. |

Repository-wide production search found two Enrollment inserts:

- `app/Http/Controllers/CourseEnrollmentController.php:447`
- `app/Services/BulkEnrollmentService.php:71`

### Conformance matrix

| Hạng mục | Bằng chứng | Kết quả | Rủi ro / yêu cầu tiếp theo |
| --- | --- | --- | --- |
| Product đúng tenant và active | Single validates and locks at `CourseEnrollmentController.php:430-435`, tenant-scoped eligibility at `856-862`; Bulk locks tenant Products at `BulkEnrollmentService.php:147-156` and requires `active` at `214-216`. | Đúng | Centralize in one shared policy. |
| Student đúng tenant, role và status | Single `846-853`; Bulk `147-155`, `211-214`. | Đúng | Preserve fail-closed validation. |
| Exactly one active Product Item | Single succeeds only for one result at `688-705`; Bulk requires count one at `174-183`, `220-222`. | Đúng | Shared resolver should own this invariant. |
| Version published | Single `679-685`; Bulk `220-222`. | Đúng | Deprecated/archived cannot create new Enrollment. |
| Item–Version tenant match | Both resolvers tenant-scope Item and Version. | Đúng ở application layer | Database has no composite tenant relationship enforcement. |
| Item–Template–Version match | Resolvers do not compare `items.template_id` and `versions.template_id`. | **Sai** | High: reject corrupt same-tenant binding and add tests. |
| Registration window | Shared `CourseEnrollmentLifecycleService.php:103-123`; both flows call it inside transaction. | Đúng | Boundaries are inclusive and malformed windows fail closed. |
| Client-supplied Version | Single prohibits at `CourseEnrollmentController.php:571-587`; Bulk and preflight prohibit at `BulkEnrollmentRequest.php:35-43` and `BulkEnrollmentPreflightRequest.php:35-43`. | Đúng | Future sources must inherit the same contract. |
| Snapshot on create | Single insert `447-470`; Bulk insert `71-94`. | Đúng | Both use backend-resolved Version. |
| HTTP update immutability | Update rejects immutable inputs at `631-675` and writes only approved metadata/time fields at `527-543`; lifecycle writes only status/timestamps. | Đúng | No exposed Admin endpoint changes the binding. |
| Persistence immutability | Enrollment migration defines mutable scalar columns and independent FKs at `2026_07_04_010000_create_core_course_enrollments_table.php:13-51`. | **Thiếu** | High: database/architecture review must approve the enforcement mechanism before any migration. |
| Duplicate/re-enrollment consistency | Bulk locks/revalidates history at `BulkEnrollmentService.php:185-239`; single-create has no history query. | **Sai** | High: confirm canonical policy, then make single and Bulk use one creation action. |
| Product Version switch | Product code changes Product Item only; regression proof at `CourseEnrollmentManagementTest.php:414-474`. | Đúng | Old Enrollment remains V7; new Enrollment receives V8. |
| Runtime authority | `VersionLessonAccessService.php:22-35` and `VersionActivityAccessService.php:22-40` constrain content by `enrollment.version_id`. | Đúng trong implemented scope | Future Assessment, Tracking and AI consumers need the same contract. |
| UI readonly/lock explanation | Product and Version are text in `course-enrollments/edit.blade.php:47-52` and `show.blade.php:57-70`; create displays Version code at `create.blade.php:248-252`. | Thiếu một phần | Add “Phiên bản khóa học”, “Đã khóa khi ghi danh” and historical helper text after policy approval. |

### Required answers

1. **Version đã khóa thật hay mới chỉ lưu `version_id`?** It is protected by
   current Admin request/application writers and is runtime authority, but the
   database does not enforce column immutability.
2. **Có đường hiện tại thay đổi Version không?** No exposed Admin route, job or
   listener was found. Direct Query Builder/SQL and a future writer can still do
   so unless the architecture provides a sole writer or persistence guard.
3. **Product status được ghi danh?** Only exact `active`; draft, inactive,
   archived, invalid/missing Item and non-published Version fail.
4. **Product đổi Version có ảnh hưởng Enrollment cũ?** No. Existing rows and
   runtime remain bound to their stored Version; new Enrollment receives the new
   eligible Version.
5. **UI đã giải thích khóa chưa?** Partially. Binding fields are read-only and
   create previews Version, but the explicit lock badge/helper is missing.

### Proposed implementation scope after policy confirmation

1. Introduce one shared Enrollment Version resolver/creation policy used by
   `CourseEnrollmentController` and `BulkEnrollmentService`.
2. Validate and lock Student, Product, exactly one Product Item, matching
   Item–Template–Version, published Version, registration window, duration
   snapshots and duplicate/re-enrollment state in one transaction.
3. Keep Bulk submission token/idempotency orchestration, but remove its duplicate
   binding policy.
4. Obtain architecture/database approval before adding any new migration for
   persistence immutability or composite tenant integrity. Do not edit historical
   migrations.
5. Update existing create/edit/show views and existing i18n files with approved
   lock wording.
6. Add tests for direct persistence mutation, Item–Template mismatch,
   single/Bulk duplicate-policy parity, MySQL Product-Version switch races, every
   future creation source and UI lock text.

### Verification

Executed:

```text
php artisan test tests/Feature/CourseEnrollmentManagementTest.php \
  tests/Feature/CourseActivityProgressRuntimeTest.php \
  tests/Feature/CourseLessonProgressRuntimeTest.php \
  tests/Feature/CourseProgressRuntimeTest.php \
  tests/Feature/CourseCompletionRuntimeTest.php \
  tests/Feature/CertificateFoundationTest.php
```

Result: **92 tests passed, 1,018 assertions**.

The passing suite proves current guarded behavior but does not close the High
findings for persistence immutability, Item–Template integrity or duplicate
policy consistency.

## Course Version lock remediation verification — 2026-08-03

Status: **PASS**

The implementation conformance findings above are retained as the pre-change
audit record. The approved Phase 3 remediation now closes the identified gaps
for the implemented Admin single-create and Admin Bulk entry points:

- `EnrollmentCreationAction` owns creation transaction coordination and the
  shared insert contract.
- `EnrollmentEligibilityPolicy` owns Student, Product and learning-cycle
  eligibility, including the canonical non-terminal duplicate matrix.
- `ProductCourseVersionResolver` requires exactly one tenant-owned active
  Product Item, matching Product Item/Version Template, and a tenant-owned
  `published` Version.
- Single and Bulk use the same preparation and insert core. Bulk retains one
  all-or-nothing transaction, durable submission token and 100-pair limit.
- Deterministic Student, Product, Product Item, Version and Enrollment-history
  locks serialize single/single, single/Bulk and Bulk/Bulk creation without a
  permanent Student–Product unique constraint.
- MySQL trigger `trg_core_course_enrollments_binding_immutable_bu` rejects an
  actual UPDATE that changes `product_id` or `version_id`, while status, notes
  and other approved updates remain allowed. Application exception rendering
  maps only SQLSTATE `45000` carrying the exact LF trigger marker.
- Create preview identifies the applicable Version and explains server
  revalidation. Show/Edit label the Course Version and display the locked badge
  and historical helper text.
- Existing lesson, activity, Progress, Completion and Certificate verification
  continues to use Enrollment Version context.

Verification results:

```text
SQLite full suite: 610 passed, 1 skipped, 7,082 assertions
Targeted Enrollment/runtime suite: 94 passed, 1,037 assertions
MySQL trigger suite: 1 passed, 6 assertions
MySQL concurrency suite: 2 passed, 31 assertions
```

The MySQL concurrency suite covers same-token replay, independent Bulk
submissions, terminal re-enrollment, single/single, single/Bulk, Product Version
switch and Product inactive transition. Each successful creation stores one
consistent binding and no race creates two non-terminal cycles.

Single-create durable replay idempotency is not added in this change set. The
existing submission table is Bulk-specific and issuing a token invalidates all
prepared tokens for the Admin, so reusing it would couple single and Bulk form
attempts incorrectly. Correctness remains enforced by authority locks and the
shared duplicate policy. A future durable single replay contract requires a
separately approved schema/lifecycle change.

Composite tenant foreign keys remain an optional hardening change set and are
not part of this remediation. Application tenant integrity is mandatory and
verified; staging/production read-only preflight remains a deployment gate.
