# Table: saas_audit_logs

## Purpose

Append-only Tenant-level audit trail.

## Relationships

Audit Log optionally belongs to Customer and actor User;
`target_type + target_id` is generic audit context.

## Business Rules

* Append-only; do not update/delete except approved retention/privacy policy.
* `customer_id` nullable only for documented system/global events.
* Audit Log is not business state and cannot replace Domain event/evidence.
* Actor may be NULL for system action.
* Action is stable lowercase `snake_case`.
* Target generic reference does not transfer ownership.
* IP/User-Agent may be anonymized, hashed or purged by privacy policy.
* Metadata cannot contain raw credential, invitation token or canonical state.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NULL | Tenant; NULL only global/system event. |
| actor_user_id | BIGINT UNSIGNED NULL | Acting User. |
| action | VARCHAR(100) NOT NULL | Stable audit action. |
| target_type | VARCHAR(100) NULL | Generic target type. |
| target_id | BIGINT UNSIGNED NULL | Generic target ID. |
| ip_address | VARCHAR(45) NULL | IPv4/IPv6 subject to privacy policy. |
| user_agent | TEXT NULL | User Agent subject to privacy policy. |
| metadata | JSON NULL | Safe audit context. |
| created_at | TIMESTAMP NOT NULL | Audit event time. |

## Indexes

```sql
INDEX (customer_id);
INDEX (actor_user_id);
INDEX (action);
INDEX (target_type, target_id);
INDEX (created_at);
INDEX (customer_id, created_at);
```

## Sample Data

`id=50, customer_id=1, actor_user_id=10, action=customer_domain_verified, target_type=saas_customer_domain, target_id=20, ip_address=203.0.113.10, created_at=2026-06-28T02:15:00Z`

## Design Notes

Audit retention, legal hold, global event taxonomy and privacy transformations
require owner policy. Audit is not technical application logging.
