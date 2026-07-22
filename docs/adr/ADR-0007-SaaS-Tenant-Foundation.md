# ADR-0007

SaaS Tenant Foundation

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

---

## Context

LearnForge là multi-tenant AI-native LMS SaaS. Mỗi request và mọi business data
phải được đặt trong một Customer boundary rõ ràng trước khi authentication,
authorization hoặc Domain logic được thực thi.

Nếu Customer identity, domain routing, settings, membership, invitation và
tenant audit bị phân tán:

* Tenant resolution có thể không nhất quán giữa các entry point.
* User có thể truy cập dữ liệu ngoài active Customer.
* Domain khác có thể tự định nghĩa tenant ownership và tạo nhiều Source Of
  Truth.
* Official roles có thể bị mở rộng ngoài Governance.
* Billing, Usage hoặc learning state có thể bị đặt nhầm trong Tenant Domain.
* Audit và invitation lifecycle khó được kiểm soát xuyên hệ thống.

Foundation cần xác định một SaaS Tenant Domain độc lập, giữ Tenant boundary ổn
định mà không tiếp quản business state của các consumer Domain.

---

## Decision

SaaS Tenant được xác định là:

```text
SaaS Foundation Domain

+

Tenant Boundary Authority
```

Tenant cung cấp Customer identity, resolved TenantContext và membership
boundary cho toàn nền tảng. Mỗi consumer Domain vẫn sở hữu business state và
business rules của chính Domain đó.

SaaS Tenant Foundation Version 1.0 gồm 6 tables.

---

## Domain Responsibility

SaaS Tenant sở hữu:

* Customer/Tenant identity và lifecycle.
* Tenant settings.
* Domain/subdomain mapping.
* User–Customer membership.
* Tenant invitation.
* Tenant-level audit trail.

SaaS Tenant không sở hữu:

* User identity, profile hoặc authentication credential.
* Course Progress, Completion hoặc Enrollment.
* LiveClass Attendance.
* Assessment Result.
* Media Processing State.
* Track behavior state.
* AI Recommendation hoặc Insight.
* Billing Invoice, Usage metering hoặc Subscription lifecycle.

---

## Tenant Boundary

```text
Incoming Host

↓

Verified Active Domain

↓

Customer

↓

TenantContext

↓

Authentication

↓

Active Membership

↓

Authorized Role Experience
```

Tenant phải được resolve trước authentication. Mọi business query phải dùng
resolved `customer_id`; resolution failure không được fallback sang Customer
khác.

`customer_id` là ownership boundary trên tenant-owned business data. Global
record chỉ được phép khi canonical Domain document định nghĩa rõ nullable
tenant scope và authorization tương ứng.

---

## Customer Identity

`saas_customers` là Source Of Truth cho Customer identity và lifecycle.

Customer root:

* Không có `customer_id` của chính nó.
* Không lưu learning state.
* Không lưu invoice, metering hoặc subscription entitlement.
* Là target của `customer_id` trên tenant-owned records.

`subdomain` và `custom_domain` trên Customer root được giữ như
compatibility/bootstrap fields. Chúng không thay thế canonical domain-routing
registry.

---

## Membership Architecture

User Domain sở hữu identity/profile. SaaS Tenant sở hữu quan hệ User–Customer
qua `saas_customer_members`.

Foundation official roles:

```text
customer_admin

teacher

student
```

`staff` không phải official Foundation role. Role mới cần Governance và ADR
approval.

Protected access phải xác nhận đồng thời:

* Resolved Customer.
* Authenticated User.
* Active membership trong Customer đó.
* Official role được phép.

Current `users.customer_id` và `users.role` là compatibility contract cho
simple tenant-owned User model. Chính sách multi-customer User identity là
future extension và không được làm suy yếu tenant isolation.

---

## Domain Routing

`saas_customer_domains` là canonical Source Of Truth cho request domain
mapping.

Supported domain types:

* `subdomain`.
* `custom_domain`.

Custom domain phải verified trước active routing. Primary-domain policy phải
được enforce trong Customer scope. Domain visibility hoặc successful host
lookup không thay thế membership và authorization.

---

## Settings Architecture

`saas_customer_settings` lưu tenant configuration theo group/key.

Settings:

* Không thay thế schema hoặc business rules của Domain khác.
* Không lưu Billing/Subscription business state.
* Không là credential vault.
* Chỉ lưu sensitive value khi encryption, access và rotation policy được
  approved.

Provider API key hoặc BYOK secret không được lưu như plain tenant setting.

---

## Invitation Lifecycle

```text
Pending Invitation

↓ validate tenant, email, token hash and expiry

Accepted Invitation

↓

Create or Activate Membership
```

Invitation lưu token hash, không lưu raw token. Invitation không tạo membership
trước acceptance. Expired, revoked hoặc already-consumed invitation không được
accept lại.

---

## Audit Architecture

`saas_audit_logs` là append-only tenant audit trail.

Audit Log:

* Không phải business state.
* Không thay thế event/evidence của Domain khác.
* Không bị update hoặc delete ngoài approved retention/privacy policy.
* Chỉ dùng `customer_id NULL` cho documented system/global event.

IP Address và User Agent có thể anonymize, hash hoặc purge theo policy.

---

## Integration

### Auth

Auth xác thực User sau khi Tenant được resolve. Auth không sở hữu Customer
identity hoặc membership.

### User

User sở hữu identity/profile/status. Tenant sở hữu User–Customer membership và
Tenant role assignment.

### Course

Course records dùng `customer_id` và tự sở hữu authoring, enrollment, progress
và completion.

### LiveClass

LiveClass dùng TenantContext cho isolation và tự sở hữu Room, Session,
Attendance, Replay, Recording reference và Chat.

### Assessment

Assessment dùng TenantContext cho isolation và tự quyết định grading/result
theo Assessment rules.

### Media

Media dùng TenantContext cho asset isolation và tự sở hữu digital-asset
metadata, processing và delivery references.

### Track

Track dùng TenantContext cho event isolation và tự sở hữu append-only behavior
events cùng projections.

### AI

AI dùng authorized tenant context và membership. AI tự sở hữu Recommendation,
Insight và interaction state nhưng không cập nhật Tenant hoặc consumer
business state.

### Billing (Future)

Billing có thể consume Customer identity nhưng tự sở hữu invoice, calculation
và billing outcome.

### Usage (Future)

Usage có thể consume Customer identity và platform measurements nhưng tự sở
hữu usage measurement/aggregation.

---

## Database Namespace

```text
saas_*
```

---

## Foundation Tables

* `saas_customers`.
* `saas_customer_settings`.
* `saas_customer_domains`.
* `saas_customer_members`.
* `saas_customer_invitations`.
* `saas_audit_logs`.

Canonical table documentation:
[docs/database/saas](../database/saas/).

---

## Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

Applied canonical principles:

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Tenant Isolation Principle.
* Evidence Principle.
* Append Only Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

This ADR references canonical definitions and does not redefine them.

---

## Foundation Freeze

SaaS Tenant Foundation Version 1.0 is Approved and Frozen by this ADR.

Changes to Domain Boundary, Tenant Boundary, ownership, Source Of Truth,
official roles or the 6-table Foundation require:

* Approved ADR Amendment; or
* New ADR.

Implementation must preserve resolved TenantContext, active-membership
validation and `customer_id` isolation.

---

## Consequences

### Benefits

* Customer identity and request routing have explicit Sources Of Truth.
* Tenant resolution occurs before authentication and Domain logic.
* User identity and Tenant membership ownership remain separate.
* Official roles remain aligned with Guardrails.
* Consumer Domains share one tenant boundary without transferring business
  ownership.
* Invitation and tenant audit lifecycles are explicit and auditable.

### Trade-offs

* Compatibility fields and the canonical domain registry require a controlled
  transition policy.
* Multi-customer User support needs a future compatibility decision.
* Domain verification, SSL and takeover protection need operational contracts.
* Settings encryption and secret storage need dedicated infrastructure policy.
* Invitation concurrency and audit retention need implementation-level policy.

---

## Future Extensions

Future topics that do not change Foundation by default:

* Multi-customer User identity policy.
* Custom-domain verification, SSL and takeover protection.
* Tenant provisioning and deprovisioning workflow.
* Settings schema and encrypted-secret integration.
* Invitation concurrency, re-invite and email normalization.
* Audit retention, privacy, legal hold and global-event policy.
* Organization and Department hierarchy.
* SSO and SCIM.
* Billing, Usage, Subscription and Marketplace integrations.

Any extension that changes Domain Boundary, Tenant Boundary, ownership, Source
Of Truth, official roles or Foundation tables requires ADR Amendment or a new
ADR.

---

## Result

```text
SaaS Tenant Foundation

Version 1.0

Status

Frozen

Ready for implementation

YES
```
