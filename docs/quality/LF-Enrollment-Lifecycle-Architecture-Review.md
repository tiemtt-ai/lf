# Enrollment Lifecycle Architecture Review

Status: **Approved — Policy 1**  
Date: 2026-07-20

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
