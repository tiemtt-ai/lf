# ADR-0006

AI Foundation

---

## Status

Frozen

---

## Version

1.0

---

## Implementation Status

Not Implemented

---

## Last Updated

2026-08-22

---

## Date

2026-06-28

---

Document Path: adr/ADR-0006-AI-Foundation.md

## Related ADRs

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md)
* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md)
* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0005 — Track Foundation](ADR-0005-Track-Foundation.md)
* [ADR-0016 — Learning Foundation](ADR-0016-Learning-Foundation.md)

---

## Amendment Record — Version 1.1 (Proposed)

Amendment Status: **Proposed — pending Architecture Owner approval.** This
record does not change the Version 1.0 Foundation Freeze below. Until an
Owner Approval decision is recorded, AI Foundation remains Version 1.0 and
Learning is not an authorized Knowledge Source.

This proposed amendment would extend the Consumer Domain Boundary to
recognize `core_learning_mastery_profiles` (Mastery Profile) under
[ADR-0016 — Learning Foundation](ADR-0016-Learning-Foundation.md) as an
approved, read-only structured read-model input for Recommendation/Insight —
not a Knowledge/RAG source. Mastery Profile is not registered in
`ai_knowledge_sources` and is not chunked or embedded; it is read directly by
the Recommendation/Insight generation step, the same way Track Features and
Course Context already are in Intelligence Architecture. This mirrors how
Course, Assessment, LiveClass, Media and Track are already recognized. It
adds a new
`## Learning Integration` section below and the ADR-0016 line in
`## Related ADRs` above. It does not add, remove or rename any of the 11
Foundation tables, and it does not change Domain Responsibility, Knowledge
Architecture or Model Run Audit.

Two independent gates must clear before this amendment has any practical
effect, regardless of its own approval:

1. Architecture Owner approval of this Amendment (recorded below).
2. Learning Foundation reaching an implemented state. As of this proposal,
   Learning Foundation is `Implementation Status: Partial`: its ten-table
   schema/triggers and internal runtime are deployed on development, while its
   external surface and production deployment remain separately gated per
   [ADR-0016](ADR-0016-Learning-Foundation.md) and the
   [Learning Database Architecture Review](../quality/LF-Learning-Foundation-Database-Architecture-Review.md).

### Owner Approval

```text
Role: LearnForge Architecture Owner
Date:
Decision:
```

---

## Context

LearnForge cần AI hỗ trợ learner, teacher và administrator dựa trên learning
context, behavior và evidence đã được các Owner Domain quản lý.

Nếu AI đọc và diễn giải trực tiếp mọi internal table mà không có boundary:

* AI coupling chặt với Course, Assessment, LiveClass, Media và Track.
* Knowledge/RAG logic bị phân tán.
* Recommendation và Insight thiếu provenance.
* Prompt thay đổi không audit được.
* Provider execution, cost và safety khó truy vết.
* AI có nguy cơ trở thành business-state authority ngoài ý muốn.

Foundation cần một Domain độc lập cho AI interaction, output và governance,
nhưng phải giữ AI là consumer/decision-support capability.

---

## Decision

AI được xác định là:

```text
Learning Intelligence & Decision Support Domain

+

Consumer Domain
```

AI tiêu thụ approved context/evidence/read models và tạo AI-owned outputs.
Human hoặc Owner Domain vẫn quyết định business action.

AI Foundation Version 1.0 gồm 11 tables thuộc 5 nhóm:

* Knowledge.
* Conversation.
* Intelligence.
* Operations.
* Governance.

---

## Domain Responsibility

AI sở hữu:

* Knowledge Source registrations.
* Derived Knowledge Chunks và Embedding references.
* Conversations, Messages và Assistant Sessions.
* Recommendations và Insights.
* Model Run audit/provenance.
* Feedback.
* Prompt Templates và prompt lifecycle.

AI không sở hữu:

* Course Progress hoặc Completion.
* Assessment final Result/Grade.
* LiveClass Attendance.
* Media Processing State.
* Track Event hoặc Track AI-ready Feature.
* Certificate Eligibility/issuance.
* Payment approval, Subscription hoặc Billing state.
* Enrollment/access entitlement.

---

## AI Scope

Foundation supports these roles:

* AI Tutor.
* AI Teaching Assistant.
* AI Recommendation.
* AI Insight Engine.
* AI Dashboard Explanation.
* AI Authoring Assistant.

Each role must use authorized tenant context, governed prompts and auditable
Model Runs.

---

## Consumer Domain Boundary

```text
Owner Domain

↓ approved context / evidence / read model

AI

↓ recommendation / insight / suggestion / request

Human or Owner Domain

↓

Final Decision
```

AI không được trực tiếp:

* Complete Course.
* Update Progress.
* Set final Grade/Assessment Result.
* Update Attendance.
* Issue Certificate.
* Approve Payment.
* Enroll User.
* Rewrite Track behavior history/features.

Interaction state như `recommendation.status = accepted` chỉ ghi nhận AI
output interaction; nó không thực thi target business action.

---

## Knowledge Architecture

```text
Authorized Source Domain Record

↓ register

ai_knowledge_sources

↓ derive

ai_knowledge_chunks

↓ embed

ai_embeddings

↓ tenant-scoped retrieval

RAG Context
```

Owner Domain vẫn sở hữu source content. Chunks/embeddings là derived data và
có thể rebuild khi source fingerprint hoặc processing contract thay đổi.

Generic source references không miễn:

* Tenant validation.
* Source existence validation.
* Authorization.
* Retention and deletion synchronization.

Embedding table lưu metadata/vector-store reference; relational database không
bắt buộc lưu vector payload.

---

## Conversation Architecture

```text
ai_conversations

↓

ai_assistant_sessions

↓

ai_messages

↓

ai_model_runs
```

Conversation giữ interaction context. Assistant Session giữ authorized runtime
context snapshot cho một AI role. Message giữ ordered user/assistant/system/tool
content.

Sent messages immutable trừ approved privacy redaction. Tool output không được
bypass authorization hoặc update business state của Domain khác trực tiếp.

---

## Intelligence Architecture

```text
Track Features
  + Course Context
  + Assessment Evidence
  + Media Knowledge
  + LiveClass Authorized Evidence

↓ Model Run

Recommendation / Insight

↓

Human or Owner Domain Decision
```

AI Recommendation và Insight là Source Of Truth cho chính AI-generated output
và provenance, không phải Source Of Truth cho target business state.

Evidence snapshots support explanation/audit but do not copy ownership from
source Domains.

---

## Prompt Governance

`ai_prompt_templates` provides versioned prompt governance.

Rules:

* Global system prompt may use `customer_id NULL`; tenant prompt has owner.
* `code + version` is stable within normalized scope.
* Published Prompt Template is immutable.
* Prompt change creates a new version.
* Deprecated prompt may reference a replacement.
* Assistant role, purpose, input schema and output schema are explicit.
* Prompt cannot contain provider credentials/BYOK secrets.
* Prompt cannot instruct AI to perform forbidden business actions.
* Effective prompt version/hash is recorded by Model Run.

Prompt Governance is an AI Foundation governance rule. It does not create a
new canonical Architecture Principle.

---

## Model Run Audit

Every provider/model execution creates `ai_model_runs` provenance:

* Tenant and optional User.
* Purpose and Assistant Session.
* Provider and model.
* Prompt Template/version/hash.
* Input/output/total tokens.
* Latency and status.
* Estimated cost/currency.
* Safety and error metadata.
* Correlation context.

Model Run measurements are not Billing Source Of Truth. Usage/Billing may
consume approved measurements through their own contract.

No AI table stores raw API key, BYOK secret or provider credential.

---

## Course Integration

AI enrolled-learning context uses Product, Enrollment, Template Version and
Version Activity.

AI Authoring Assistant may generate suggestions for working content.
Teacher/Course Domain decides whether to save or publish. AI does not update
Progress/Completion.

---

## Assessment Integration

AI may produce Question, Rubric, Grading and Feedback Suggestions.

Assessment/authorized human owns final Result. AI confidence is advisory and
never final score.

---

## LiveClass Integration

AI may consume authorized Transcript, operational Evidence and Track behavior
to produce Summary/Insight.

AI does not update Room, Session, Attendance or Replay.

---

## Media Integration

Media owns binary, transcript, caption, processing and delivery.

AI stores authorized Knowledge registration and derived chunks/embedding
references. AI does not update Media Processing State.

---

## Track Integration

AI prioritizes Track summaries, AI-ready features and feature snapshots when an
appropriate projection exists.

Track owns behavior events/features. AI owns Recommendation, Insight and
Assistant output.

---

## Learning Integration

**Proposed — see Amendment Record above. This section is not in effect.** It
describes the Version 1.1 proposal only
and takes effect solely once the Amendment Record above is approved.

Once approved and once Learning Foundation is implemented, AI may read
`core_learning_mastery_profiles` (Mastery Profile) as an authorized,
read-only read-model input — consumed directly by Intelligence Architecture,
not registered as an `ai_knowledge_sources` entry, chunked or embedded — to
produce Recommendation or Insight, under
[ADR-0016 — Learning Foundation](ADR-0016-Learning-Foundation.md). Registering
Mastery Profile in `ai_knowledge_sources` for RAG/chunk/embedding use is out
of scope for this Amendment and would need its own review.

AI does not write Learning Evidence, Mastery Calculation, Mastery Profile,
Framework or Node state, and does not resolve Framework Version basis on
Learning's behalf. Learning remains Source Of Truth for Framework semantics,
Evidence and Mastery; AI only consumes the current Profile projection that
Learning already resolved and published.

---

## Database Namespace

```text
ai_*
```

---

## Foundation Tables

Knowledge:

* `ai_knowledge_sources`.
* `ai_knowledge_chunks`.
* `ai_embeddings`.

Conversation:

* `ai_conversations`.
* `ai_messages`.
* `ai_assistant_sessions`.

Intelligence:

* `ai_recommendations`.
* `ai_insights`.

Operations:

* `ai_model_runs`.
* `ai_feedback`.

Governance:

* `ai_prompt_templates`.

Canonical table documentation:
[docs/database/ai](../database/ai/).

---

## Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

Applied canonical principles:

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Evidence Principle.
* Read Model Principle.
* Generic Reference Principle.
* Tenant Isolation Principle.
* AI Consumer Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

This ADR references canonical definitions and does not redefine them.

---

## Foundation Freeze

AI Foundation Version 1.0 is Approved and Frozen by this ADR.

Changes to Domain Boundary, ownership, Source Of Truth or the 11-table
Foundation require:

* Approved ADR Amendment; or
* New ADR.

Implementation details must preserve the Consumer Domain Boundary.

---

## Consequences

### Benefits

* AI outputs are tenant-scoped and auditable.
* Knowledge/RAG pipeline is decoupled from source Domains.
* Recommendation and Insight ownership is explicit.
* Prompt changes are versioned.
* Provider/model execution has provenance.
* Track remains behavior authority while AI consumes features.
* No duplicate ownership of learning/commercial state.

### Trade-offs

* Derived chunks/embeddings need rebuild and deletion synchronization.
* Conversation/message retention requires privacy policy.
* Prompt approval/evaluation adds governance overhead.
* Provider abstraction, safety and cost attribution need operational contracts.
* Recommendation/Insight expiry and feedback lifecycle need ongoing policy.

---

## Future Extensions

Future topics that do not change Foundation by default:

* Provider abstraction and BYOK secret management.
* Prompt evaluation, tenant override and rollback workflow.
* Advanced vector-store/data-residency options.
* Multimodal knowledge and conversation.
* Agent/tool action governance.
* Human escalation and AI safety policy.
* Recommendation ranking/experimentation.
* Offline assistant experiences.
* Usage/cost export.
* Multi-agent coordination.

Any extension that changes Domain Boundary, ownership, Source Of Truth or
Foundation tables requires ADR Amendment or a new ADR.

---

## Result

```text
AI Foundation

Version 1.0

Status

Frozen

Ready for implementation

YES
```
