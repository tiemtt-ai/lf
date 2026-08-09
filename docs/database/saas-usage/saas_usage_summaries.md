# Table: saas_usage_summaries

Document Path: database/saas-usage/saas_usage_summaries.md

## Purpose

Versioned Usage read model for reporting and Billing consumption.

## Relationships

Summary belongs to one Customer and is projected from
`saas_usage_events`; Billing is a read-only consumer.

## Business Rules

* Every Summary belongs to one `customer_id`.
* Summary is a Read Model and is not Source Of Truth.
* Summary can regenerate from Usage Events.
* `period_start` must precede `period_end`.
* `projection_version` identifies the projection formula/schema.
* The same period may retain multiple projection versions for compare or
  rollback.
* Billing may read Summary but does not own or update it.
* `summary_data` contains projected Usage measurements only.
* Summary does not contain canonical Plan, Subscription, Entitlement, Invoice
  or Payment state.
* Metadata cannot replace indexed period/version fields.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| summary_type | VARCHAR(100) NOT NULL | Stable summary purpose/type. |
| period_start | TIMESTAMP NOT NULL | Inclusive period start. |
| period_end | TIMESTAMP NOT NULL | Exclusive period end. |
| summary_data | JSON NOT NULL | Projected Usage measurements. |
| projection_version | VARCHAR(50) NOT NULL | Projection formula/schema version. |
| generated_at | TIMESTAMP NOT NULL | Generation/rebuild time. |
| metadata | JSON NULL | Non-canonical projection context. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (customer_id);
UNIQUE (customer_id, summary_type, period_start, period_end, projection_version);
INDEX (customer_id, period_start, period_end);
INDEX (summary_type, generated_at);
```

## Sample Data

`id=30001, customer_id=1, summary_type=monthly_billing_usage, period_start=2026-06-01T00:00:00Z, period_end=2026-07-01T00:00:00Z, summary_data={"ai_tokens":2500000,"storage_bytes":214748364800}, projection_version=v1, generated_at=2026-07-01T00:05:00Z`

## Design Notes

Billing cutoff, late-event regeneration, timezone and JSON schema compatibility
must be approved before implementation. A “Billing snapshot” here is still a
Usage projection; Invoice state remains Billing-owned.
