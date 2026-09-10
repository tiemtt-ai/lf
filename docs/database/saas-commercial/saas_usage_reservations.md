# Table: saas_usage_reservations

Version: 1.0

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-09-09

Document Path: database/saas-commercial/saas_usage_reservations.md

---

# Purpose

Commercial-owned, tenant-scoped ledger that atomically holds an effective
Entitlement limit before a metered provider operation. It answers only whether
capacity is temporarily available; `saas_usage_events` remains Source Of Truth
for consumed usage and `saas_usage_counters` remains its rebuildable projection.

# Owner Decision

Approved by LearnForge Architecture Owner on 2026-09-09: Commercial owns the
reservation ledger; Tenant Settings, Entitlements and Usage must be implemented
before the first provider is activated. This document freezes the physical
candidate for independent architecture review; it does not activate a provider.

# Business Rules

* Every row belongs to `customer_id` and one effective `saas_entitlements` row.
* Allowed status: `reserved`, `executing`, `settling`, `committed`,
  `committed_over_limit`, `released`, `expired`, `reconciled_released`.
* `reserved → executing|released|expired`; `executing → settling|reconciled_released`;
  `settling → committed|committed_over_limit|reconciled_released`; terminal states
  are `committed|committed_over_limit|released|expired|reconciled_released`.
* `reconciled_released` is the only exit for a hold that already crossed the
  provider boundary and that provider-aware reconciliation proved consumed
  nothing. It is deliberately a distinct status from `released`: an auditor must
  be able to tell a hold that never started from one reclaimed after
  investigation, and only the latter required evidence about the provider.
  Without it an `executing` row has no legal terminal state at all and holds the
  tenant's quota forever.
* `(customer_id, source_type, source_uuid, feature_key, period_key, unit)` is the
  idempotency identity. A retry returns the existing reservation.
* Reserve locks the entitlement and matching active ledger rows in one database
  transaction; read-then-call is forbidden.
* Available quantity is entitlement limit minus `reserved + committed` ledger
  quantity in the same period. The ledger is enforcement state, not analytical
  Usage measurement.
* Default lease TTL is 15 minutes. Renewal is capped at the earlier of two hours
  after creation and `period_end_at`. Only `reserved` may auto-expire.
  `executing|settling` require provider-aware reconciliation and are never
  released by the generic sweeper.
* The caller marks `executing` before invoking the provider and `settling`
  immediately after a usable response. This fail-safe may temporarily retain a
  hold after a pre-call crash, but it can never refund usage that may have
  reached a provider.
* Commit is atomic with append of one idempotent `saas_usage_events` measurement
  and stores its `usage_event_id`. It never updates Usage Counter directly.
* Provider success followed by settlement failure leaves `settling`; retry or
  provider-aware reconciliation commits it. It must not release usage that
  already happened.
* `reserved_quantity` is the capacity held, not a cap on what may be recorded.
  Provider limits should be configured so actual usage does not exceed it, but a
  provider that returns more has already consumed it.
* Settlement records the **full actual quantity**, always. When it exceeds
  `reserved_quantity` the row commits as `committed_over_limit` and the Usage
  Event carries the true amount. Truncating to make a CHECK pass would under-bill
  the tenant and corrupt Usage, which is Source Of Truth; a breached limit is an
  enforcement signal for the *next* reservation, never a reason to lose the
  measurement that already happened.
* `committed_over_limit` is a distinct terminal status so a breach is visible to
  Commercial without recomputing it, and so `committed` keeps its plain meaning
  of "settled inside the hold". The producer is told with
  `AI_QUOTA_RESERVATION_EXCEEDED`; the usage is recorded either way.
* No credential, prompt, source text, PII or signed URL is stored.

# Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Primary key. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| reservation_uuid | CHAR(36) NOT NULL | Stable reservation identity. |
| entitlement_id | BIGINT UNSIGNED NOT NULL | Effective Commercial limit used. |
| source_type | VARCHAR(100) NOT NULL | Approved producer type, initially `ai_model_run`. |
| source_id | BIGINT UNSIGNED NOT NULL | Producer row id for audit. |
| source_uuid | CHAR(36) NOT NULL | Stable producer attempt identity. |
| feature_key | VARCHAR(100) NOT NULL | Entitled feature. |
| period_type | VARCHAR(50) NOT NULL | `daily`, `monthly`, `yearly`, `lifetime`. |
| period_key | VARCHAR(50) NOT NULL | Canonical period identity. |
| period_start_at | DATETIME(6) NOT NULL | UTC inclusive boundary snapshot. |
| period_end_at | DATETIME(6) NULL | UTC exclusive boundary; NULL only for lifetime. |
| timezone_snapshot | VARCHAR(64) NOT NULL | IANA timezone used to derive boundaries. |
| unit | VARCHAR(50) NOT NULL | Unit matching entitlement and Usage metric. |
| reserved_quantity | DECIMAL(20,6) NOT NULL | Maximum held quantity, greater than zero. |
| committed_quantity | DECIMAL(20,6) NULL | Full actual usage, never truncated; may exceed the hold only with `committed_over_limit`. |
| status | VARCHAR(30) NOT NULL DEFAULT `reserved` | Reservation lifecycle. |
| usage_event_id | BIGINT UNSIGNED NULL | Idempotent Usage measurement created at commit. |
| lease_expires_at | DATETIME(6) NOT NULL | Current lease expiry. |
| execution_started_at | DATETIME(6) NULL | Set before the external call boundary. |
| provider_completed_at | DATETIME(6) NULL | Set when provider returns a usable result. |
| max_lease_expires_at | DATETIME(6) NOT NULL | Hard renewal cap. |
| reconciled_at | DATETIME(6) NULL | Provider-aware reconciliation completed; required by `reconciled_released`. |
| settled_at | DATETIME(6) NULL | Terminal transition time. |
| metadata | JSON NULL | Safe policy/version identifiers only. |
| created_at | TIMESTAMP(6) NULL | Created time. |
| updated_at | TIMESTAMP(6) NULL | Last lifecycle update. |

# Constraints and Indexes

```sql
UNIQUE (id, customer_id);
UNIQUE (customer_id, reservation_uuid);
UNIQUE (customer_id, source_type, source_uuid, feature_key, period_key, unit);
INDEX  (customer_id, feature_key, period_key, unit, status);
INDEX  (customer_id, status, lease_expires_at);
INDEX  (customer_id, usage_event_id);
INDEX  (entitlement_id, customer_id);
INDEX  (usage_event_id, customer_id);

FOREIGN KEY (customer_id) REFERENCES saas_customers(id) RESTRICT;
FOREIGN KEY (entitlement_id, customer_id)
  REFERENCES saas_entitlements(id, customer_id) RESTRICT;
FOREIGN KEY (usage_event_id, customer_id)
  REFERENCES saas_usage_events(id, customer_id) RESTRICT;

CHECK (status IN ('reserved','executing','settling','committed','committed_over_limit',
                  'released','expired','reconciled_released'));
CHECK (reserved_quantity > 0);
CHECK (committed_quantity IS NULL OR committed_quantity >= 0);
CHECK (status <> 'committed' OR committed_quantity <= reserved_quantity);
CHECK (status <> 'committed_over_limit' OR committed_quantity > reserved_quantity);
CHECK ((period_type = 'lifetime' AND period_end_at IS NULL) OR
       (period_type <> 'lifetime' AND period_end_at > period_start_at));
CHECK ((status = 'reserved' AND committed_quantity IS NULL AND
        usage_event_id IS NULL AND execution_started_at IS NULL AND
        provider_completed_at IS NULL AND settled_at IS NULL) OR
       (status = 'executing' AND execution_started_at IS NOT NULL AND
        provider_completed_at IS NULL AND committed_quantity IS NULL AND
        usage_event_id IS NULL AND settled_at IS NULL) OR
       (status = 'settling' AND execution_started_at IS NOT NULL AND
        provider_completed_at IS NOT NULL AND committed_quantity IS NULL AND
        usage_event_id IS NULL AND settled_at IS NULL) OR
       (status IN ('committed','committed_over_limit') AND
        execution_started_at IS NOT NULL AND
        provider_completed_at IS NOT NULL AND committed_quantity IS NOT NULL AND
        usage_event_id IS NOT NULL AND settled_at IS NOT NULL) OR
       (status IN ('released','expired') AND committed_quantity IS NULL AND
        usage_event_id IS NULL AND execution_started_at IS NULL AND
        provider_completed_at IS NULL AND settled_at IS NOT NULL) OR
       (status = 'reconciled_released' AND committed_quantity IS NULL AND
        usage_event_id IS NULL AND execution_started_at IS NOT NULL AND
        reconciled_at IS NOT NULL AND settled_at IS NOT NULL));
CHECK (reconciled_at IS NULL OR status = 'reconciled_released');
```

# Transaction Contract

Reserve locks the effective entitlement, resolves its period snapshot, and
counts ledger rows for the same tenant/feature/period/unit. Insert and capacity
decision occur in that transaction. Commit locks the reservation, appends the
Usage Event with a deterministic event UUID derived from `reservation_uuid`, and
marks the reservation committed in the same transaction. Projectors update
Counter later; quota enforcement never depends on projection freshness.

The expiry sweeper selects only `status='reserved' AND lease_expires_at <= now`.
It must never infer that `executing` or `settling` is unused from elapsed time.

A separate provider-aware reconciliation path owns `executing` and `settling`.
It may only reach `reconciled_released` on positive evidence that the provider
consumed nothing — never on elapsed time alone — and records `reconciled_at`.
Where evidence is unavailable the row stays held: over-holding a tenant's quota
is recoverable by a human, refunding usage that happened is not.

# Rollback

Migration `down()` must fail before DDL if any reservation row exists. No
backfill and no provider activation are part of the migration packet.
