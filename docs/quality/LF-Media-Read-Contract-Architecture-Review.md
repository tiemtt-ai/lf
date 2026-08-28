# Media Read Contract Architecture Review

Version: 1.13

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-08-28

Review Date: 2026-08-24

Document Path: quality/LF-Media-Read-Contract-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Media × AI × Course |
| Specification | [LF-Media-Read-Contract](../platform/LF-Media-Read-Contract.md) v1.13 |
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
- [x] Review độc lập 2026-08-28 đã đọc Spec B và implementation crop, kết luận
      BLOCKED với 3 blocker; cả ba đã được sửa và ghi ở § Crop scope.
- [ ] Reviewer độc lập chưa xác nhận lại sau khi sửa; crop chưa có
      Architecture Review riêng.
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
CLOSED BY OWNER ATTESTATION — 2026-08-28.
SCOPE CORRECTED — 2026-08-28 sau review độc lập.

Đóng bằng chỉ thị trực tiếp của Architecture Owner trong phiên làm việc
2026-08-28 ("tôi cho phép đóng"), áp dụng cho Spec B v1.8 tại thời điểm đó.

SỬA CĂN CỨ: bản trước viện dẫn ADR-0017 § Course Template Mapping Intent
Amendment. Điều khoản "implementation review is optional assurance" nằm trong
amendment có phạm vi Course Template Mapping Intent, KHÔNG cấp thẩm quyền cho
Media Read. Trích dẫn đó bị rút. Thẩm quyền thật là chỉ thị Owner trực tiếp —
đủ để đóng, nhưng phải ghi đúng nguồn thay vì mượn phạm vi của ADR khác.

KHÔNG có independent architecture review nào được thực hiện cho Spec B. A–H
record này do cùng implementation stream lập.

KHÔNG BAO GỒM: crop (Spec B § 5.3, v1.9–v1.11), signed delivery của crop, và
vòng đời crop trên storage. Toàn bộ phần đó ra đời SAU lần đóng này và chưa
từng được đóng bởi bất kỳ thẩm quyền nào. Xem § Crop scope bên dưới.
```

## Crop scope — chưa được đóng

Review độc lập ngày 2026-08-28 chặn crop với ba blocker; hai cái đầu là defect
thật trong code và contract, cái thứ ba chính là phạm vi thẩm quyền ở trên:

| # | Blocker | Trạng thái |
| --- | --- | --- |
| 1 | Crop upload ngoài transaction; revision thất bại để lại object mồ côi có thể chứa PII | Đã sửa: purge ở provider, job và `failed()` khi worker bị giết; kết quả `false` không còn bị nuốt; lỗi cleanup không thay thế lỗi gốc. Sweeper quét cả revision thất bại trên Media còn `ready`. **Chưa lên lịch tự động** |
| 2 | Spec B nói crop all-or-nothing theo revision, runtime chỉ cắt `role = figure` — consumer diễn giải `crop = null` sai | Đã sửa: Spec B v1.11 đổi sang all-or-nothing trên **tập vùng đủ điều kiện** |
| 3 | Đóng bằng thẩm quyền ngoài phạm vi, và tham chiếu Spec B v1.8 đã lạc hậu | Đã sửa ở record này |

### Vòng hai — 2026-08-28

Review độc lập lần hai xác nhận blocker 2 đã đóng, blocker 3 sửa đúng căn cứ, và
mở thêm một blocker về vòng đời xoá Media:

| # | Blocker | Trạng thái |
| --- | --- | --- |
| 5 | Sweeper sai phạm vi (chỉ quét `status = 'deleted'` nên bỏ sót crop của revision thất bại trên Media còn `ready`), `--limit` gây starvation, và một disk hỏng dừng cả lượt quét | Đã sửa: thêm nguồn revision thất bại với định nghĩa mồ côi theo row tham chiếu, `--limit` đếm Media đã xử lý, isolation try/catch theo từng Media. Năm test mới, năm mutation đều đổ |
| 6 | Nút Dọn ngay có race với structured job đang chạy: crop đã lên storage nhưng row chưa commit trông y hệt rác mồ côi, nên "dọn orphan" có thể xoá asset hợp lệ. `--limit` còn bị row lỗi tiêu tốn | Đã sửa: ba lớp chặn (bỏ qua lúc quét, kiểm lại trong khoá row `media_files` cùng khoá mà job dùng để claim, tính lại danh sách key trong khoá) và tách ngân sách lỗi khỏi `--limit`. Bốn mutation, bốn test đổ |
| 4 | `deleteMedia()` xoá crop trước source: hỏng một trong hai hướng đều để lại Media `ready` mất dữ liệu, hoặc Media `deleted` còn crop chứa PII; `deleteDirectory()` trả `false` bị nuốt | Đã sửa: DB đánh dấu `deleted` trước, hai hàm xoá kiểm chứng lại kết quả, thất bại được log mức error và có sweeper |

Ba blocker đã đóng về mặt implementation. **Chưa đóng**, và không được coi là đã
đóng:

* Owner quyết định ngày 2026-08-28, **thay thế** policy "sweeper chạy mỗi giờ"
  ghi trước đó cùng ngày: không có tác vụ nào tự động xoá file. Dọn rác là thao
  tác thủ công có chủ đích — nút trong Quản lý Media, hoặc lệnh artisan. Đây là
  quyết định đã đóng, không phải gate vận hành còn mở. Hệ quả được chấp nhận:
  một lần dọn thất bại chỉ lộ ra qua log cho tới khi có người bấm lại.
* Crop **vẫn cần một Architecture Review riêng** trước khi mở cho AI consumer
  thật, cùng gate với retention/redaction (rủi ro B1).

## Rủi ro đã biết khi đóng theo cách này

Trong chính giai đoạn làm Spec B, bốn defect chặn đã được tìm ra và **không cái
nào do công cụ tự động phát hiện** — `docs:lint` và `schema:drift` xanh xuyên
suốt trong lúc chúng tồn tại:

| Defect | Ai tìm ra |
| --- | --- |
| `structureCoverage()` trộn nhiều source/revision, báo coverage sai mà hợp lý | vòng review sau khi tác giả nói xong |
| Bỏ qua guard `ambiguous_source` — hai lối vào cùng hợp đồng có hai luật | như trên |
| Coverage read không ghi access audit, trái § 8 của chính hợp đồng | như trên |
| Version của Spec B bị lùi từ 1.7 xuống 1.4 | như trên |

Ba trong bốn là **sự không nhất quán với chính hợp đồng vừa được đọc kỹ**, nằm
cách đó vài chục dòng trong cùng một file. Đó là dữ liệu về giới hạn của
self-assessment, không phải về sự cẩu thả.

Owner chấp nhận rủi ro này. Nếu về sau có reviewer độc lập đọc Spec B, kết quả
của họ được ghi tiếp vào record này chứ không thay thế mục trên.

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
* writes allowed/denied `read_derived` audit evidence for coverage reads,
  including authorization denial or ambiguity whenever the owner-scoped Media
  row can be resolved without granting access; and
* has regression coverage proving a ready row from another source revision is
  excluded, plus a denied coverage read is recorded with
  `decision=denied/error_code=unauthorized`.

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
