# LF-AI.md

Version: 1.0

Status: Foundation Approved and Frozen

Last Updated: 2026-06

---

# LF AI Architecture

AI là Learning Intelligence & Decision Support Domain của LearnForge.

AI là Consumer Domain. AI đọc dữ liệu được phép từ các Owner Domain và tạo
conversation, recommendation, insight hoặc authoring suggestion. AI không sở
hữu và không cập nhật business state của Domain tiêu thụ output.

---

# Mission

Biến approved learning context, behavior và evidence thành hỗ trợ có thể audit
cho learner, teacher và administrator.

```text
Approved Domain Data

↓

AI Knowledge + Track Features

↓

Model Run

↓

Conversation / Recommendation / Insight / Suggestion

↓

Human or Owner Domain Decision
```

---

# AI Roles

* AI Tutor.
* AI Teaching Assistant.
* AI Recommendation.
* AI Insight Engine.
* AI Dashboard Explanation.
* AI Authoring Assistant.

Mỗi role phải có prompt scope, allowed context, allowed output và human/Domain
decision boundary rõ ràng.

---

# Domain Responsibility

AI sở hữu:

* AI Knowledge registrations, chunks và embedding references.
* AI Conversations, Messages và Assistant Sessions.
* AI Recommendations và Insights.
* Model Run audit/provenance.
* AI Feedback.
* Prompt Templates và prompt lifecycle.

AI không sở hữu hoặc quyết định:

* Course Progress hoặc Completion.
* Assessment final Grade/Result.
* LiveClass Attendance.
* Media Processing State.
* Certificate Eligibility hoặc issuance.
* Payment approval.
* Enrollment hoặc access entitlement.
* Track Event hoặc Track Feature Data.

---

# Consumer Domain Principle

AI chỉ tiêu thụ data contract được Owner Domain cho phép.

```text
Source Domain

↓ approved context / evidence / read model

AI

↓ recommendation / insight / request

Owner Domain or Human

↓

Final Decision
```

AI không trực tiếp complete Course, grade cuối cùng, issue Certificate,
approve Payment, update Progress hoặc update Attendance.

---

# Architecture Layers

## Knowledge

```text
Knowledge Source

↓ extract/chunk

Knowledge Chunk

↓ embed

Embedding Reference

↓ retrieve

RAG Context
```

Source Domain vẫn sở hữu nội dung gốc. Chunk và embedding là derived data, có
thể rebuild khi source fingerprint hoặc processing contract thay đổi.

## Conversation

```text
Conversation

↓

Assistant Session

↓

Messages

↓

Model Runs
```

Conversation state thuộc AI, nhưng message output không phải final business
decision.

## Intelligence

```text
Track Features + Course Context + Assessment Evidence + Media Knowledge

↓

Recommendation / Insight

↓

User or Owner Domain Decision
```

AI sở hữu Recommendation/Insight record và provenance, không sở hữu action
được đề xuất.

## Operations

Model Run lưu provider/model, tokens, latency, status, prompt version và cost
estimate để audit. Model Run không phải Billing Source of Truth; Usage/Billing
chỉ tiêu thụ approved measurements qua contract riêng.

## Governance

Prompt Template có lifecycle, version và approval boundary. Published prompt
immutable; thay đổi tạo version mới. Prompt không chứa credentials hoặc tenant
secret.

---

# Knowledge Sources

Approved source examples:

* Course published Version/Version Activity.
* Assessment authoring or immutable snapshot theo use case được phép.
* Media File/Transcript.
* Track Summary/AI-ready Feature.
* LiveClass transcript or operational evidence reference.

Generic source reference không miễn tenant validation, source existence,
authorization hoặc retention policy.

AI Knowledge không thay thế source content. Khi source thay đổi, pipeline đánh
dấu derived chunks/embeddings stale và rebuild theo policy.

---

# RAG Principle

Retrieval Augmented Generation:

```text
Question

↓

Tenant-scoped Retrieval

↓

Authorized Knowledge Chunks

↓

Prompt Template + Context

↓

Model Run

↓

Auditable Answer
```

Retrieval không được cross-tenant hoặc bypass source authorization.

---

# Track Integration

AI ưu tiên Track summaries, AI-ready features và feature snapshots thay vì tự
gom raw events khi projection phù hợp đã tồn tại.

Track sở hữu behavior events/features. AI sở hữu Recommendation, Prediction,
Insight và Assistant output.

```text
Track

↓ summaries / features

AI

↓ decision support

Human or Owner Domain
```

---

# Course Integration

AI learning context dùng:

* `product_id`.
* `enrollment_id`.
* `template_version_id`.
* `version_activity_id`.

AI không dùng working Template IDs làm enrolled learning context. AI Authoring
Assistant có thể tạo suggestion cho working authoring flow, nhưng teacher hoặc
Course Domain mới quyết định lưu/publish.

---

# Assessment Integration

AI có thể tạo:

* Question suggestion.
* Rubric suggestion.
* Grading suggestion.
* Feedback suggestion.

Assessment hoặc authorized human quyết định final result. AI confidence không
phải final score.

---

# LiveClass Integration

AI có thể dùng authorized transcript, Track behavior và LiveClass evidence để
tạo summary/insight. AI không thay đổi Room, Session, Attendance hoặc Replay
state.

---

# Media Integration

Media sở hữu binary, transcript, caption, processing và delivery. AI chỉ giữ
knowledge reference/chunk/embedding derived từ authorized Media content.

---

# Recommendation And Insight

Recommendation và Insight là AI-owned decision-support records.

* `accepted` hoặc `dismissed` chỉ là interaction state của AI output.
* Acceptance không tự enroll User, complete Course hoặc update curriculum.
* Evidence/provenance phải snapshot đủ để explain và audit.
* Expired/stale output không được dùng như current business decision.

---

# Model Run Audit

Mọi provider call phải có Model Run:

* Tenant and optional User.
* Purpose and assistant role.
* Provider and model.
* Prompt Template/version/hash.
* Input/output token counts.
* Latency and status.
* Cost estimate.
* Safety/error metadata.
* Correlation/provenance.

Không lưu provider API key, BYOK secret hoặc raw credential trong AI tables.

---

# Prompt Governance

Prompt Template rules:

* Global system prompt có `customer_id NULL`; tenant prompt có owner.
* `code + version` ổn định trong scope.
* Published prompt immutable.
* Thay đổi prompt tạo version mới.
* Deprecated prompt có replacement reference khi phù hợp.
* Prompt input/output schema phải audit được.
* Prompt phải xác định allowed role, purpose và context.
* Prompt không được yêu cầu model thực thi forbidden business action.

---

# Feedback

Feedback có thể target Message, Recommendation, Insight hoặc Model Run.
Feedback phục vụ quality/evaluation và không trực tiếp sửa output lịch sử hay
business state.

---

# Tenant, Privacy And Safety

1. Mọi AI business record phải tenant-scoped bằng `customer_id`; chỉ approved
   global Prompt Template được phép `customer_id NULL`.
2. Retrieval, prompt context, conversation và output không cross-tenant.
3. Conversation/message retention và redaction tuân privacy policy.
4. Sensitive raw prompt/response chỉ lưu khi purpose và retention được phép.
5. AI output phải có provenance và human/Domain escalation path.
6. Không train chéo tenant data.
7. Prompt injection, unsafe output và data exfiltration cần safety policy trước
   implementation.

---

# Database Namespace

```text
ai_*
```

Foundation tables:

```text
ai_knowledge_sources
ai_knowledge_chunks
ai_embeddings
ai_conversations
ai_messages
ai_assistant_sessions
ai_recommendations
ai_insights
ai_model_runs
ai_feedback
ai_prompt_templates
```

Table documentation:
[docs/database/ai](../database/ai/).

---

# Principles Applied

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Evidence Principle.
* Read Model Principle.
* AI Consumer Principle.
* Tenant Isolation Principle.
* Generic Reference Principle.
* Backward Compatibility Principle.
* Simplicity Principle.

---

# AI Foundation Governance Rule

Prompt Governance là governance rule của AI Foundation, không phải canonical
Architecture Principle mới. Canonical principles nằm tại
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

---

# Future Extensions

* Provider abstraction and BYOK secret management.
* Prompt approval and tenant override policy.
* Chunking/re-embedding lifecycle.
* Vector-store strategy and data residency.
* Conversation/message retention and redaction.
* Model safety, human escalation and tool/action governance.
* Usage/cost attribution contract.
* Recommendation/Insight expiration and feedback lifecycle.
* Multimodal input/output policy.

Thay đổi Domain Boundary, ownership, Source Of Truth hoặc 11-table Foundation
phải có ADR Amendment hoặc ADR mới.

---

# Architecture Decision

AI Foundation Version 1.0 được approved và freeze tại:

[ADR-0006 — AI Foundation](../adr/ADR-0006-AI-Foundation.md)

ADR-0006 là canonical decision cho Consumer Domain Boundary, Knowledge/RAG,
Conversation, Recommendation/Insight, Prompt Governance và Model Run Audit.

---

# Final Statement

AI hỗ trợ quyết định; AI không sở hữu business state.

Course giữ Progress/Completion. Assessment giữ Result. LiveClass giữ
Attendance. Media giữ Processing State. Track giữ Behavior Events/Features.
Certificate giữ Eligibility/Issuance. Billing giữ commercial state.

```text
Foundation Approved and Frozen

Version 1.0
```
