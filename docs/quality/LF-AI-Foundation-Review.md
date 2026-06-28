# LF AI Foundation Review

Review Date: 2026-06-28

Status: Ready for Owner Review

---

## 1. Tables

| Area | Table | File | Status |
| --- | --- | --- | --- |
| Knowledge | `ai_knowledge_sources` | [ai_knowledge_sources.md](../database/ai/ai_knowledge_sources.md) | Designed |
| Knowledge | `ai_knowledge_chunks` | [ai_knowledge_chunks.md](../database/ai/ai_knowledge_chunks.md) | Designed |
| Knowledge | `ai_embeddings` | [ai_embeddings.md](../database/ai/ai_embeddings.md) | Designed |
| Conversation | `ai_conversations` | [ai_conversations.md](../database/ai/ai_conversations.md) | Designed |
| Conversation | `ai_messages` | [ai_messages.md](../database/ai/ai_messages.md) | Designed |
| Conversation | `ai_assistant_sessions` | [ai_assistant_sessions.md](../database/ai/ai_assistant_sessions.md) | Designed |
| Intelligence | `ai_recommendations` | [ai_recommendations.md](../database/ai/ai_recommendations.md) | Designed |
| Intelligence | `ai_insights` | [ai_insights.md](../database/ai/ai_insights.md) | Designed |
| Operations | `ai_model_runs` | [ai_model_runs.md](../database/ai/ai_model_runs.md) | Designed |
| Operations | `ai_feedback` | [ai_feedback.md](../database/ai/ai_feedback.md) | Designed |
| Governance | `ai_prompt_templates` | [ai_prompt_templates.md](../database/ai/ai_prompt_templates.md) | Designed |

---

## 2. Architecture Summary

AI là Learning Intelligence & Decision Support Domain và là Consumer Domain.

```text
Owner Domain Data + Track Features

↓ authorized consumption

Knowledge / Conversation / Model Run

↓

Recommendation / Insight / Suggestion

↓

Human or Owner Domain Decision
```

AI chỉ sở hữu AI interaction/output/provenance. AI không sở hữu business state
của consumer Domain.

---

## 3. AI Roles

* AI Tutor.
* AI Teaching Assistant.
* AI Recommendation.
* AI Insight Engine.
* AI Dashboard Explanation.
* AI Authoring Assistant.

Mỗi role phải dùng approved Prompt Template, tenant-scoped context và Model Run
audit.

---

## 4. Relationship With Course

AI đọc published Version/Enrollment context cho learner. AI Authoring Assistant
chỉ tạo suggestion; Course/Teacher quyết định lưu và publish.

AI không update Course Progress, Completion hoặc Enrollment.

---

## 5. Relationship With LiveClass

AI có thể đọc authorized transcript/evidence và Track-derived behavior để tạo
summary/insight.

AI không update Room, Session, Attendance hoặc Replay.

---

## 6. Relationship With Assessment

AI tạo Question, Rubric, Grading hoặc Feedback Suggestion. Assessment/authorized
human quyết định final Result.

AI confidence không phải final grade.

---

## 7. Relationship With Media

Media sở hữu binary, transcript, processing và delivery. AI sở hữu derived
Knowledge registrations/chunks/embedding references.

AI không update Media Processing State.

---

## 8. Relationship With Track

AI ưu tiên Track summaries, AI-ready features và snapshots khi projection phù
hợp tồn tại.

Track sở hữu behavior events/features; AI sở hữu Recommendation/Insight.

---

## 9. Principles Applied

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Evidence Principle.
* Read Model Principle.
* AI Consumer Principle.
* Tenant Isolation Principle.
* Generic Reference Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* Prompt Governance.

---

## 10. Open Questions

* Provider abstraction and BYOK secret boundary.
* Prompt approval, tenant override and rollback policy.
* Chunking/re-embedding lifecycle.
* Vector-store strategy and data residency.
* Conversation/message retention and redaction.
* Model safety, human escalation and tool/action governance.
* Usage/cost attribution contract.
* Recommendation/Insight expiration and feedback lifecycle.
* Multimodal input/output policy.

---

## 11. Final Conclusion

AI Foundation có Domain Boundary, Consumer contract, 11 table designs, prompt
governance và execution provenance rõ.

Open questions cần owner review/P1 trước ADR và freeze.

```text
AI Foundation Ready for owner review:

YES
```

Foundation status:

```text
Foundation In Design
```
