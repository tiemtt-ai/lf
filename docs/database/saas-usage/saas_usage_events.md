# Table: saas_usage_events

Document Path: database/saas-usage/saas_usage_events.md

## Purpose

Append-only Source Of Truth for tenant resource-consumption measurements.

## Relationships

Usage Event belongs to one Customer. `source_type + source_id` references the
source Domain record that produced the measurement without transferring
ownership.

## Business Rules

* Every Usage Event belongs to one `customer_id`.
* Event is append-only; do not update or delete it.
* Any legally required retention/privacy purge needs separate Governance
  approval and is outside normal Foundation lifecycle.
* Usage Event is Source Of Truth for Usage measurement.
* `feature_key`, `usage_type` and `unit` are stable lowercase `snake_case`.
* `quantity` follows the approved metric/unit contract.
* `occurred_at` is source event time; `created_at` is ingestion time.
* Source reference must resolve within the same tenant.
* `correlation_id` groups measurements in one flow but is not event identity or
  an idempotency key.
* Event does not store Plan, Subscription, Entitlement, Invoice or Payment.
* Usage Event does not replace Track Event, AI Model Run, Media Processing
  state or Audit.
* Metadata cannot contain canonical source state or credentials.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính của Usage Event. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| feature_key | VARCHAR(100) NOT NULL | Commercial/platform feature identifier. |
| usage_type | VARCHAR(100) NOT NULL | Stable measurement type. |
| quantity | DECIMAL(20,6) NOT NULL | Measured quantity under the metric contract. |
| unit | VARCHAR(50) NOT NULL | Stable unit such as request, token, byte or minute. |
| source_type | VARCHAR(100) NOT NULL | Source Domain/entity type. |
| source_id | BIGINT UNSIGNED NOT NULL | Source record ID. |
| occurred_at | TIMESTAMP NOT NULL | Time resource consumption occurred. |
| correlation_id | VARCHAR(100) NULL | Cross-measurement flow correlation. |
| metadata | JSON NULL | Non-canonical measurement context. |
| created_at | TIMESTAMP NOT NULL | Usage ingestion time. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (customer_id);
INDEX (customer_id, feature_key, occurred_at);
INDEX (customer_id, usage_type, occurred_at);
INDEX (customer_id, source_type, source_id);
INDEX (customer_id, correlation_id);
INDEX (occurred_at);
```

## Sample Data

`id=10001, customer_id=1, feature_key=ai_tutor, usage_type=input_token, quantity=1250, unit=token, source_type=ai_model_run, source_id=9001, occurred_at=2026-06-28T08:15:00Z, correlation_id=ai-session-550, created_at=2026-06-28T08:15:02Z`

## Design Notes

The Foundation field set does not yet include a dedicated immutable event UUID
or idempotency key. Duplicate-ingestion and correction/reversal policy must be
approved before migration. Do not infer uniqueness from `correlation_id` or
generic source reference.

Measurement Contract: Usage does not define metrics. The relevant Domain Owner
must approve the `feature_key + usage_type + unit` taxonomy before Usage records
the measurement. Recording a Usage Event does not transfer or modify the source
Domain's Source Of Truth.
