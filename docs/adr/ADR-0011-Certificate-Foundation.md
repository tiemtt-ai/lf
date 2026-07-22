# ADR-0011

Certificate Foundation

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
* [ADR-0009 — SaaS Usage Foundation](ADR-0009-SaaS-Usage-Foundation.md)
* [ADR-0010 — SaaS Billing Foundation](ADR-0010-SaaS-Billing-Foundation.md)

---

## Context

LearnForge cần phát hành Certificate có thể kiểm chứng, audit và tái hiện đúng
ngữ cảnh lịch sử.

Nếu Certificate đọc trực tiếp dữ liệu mutable hiện tại:

* User đổi tên có thể làm Certificate cũ thay đổi.
* Product hoặc Course Template đổi có thể làm sai historical meaning.
* Mapping/rules mới có thể retroactively thay đổi issuance evidence.
* Certificate Template mới có thể thay rendering/content đã cấp.
* File binary có thể bị đặt sai ownership.
* Verification/Download activity có thể mất audit trail.
* Course hoặc Assessment có thể issue Certificate ngoài Certificate boundary.

Foundation cần một Domain độc lập cho eligibility, issuance, historical
evidence và verification.

---

## Decision

Certificate được xác định là:

```text
Business Evidence Domain

+

Eligibility and Issuance Authority
```

Certificate consumes Course Completion và approved Evaluation Evidence, applies
Certificate rules and makes its own eligibility/issuance decision.

Certificate Foundation Version 1.0 gồm 5 tables.

---

## Domain Responsibility

Certificate sở hữu:

* Certificate Template.
* Template/Product Mapping.
* Eligibility and issuance rules.
* Eligibility and issuance decision.
* Issued Certificate lifecycle.
* Verification audit.
* Download/activity audit.

Certificate không sở hữu:

* Course Progress/Completion.
* Assessment Score/Result.
* Media binary/storage/delivery.
* Track Event/Summary.
* AI output.
* Billing state.

---

## Certificate Lifecycle

```text
Certificate Template

↓ Product + published Template Version mapping

Eligibility Rules

↓ Course Completion / Assessment Evidence

Eligibility Decision

↓ issue

Issued Certificate

↓

Verification / Download Audit

↓ optional

Expire / Revoke / Reissue
```

Certificate history is retained. Revocation changes lifecycle state and records
reason; it does not delete historical evidence.

---

## Template Architecture

`core_certificate_templates` owns layout, branding, content, rendering
configuration, verification display configuration and Media references.

Template does not contain Course Completion or Product eligibility decisions.

Template changes do not affect issued Certificate because issuance snapshots
historical presentation and identity data.

---

## Product Mapping

`core_certificate_template_products` binds:

* Certificate Template.
* Course Product.
* Published Course Template Version.
* Completion requirement.
* Normalized minimum score when required.
* Issue mode.
* Validity policy.

Foundation allows one active Certificate Mapping per Product.

Mapping is Source Of Truth for Product-specific Certificate rules. Course
Product does not hold a competing direct Certificate Template/rule source.

---

## Snapshot Strategy

Issued Certificate is Historical Business Evidence.

Issued Certificate must snapshot all important data:

* Student/recipient identity and display values.
* Product identity/title.
* Course Template identity/title and published Version.
* Certificate Template identity/version/rendering data.
* Completion rule and evidence context.
* Score/result when applicable.
* Completion, issuance and validity dates.

Issued Certificate must not depend completely on current User, Product,
Certificate Template or mapping data.

Current source references remain useful for lineage, but snapshots preserve
historical meaning when source records change.

---

## Certificate Evidence Principle

Certificate is Business Evidence.

Verification Log and Download Log are Audit Evidence.

Rules:

* Do not directly edit issued Certificate history.
* Revocation uses `revoked` state with timestamp, actor and reason.
* Do not delete an issued Certificate.
* Reissue creates a new Certificate with lineage.
* Verification and Download Logs are append-only.
* Retention/privacy policy cannot silently rewrite historical business meaning.

---

## Verification Architecture

```text
Resolved Tenant

↓

verification_code

↓ tenant-scoped lookup

Issued Certificate status / expiry / policy

↓

Verification Result

↓ append

Verification Log
```

Successful and failed lookups retain `customer_id`.

Verification Log snapshots result context at verify time. It does not update or
decide Issued Certificate lifecycle.

---

## Download Audit

`core_certificate_download_logs` appends view, download, print and share
activity.

Download Log:

* Is Audit Evidence.
* Does not store Certificate binary.
* Does not update Certificate state.
* Does not replace SaaS Usage measurement.

---

## Media Integration

Media owns rendered PDF/image binary, storage, variants and delivery.

Certificate keeps a Media File reference and historical rendering/content
snapshots. Certificate does not store binary, and Media does not decide
eligibility/issuance.

---

## Relationship With Course

Course owns Enrollment, Progress and Completion.

Certificate consumes Course Completion and Product/published Template Version
context, then makes its own eligibility/issuance decision.

Course does not issue or revoke Certificate directly.

---

## Relationship With Assessment

Assessment owns Score, Result and Evaluation Evidence.

Certificate may consume normalized Assessment evidence according to mapping
rules. Assessment does not decide Certificate eligibility/issuance.

---

## Relationship With Media

Media owns asset identity, binary, storage, processing and delivery.

Certificate owns the business meaning of the credential and stores only Media
reference plus Certificate snapshots.

---

## Relationship With Track

Track may consume Certificate issued, verified, revoked or downloaded events.
Track does not decide eligibility or issuance.

Approved Track Summary may become future eligibility input; Certificate remains
the decision owner.

---

## Relationship With AI

AI may suggest Template content, summarize Evidence or explain eligibility.

AI does not issue, revoke, verify or modify Certificate. AI output is not
Certificate Source Of Truth.

---

## Database Namespace

```text
core_certificate_*
```

---

## Foundation Tables

* `core_certificate_templates`.
* `core_certificate_template_products`.
* `core_certificate_issued_certificates`.
* `core_certificate_verification_logs`.
* `core_certificate_download_logs`.

Canonical table documentation:
[docs/database/course](../database/course/).

The physical documentation folder does not change Certificate Domain
ownership.

---

## Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

Applied canonical principles:

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

This ADR references canonical definitions and does not redefine them.

---

## Foundation Freeze

Certificate Foundation Version 1.0 is Approved and Frozen by this ADR.

Changes to Domain Boundary, ownership, Source Of Truth, snapshot/evidence
strategy or the 5-table Foundation require:

* Approved ADR Amendment; or
* New ADR.

Implementation must preserve Historical Business Evidence, Audit Evidence and
tenant isolation.

---

## Consequences

### Benefits

* Certificate eligibility/issuance ownership is explicit.
* Issued credentials retain historical meaning.
* User, Product, Template and mapping changes do not rewrite issued evidence.
* Binary/storage remains in Media.
* Verification and Download activity are auditable.
* Revocation/reissue preserve history.
* Course, Assessment, Track and AI remain independent.

### Trade-offs

* Snapshot fields duplicate selected source values intentionally.
* Template/mapping changes need controlled lifecycle.
* Public verification requires tenant resolution and abuse protection.
* Audit logs require privacy/retention policy.
* Reissue and revocation workflows require operational policy.
* Media deletion/retention must preserve credential delivery obligations.

---

## Future Extensions

Future topics that do not change Foundation by default:

* Template authoring/version replacement.
* Multiple active mappings per Product.
* Manual issuance and approval workflow.
* Advanced eligibility using Track summaries.
* Reissue/replacement workflow.
* Revocation publication/list integration.
* Verification fraud/abuse controls.
* Audit retention and privacy.
* Open Badges or external credential standards.

Any extension that changes Domain Boundary, ownership, Source Of Truth,
snapshot/evidence strategy or Foundation tables requires ADR Amendment or a new
ADR.

---

## Result

```text
Certificate Foundation

Version 1.0

Status

Frozen

Ready for implementation

YES
```
