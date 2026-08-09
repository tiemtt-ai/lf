# LF-Core-Assessment.md

Version: 1.0

Status: Foundation Approved

Last Updated: 2026-06

Document Path: core/LF-Core-Assessment.md

---

# LF-Core Assessment Architecture

Assessment là Evaluation Domain của LearnForge.

Assessment quản lý authoring và evaluation evidence:

* Question Banks, Questions, Topics
* Quizzes, Exams, Assignments
* Attempts and Answers
* Grading and Rubrics
* Score, pass/fail, feedback and rubric results

Assessment không phải Course Domain và không sở hữu Course Progress, Course
Completion hoặc Certificate Eligibility.

## Current Course Integration Boundary

The immutable Assessment binding described below is the approved target
architecture, not a completed Course publish integration. Current Course
authoring uses `activity_type = quiz` with a provisional numeric reference, and
`LF-Core-Course.md` is the source of truth for publish readiness. Templates
containing Quiz Activities remain blocked from publish until Assessment Phase
2 implements and reconciles the immutable Version Activity/Quiz contract.

---

# Architecture Flow

```text
Course Template

↓

Template Activity (`activity_type = assessment`)

↓ publish

Template Version

↓

Version Activity (immutable)

↓

Assessment Quiz / Assignment / Exam

↓

Attempt / Answer / Grading

↓ evaluation evidence

Course Activity Progress

↓

Certificate Eligibility
```

---

# Assessment Evaluation Principle

Assessment chỉ sinh:

```text
Attempt

Answer

Score

Pass / Fail

Grading Result

Rubric Result

Feedback

Rubric Result

Evaluation Evidence
```

Assessment không sở hữu:

```text
Course Progress

Course Completion

Certificate Eligibility

Promotion

Learning State
```

Course Domain đọc Evaluation Evidence và tự quyết định Activity
Progress/Completion. Certificate Domain đọc Evaluation Evidence theo policy
của mình và tự quyết định Certificate Eligibility/issuance.

Assessment không được cập nhật trực tiếp business state của Domain khác.

---

# Course Version Binding

Working Template chỉ định nghĩa:

```text
activity_type = assessment
```

Student không làm bài qua working Template Activity.

Course-linked Quiz phải lưu:

```text
customer_id

product_id

template_version_id

version_activity_id
```

Version Activity đóng băng assessment learning context và completion rule.
`version_activity_id` là link chính giữa Assessment và Course Domain.

---

# Authoring And Snapshot Model

Authoring sources:

```text
Question Bank

↓

Question

↓

Content / Media / Options / Topics
```

Question authoring data có thể thay đổi. Khi publish Quiz:

```text
Question

↓ snapshot

Quiz Question
```

Quiz Question đóng băng:

* Prompt/content/media context
* Options label/text
* Correct answer
* Scoring
* Required/order

Published Quiz và snapshots immutable cho existing Attempts.

---

# Quiz Structure

```text
Quiz

↓

Quiz Sections

↓

Quiz Questions (snapshots)
```

Foundation supports:

```text
quiz

exam

assignment

homework

placement_test

mock_test
```

Grading modes:

```text
automatic

manual

hybrid
```

---

# Attempt And Answer

```text
Enrollment

↓

Attempt

↓

Answers

↓

Answer Files
```

Attempt phải tham chiếu `enrollment_id`; Enrollment phải active khi Attempt bắt
đầu. `user_id`, Product, Template Version và Version Activity phải khớp
Enrollment/Quiz.

Attempt khóa Quiz policy/structure snapshot tại start. Answer tham chiếu frozen
Quiz Question và snapshot option label/text đã chọn để bảo toàn audit.

Attempt score/pass/fail chỉ là evaluation evidence.

---

# Grading Architecture

```text
Attempt / Answer

↓

Grading Assignment

↓

Teacher / System / AI Suggestion

↓

Final Assessment Result
```

Automatic grading dùng cho objective questions theo approved rules.

Manual grading dùng cho essay, writing, speaking và các response cần judgment.

Hybrid grading:

```text
AI Suggestion

↓

Teacher Review

↓

Final Decision
```

AI grading không phải final grade. Teacher/final grader quyết định kết quả cuối.
Assessment service tổng hợp Answer/Attempt result nhưng không update Course
Progress trực tiếp.

`confidence_score` chỉ mô tả độ tự tin của AI suggestion, không phải final
score và không bắt buộc Teacher chấp nhận.

---

# Rubric Architecture

```text
Rubric Template

↓

Rubric Items

↓ snapshot at grading

Rubric Result
```

Rubric có thể tái sử dụng và thay đổi ở authoring layer. Mỗi Grading phải
snapshot criteria/weights và result để lịch sử không drift.

Khi grading bắt đầu, Rubric và toàn bộ Rubric Items được snapshot. Historical
grading luôn đọc snapshot; Rubric authoring không phải historical grading
source.

---

# Media Integration

Media Domain sở hữu file thật cho:

* Question images/audio/video/attachments
* Uploaded answers
* Speaking recordings
* Essay attachments
* Evidence files

Assessment chỉ lưu `media_file_id`. Binary, storage, processing, signed
delivery, transcript và retention thuộc Media Domain.

---

# AI Integration

AI có thể hỗ trợ:

* Question generation
* Quiz assembly
* Rubric suggestion
* Score suggestion
* Feedback suggestion

AI suggestion phải audit được và không được ghi đè final human decision.
Provider/model/prompt-version provenance cần được lưu trong grading metadata.

---

# Track Integration

Track Domain có thể ghi raw events:

```text
Quiz Started

Question Viewed

Answer Submitted

Quiz Finished
```

Assessment giữ evaluation business records. Track không thay thế Attempt,
Answer hoặc Grading source data.

---

# Database Namespace

```text
core_assessment_*
```

Foundation tables:

```text
core_assessment_categories
core_assessment_question_banks
core_assessment_questions
core_assessment_question_contents
core_assessment_question_media
core_assessment_question_options
core_assessment_topics
core_assessment_question_topics
core_assessment_quizzes
core_assessment_quiz_sections
core_assessment_quiz_questions
core_assessment_attempts
core_assessment_answers
core_assessment_answer_files
core_assessment_grading_assignments
core_assessment_gradings
core_assessment_rubrics
core_assessment_rubric_items
```

---

# Design Rules

1. Mọi Assessment business data phải có `customer_id`.
2. Cross-domain references phải cùng tenant.
3. Assessment là Evaluation Domain, không phải Course Domain.
4. Student không làm bài qua working Template Activity.
5. Course integration dùng immutable Version Activity.
6. Published Quiz/Quiz Question snapshots không được mutate cho existing Attempts.
7. Attempt thuộc một Enrollment learning cycle.
8. Answer thuộc Attempt và frozen Quiz Question.
9. File thật thuộc Media Domain.
10. AI grading chỉ là suggestion.
11. Rubric criteria/result phải snapshot khi grading.
12. Assessment evidence không tự quyết định Course completion/certificate.
13. Course Activity Progress là source of truth cho activity completion.
14. Cross-domain effect dùng Evidence/Event/Request; Domain đích tự quyết định.

---

# Architecture Decision

Assessment Foundation được phê duyệt và freeze tại:

[ADR-0003 — Assessment Foundation](../adr/ADR-0003-Assessment-Foundation.md)

ADR này là source quyết định cho Evaluation Domain ownership, Question/Quiz
snapshot strategy, Rubric snapshots, AI grading boundaries và Course/Media
integration.

---

# Final Statement

Assessment đo lường learning outcome và sinh evaluation evidence.

Assessment Foundation Version 1.0 đã được phê duyệt. Mọi thay đổi kiến trúc sau
freeze phải được review bằng ADR mới hoặc amendment được owner chấp thuận.

Course Version Activity giữ learning context bất biến. Enrollment giữ learning
cycle. Assessment giữ Attempt, Answer, Score và Grading. Media giữ file. Track
giữ behavior events. Course Domain giữ Progress/Completion, và Certificate
Domain tự quyết định eligibility.

---

End of LF-Core-Assessment
