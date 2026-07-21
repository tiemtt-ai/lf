# Enrollment Lifecycle Architecture Review

Status: **Approved and Frozen — Policy 1**
Date: 2026-07-21

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

The Admin Enrollment list exposes lifecycle mutations independently from the
bulk common-information update. The common-information request continues to
prohibit `status`, lifecycle timestamps, binding fields, source fields and
cross-domain state. Bulk lifecycle accepts only a canonical action:

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
