# Table: saas_customer_domains

Document Path: database/saas/saas_customer_domains.md

## Purpose

Canonical Tenant subdomain/custom-domain registry for request resolution.

## Relationships

`Customer 1 → N Customer Domains`; each normalized Domain belongs to exactly
one Customer.

## Business Rules

* Domain is globally unique after normalization.
* Allowed `domain_type`: `subdomain`, `custom_domain`.
* Allowed `verification_status`: `pending`, `verified`, `failed`.
* Allowed `status`: `active`, `inactive`, `blocked`, `archived`.
* Custom domain requires verification before active routing.
* Only one primary active Domain per Customer.
* Tenant routing resolves Domain before authentication.
* Store host only: lowercase/punycode-normalized, without protocol/path/port.
* Domain mapping does not grant authorization.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| domain | VARCHAR(255) NOT NULL | Normalized unique host. |
| domain_type | VARCHAR(50) NOT NULL | Subdomain/custom domain. |
| verification_status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Verification lifecycle. |
| verified_at | TIMESTAMP NULL | Verification time. |
| is_primary | BOOLEAN NOT NULL DEFAULT false | Primary routing domain flag. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Routing lifecycle. |
| metadata | JSON NULL | Verification/routing metadata without secrets. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (domain);
INDEX (customer_id);
INDEX (customer_id, is_primary);
INDEX (verification_status);
INDEX (customer_id, status);
```

## Sample Data

`id=20, customer_id=1, domain=kaha.learnforge.vn, domain_type=subdomain, verification_status=verified, verified_at=2026-06-28T01:00:00Z, is_primary=true, status=active`

## Design Notes

MySQL has no portable partial unique constraint for one primary active row.
Generated primary-slot or transactional service enforcement must be approved
before migration. Domain takeover/SSL verification also needs policy.
