# ADR-0003

Assessment Foundation

---

## Status

Approved

---

## Date

2026-06-27

---

Document Path: adr/ADR-0003-Assessment-Foundation.md

## Related ADRs

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md)

Implementation note: this ADR approves the target Assessment architecture.
Course Templates containing Quiz Activities remain blocked from publish under
the canonical `LF-Core-Course.md` Content Readiness policy until Assessment
Phase 2 implements the immutable Version Activity/Quiz binding.

---

## Context

LearnForge cần một Domain độc lập để quản lý:

* Question Banks and Questions
* Quiz
* Assignment
* Exam
* Attempt and Answer
* Grading
* Rubric

Course Domain vẫn phải là nơi định nghĩa immutable learning context và quyết
định Learning Progress. Media Domain phải tiếp tục sở hữu file. AI có thể hỗ
trợ evaluation nhưng không được trở thành final authority.

Nếu Assessment trực tiếp complete Course hoặc issue Certificate, hệ thống sẽ
có nhiều source of truth. Nếu historical Attempt đọc mutable Question/Rubric
authoring data, kết quả cũ có thể drift. Foundation cần khóa rõ ownership,
snapshot strategy và cross-domain evidence flow.

---

## Decision

Assessment được thiết kế là:

```text
Evaluation Domain
```

Assessment không phải Learning Domain và không phải Course Domain.

Assessment sở hữu authoring/evaluation data và sinh Evaluation Evidence. Course
Domain, Certificate Domain và các consumer khác tự quyết định business state
thuộc trách nhiệm của họ.

Mọi Assessment business record phải tenant-scoped bằng `customer_id`.

---

## Final Architecture

```text
Course Template

↓

Template Activity

↓ publish

Template Version

↓

Version Activity

↓

Assessment Quiz

↓

Attempt

↓

Answer

↓

Grading

↓

Evaluation Evidence

↓

Course Activity Progress
```

---

## Evaluation Ownership Decision

Assessment sở hữu:

* Question Bank
* Questions and localized content
* Question Media references and Options
* Topics
* Quiz and Quiz snapshots
* Attempt
* Answer
* Grading
* Rubric authoring and grading snapshots

Assessment không sở hữu:

* Course Progress
* Course Completion
* Certificate Eligibility or issuance
* Student Promotion
* Learning State

Assessment chỉ sinh Evidence. Domain đích tự validate và quyết định.

---

## Snapshot Strategy

Question là mutable authoring source với lifecycle:

```text
draft

↓ Teacher Edit

published

↓

archived
```

Published Question không immutable và không cần Question Version.

Khi Quiz publish:

```text
Question Authoring

↓ snapshot

Quiz Question
```

Quiz Question snapshot giữ prompt/content/media context, options, correct
answer, scoring và order. Published Quiz/Quiz Question snapshots immutable cho
existing Attempts.

Attempt khóa Quiz policy/structure tại attempt time. Answer snapshot option
label/text đã chọn.

---

## Rubric Snapshot

Rubric và Rubric Items là reusable authoring sources.

Khi grading bắt đầu:

```text
Rubric + Rubric Items

↓ snapshot

Grading Rubric Snapshot

↓

Historical Rubric Result
```

Rubric thay đổi sau đó không ảnh hưởng bài đã chấm. Historical grading luôn đọc
Rubric Snapshot.

```text
Rubric authoring ≠ Historical grading source
```

---

## AI Integration

```text
AI

↓

Question Generation

↓

Quiz Assembly

↓

Grading Suggestion

↓

Feedback Suggestion

↓

Teacher / Approved Policy

↓

Final Decision
```

AI không được quyết định Final Grade.

`confidence_score` chỉ mô tả độ tự tin của `ai_suggestion`; nó không phải final
score và không bắt buộc Teacher chấp nhận. AI grading phải giữ audit provenance
như provider, model và prompt version.

---

## Media Integration

Media Domain sở hữu:

* Question Media
* Uploaded Answers
* Speaking Recordings
* Essay Files
* Evidence Files

```text
Assessment

↓

Media References
```

Assessment không sở hữu binary, storage, processing, delivery hoặc retention.

---

## Course Integration

```text
Version Activity

↓

Assessment

↓

Evaluation Evidence

↓

Course Activity Progress
```

`version_activity_id` là liên kết chính với immutable Course learning context.
Attempt phải thuộc `enrollment_id`.

Assessment không update trực tiếp Course Progress/Completion. Course Domain đọc
score/pass/fail/grading evidence, áp dụng frozen completion rule và tự quyết
định.

Certificate Domain đọc evidence theo policy của mình và tự quyết định
eligibility/issuance.

---

## Foundation Decisions

* Assessment là Evaluation Domain.
* Assessment không phải Course Domain hoặc Learning Domain.
* Mọi Assessment data thuộc `customer_id`.
* Version Activity là liên kết chính với Course.
* Student không làm Assessment qua working Template Activity.
* Attempt thuộc một Enrollment learning cycle.
* Answer thuộc Attempt và frozen Quiz Question.
* Question là mutable authoring source.
* Question lifecycle là `draft`, `published`, `archived`.
* Archived Question không dùng cho Quiz mới.
* Question không cần Version.
* Quiz Question snapshot đủ bảo vệ historical Quiz/Attempt.
* Published Quiz/Quiz Question snapshots immutable cho existing Attempts.
* Answer snapshot lựa chọn đã nộp.
* Rubric và Rubric Items được snapshot khi grading bắt đầu.
* Historical grading luôn đọc Rubric Snapshot.
* AI chỉ tạo suggestion.
* `confidence_score` không phải final score.
* Teacher hoặc approved final-grading policy quyết định Final Grade.
* Media Domain sở hữu mọi Assessment file.
* Track Domain có thể sở hữu raw behavior events.
* Assessment chỉ sinh Evaluation Evidence.
* Assessment không complete Course, issue Certificate, promote Student hoặc
  update Course Progress.
* Course Activity Progress là source of truth cho Activity completion.

---

## Future Considerations

Các hạng mục sau là future scope, không phải Foundation defects:

* Adaptive Testing
* Computerized Adaptive Testing (CAT)
* Advanced Question Randomization
* AI Proctoring
* Plagiarism Detection
* Secure Browser
* Offline Exam
* Peer Review
* Regrade/versioned grading history
* Advanced competency mapping

Future changes phải giữ Evaluation Evidence Principle hoặc được phê duyệt bằng
ADR mới/amendment.

---

## Consequences

### Positive

* Course, Assessment, Media, Track, AI và Certificate responsibilities rõ ràng.
* Không tạo Course completion source song song.
* Mutable Question/Rubric authoring không làm drift historical Attempts.
* Enrollment cycle và published Course Version context được giữ ổn định.
* AI grading có human/policy authority boundary rõ.

### Trade-offs

* Snapshot data làm tăng storage và cần schema/version contract rõ.
* Course/Certificate cần evidence-consumption và recalculation flow idempotent.
* Max-attempt, regrade, AI audit và automatic/manual final-selection policies
  cần được chốt ở implementation phase.

---

## Applied Principles

See:

[LF-Architecture-Principles.md](../governance/LF-Architecture-Principles.md)

* Domain Responsibility Principle
* Source Of Truth Principle
* Immutable Principle
* Snapshot Principle
* Versioning Principle
* Evidence Principle
* Evaluation Domain Principle
* Tenant Isolation Principle
* Read Model Principle
* AI Consumer Principle
* Backward Compatibility Principle
* ADR Principle

---

## Result

```text
Foundation Ready

YES
```
