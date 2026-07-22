# ADR-0009

SaaS Usage Foundation

---

## Status

Frozen

---

## Version

1.0

---

## Date

2026-06-28

---

## Related ADRs

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md)
* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md)
* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0005 — Track Foundation](ADR-0005-Track-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md)
* [ADR-0007 — SaaS Tenant Foundation](ADR-0007-SaaS-Tenant-Foundation.md)
* [ADR-0008 — SaaS Commercial Foundation](ADR-0008-SaaS-Commercial-Foundation.md)

---

## Context

LearnForge cần đo lượng tài nguyên mỗi Customer đã sử dụng để hỗ trợ
entitlement comparison, Billing, reporting và capacity planning.

Nếu Usage không có boundary riêng:

* Commercial Entitlement có thể bị dùng như current consumption.
* Billing có thể tự tạo hoặc sửa Usage measurement.
* Track Event có thể bị dùng nhầm như metering event.
* AI Model Run có thể bị thay thế bởi một Usage record.
* Metric taxonomy có thể bị Usage tự định nghĩa ngoài Owner Domain.
* Counter/Summary có thể trở thành Source Of Truth song song với raw
  measurement.

Foundation cần một Domain độc lập trả lời “Used.” mà không tiếp quản “Can Use?”
hoặc “Pay.”.

---

## Decision

SaaS Usage được xác định là:

```text
SaaS Measurement Domain

+

Usage Projection Owner
```

Boundary:

```text
Commercial → Can Use?

Usage → Used.

Billing → Pay.
```

SaaS Usage Foundation Version 1.0 gồm 3 tables:

```text
Usage Event

↓

Counter

↓

Summary
```

---

## Domain Responsibility

Usage sở hữu:

* Usage measurement.
* Usage aggregation.
* Quota consumption measurement.
* Usage counters.
* Usage summaries.

Usage không sở hữu:

* Customer identity.
* Plan, Subscription hoặc Entitlement.
* Allowed quota/limit.
* Pricing, Invoice hoặc Payment.
* Course Progress hoặc Completion.
* Track Event.
* AI Model Run.
* Media Processing State.
* Audit trail của Domain khác.

Allowed quota/limit thuộc Commercial. Usage chỉ sở hữu lượng đã dùng.

---

## Usage Boundary

```text
Approved Source Measurement

↓ append

saas_usage_events

↓ aggregate

saas_usage_counters

↓ project

saas_usage_summaries

↓ read

Commercial Comparison / Billing / Reporting
```

Flow không đi ngược chiều. Counter hoặc Summary không được tạo, sửa hoặc
rewrite Usage Event.

---

## Measurement Contract

Usage chỉ ghi nhận measurement đã được Domain Owner phê duyệt.

Usage không tự định nghĩa metric.

Mỗi Usage Event phải sử dụng metric taxonomy đã được chuẩn hóa:

* `feature_key`.
* `usage_type`.
* `unit`.

Measurement Contract cũng phải xác định quantity semantics, source contract và
aggregation rule.

```text
Domain Owner

↓ defines and approves metric taxonomy

Usage

↓ records measurement

Counter / Summary
```

Responsibility remains:

* Commercial quyết định “Can Use?”.
* Usage ghi nhận “Used.”.
* Billing quyết định “Pay.”.
* Track quyết định và sở hữu Learning Behavior.
* AI quyết định và sở hữu Model Provenance.

Usage không thay đổi Source Of Truth của bất kỳ Domain nào khác.

`saas_usage_events` là Source Of Truth của Usage measurement. Counter và
Summary là projection/read model, có thể rebuild.

Billing chỉ đọc Usage. Commercial không ghi Usage.

---

## Usage Event Architecture

`saas_usage_events` là append-only Source Of Truth.

Usage Event:

* Luôn tenant-scoped.
* Lưu approved feature, usage type, quantity và unit.
* Giữ `occurred_at` là measurement time và `created_at` là ingestion time.
* Có generic source reference để truy vết nhưng không chuyển ownership.
* Không update hoặc delete trong normal lifecycle.
* Không lưu Entitlement, Invoice hoặc Payment.

Track Event, AI Model Run và Media Processing record không phải Usage Event.
Một source action chỉ tạo Usage Event qua approved Measurement Contract.

---

## Counter Projection

`saas_usage_counters` là current accumulated Usage theo Customer, feature,
period và unit.

Counter:

* Derived hoàn toàn từ Usage Events.
* Có thể rebuild.
* Không phải Source Of Truth.
* Không chứa allowed Entitlement value.
* Không được source Business Domain hoặc Billing update trực tiếp.

Supported period types:

```text
daily

monthly

yearly

lifetime
```

---

## Summary Projection

`saas_usage_summaries` là versioned reporting/Billing read model.

Summary:

* Derived từ Usage Events.
* Có `projection_version`.
* Có thể regenerate.
* Không phải Source Of Truth.
* Thuộc Usage dù Billing là consumer.
* Không chứa canonical Invoice, Payment hoặc Entitlement state.

---

## Relationship With Tenant

Tenant sở hữu Customer identity và TenantContext.

Usage Event, Counter và Summary luôn có `customer_id`. Source reference,
correlation và projection rebuild phải giữ cùng tenant.

Usage không update Customer lifecycle, domain, settings hoặc membership.

---

## Relationship With Commercial

Commercial sở hữu Plan, Subscription và Entitlement — “Can Use?”.

Usage có thể đọc Customer/Subscription/Entitlement context để so sánh used
versus allowed. Commercial không ghi Usage, và Usage không update Commercial
state.

---

## Relationship With Billing

Billing đọc approved Usage Summary hoặc measurement contract để quyết định
“Pay.”.

Billing không update Usage Event, Counter hoặc Summary. Usage không tính price,
không tạo Invoice và không ghi Payment.

---

## Relationship With Track

```text
Track Event

≠

Usage Event
```

Track sở hữu Learning Behavior. Usage chỉ ghi resource-consumption measurement
theo taxonomy do Domain Owner phê duyệt.

Một action có thể tạo cả Track Event và Usage Event qua hai contract độc lập;
record này không thay thế record kia.

---

## Relationship With AI

```text
AI Model Run

≠

Usage Event
```

AI sở hữu Model Provenance. Usage có thể tham chiếu Model Run và ghi approved
request/token measurements nhưng không update hoặc thay thế Model Run.

Estimated cost trong Model Run không phải Usage hoặc Billing Source Of Truth.

---

## Database Namespace

```text
saas_usage_*
```

---

## Foundation Tables

* `saas_usage_events`.
* `saas_usage_counters`.
* `saas_usage_summaries`.

Canonical table documentation:
[docs/database/saas-usage](../database/saas-usage/).

---

## Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

Applied canonical principles:

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Evidence Principle.
* Generic Reference Principle.
* Tenant Isolation Principle.
* Read Model Principle.
* Append Only Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

This ADR references canonical definitions and does not redefine them.

---

## Foundation Freeze

SaaS Usage Foundation Version 1.0 is Approved and Frozen by this ADR.

Changes to Domain Boundary, ownership, Source Of Truth, Measurement Contract or
the 3-table Foundation require:

* Approved ADR Amendment; or
* New ADR.

Implementation must preserve append-only Usage Events and rebuildable
Counter/Summary projections.

---

## Consequences

### Benefits

* Usage has one append-only measurement Source Of Truth.
* Metric meaning remains with the approving Domain Owner.
* Counter and Summary can rebuild without rewriting measurement history.
* Commercial, Usage and Billing ownership remains separate.
* Track behavior and AI provenance remain independent.
* Billing receives stable, tenant-scoped Usage projections.

### Trade-offs

* Metric taxonomy needs a governed registry/approval workflow.
* Duplicate ingestion needs an idempotency contract.
* Corrections need an append-only measurement policy.
* Late events require projection rebuild rules.
* Counter concurrency and summary version compatibility need operational
  contracts.
* High-volume retention and partitioning need implementation planning.

---

## Future Extensions

Future topics that do not change Foundation by default:

* Event identity and idempotency contract.
* Correction/reversal measurement policy.
* Metric taxonomy registry and unit compatibility workflow.
* Period timezone and key format.
* Late-arriving event rebuild window.
* Counter concurrency and rebuild strategy.
* Summary schema/version compatibility.
* Billing-period cutoff/finalization policy.
* Retention, privacy and high-volume partitioning.

Any extension that changes Domain Boundary, ownership, Source Of Truth,
Measurement Contract or Foundation tables requires ADR Amendment or a new ADR.

---

## Result

```text
SaaS Usage Foundation

Version 1.0

Status

Frozen

Ready for implementation

YES
```
