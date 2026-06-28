# Table Name

`saas_customers`

## Purpose

Tenant/Customer root identity and lifecycle record.

## Relationships

`Customer 1 → N Settings / Domains / Members / Invitations / Audit Logs`;
`saas_customers.id` is referenced as `customer_id` by business tables.

## Business Rules

* This is the Tenant root and does not contain `customer_id`.
* `slug` is globally unique and stable.
* `subdomain`, when present, is unique.
* Allowed `status`: `active`, `inactive`, `suspended`, `archived`.
* Do not store Billing, Subscription, Usage or learning state here.
* `subdomain`/`custom_domain` are compatibility/bootstrap fields;
  `saas_customer_domains` is canonical for routing.
* Metadata cannot replace settings requiring query/constraint.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Customer/Tenant identity. |
| name | VARCHAR(255) NOT NULL | Organization display name. |
| slug | VARCHAR(100) NOT NULL | Stable global tenant slug. |
| subdomain | VARCHAR(255) NULL | Compatibility primary subdomain value. |
| custom_domain | VARCHAR(255) NULL | Compatibility primary custom domain value. |
| organization_type | VARCHAR(100) NULL | Organization classification. |
| theme_key | VARCHAR(100) NULL | Default theme key. |
| layout_key | VARCHAR(100) NULL | Default layout key. |
| email | VARCHAR(255) NULL | Tenant contact email. |
| phone | VARCHAR(50) NULL | Tenant contact phone. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Tenant lifecycle. |
| metadata | JSON NULL | Non-canonical extension metadata. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle/profile update time. |

## Indexes

```sql
UNIQUE (slug);
UNIQUE (subdomain);
INDEX (status);
```

## Sample Data

`id=1, name=KAHA Academy, slug=kaha, subdomain=kaha.learnforge.vn, custom_domain=NULL, organization_type=academy, theme_key=korean, layout_key=academy_default, email=admin@kaha.vn, status=active`

## Design Notes

Routing must migrate toward `saas_customer_domains`. Root compatibility fields
must never drift from the primary active Domain registry record.
