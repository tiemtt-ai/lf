# LF-SaaS-Tenant.md

Version: 1.0

Status: Foundation Approved and Frozen

Last Updated: 2026-06

Document Path: saas/LF-SaaS-Tenant.md

---

# LF SaaS Tenant Architecture

Tenant Domain là multi-tenant foundation của LearnForge.

Tenant xác định Customer identity, request tenant context, settings, domain
mapping, membership, invitation và tenant-level audit trail.

```text
One Codebase

↓

One Platform

↓

Many Isolated Customers
```

---

# Domain Responsibility

SaaS Tenant sở hữu:

* Customer/Tenant identity.
* Tenant settings.
* Domain/subdomain mapping.
* Customer membership.
* Tenant invitation.
* Tenant-level audit trail.

SaaS Tenant không sở hữu:

* Course Progress hoặc Completion.
* Assessment Result.
* LiveClass Attendance.
* Media Processing State.
* Track behavior state.
* AI Recommendation.
* Billing Invoice.
* Usage metering.
* Subscription lifecycle.

---

# Source Of Truth

| State | Source Of Truth |
| --- | --- |
| Customer identity/lifecycle | `saas_customers` |
| Request domain mapping | `saas_customer_domains` |
| Tenant configuration | `saas_customer_settings` |
| User–Customer membership | `saas_customer_members` |
| Invitation lifecycle | `saas_customer_invitations` |
| Tenant audit history | `saas_audit_logs` |
| User identity/profile/auth credential | User/Auth Domain |

`customer_id` trên mọi business table tham chiếu `saas_customers.id`.

---

# Architecture

```text
Incoming Host

↓

saas_customer_domains

↓

saas_customers

↓

TenantContext

↓

Authentication

↓

saas_customer_members

↓

Authorization / Role Experience
```

Tenant phải được resolve trước authentication.

---

# Customer Root

`saas_customers` là Tenant root identity và lifecycle record.

Allowed status:

```text
active

inactive

suspended

archived
```

Root không chứa Billing, Subscription, Usage hoặc learning state.

`subdomain` và `custom_domain` trên root là compatibility/bootstrap fields.
`saas_customer_domains` là canonical routing registry. Routing không được tạo
hai Source Of Truth.

---

# Domain Resolution

Supported domain types:

```text
subdomain

custom_domain
```

Custom domain phải verified trước khi active routing. Chỉ một primary active
domain được phép trong một Customer scope.

```text
Request

↓ Host lookup

Verified Active Domain

↓

Customer

↓

TenantContext
```

Domain visibility không thay authorization.

---

# Tenant Context

TenantContext cung cấp current Customer identity cho request:

```php
TenantContext::customer()

TenantContext::customerId()

TenantContext::slug()

TenantContext::themeKey()

TenantContext::layoutKey()
```

Mọi query business data phải dùng current `customer_id`. Không hardcode tenant
ID và không fallback sang tenant khác khi resolution thất bại.

---

# Settings

`saas_customer_settings` lưu configuration theo group/key.

Settings không phải:

* Course business rule.
* Billing/Subscription state.
* Credential vault.
* Arbitrary schema replacement.

Sensitive value chỉ được lưu khi encryption, access and rotation policy được
approved. Provider API key/BYOK secret không được lưu như plain setting.

---

# Membership

`saas_customer_members` là Source Of Truth cho quan hệ User–Customer.

Foundation roles tuân Guardrails:

```text
customer_admin

teacher

student
```

`staff` không phải official role và không thuộc Foundation. Role mới cần
Guardrail/ADR approval.

Current `users.customer_id` và `users.role` vẫn phục vụ simple tenant-owned User
model. Membership không được âm thầm phá compatibility. Multi-customer User
identity policy phải được owner chốt trước implementation.

Protected route phải validate:

```text
Resolved Tenant

+

Authenticated User

+

Active Membership

+

Allowed Role
```

---

# Invitation

Invitation lưu token hash, không lưu raw token.

```text
Pending Invitation

↓ validate tenant/email/token/expiry

Accept

↓

Create or Activate Membership
```

Invitation không tạo Membership trước acceptance. Expired/revoked invitation
không được accept.

---

# Audit Trail

`saas_audit_logs` là append-only tenant-level audit trail.

Audit Log:

* Không phải business state.
* Không thay event/evidence của Course, Assessment, Track hoặc Billing.
* Không update/delete ngoài approved retention/privacy policy.
* Có thể dùng `customer_id NULL` chỉ cho documented system/global event.

IP Address và User Agent có thể anonymize, hash hoặc purge theo privacy policy.

---

# Relationship With Auth And User

User Domain sở hữu identity/profile/status. Auth sở hữu authentication flow.
Tenant sở hữu Customer identity và membership.

```text
Resolve Tenant

↓

Authenticate User

↓

Validate Active Membership

↓

Validate Official Role
```

Single `/login` và official redirects/roles không thay đổi.

---

# Relationship With Core Domains

Course, Assessment, LiveClass, Media, Track và AI records giữ `customer_id`
ownership. Tenant Domain cung cấp boundary/context nhưng không quyết định
learning, operational, evaluation, media hoặc AI state.

---

# Relationship With Commercial, Billing And Usage

Commercial, Billing và Usage là separate SaaS Domains.

Tenant cung cấp Customer identity/context cho các Domain đó nhưng không lưu
Plan, Subscription, Entitlement, Invoice, metering hoặc charge state.

---

# Database Namespace

```text
saas_*
```

Foundation tables:

```text
saas_customers
saas_customer_settings
saas_customer_domains
saas_customer_members
saas_customer_invitations
saas_audit_logs
```

Table documentation:
[docs/database/saas](../database/saas/).

---

# Principles Applied

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Tenant Isolation Principle.
* Evidence Principle.
* Append Only Principle.
* Backward Compatibility Principle.
* Simplicity Principle.

---

# Architecture Decision

[ADR-0007 — SaaS Tenant Foundation](../adr/ADR-0007-SaaS-Tenant-Foundation.md)
approves and freezes this Foundation at Version 1.0.

Changes to Domain Boundary, Tenant Boundary, ownership, Source Of Truth,
official roles or Foundation tables require an approved ADR Amendment or a new
ADR.

---

# Future Extensions

* Single-customer versus multi-customer User identity policy.
* Domain primary uniqueness enforcement and legacy field transition.
* Custom-domain verification, SSL and takeover protection.
* Settings schema, encryption and secret-management boundary.
* Invitation concurrency, re-invite and email-normalization policy.
* Audit retention, privacy, legal hold and global-event policy.
* Tenant provisioning/deprovisioning and last-admin protection.

---

# Final Statement

SaaS Tenant là owner của Customer identity và tenant boundary, không phải owner
của learning hoặc commercial state ngoài Tenant scope.

```text
Foundation Approved and Frozen

Version 1.0
```
