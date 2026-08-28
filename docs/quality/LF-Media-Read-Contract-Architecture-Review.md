# Media Read Contract Architecture Review

Version: 1.5

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-08-28

Review Date: 2026-08-24

Document Path: quality/LF-Media-Read-Contract-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Media × AI × Course |
| Specification | [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) v1.8 |
| Producer Contract | [LF-Media-Processing-Contract](../platform/LF-Media-Processing-Contract.md) v2.7 |
| Parent ADRs | [ADR-0004](../adr/ADR-0004-Media-Foundation.md), [ADR-0006](../adr/ADR-0006-AI-Foundation.md), [ADR-0017](../adr/ADR-0017-AI-Assisted-Learning-Authoring.md), [ADR-0018](../adr/ADR-0018-Media-PII-And-External-Processing-Boundary.md), [ADR-0019](../adr/ADR-0019-Media-Structured-Extraction-Boundary.md) |
| Review Scope | Internal Media Read Service/API for authorized derived output consumers |

Runtime closure 2026-08-24: service nhận `actor_id` tường minh cho HTTP,
queue và console; denied read trên Media File resolve được được audit với
decision/error code. Không còn dependency ẩn vào `request()->user()`.

Review provenance: khung A–H và implementation evidence này được lập trong cùng
implementation stream với Spec B/Media Read runtime. Đây là **author
self-assessment**, không phải independent Architecture Review. Các checkbox là
review packet cho reviewer kế tiếp; chúng không tự tạo verdict Approved.

# A — Domain Boundary

- [x] Media owns selection, readiness, locator and signed delivery policy.
- [x] AI is consumer only; no direct `media_*` query and no object-storage read.
- [x] Read Service creates no Proposal, Mapping, Evidence, Mastery or other
      cross-domain business state.
- [x] Course remains authority for owner-context authorization.

# B — Data Ownership And Authorization

- [x] Input is `owner_type + owner_id`, never bare `media_file_id`.
- [x] Phase 1 owner vocabulary is exactly `course_activity` and
      `course_version_activity`.
- [x] Tenant comes from `TenantContext`; owner adapter must verify owner,
      actor authorization, active same-tenant usage and same-tenant Media File.
- [x] A valid revision or Media identifier never grants access outside the
      current authorized owner context.
- [x] `usage_type` là selector bắt buộc; exact-slot lookup không phụ thuộc query
      order và nhiều active row trong cùng slot trả `ambiguous_source`.

# C — Versioning And Selection

- [x] Explicit locale is exact; omitted locale resolves only
      `media_files.processing_locale`; no implicit fallback.
- [x] Default selection excludes archived rows and returns the current ready
      revision for exact owner/content type/locale.
- [x] Archived read requires explicit `processing_version`; optional
      `source_fingerprint` must match that same revision.
- [x] `revision_unavailable`, `revision_mismatch`, `locale_unavailable` and
      `unauthorized` remain distinct fail-closed results.
- [x] Historical row and locator remain immutable; no silent rebind to latest.

# D — Business Rules

- [x] Every text unit carries exact `source_fingerprint`, `processing_version`
      and citation locator (`page` or `timespan`).
- [x] Caption/variant are file assets with `locator = null`; cue citation is not
      invented.
- [x] Signed URL is generated only for `caption_asset` and `variant`, after the
      same owner-context authorization and revision selection.
- [x] Non-ready state returns the closed error contract rather than an empty
      collection or locale/revision fallback.
- [x] A page with canonical text but no region returns
      `structure_unavailable`; consumers must fallback to page-level
      `extracted_text` rather than interpreting an empty structure result as a
      blank page.
- [x] Live structure coverage selects one current ready structured revision and
      one canonical text revision with the same `source_fingerprint`; rows from
      historical or different-source revisions are excluded.

# E — Database And Audit

- [x] Read paths are tenant-scoped and join through active usage ownership.
- [x] Every successful derived read appends `media_access_logs.action =
      read_derived`; owner context and selectors belong in safe metadata.
- [x] Structure-coverage reads use the same active usage, ambiguity,
      authorization and audit boundary as unit reads.
- [x] Access log is physically append-only through `BEFORE UPDATE` and `BEFORE
      DELETE` triggers; logs store no signed URL or credential.
- [x] No schema relationship gives AI write authority over Media.

# F — Architecture

- [x] Media Read Service is the only consumer boundary; AI cannot reproduce
      selection or authorization logic in repositories of its own.
- [x] Course adapter answers owner authorization; Media does not infer Course
      business state.
- [x] Signed delivery remains private, short-lived and tenant-aware.
- [x] AI Knowledge Source stores exact fingerprint/version and becomes stale
      when a new Media revision exists; Media neither rebuilds nor deletes AI
      chunks/embeddings.

# G — Documentation

- [x] Spec B is routed from `LF-INDEX.md` and linked from the processing contract.
- [x] Error, locale, revision, locator, audit and stale boundaries are explicit.
- [x] Retention/redaction remains an open risk; this review does not invent a
      policy or authorize consumer access to real personal data.
- [x] DOC-CONFLICT-0014 and DOC-CONFLICT-0015 remain open; their two explicit
      Owner yes/no decisions are recorded in the conflict register.

# H — Independent Review Gate

- [x] Owner-context input and authorization boundary are deterministic.
- [x] Current and explicit archived revision selection are deterministic.
- [x] Output/error shapes and append-only audit requirement are deterministic.
- [x] Owner directive đã authorize scoped implementation; không đồng nghĩa
      independent review approval.
- [x] Runtime, migrations and HIGH tests completed.
- [ ] Independent reviewer chưa được ghi nhận; reviewer phải kiểm Spec B và
      implementation evidence này.
- [ ] Retention/redaction policy approved for real-consumer rollout.

# Documented Risks

| # | Risk | Gate |
| --- | --- | --- |
| B1 | Retention/redaction for OCR/transcript content is not approved | Blocks real AI consumer rollout |
| B2 | Cue-level caption citation has no contract | Use transcript locator; new cue contract requires review |
| B3 | Structured reads exist, but scan-page region coverage is not guaranteed under the current layout-only provider configuration | Consumer must honor `structure_unavailable` and fallback to canonical page text |
| B4 | External processing providers are not configured | Blocks production-derived content generation |
| B5 | DOC-CONFLICT-0021 is resolved by required `usage_type`; multiple active rows in the exact slot fail closed as `ambiguous_source` | Closed in Spec B v1.7; retain regression coverage |

# Review Result

```text
PENDING INDEPENDENT REVIEW — author self-assessment finds the contract
deterministic, but no independent reviewer evidence is recorded. Scoped runtime
exists under Owner implementation directive; this record must not be cited as
an Approved architecture verdict. B1–B4 remain open.
```

## Spec B Version 1.8 remediation evidence — 2026-08-28

Review of the first `structureCoverage()` implementation found that it selected
all ready rows for a tenant/media/locale, bypassed exact active-usage ambiguity,
and emitted no access audit. That implementation could mix historical source
revisions and report a plausible but false coverage value. Version 1.8 now:

* resolves exactly one active `(owner_type, owner_id, usage_type)` slot and
  fails closed for detached or ambiguous ownership;
* selects the current ready structured revision before page filtering;
* selects canonical page text from the same `source_fingerprint` and one text
  revision only;
* constrains table page lookup to regions in that same structured revision;
* writes allowed/denied `read_derived` audit evidence for coverage reads; and
* has regression coverage proving a ready row from another source revision is
  excluded.

This remediation closes the implementation defects found in this review pass.
It does **not** satisfy the unchecked independent-review gate above, and it does
not approve retention/redaction or real AI consumer rollout.

# Owner Implementation Directive (not review approval)

```text
Role: LearnForge Architecture Owner
Date: 2026-08-24
Decision: Approved for scoped implementation by the owner directive recorded in
          the implementation request. This does not mark this self-authored
          review Approved; independent review remains required. Retention/
          redaction and production-provider gates remain open.
```

## Owner

Architecture Team

## Primary Consumers

* Developer
* Reviewer
* AI Agent
