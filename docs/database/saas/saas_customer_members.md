# Table: saas_customer_members

Document Path: database/saas/saas_customer_members.md

## Purpose

Source Of Truth for membership between User and Customer/Tenant.

## Relationships

`Customer N ↔ N Users` through Membership; optional inviter User reference.

## Business Rules

* `(customer_id, user_id)` is unique.
* User and inviter references must be valid under approved identity policy.
* Foundation roles: `customer_admin`, `teacher`, `student`.
* `staff` is not an official Foundation role and requires Guardrail/ADR approval.
* Allowed `status`: `active`, `invited`, `suspended`, `removed`.
* Normal invitation flow creates/activates Membership only after acceptance;
  `invited` is reserved for compatibility/provisioning policy.
* Protected routes require active Membership and allowed official role.
* Current `users.customer_id/role` compatibility remains until multi-customer
  identity policy is approved.
* Removing membership does not delete User or historical business records.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Membership Tenant. |
| user_id | BIGINT UNSIGNED NOT NULL | Member User. |
| role | VARCHAR(50) NOT NULL | Official role in Tenant. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Membership lifecycle. |
| joined_at | TIMESTAMP NULL | Activation/join time. |
| invited_by_user_id | BIGINT UNSIGNED NULL | Inviting User. |
| metadata | JSON NULL | Provisioning/audit metadata. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, user_id);
INDEX (user_id);
INDEX (customer_id, role);
INDEX (customer_id, status);
```

## Sample Data

`id=30, customer_id=1, user_id=100, role=student, status=active, joined_at=2026-06-28T02:00:00Z, invited_by_user_id=10`

## Design Notes

Single- versus multi-customer User identity, role reconciliation with
`users.role`, last-active-admin protection and transfer policy require owner
review.
