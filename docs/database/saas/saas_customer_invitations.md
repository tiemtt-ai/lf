# Table Name

`saas_customer_invitations`

## Purpose

Secure invitation lifecycle for joining a Tenant.

## Relationships

Invitation belongs to Customer and inviter User; may reference accepted User.
Acceptance creates/activates one Customer Membership.

## Business Rules

* Store token hash only; raw token is never persisted.
* Invitation does not create Membership until accepted.
* Foundation invitation roles: `customer_admin`, `teacher`, `student`.
* Allowed `status`: `pending`, `accepted`, `expired`, `revoked`.
* Expired/revoked/accepted Invitation cannot be accepted again.
* Acceptance validates token, normalized email, Tenant, expiry and role.
* `accepted_at` and `accepted_user_id` required when accepted.
* Only one active pending Invitation per normalized Tenant/email is intended.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Inviting Tenant. |
| email | VARCHAR(255) NOT NULL | Normalized invitee email. |
| role | VARCHAR(50) NOT NULL | Intended official role. |
| token_hash | VARCHAR(255) NOT NULL | One-way invitation token hash. |
| invited_by_user_id | BIGINT UNSIGNED NOT NULL | Inviting active member. |
| accepted_user_id | BIGINT UNSIGNED NULL | User accepting invitation. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Invitation lifecycle. |
| expires_at | TIMESTAMP NOT NULL | Expiration time. |
| accepted_at | TIMESTAMP NULL | Acceptance time. |
| metadata | JSON NULL | Delivery/audit metadata without raw token. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
INDEX (customer_id);
INDEX (email);
INDEX (status);
INDEX (customer_id, email, status);
UNIQUE (token_hash);
```

## Sample Data

`id=40, customer_id=1, email=teacher@example.com, role=teacher, token_hash=$2y$..., invited_by_user_id=10, status=pending, expires_at=2026-07-05T02:00:00Z`

## Design Notes

`UNIQUE (customer_id, email, status)` would block legitimate re-invites after
accepted/revoked history. Enforce only one pending invite using generated
active slot or transactional service policy after owner review.
