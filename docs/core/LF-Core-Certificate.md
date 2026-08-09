# LF-Core-Certificate.md

Version: 1.0

Document Status: Frozen

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: core/LF-Core-Certificate.md

---

# LF Core Certificate Architecture

Certificate là Business Evidence Domain của LearnForge.

Certificate tiêu thụ Course Completion và approved Evaluation Evidence để tự
quyết định eligibility/issuance theo Certificate rules.

```text
Course Completion

↓

Certificate Template Product Mapping

↓

Issued Certificate

↓

Verification

↓

Download Audit
```

---

# Domain Responsibility

Certificate sở hữu:

* Certificate Template.
* Template/Product Mapping.
* Eligibility and issuance rules.
* Certificate eligibility and issuance decision.
* Issued Certificate lifecycle.
* Verification audit.
* Download/activity audit.

Certificate không sở hữu:

* Course Progress hoặc Completion.
* Assessment Score/Result.
* Media binary, storage hoặc delivery.
* Track behavior.
* AI Recommendation.
* Billing state.

---

# Source Of Truth

| Business State | Source Of Truth |
| --- | --- |
| Template presentation/rendering configuration | `core_certificate_templates` |
| Product/Template Version eligibility and issuance rules | `core_certificate_template_products` |
| Issued Certificate identity, snapshots and lifecycle | `core_certificate_issued_certificates` |
| Verification result audit | `core_certificate_verification_logs` |
| View/download/print/share audit | `core_certificate_download_logs` |
| Course Progress/Completion | Course Domain |
| Assessment Score/Result | Assessment Domain |
| Rendered Certificate binary | Media Domain |

---

# Certificate Lifecycle

```text
Template

↓ mapped to Product + published Template Version

Eligibility Rules

↓ consume Course Completion / Assessment Evidence

Eligibility Decision

↓ issue

Issued Certificate

↓ optional

Expire / Revoke / Reissue
```

Certificate history is never deleted. Invalidated Certificate uses `revoked`
state with revocation evidence.

---

# Template Architecture

Certificate Template owns:

* Layout.
* Branding.
* Certificate content.
* Rendering configuration.
* Verification display configuration.
* Media references.

Template does not own Product completion, eligibility or issuance rules.

Template changes do not alter already issued Certificates because issuance
captures historical snapshots.

---

# Product Mapping

Certificate Template Product Mapping binds:

```text
Certificate Template

+

Course Product

+

Published Course Template Version

+

Eligibility / Issuance Rules
```

Foundation supports one active mapping per Product. Mapping stores normalized
completion/score threshold, issue mode and validity policy.

Course Product does not store a direct Certificate Template as issuance Source
Of Truth.

---

# Snapshot Strategy

Issued Certificate is Historical Business Evidence.

Issued Certificate snapshots all important issuance data:

* Student/recipient identity and display values.
* Product identity/title.
* Course Template identity/title and published Version.
* Certificate Template identity/version/rendering configuration.
* Completion rules.
* Score/result and completion time.
* Issuance/validity context.

Issued Certificate does not depend completely on current User, Product,
Template or mapping data.

---

# Certificate Evidence Principle

Certificate is Business Evidence.

Verification Log and Download Log are Audit Evidence.

Rules:

* Do not directly edit issued Certificate history.
* Revoke with `revoked` state, timestamp, actor and reason.
* Do not delete an issued Certificate.
* Reissue creates a new record with lineage to the previous Certificate.
* Audit Logs are append-only.

---

# Verification Architecture

Verification always runs inside resolved Tenant context.

```text
verification_code

↓ tenant-scoped lookup

Issued Certificate

↓ evaluate status / expiry / verification policy

Verification Result

↓ append

Verification Log
```

Successful and failed lookups retain `customer_id`. Verification Log snapshots
the result context and does not decide Certificate lifecycle.

---

# Download Audit

View, download, print and share activity append Download Logs.

Download Log:

* Is audit/usage evidence.
* Does not store the Certificate file.
* Does not change Certificate state.
* Does not replace SaaS Usage measurement when commercial metering is needed.

---

# Relationship With Course

Course owns Enrollment, Progress and Completion. Certificate consumes Course
Completion and Product/Template Version context.

Course does not issue Certificate directly. Certificate evaluates its own
mapping/rules and makes the eligibility/issuance decision.

---

# Relationship With Assessment

Assessment owns Score, Result and Evaluation Evidence.

Certificate may consume normalized Assessment evidence according to mapping
rules. Assessment does not update Certificate eligibility or issue
Certificate.

---

# Media Integration

Media owns rendered PDF/image binary, storage, variants and delivery.

Certificate stores `media_file_id`/file reference plus rendering/content
snapshots required for historical meaning. Certificate does not store binary.

---

# Relationship With Track

Track may consume Certificate issued, verified, revoked or downloaded events.
Track does not decide Certificate eligibility/issuance.

Track summaries may be future policy input only when approved; Certificate
still makes the decision.

---

# Relationship With AI

AI may suggest Template content, summarize evidence or explain eligibility.

AI does not issue, revoke, verify or update Certificate. AI output is not
Certificate Source Of Truth.

---

# Tenant Isolation

* All five Certificate tables use `customer_id`.
* Template, Product, published Template Version and mapping must share Tenant.
* Issued Certificate source references and Media reference must share Tenant.
* Verification and download lookup/logging are tenant-scoped.
* Public verification does not bypass Tenant resolution.

---

# Database Namespace

```text
core_certificate_*
```

Foundation tables:

```text
core_certificate_templates
core_certificate_template_products
core_certificate_issued_certificates
core_certificate_verification_logs
core_certificate_download_logs
```

Canonical table documentation:

* [core_certificate_templates](../database/course/core_certificate_templates.md)
* [core_certificate_template_products](../database/course/core_certificate_template_products.md)
* [core_certificate_issued_certificates](../database/course/core_certificate_issued_certificates.md)
* [core_certificate_verification_logs](../database/course/core_certificate_verification_logs.md)
* [core_certificate_download_logs](../database/course/core_certificate_download_logs.md)

The current physical documentation folder does not change Certificate Domain
ownership.

---

# Principles Applied

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Immutable Principle.
* Snapshot Principle.
* Evidence Principle.
* Platform Domain Principle.
* Tenant Isolation Principle.
* Append Only Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

---

# Architecture Decision

[ADR-0011 — Certificate Foundation](../adr/ADR-0011-Certificate-Foundation.md)
approves and freezes this Foundation at Version 1.0.

Changes to Domain Boundary, ownership, Source Of Truth, snapshot/evidence
strategy or the 5-table Foundation require an approved ADR Amendment or a new
ADR.

---

# Future Extensions

* Template authoring/version replacement workflow.
* Multiple active mappings per Product.
* Manual issuance and approval workflow.
* Advanced eligibility policies using Track summaries.
* Reissue/replacement workflow.
* Revocation publication/list integration.
* Verification fraud/abuse protection.
* Verification/download retention and privacy.
* Open Badges or external credential standards.

---

# Final Statement

```text
Certificate Foundation

Version 1.0

Status

Foundation Approved and Frozen

Ready for implementation

YES
```
