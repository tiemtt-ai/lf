# Table: saas_usage_events

Version: 1.1

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-09-10

Document Path: database/saas-usage/saas_usage_events.md

## Owner Freeze — 2026-09-10 — superseded by schema amendment

```text
Role: LearnForge Architecture Owner
Date: 2026-09-10
Decision: APPROVED AND FROZEN
Scope: saas_usage_events idempotency, reversal and reservation provenance schema
```

**Superseded 2026-09-10.** Khối trên giữ nguyên làm lịch sử: Owner đã quyết đúng
như vậy trên **bản tại thời điểm đó**. Bản hiện tại đã đổi schema sau finding E1
(khoá idempotency của reversal), E2 (bẫy TIMESTAMP ngầm) và E3 (append-only
enforce bằng trigger) của lượt review độc lập, nên nó **không** nằm trong phạm
vi chữ ký trên. Tài liệu trở lại `Review`; cần một reviewer độc lập PASS rồi
Owner phê duyệt lại bản sửa trước khi Frozen.

Implementation remains `Not Implemented`. Freeze does not itself authorize or
apply a migration without the required Architecture Review PASS.

## Purpose

Append-only Source Of Truth for tenant resource-consumption measurements.

## Relationships

Usage Event belongs to one Customer. `source_type + source_id` references the
source Domain record that produced the measurement without transferring
ownership.

## Business Rules

* Every Usage Event belongs to one `customer_id`.
* Event is append-only, enforced by BEFORE UPDATE and BEFORE DELETE triggers
  raising `LF_USAGE_EVENT_IMMUTABLE`. A retention purge approved under the rule
  below drops the triggers deliberately and restores them.
* `occurred_at` là `DATETIME(6)` và `created_at` khai default tường minh. Một
  `TIMESTAMP NOT NULL` trần sẽ là cột đầu tiên như vậy ở đây, và MariaDB tự gắn
  `DEFAULT CURRENT_TIMESTAMP` **cùng** `ON UPDATE CURRENT_TIMESTAMP` cho nó —
  chỉ khi `explicit_defaults_for_timestamp` tắt. Đo ngày 2026-09-10: server
  deployment (MariaDB 10.4.21) đặt `explicit_defaults_for_timestamp = 0`, còn
  một bản MariaDB 11.4.12 cài mặc định đặt `= 1`. Nghĩa là **cùng một migration
  sinh ra hai schema khác nhau** tùy nơi chạy, và `schema:drift --fresh` xanh
  trên chính server nó vừa dựng nên không nhìn thấy khác biệt đó. Khai
  kiểu/default tường minh làm schema độc lập với biến cấu hình này.
* Bẫy đó đã làm hỏng các cột occurrence khác trong chính codebase này; xem
  `2026_08_09_050000_remove_implicit_timestamp_on_update_from_occurrence_columns`.
  Với một bảng append-only là Source Of Truth tính tiền, `occurred_at` bị ghi đè
  là mất bằng chứng không dựng lại được.
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
* `event_uuid` is the immutable idempotency identity. Retry returns the existing
  event only when its complete immutable snapshot matches.
* Corrections are append-only reversals: one `reversal` references exactly one
  earlier `measurement` in the same tenant, and a measurement is reversed at
  most once. Quantity is always positive; projector subtracts reversal rows.
* Metric taxonomy declares `reservation_required`. When true, Usage append is
  accepted only from Commercial settlement and must carry `reservation_uuid`.
  Direct append for that metric is rejected. Non-metered measurements keep it
  NULL.
* A reversal of a reserved measurement **keeps the same `reservation_uuid`**, so
  a correction stays traceable to the hold that produced it. Uniqueness is
  therefore per `(customer_id, reservation_uuid, event_kind)`: one settlement and
  at most one correction of it. Scoping the key to the measurement alone would
  make metered metrics — the only ones that bill — the only ones that can never
  be corrected.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính của Usage Event. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| event_uuid | CHAR(36) NOT NULL | Stable idempotency identity. |
| event_kind | VARCHAR(30) NOT NULL DEFAULT `measurement` | `measurement` or `reversal`. |
| reverses_event_id | BIGINT UNSIGNED NULL | Original measurement being reversed. |
| reservation_uuid | CHAR(36) NULL | Commercial reservation provenance for reserved metrics. |
| feature_key | VARCHAR(100) NOT NULL | Commercial/platform feature identifier. |
| usage_type | VARCHAR(100) NOT NULL | Stable measurement type. |
| quantity | DECIMAL(20,6) NOT NULL | Measured quantity under the metric contract. |
| unit | VARCHAR(50) NOT NULL | Stable unit such as request, token, byte or minute. |
| source_type | VARCHAR(100) NOT NULL | Source Domain/entity type. |
| source_id | BIGINT UNSIGNED NOT NULL | Source record ID. |
| occurred_at | DATETIME(6) NOT NULL | Time resource consumption occurred. |
| correlation_id | VARCHAR(100) NULL | Cross-measurement flow correlation. |
| metadata | JSON NULL | Non-canonical measurement context. |
| created_at | TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) | Usage ingestion time; no on-update clause. |

## Indexes

```sql
PRIMARY KEY (id);
UNIQUE (id, customer_id);
UNIQUE (customer_id, event_uuid);
UNIQUE (customer_id, reverses_event_id);
UNIQUE (customer_id, reservation_uuid, event_kind);
INDEX (customer_id);
INDEX (customer_id, feature_key, occurred_at);
INDEX (customer_id, usage_type, occurred_at);
INDEX (customer_id, source_type, source_id);
INDEX (customer_id, correlation_id);
INDEX (occurred_at);
INDEX (reverses_event_id, customer_id);
FOREIGN KEY (reverses_event_id, customer_id)
  REFERENCES saas_usage_events(id, customer_id) RESTRICT;
CHECK (event_kind IN ('measurement','reversal'));
CHECK (quantity > 0);
CHECK ((event_kind = 'measurement' AND reverses_event_id IS NULL) OR
       (event_kind = 'reversal' AND reverses_event_id IS NOT NULL));

-- Append-only is enforced physically, not by convention. `media_access_logs`
-- already sets this precedent for audit data; a billing Source Of Truth is not
-- entitled to weaker protection than an access log.
CREATE TRIGGER trg_saas_usage_events_bu_immutable BEFORE UPDATE ON saas_usage_events
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_USAGE_EVENT_IMMUTABLE';
CREATE TRIGGER trg_saas_usage_events_bd_immutable BEFORE DELETE ON saas_usage_events
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_USAGE_EVENT_IMMUTABLE';
```

## Sample Data

`id=10001, customer_id=1, feature_key=ai_tutor, usage_type=input_token, quantity=1250, unit=token, source_type=ai_model_run, source_id=9001, occurred_at=2026-06-28T08:15:00Z, correlation_id=ai-session-550, created_at=2026-06-28T08:15:02Z`

## Design Notes

The 2026-09-09 Owner decision closes duplicate-ingestion and correction policy:
`event_uuid` is canonical idempotency; reversal is append-only and unique per
original event. Do not infer identity from `correlation_id`.

Measurement Contract: Usage does not define metrics. The relevant Domain Owner
must approve the `feature_key + usage_type + unit` taxonomy before Usage records
the measurement. Recording a Usage Event does not transfer or modify the source
Domain's Source Of Truth.
