# LF-SaaS-Usage.md

Version: 0.1

Status: Foundation In Design

Last Updated: 2026-06

---

# LF SaaS Usage Architecture

SaaS Usage là Domain đo lượng tài nguyên LearnForge mà từng Customer đã tiêu
thụ.

```text
Commercial → Can Use?

Usage → Used.

Billing → Pay.
```

Ba quyết định có Source Of Truth riêng. Usage không được thay Commercial hoặc
Billing quyết định business state.

---

# Domain Responsibility

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
* Invoice hoặc Payment.
* Course Progress hoặc Completion.
* Track Event.
* AI Model Run.
* Media Processing State.
* Audit trail của Domain khác.

“Quota consumption” chỉ là lượng đã dùng. Allowed quota/limit vẫn thuộc
Commercial Entitlement.

---

# Source Of Truth

| State | Source Of Truth |
| --- | --- |
| Raw Usage measurement — “Used.” | `saas_usage_events` |
| Current accumulated Usage | Derived `saas_usage_counters` |
| Reporting/Billing projection | Derived `saas_usage_summaries` |
| Customer identity | Tenant Domain |
| Subscription and Entitlement — “Can Use?” | Commercial Domain |
| Invoice and Payment — “Pay.” | Billing Domain |
| Learning behavior event | Track Domain |
| AI execution provenance | AI Domain |

Counters và Summaries là rebuildable read models. Chúng không thay thế Usage
Event Source Of Truth.

---

# Architecture

```text
Approved Source Measurement

↓ append

Usage Event

↓ aggregate

Usage Counter

↓ project

Usage Summary

↓ read

Commercial Comparison / Billing / Reporting
```

Data flow chỉ đi theo hướng:

```text
Usage Event

↓

Counter

↓

Summary
```

Counter hoặc Summary không được ghi ngược, sửa hoặc tạo lại Usage Event.

---

# Usage Event

`saas_usage_events` ghi immutable resource-consumption measurement.

Examples:

* AI request.
* AI token.
* Storage upload/download.
* Video transcoding.
* Certificate generated.
* Email sent.
* LiveClass minutes.
* API call.

Usage Event:

* Append-only.
* Tenant-scoped.
* Có event time (`occurred_at`) và ingestion time (`created_at`).
* Có source reference để truy vết.
* Không chứa Invoice, Payment hoặc Entitlement.

Late event vẫn được append với original `occurred_at`; projection policy quyết
định Counter/Summary nào cần rebuild.

---

# Usage Counter

`saas_usage_counters` là current accumulated usage theo feature, period và
unit.

Supported period types:

```text
daily

monthly

yearly

lifetime
```

Counter:

* Derived hoàn toàn từ Usage Events.
* Có thể rebuild.
* Không phải Source Of Truth.
* Không được Business Domain cập nhật trực tiếp.
* Không chứa allowed Entitlement value.

Counter update là projection operation của Usage Domain.

---

# Usage Summary

`saas_usage_summaries` là versioned reporting/Billing projection.

Examples:

* Daily Usage Summary.
* Monthly Usage Summary.
* Billing-period Usage Snapshot.

Summary:

* Là Read Model.
* Có `projection_version`.
* Có thể regenerate từ Usage Events.
* Không phải Source Of Truth.
* Được Billing đọc nhưng vẫn thuộc Usage.

`summary_data` chỉ chứa projected measurements. Nó không được chứa Invoice,
Payment hoặc effective Entitlement như canonical state.

---

# Metric Contract

Mỗi Usage metric phải có contract ổn định:

```text
feature_key

usage_type

unit

quantity semantics

source contract

aggregation rule
```

Examples:

```text
ai_tutor / request / request

ai_tutor / input_token / token

storage / stored_bytes / byte

liveclass / participant_minutes / minute
```

`feature_key`, `usage_type` và `unit` dùng lowercase `snake_case`. Không đổi
nghĩa metric đã publish; metric mới phải dùng key mới hoặc transition contract.

---

# Relationship With Tenant

Tenant sở hữu Customer identity và TenantContext. Mọi Usage record thuộc
`customer_id`.

Usage có thể đọc Customer identity/context nhưng không update Customer status,
domain, settings hoặc membership.

---

# Relationship With Commercial

Commercial sở hữu Plan, Subscription và Entitlement.

```text
Commercial Entitlement

↓ allowed

Usage Measurement

↓ used

Used Versus Allowed
```

Usage chỉ đọc Subscription/Entitlement khi comparison hoặc reporting cần.
Usage không activate Subscription, không update Entitlement và không quyết
định “Can Use?”.

---

# Relationship With Billing

Billing đọc approved Usage Summary hoặc measurement contract để tính amount
due.

```text
Usage Summary

↓ read

Billing Calculation

↓

Invoice / Payment
```

Billing không update Usage Event, Counter hoặc Summary. Usage không tính price,
không tạo Invoice và không ghi Payment.

---

# Relationship With Track

```text
Track Event

≠

Usage Event
```

Track Event mô tả learning behavior/observation. Usage Event đo resource
consumption. Một source action có thể sinh cả hai theo hai contract độc lập,
nhưng record này không thay thế record kia.

Usage không đọc Track Event như Usage Event nếu chưa có explicit measurement
mapping.

---

# Relationship With AI

```text
AI Model Run

≠

Usage Event
```

AI Model Run là execution provenance của AI Domain. Usage Event là approved
measurement như request count hoặc token quantity.

Usage có thể tham chiếu Model Run bằng `source_type + source_id` nhưng không
copy ownership, không sửa Model Run và không dùng estimated AI cost làm Billing
Source Of Truth.

---

# Relationship With Media And Other Sources

```text
Media Processing

≠

Usage Event
```

Media sở hữu processing state. Usage chỉ ghi measurement được phát sinh từ
processing, storage hoặc delivery contract.

Tương tự, Certificate generated, Email sent, LiveClass minutes và API call chỉ
trở thành Usage Event khi có approved metric contract; Usage không tiếp quản
source business state.

---

# Append-Only And Correction

Usage Event không update hoặc delete trong normal lifecycle.

Correction/reversal phải được biểu diễn bằng append-only measurement theo
policy được owner phê duyệt. Counter và Summary được rebuild từ event history;
không sửa source history để khớp projection.

Retention/privacy exception cần Governance approval và không được làm Counter
hoặc Summary trở thành Source Of Truth.

---

# Tenant Isolation

* Mọi Usage Event, Counter và Summary có `customer_id`.
* Source reference phải thuộc cùng tenant.
* Correlation không được nối data giữa tenants.
* Counter/Summary query và rebuild phải tenant-scoped.
* Billing và reporting consumer không được bypass TenantContext.

---

# Database Namespace

```text
saas_usage_*
```

Foundation tables:

```text
saas_usage_events
saas_usage_counters
saas_usage_summaries
```

Table documentation:
[docs/database/saas-usage](../database/saas-usage/).

---

# Principles Applied

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

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

---

# Foundation Constraints

* Không thêm Plan, Subscription hoặc Entitlement state vào Usage tables.
* Không thêm pricing, Invoice hoặc Payment state.
* Không dùng Counter/Summary làm Source Of Truth.
* Không dùng Usage Event thay Track Event, AI Model Run, Media Processing hoặc
  Audit.
* Không cho source Domain hoặc Billing update Usage projections trực tiếp.
* Không tạo migration trước owner review, ADR-0009 và Foundation Freeze.

---

# Open Questions

* Event idempotency and duplicate-ingestion contract.
* Correction/reversal measurement policy.
* Metric taxonomy governance and unit normalization.
* Period timezone and `period_key` format.
* Late-arriving event rebuild window.
* Counter concurrency and rebuild strategy.
* Summary schema/version compatibility.
* Billing-period snapshot and cutoff policy.
* Retention, privacy and high-volume partitioning.

---

# Final Statement

SaaS Usage sở hữu measurement và derived projections của “Used.”. Commercial
giữ “Can Use?”; Billing giữ “Pay.”.

```text
Foundation In Design

Ready for owner review

YES
```
