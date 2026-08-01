# Bulk Enrollment Architecture Review

Version: 3.0

Status: Approved and Frozen

Review Date: 2026-07-21

Superseding Approval Date: 2026-07-21

Enrollment-Date Eligibility Approval Date: 2026-08-01

## Review basis

Approval for this review was supplied directly by the LearnForge Architecture
Owner in the Bulk Enrollment implementation prompts dated 2026-07-21 and the
Enrollment-date eligibility decision dated 2026-08-01. Version 2.0 superseded
the Version 1.0 selection-scope, wizard, partial-success and submission-token
decisions. Version 3.0 supersedes the registration-window override and Product
selector presentation decisions. Decisions not explicitly superseded remain
frozen.

Relevant contracts:

- [ADR-0001 — Course Foundation](../adr/ADR-0001-Course-Foundation.md)
- [Course Domain](../core/LF-Core-Course.md)
- [Enrollment database contract](../database/course/core_course_enrollments.md)
- [Enrollment Lifecycle Review](LF-Enrollment-Lifecycle-Architecture-Review.md)
- [Course Product Integrated Review](LF-Course-Product-Integrated-Architecture-Review.md)

## Approved scope

- Customer Admin uses one unified flow supporting one or many Students and one
  or many Products: `1 × 1`, `1 × M`, `N × 1` and `N × M`.
- The normalized work set is the Cartesian product of the unique selected
  Student IDs and Product IDs. Each valid Student–Product pair creates one
  independent Enrollment; there is no group Enrollment.
- All cardinalities use one `BulkEnrollmentService` and one Enrollment
  contract. The former `product_students` and `student_products` UI modes are
  superseded and must not remain request authority.
- Teacher, Student and Guest access is not authorized.
- Each submission is limited to 100 pairs, calculated as
  `student_count × product_count <= 100`, and remains synchronous. The limit is
  enforced by the client, preflight and commit endpoint. Selections are never
  silently truncated.
- Cohort creation, assignment, transfer and capacity are outside this phase.

## Enrollment and Version contract

- Admin-created Enrollment uses source `admin` and status `active`.
- Student, Product, active Product Item and published immutable Version are
  independently revalidated in the current tenant inside the pair transaction.
- Version is resolved by the server and is never accepted from request input.
- `product_id` and `version_id` are frozen on the new Enrollment.
- Product changes never update historical Enrollment or learning records.
- Automatic access expiry remains **Deferred**. `access_duration_days` is not
  converted automatically to `access_ends_at`, and runtime expiry is unchanged.

## Eligibility and registration window

- Student must be an active `student` user in the current tenant.
- Product must be active and resolve exactly one valid active Product Item to a
  published Version in the same tenant.
- Admin assignment does not implicitly override the Product registration
  window. Product registration eligibility is evaluated against the shared
  `enrolled_at` selected for the submission, not against server `now`.
- A Product with no registration boundaries is unrestricted by registration
  time. A complete valid window is inclusive at both boundaries. A missing
  boundary, an invalid interval, a selected instant before opening, or a
  selected instant after closing fails closed with its exact reason.
- Preflight and commit repeat the shared backend policy. A later separately
  approved override must be explicit, authorized and auditable; this workflow
  currently defines no such override.

## Preflight, existing Enrollment and re-enrollment

- Preflight classifies every normalized pair as `creatable`,
  `existing_non_terminal`, `reenrollment_eligible` or `ineligible`, with the
  relevant Student, Product and previous Enrollment identity where applicable.
- Existing `pending`, `active` or `suspended` Enrollment makes the complete
  submission invalid; no new cycle is created.
- If only terminal `completed`, `expired` or `cancelled` Enrollments exist, a
  new cycle requires explicit confirmation for that exact Student–Product pair.
- Re-enrollment confirmation defaults to false and is submitted per exact pair.
  An optional confirm-all action must be explicit, warned and never selected by
  default. Any eligible pair without confirmation keeps preflight invalid.
- Re-enrollment is never automatic. Pair eligibility and its matching
  confirmation are revalidated under lock at commit time.
- Historical Enrollment, Version, progress and evidence are never reset or
  mutated. No permanent Student–Product unique constraint is introduced.

## Atomicity, transactions and concurrency

- Version 1.0 partial success and per-pair transaction boundaries are
  superseded. One submission has one atomic transaction boundary.
- If any pair is invalid at preflight, confirmation is incomplete, or commit
  revalidation fails, no Enrollment is created. Invalid pairs are returned to
  the Admin for correction; no pair is skipped silently.
- If eligibility changes after preflight, commit fails and rolls back the
  entire submission while reporting every pair that is no longer valid.
- Authority rows are locked in a deterministic order. Student and Product
  eligibility, Product Item/Version resolution and existing Enrollment state
  are rechecked under lock. Transaction deadlocks use the project's bounded
  retry convention.
- Concurrent requests for the same pair must not create two non-terminal
  cycles. Database locking and revalidation remain duplicate-cycle authority.

## Submission token and idempotency

- The server issues an opaque token for one normalized submission; the client
  cannot choose it.
- The token is tenant-scoped, Admin-scoped and bound to normalized unique
  Student IDs, normalized unique Product IDs, pair-level re-enrollment
  confirmations and shared Step 2 configuration.
- Token lifetime is 30 minutes and it may be committed once.
- Token completion and Enrollment inserts occur in the same database
  transaction. A rollback does not mark the token complete.
- Repeating an already committed token returns the stored result and never
  creates another Enrollment.
- Expired tokens, payload mismatches, cross-tenant use and cross-Admin use are
  rejected.
- Returning to Step 1 or changing selections, confirmations or shared
  configuration invalidates the old client token. A successful preflight issues
  a new token.
- Submission identity must not be inferred from Enrollment timestamps and no
  `bulk` Enrollment source is introduced.

## UI and response contract

- Version 1.0 selection-direction cards and three-step wizard are superseded.
- The unified flow has exactly two steps: **Select Students and Products**, then
  **Configure and Confirm**.
- Step 1 contains independent multi-select Student and Product selectors. It
  displays the resulting pair count and performs preflight before Step 2.
- Step 2 contains shared Enrollment configuration, full pair review, explicit
  re-enrollment confirmations and the final commit action. There is no third
  confirmation step.
- Search is server-side and paginated. Selection persists across queries and
  pages. Select-all applies only to the visible result page.
- The primary Product list contains only Products eligible for every selected
  Student at the selected `enrolled_at`. Ineligible Products appear disabled in
  a collapsed secondary group with exact reasons and an independent count.
  Search and pagination may limit that secondary group.
- Select-all affects eligible Products only. The UI reports selected Products
  as `selected / eligible`, and uses the label **Eligible for enrollment**.
- Changing `enrolled_at` re-evaluates both Product groups, their counts,
  select-all state and projected access/review windows.
- A selected Product that becomes ineligible is retained in a visible marked
  correction area and blocks continuation. It is never silently deselected;
  the Admin must change `enrolled_at` or remove the Product.
- Existing and historical Enrollment states are visible in preflight;
  re-enrollment is confirmed per pair.
- Client, preflight and commit surfaces display the exact Cartesian pair count
  and reject a count above 100 without truncation.
- Since commit is atomic, the final result represents one completed submission.
  Validation failures return pair-level reasons without creating Enrollment.
- Responsive and accessibility requirements include labels, keyboard focus,
  status text beyond color, and `aria-live` updates.

## Shared Enrollment configuration

- Step 2 accepts one optional shared configuration for all newly created or
  re-enrolled cycles: access window, review window and internal notes.
- Status `active`, source `admin` and the actual server creation time remain
  read-only server-owned values. The client cannot submit alternatives.
- Access fields map directly to `access_starts_at` and `access_ends_at`; no
  Product duration or registration-window projection is introduced.
- Review fields map directly to `review_starts_at` and `review_ends_at` only
  when the locked Product is a self-paced course with a positive
  `review_duration_days`. In a mixed-Product batch, ineligible Products still
  enroll but their review fields remain `NULL`.
- Shared values apply only to the newly created or explicitly re-enrolled
  records in a successfully committed atomic submission.
- Blank optional fields normalize to `NULL`. Enrollment `notes` remains
  internal and is rendered as escaped text under existing authorization.

## Explicit exclusions

- Automatic access-expiry calculation, scheduler or runtime transition.
- Cohort mutation or capacity enforcement.
- Queue/background processing.
- Teacher, Student, purchase, promotion, self-registration or API bulk flows.
- Changes to the approved Enrollment lifecycle or historical Version freeze.

## Review result

```text
Decision: PASS
Architecture freeze: YES
Implementation authorized: YES
Owner approval date: 2026-07-21
Superseding approval date: 2026-07-21
```

## MySQL production-concurrency verification

Status: **Verified — 2026-07-21**

The approved concurrency contract was exercised against the dedicated test
database `lf_bulk_enrollment_concurrency_test_20260721` using MariaDB 10.4.21,
the MySQL Laravel driver and `REPEATABLE-READ` isolation. Workers used real
independent processes and connections. Submission-token replay used the shared
MySQL `database` cache store, whose atomic `add` operation was the token
consumption authority; the PHPUnit `array` cache was not used for this proof.

Verified scenarios:

- two tokens concurrently creating the same new Student–Product pair;
- the same token replayed concurrently;
- two Admins concurrently creating the same pair;
- two unified-flow submissions concurrently producing the same normalized pair;
- concurrent confirmed re-enrollment after a terminal cycle;
- Product Item Version binding committed while Enrollment waited on the
  Product parent lock, followed by immutable Version-freeze verification;
- a controlled deadlock where the Bulk transaction was the victim and
  succeeded on its bounded retry;
- bounded retry behavior under lock contention.

Every creation race produced exactly one non-terminal Enrollment. The losing
worker returned `skipped_existing`; terminal history was unchanged. The full
matrix passed three consecutive complete runs with 39 assertions per run. A
five-run matrix without the later retry-exhaustion extension also passed 175
assertions.

Serialization authority is the existing Student row followed by the Product
row. These records exist before any Enrollment row, so the no-existing-row case
does not depend on gap locking. All unified-flow cardinalities normalize to the
same service pairs and use the same lock order:

```text
Student -> Product -> Product Item / published Version -> Enrollment rows
```

Enrollment rows are queried again only after those parent locks are held, and
Version resolution remains inside the transaction. The Version 1.0 production
verification predates the Version 2.0 atomic submission and durable replay
contract; those superseding behaviors require new verification. Automatic
access expiry remains Deferred and Cohort remains outside Bulk Enrollment.

Relevant MySQL regression coverage passed with 136 tests and 1,337 assertions
across Product, Enrollment and Cohort behavior. The standard SQLite suite
passed with 528 tests and 5,812 assertions; the MySQL-only concurrency matrix
was skipped there by design. A full MySQL run passed 524 tests and reported five
pre-existing portability failures outside Bulk Enrollment: one Course Media
redirect assertion assumes a fixed auto-increment ID, one Course Template
concurrency test exposes uncommitted fixture data to a second connection, two
Course Template cleanup paths violate a self-referencing foreign key deletion
order, and one Course Template test writes an overlength title before service
validation. These failures do not touch the Bulk Enrollment execution path or
weaken the dedicated concurrency evidence above, but remain MySQL test-suite
debt rather than being represented as a completely green full-MySQL suite.
